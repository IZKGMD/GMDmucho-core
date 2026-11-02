package core

import (
	"crypto/sha256"
	"crypto/subtle"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"unicode/utf8"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
)

func queryString(r request, key string) string {
	values := r.raw.URL.Query()[key]
	if len(values) == 0 {
		return ""
	}
	return values[len(values)-1]
}
func v2StringList(raw string) []string {
	result := []string{}
	raw = v2Trim(raw)
	if raw == "" {
		return result
	}
	var values []any
	decoder := json.NewDecoder(strings.NewReader(raw))
	decoder.UseNumber()
	token, err := decoder.Token()
	container, ok := token.(json.Delim)
	decoded := err == nil && ok && (container == '[' || container == '{')
	if decoded {
		for decoder.More() {
			if container == '{' {
				if _, err = decoder.Token(); err != nil {
					break
				}
			}
			var value any
			if err = decoder.Decode(&value); err != nil {
				break
			}
			values = append(values, value)
		}
		if err == nil {
			_, err = decoder.Token()
		}
		var trailing any
		decoded = err == nil && decoder.Decode(&trailing) == io.EOF
	}
	if !decoded {
		values = nil
		for _, part := range strings.Split(raw, ",") {
			values = append(values, part)
		}
	}
	seen := map[string]bool{}
	for _, item := range values {
		value := v2Trim(v2String(item))
		if value != "" && !seen[value] {
			seen[value] = true
			result = append(result, value)
		}
	}
	return result
}
func (h *Handler) v2Profile(r request) (v2Result, error) {
	raw := v2Trim(queryString(r, "account_id"))
	unsigned := strings.TrimPrefix(raw, "+")
	id, err := strconv.ParseInt(raw, 10, 64)
	if err != nil || id <= 0 || (len(unsigned) > 1 && unsigned[0] == '0') {
		return v2Error("invalid_account_id", 400), nil
	}
	row, err := dbutil.One(r.ctx, h.db, `SELECT p.*,pr.last_seen,UNIX_TIMESTAMP(pr.last_seen) AS last_stamp,UNIX_TIMESTAMP(NOW()) AS now_stamp,COALESCE(ro.code,'user') AS server_role,COALESCE(ro.priority,0) AS server_rank FROM mucho_profile_customization p LEFT JOIN mucho_profile_presence pr ON pr.account_id=p.account_id LEFT JOIN accounts a ON a.account_id=p.account_id LEFT JOIN roles ro ON ro.id=a.role_id WHERE p.account_id=? LIMIT 1`, id)
	if errors.Is(err, sql.ErrNoRows) {
		return v2Error("profile_not_found", 404), nil
	}
	if err != nil {
		return v2Result{}, err
	}
	visible := row.Int("online_visible") != 0
	online := visible && row["last_seen"] != "" && row.Int("last_stamp") > 0 && row.Int("now_stamp")-row.Int("last_stamp") <= 90
	var last any
	if visible && row["last_seen"] != "" {
		last = row["last_seen"]
	}
	data := map[string]any{"account_id": row.Int("account_id"), "display_name": row["display_name"], "authority": map[string]any{"role": strings.ToUpper(row["server_role"]), "rank": row.Int("server_rank"), "verified": false}, "title": row["title"], "badges": v2StringList(row["badges"]), "status": row["status"], "bio": row["bio"], "theme": map[string]any{"primary": row["theme_primary"], "secondary": row["theme_secondary"], "banner": row["banner"]}, "pinned_levels": idList(row["pinned_levels"], 3), "showcase": v2StringList(row["showcase"]), "favorite_difficulty": row["favorite_difficulty"], "presence": map[string]any{"visible": visible, "online": online, "last_seen": last}, "created_at": row["created_at"], "updated_at": row["updated_at"]}
	if data["pinned_levels"].([]int64) == nil {
		data["pinned_levels"] = []int64{}
	}
	return v2OK(map[string]any{"ok": true, "api": "MuchoCore", "version": "2.2", "data": data}), nil
}
func (h *Handler) v2Heartbeat(r request, input map[string]any) (v2Result, error) {
	id := v2Int(input["account_id"])
	token := v2Trim(v2String(input["token"]))
	if id <= 0 {
		return v2Error("invalid_account_id", 400), nil
	}
	if len(token) < 32 || len(token) > 256 {
		return v2Error("invalid_token", 401), nil
	}
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return v2Result{}, err
	}
	defer tx.Rollback()
	row, err := dbutil.One(r.ctx, tx, `SELECT p.client_token_hash,p.last_seen,a.is_active,a.is_banned,UNIX_TIMESTAMP(NOW())-UNIX_TIMESTAMP(p.last_seen) AS elapsed FROM mucho_profile_presence p JOIN accounts a ON a.account_id=p.account_id WHERE p.account_id=? LIMIT 1 FOR UPDATE`, id)
	if errors.Is(err, sql.ErrNoRows) {
		return v2Error("unauthorized", 401), nil
	}
	if err != nil {
		return v2Result{}, err
	}
	hash := fmt.Sprintf("%x", sha256.Sum256([]byte(token)))
	if row.Int("is_active") != 1 || row.Int("is_banned") != 0 || row["client_token_hash"] == "" || subtle.ConstantTimeCompare([]byte(row["client_token_hash"]), []byte(hash)) != 1 {
		return v2Error("unauthorized", 401), nil
	}
	if row["last_seen"] != "" && row.Int("elapsed") < 5 {
		result := v2Error("heartbeat_rate_limited", 429)
		result.data["retry_after_seconds"] = 5
		result.headers = http.Header{"Retry-After": {"5"}}
		return result, nil
	}
	if _, err = tx.ExecContext(r.ctx, `UPDATE mucho_profile_presence SET last_seen=CURRENT_TIMESTAMP WHERE account_id=?`, id); err != nil {
		return v2Result{}, err
	}
	if err = tx.Commit(); err != nil {
		return v2Result{}, err
	}
	return v2OK(map[string]any{"ok": true, "api": "MuchoCore", "version": "2.2", "presence": map[string]any{"account_id": id, "online": true, "next_heartbeat_seconds": 30, "online_timeout_seconds": 90}}), nil
}
func (h *Handler) v2Dashboard(r request) (v2Result, error) {
	stats := map[string]any{}
	for _, item := range []struct{ key, query string }{{"players", `SELECT COUNT(*) FROM accounts WHERE is_active=1 AND is_banned=0`}, {"levels", `SELECT COUNT(*) FROM levels WHERE is_deleted=0 AND is_unlisted=0 AND unlisted2=0`}, {"comments", `SELECT COUNT(*) FROM comments`}} {
		var count int64
		if err := h.db.QueryRowContext(r.ctx, item.query).Scan(&count); err != nil {
			return v2Result{}, err
		}
		stats[item.key] = count
	}
	rows, err := dbutil.Query(r.ctx, h.db, `SELECT l.level_id,l.account_id,l.name,l.downloads,l.likes,l.stars,a.username AS creator_name FROM levels l LEFT JOIN accounts a ON a.account_id=l.account_id WHERE l.is_deleted=0 AND l.is_unlisted=0 AND l.unlisted2=0 ORDER BY l.level_id DESC LIMIT 6`)
	if err != nil {
		return v2Result{}, err
	}
	levels := []map[string]any{}
	for _, row := range rows {
		name, creator := row["name"], row["creator_name"]
		if name == "" {
			name = "Unnamed Level"
		}
		if creator == "" {
			creator = fmt.Sprintf("User #%d", row.Int("account_id"))
		}
		levels = append(levels, map[string]any{"id": row.Int("level_id"), "name": name, "creator": creator, "downloads": row.Int("downloads"), "likes": row.Int("likes"), "stars": row.Int("stars")})
	}
	return v2OK(map[string]any{"ok": true, "api": "MuchoCore", "version": "2.2", "stats": stats, "latest_levels": levels}), nil
}
func sanitizeBrandName(value string) string {
	if !utf8.ValidString(value) {
		return ""
	}
	value = threadControls.ReplaceAllString(v2Trim(value), "")
	runes := []rune(value)
	if len(runes) > 64 {
		value = string(runes[:64])
	}
	return v2Trim(value)
}
func (h *Handler) branding(r request) map[string]any {
	name := sanitizeBrandName(h.options.ServerName)
	if name == "" {
		name = "Mucho GDPS"
	}
	fallback := map[string]any{"server_name": name, "server_by_name": "", "social_url": ""}
	row, err := dbutil.One(r.ctx, h.db, `SELECT server_name,server_by_name,social_url FROM mucho_branding WHERE id=1 LIMIT 1`)
	if err != nil {
		return fallback
	}
	social := threadControls.ReplaceAllString(v2Trim(row["social_url"]), "")
	if social != "" {
		u, err := url.Parse(social)
		if err != nil || len(social) > 512 || u.Hostname() == "" || (strings.ToLower(u.Scheme) != "http" && strings.ToLower(u.Scheme) != "https") {
			return fallback
		}
	}
	name = sanitizeBrandName(row["server_name"])
	if name == "" {
		name = "Mucho GDPS"
	}
	return map[string]any{"server_name": name, "server_by_name": sanitizeBrandName(row["server_by_name"]), "social_url": social}
}
func (h *Handler) v2Music(r request) (v2Result, error) {
	rows, err := dbutil.Query(r.ctx, h.db, `SELECT id,name,author_name,size,download_url,created_at FROM songs WHERE is_verified=1 ORDER BY id DESC LIMIT 20`)
	if err != nil {
		return v2Result{}, err
	}
	songs := []map[string]any{}
	for _, row := range rows {
		var created any
		if row["created_at"] != "" {
			created = row["created_at"]
		}
		songs = append(songs, map[string]any{"id": row.Int("id"), "name": row["name"], "artist": row["author_name"], "size_mb": row.Float("size"), "download_url": row["download_url"], "created_at": created})
	}
	return v2OK(map[string]any{"ok": true, "songs": songs}), nil
}
func (h *Handler) v2ClientConfig(r request) (v2Result, error) {
	platform := strings.ToLower(v2Trim(queryString(r, "platform")))
	version := v2Trim(queryString(r, "version"))
	if platform != "android" && platform != "windows" {
		return v2Error("invalid_platform", 400), nil
	}
	rows, err := dbutil.Query(r.ctx, h.db, `SELECT flag_key,enabled FROM mucho_feature_flags ORDER BY flag_key`)
	if err != nil {
		return v2Result{}, err
	}
	var flags any = []any{}
	if len(rows) > 0 {
		items := map[string]bool{}
		for _, row := range rows {
			items[row["flag_key"]] = row.Int("enabled") != 0
		}
		flags = items
	}
	release, err := dbutil.TypedOne(r.ctx, h.db, `SELECT current_version,minimum_version,download_url,sha256,release_notes,maintenance,updated_at FROM mucho_client_releases WHERE platform=? LIMIT 1`, platform)
	if errors.Is(err, sql.ErrNoRows) {
		release = map[string]any{}
	} else if err != nil {
		return v2Result{}, err
	}
	current, _ := release["current_version"].(string)
	minimum, _ := release["minimum_version"].(string)
	var reported any
	if version != "" {
		reported = version
	}
	client := map[string]any{"reported_version": reported, "current_version": release["current_version"], "minimum_version": release["minimum_version"], "update_available": version != "" && current != "" && comparePHPVersion(version, current) < 0, "update_required": version != "" && minimum != "" && comparePHPVersion(version, minimum) < 0, "maintenance": anyInt(release["maintenance"]) != 0, "download_url": release["download_url"], "sha256": release["sha256"], "release_notes": release["release_notes"]}
	return v2OK(map[string]any{"ok": true, "api": "MuchoCore", "version": "2.3", "platform": platform, "client": client, "features": flags, "config_updated_at": release["updated_at"]}), nil
}
