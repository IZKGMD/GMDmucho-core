package core

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strconv"
	"strings"

	"github.com/IZKGMD/GMDmucho-core/go/internal/admin"
	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
)

func adminFailure(code, message string, status int) (v2Result, error) {
	return v2Result{data: map[string]any{"ok": false, "error": code, "message": message}, status: status}, nil
}
func (h *Handler) adminClient(r request) (response reply) {
	id, err := randomHex(12)
	if err != nil {
		return jsonReply(map[string]any{"ok": false, "error": "server_error", "message": "Internal server error."}, 500)
	}
	result := v2Result{data: map[string]any{"ok": false, "error": "server_error", "message": "Internal server error."}, status: 500}
	defer func() {
		if recover() != nil {
			result, _ = adminFailure("server_error", "Internal server error.", 500)
			if h.options.Logger != nil {
				h.options.Logger.Error("administrator API request panicked")
			}
		}
		result.data["request_id"] = id
		response = jsonReply(result.data, result.status)
		response.Headers = http.Header{"Cache-Control": {"no-store, max-age=0"}, "Pragma": {"no-cache"}, "X-Content-Type-Options": {"nosniff"}, "X-Frame-Options": {"DENY"}, "Referrer-Policy": {"no-referrer"}, "X-Request-ID": {id}}
		if r.raw.Method != "POST" {
			response.Headers.Set("Allow", "POST")
		}
	}()
	if r.raw.Method != "POST" {
		result, _ = adminFailure("method_not_allowed", "POST required.", 405)
		return
	}
	data, err := io.ReadAll(io.LimitReader(r.raw.Body, 65537))
	if err != nil || len(data) > 65536 {
		result, _ = adminFailure("invalid_request", "Request body is missing or too large.", 413)
		return
	}
	decoder := json.NewDecoder(strings.NewReader(string(data)))
	decoder.UseNumber()
	var input map[string]any
	if err = decoder.Decode(&input); err != nil || input == nil {
		result, _ = adminFailure("invalid_json", "JSON object required.", 400)
		return
	}
	var trailing any
	if decoder.Decode(&trailing) != io.EOF {
		result, _ = adminFailure("invalid_json", "JSON object required.", 400)
		return
	}
	action := v2String(input["action"])
	if action == "" || len(action) > 64 {
		result, _ = adminFailure("invalid_request", "Action is required.", 422)
		return
	}
	result, err = h.adminClientData(r, action, input)
	if err != nil {
		var denial *admin.Error
		if errors.As(err, &denial) {
			result, _ = adminFailure(denial.Code, denial.Message, denial.Status)
		} else {
			result, _ = adminFailure("server_error", "Internal server error.", 500)
			if h.options.Logger != nil {
				h.options.Logger.Error("administrator API operation failed", "request_id", id)
			}
		}
	}
	return
}
func adminInt(input map[string]any, key string, minimum, maximum int64) (int64, error) {
	number, ok := input[key].(json.Number)
	if !ok {
		return 0, &admin.Error{Code: "invalid_request", Message: "Invalid field: " + key, Status: 422}
	}
	value, err := number.Int64()
	if err != nil {
		return 0, &admin.Error{Code: "invalid_request", Message: "Invalid field: " + key, Status: 422}
	}
	return max(minimum, min(maximum, value)), nil
}
func adminBool(input map[string]any, key string) (bool, error) {
	value, ok := input[key].(bool)
	if !ok {
		return false, &admin.Error{Code: "invalid_request", Message: "Invalid field: " + key, Status: 422}
	}
	return value, nil
}
func adminIdentity(identity admin.Identity) map[string]any {
	return map[string]any{"id": identity.ID, "username": identity.Username, "role": identity.Role, "rank": admin.Rank(identity.Role)}
}
func (h *Handler) adminClientData(r request, action string, input map[string]any) (v2Result, error) {
	service := admin.Service{DB: h.db, Now: h.now}
	if action == "auth.login" {
		identity, token, err := service.Login(r.ctx, v2String(input["username"]), v2String(input["password"]), v2String(input["totp"]), v2String(input["client_version"]), r.ip)
		if err != nil {
			return v2Result{}, err
		}
		return v2OK(map[string]any{"ok": true, "token": token, "expires_in": admin.TokenTTL, "admin": adminIdentity(identity)}), nil
	}
	permissions := map[string]string{"auth.logout": "self.security", "auth.me": "self.security", "server.health": "settings.manage", "players.search": "players.view", "players.get": "players.view", "players.set_ban": "players.manage", "players.set_role": "players.manage", "players.update_profile": "players.manage", "levels.search": "levels.view", "levels.update": "levels.manage", "audit.list": "audit.view", "mucho.konami": "settings.manage"}
	permission, known := permissions[action]
	if !known {
		return adminFailure("unknown_action", "Unknown API action.", 404)
	}
	identity, err := service.Authorize(r.ctx, r.raw.Header.Get("Authorization"), permission)
	if err != nil {
		return v2Result{}, err
	}
	switch action {
	case "auth.me":
		return v2OK(map[string]any{"ok": true, "admin": adminIdentity(identity)}), nil
	case "auth.logout":
		tx, err := h.db.BeginTx(r.ctx, nil)
		if err != nil {
			return v2Result{}, err
		}
		defer tx.Rollback()
		if _, err = tx.ExecContext(r.ctx, `UPDATE mucho_admin_client_tokens SET revoked_at=NOW() WHERE id=?`, identity.TokenID); err != nil {
			return v2Result{}, err
		}
		if err = admin.Audit(r.ctx, tx, identity, r.ip, "auth.logout", "", "", nil); err != nil {
			return v2Result{}, err
		}
		return v2OK(map[string]any{"ok": true}), tx.Commit()
	case "server.health":
		var ready int
		if err = h.db.QueryRowContext(r.ctx, `SELECT 1`).Scan(&ready); err != nil {
			return v2Result{}, err
		}
		return v2OK(map[string]any{"ok": true, "api": "MuchoAdminClient", "version": "1.0.1", "database": "connected", "time": h.now().UTC().Format("2006-01-02T15:04:05+00:00"), "motto": "The cube is watching the backups.", "admin": identity.Username}), nil
	case "players.search", "players.get":
		return h.adminPlayersRead(r, input, action)
	case "players.set_ban", "players.set_role", "players.update_profile":
		return h.adminPlayersWrite(r, input, action, identity)
	case "levels.search":
		search := v2Trim(v2String(input["query"]))
		if len(search) > 64 {
			search = search[:64]
		}
		exact := int64(-1)
		if digits.MatchString(search) {
			exact, _ = strconv.ParseInt(search, 10, 64)
		}
		rows, err := dbutil.Query(r.ctx, h.db, `SELECT l.level_id,l.account_id,l.name,a.username AS creator,l.stars,l.difficulty,l.demon,l.demon_difficulty,l.featured,l.epic,l.custom_difficulty,l.rate_tier,l.custom_rate_tier,l.requested_stars,l.downloads,l.likes,l.is_deleted,l.updated_at FROM levels l LEFT JOIN accounts a ON a.account_id=l.account_id WHERE (?=1 OR l.name LIKE ? OR a.username LIKE ? OR l.level_id=?) ORDER BY l.level_id DESC LIMIT 50`, search == "", "%"+search+"%", "%"+search+"%", exact)
		if err != nil {
			return v2Result{}, err
		}
		levels := []map[string]any{}
		for _, row := range rows {
			level := map[string]any{"name": row["name"], "creator": row["creator"], "updated_at": row["updated_at"]}
			for _, key := range []string{"level_id", "account_id", "stars", "difficulty", "demon_difficulty", "epic", "custom_difficulty", "rate_tier", "custom_rate_tier", "requested_stars", "downloads", "likes"} {
				level[key] = row.Int(key)
			}
			for _, key := range []string{"demon", "featured", "is_deleted"} {
				level[key] = row.Int(key) != 0
			}
			levels = append(levels, level)
		}
		return v2OK(map[string]any{"ok": true, "levels": levels}), nil
	case "levels.update":
		return h.adminLevelWrite(r, input, identity)
	case "audit.list":
		rows, err := dbutil.TypedQuery(r.ctx, h.db, `SELECT id,admin_username,action,target_type,target_id,details,created_at FROM mucho_admin_client_audit ORDER BY id DESC LIMIT 100`)
		return v2OK(map[string]any{"ok": true, "entries": rows}), err
	case "mucho.konami":
		err := admin.Audit(r.ctx, h.db, identity, r.ip, "mucho.konami", "", "", nil)
		return v2OK(map[string]any{"ok": true, "message": "↑ ↑ ↓ ↓ ← → ← → B A — Mucho remembers."}), err
	}
	return v2Result{}, fmt.Errorf("unknown administrator operation")
}

const adminPlayerSQL = `SELECT a.account_id,a.username,COALESCE(ro.code,'user') AS role,a.is_active,a.is_banned,p.stars,p.moons,p.diamonds,p.secret_coins,p.user_coins,p.demons,p.creator_points,p.bio,p.last_played_at FROM accounts a LEFT JOIN profiles p ON p.account_id=a.account_id LEFT JOIN roles ro ON ro.id=a.role_id`

func adminPlayerPayload(row dbutil.Row) map[string]any {
	var played any
	if row["last_played_at"] != "" {
		played = row["last_played_at"]
	}
	data := map[string]any{"account_id": row.Int("account_id"), "username": row["username"], "role": row["role"], "is_active": row.Int("is_active") != 0, "is_banned": row.Int("is_banned") != 0, "bio": row["bio"], "last_played_at": played}
	for _, key := range []string{"stars", "moons", "diamonds", "secret_coins", "user_coins", "demons", "creator_points"} {
		data[key] = int64(row.Float(key))
	}
	return data
}
func (h *Handler) adminPlayersRead(r request, input map[string]any, action string) (v2Result, error) {
	if action == "players.get" {
		id, err := adminInt(input, "account_id", 1, 1<<63-1)
		if err != nil {
			return v2Result{}, err
		}
		rows, err := dbutil.Query(r.ctx, h.db, adminPlayerSQL+` WHERE a.account_id=? LIMIT 1`, id)
		if err != nil {
			return v2Result{}, err
		}
		if len(rows) == 0 {
			return adminFailure("not_found", "Player not found.", 404)
		}
		return v2OK(map[string]any{"ok": true, "player": adminPlayerPayload(rows[0])}), nil
	}
	search := v2Trim(v2String(input["query"]))
	if len(search) > 64 {
		search = search[:64]
	}
	exact := int64(-1)
	if digits.MatchString(search) {
		exact, _ = strconv.ParseInt(search, 10, 64)
	}
	rows, err := dbutil.Query(r.ctx, h.db, adminPlayerSQL+` WHERE (?=1 OR a.username LIKE ? OR a.account_id=?) ORDER BY a.account_id DESC LIMIT 50`, search == "", "%"+search+"%", exact)
	if err != nil {
		return v2Result{}, err
	}
	players := []map[string]any{}
	for _, row := range rows {
		players = append(players, adminPlayerPayload(row))
	}
	return v2OK(map[string]any{"ok": true, "players": players}), nil
}
