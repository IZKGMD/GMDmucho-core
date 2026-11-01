package core

import (
	"bytes"
	"compress/gzip"
	"crypto/hmac"
	"crypto/sha256"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"math"
	"net/netip"
	"sort"
	"strconv"
	"strings"
	"unicode/utf8"

	"github.com/IZKGMD/GMDmucho-core/go/internal/account"
	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
	"github.com/IZKGMD/GMDmucho-core/go/internal/jobs"
	"github.com/IZKGMD/GMDmucho-core/go/internal/protocol"
)

func (r request) bounded(key string, fallback, low, high int64) (int64, error) {
	if _, ok := r.form[key]; !ok {
		return fallback, nil
	}
	value := r.str(key)
	if !strictInt.MatchString(value) {
		return 0, errors.New("invalid numeric field")
	}
	n, err := strconv.ParseInt(strings.TrimSuffix(value, "\n"), 10, 64)
	if err != nil || n < low || n > high {
		return 0, errors.New("numeric field out of range")
	}
	return n, nil
}
func (r request) flag(key string) (int64, error) {
	v := r.str(key)
	if v == "" || v == "0" {
		return 0, nil
	}
	if v == "1" {
		return 1, nil
	}
	return 0, errors.New("invalid boolean field")
}
func (r request) limited(key, fallback string, limit int) (string, error) {
	v := r.str(key)
	if _, ok := r.form[key]; !ok {
		v = fallback
	}
	if len(v) > limit {
		return "", errors.New("field too large")
	}
	return v, nil
}
func (h *Handler) restricted(r request, id int64, kind string) (bool, error) {
	var n int
	err := h.db.QueryRowContext(r.ctx, `SELECT COUNT(*) FROM mucho_gd2209_restrictions WHERE account_id=? AND kind=? AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP())`, id, kind).Scan(&n)
	return n > 0, err
}
func (h *Handler) findLevel(r request, id int64) (dbutil.Row, error) {
	return dbutil.One(r.ctx, h.db, `SELECT l.*,COALESCE(p.user_id,l.account_id) AS user_id FROM levels l LEFT JOIN profiles p ON p.account_id=l.account_id WHERE l.level_id=? AND l.is_deleted=0 LIMIT 1`, id)
}

func (h *Handler) uploadLevel(r request) (string, error) {
	id := r.int("accountID")
	var err error
	if credential := r.credential(); credential != "" {
		_, err = h.accounts.Authenticate(r.ctx, id, credential)
	} else if r.version.Effective() > 0 && r.version.Effective() < 19 && strings.TrimSpace(r.str("udid")) != "" {
		id, err = h.accounts.LegacyIdentity(r.ctx, r.str("udid"), r.str("userName"), r.ip)
	} else {
		return "", account.ErrUnauthorized
	}
	if err != nil {
		return "", err
	}
	if banned, err := h.restricted(r, id, "upload"); err != nil || banned {
		return "-1", err
	}
	if r.int("gameVersion") == 0 && r.version.Effective() > 0 {
		r.form.Set("gameVersion", strconv.Itoa(r.version.Effective()))
	}
	game, err := r.bounded("gameVersion", 1, 1, 1000)
	if err != nil {
		return "", err
	}
	binary, err := r.bounded("binaryVersion", 0, 0, 1000000)
	if err != nil {
		return "", err
	}
	version := protocol.Version{Game: int(game), Binary: int(binary)}
	levelID, err := r.bounded("levelID", 0, 0, math.MaxInt64)
	if err != nil {
		return "", err
	}
	fields := map[string]any{"account_id": id}
	for _, f := range []struct {
		column, key         string
		fallback, low, high int64
	}{
		{"level_version", "levelVersion", 1, 1, 1000000}, {"game_version", "gameVersion", 22, 1, 1000}, {"binary_version", "binaryVersion", 0, 0, 1000000}, {"length", "levelLength", 0, 0, 10}, {"audio_track", "audioTrack", 0, 0, 1000000}, {"song_id", "songID", 0, 0, math.MaxInt64}, {"original_level_id", "original", 0, 0, math.MaxInt64}, {"object_count", "objects", 0, 0, 5000000}, {"coins", "coins", 0, 0, 1000}, {"requested_stars", "requestedStars", 0, 0, 100}, {"wt", "wt", 0, 0, math.MaxInt64}, {"wt2", "wt2", 0, 0, math.MaxInt64}, {"ts", "ts", 0, 0, math.MaxInt64},
	} {
		v, err := r.bounded(f.key, f.fallback, f.low, f.high)
		if err != nil {
			return "", err
		}
		fields[f.column] = v
	}
	for _, f := range []struct {
		column, key, fallback string
		limit                 int
	}{
		{"name", "levelName", "Unnamed", 64}, {"description", "levelDesc", "", 8192}, {"copy_password", "password", "0", 64}, {"extra_string", "extraString", "29_29_29_40_29_29_29_29_29_29_29_29_29_29_29_29", 65536}, {"level_data", "levelString", "", 32 * 1024 * 1024}, {"level_info", "levelInfo", "", 1024 * 1024}, {"settings_string", "settingsString", "", 1024 * 1024}, {"song_ids", "songIDs", "", 65536}, {"sfx_ids", "sfxIDs", "", 65536},
	} {
		v, err := r.limited(f.key, f.fallback, f.limit)
		if err != nil {
			return "", err
		}
		fields[f.column] = v
	}
	name := strings.Trim(fields["name"].(string), " \t\r\n\x00\x0b")
	if name == "" {
		name = "Unnamed"
	}
	if !utf8.ValidString(name) {
		return "-1", nil
	}
	for _, c := range name {
		if c < 32 || c == 127 {
			return "-1", nil
		}
	}
	fields["name"] = name
	payload := fields["level_data"].(string)
	if payload == "" || strings.ContainsRune(payload, 0) {
		return "-1", nil
	}
	fields["description"] = protocol.DescriptionStorage(fields["description"].(string), version.Effective())
	if len(fields["description"].(string)) > 8192 {
		return "-1", nil
	}
	unlisted := "unlisted"
	if _, ok := r.form["unlisted1"]; ok {
		unlisted = "unlisted1"
	}
	unlisted2 := unlisted
	if _, ok := r.form["unlisted2"]; ok {
		unlisted2 = "unlisted2"
	}
	for column, key := range map[string]string{"auto_level": "auto", "two_player": "twoPlayer", "is_unlisted": unlisted, "unlisted2": unlisted2, "ldm": "ldm"} {
		v, err := r.flag(key)
		if err != nil {
			return "", err
		}
		fields[column] = v
	}
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return "", err
	}
	defer tx.Rollback()
	reason := "upload"
	if levelID > 0 {
		var owner int64
		if err = tx.QueryRowContext(r.ctx, `SELECT account_id FROM levels WHERE level_id=? AND is_deleted=0 FOR UPDATE`, levelID).Scan(&owner); err != nil {
			return "", err
		}
		if owner != id {
			return "", account.ErrUnauthorized
		}
		reason = "update"
	}
	keys := make([]string, 0, len(fields))
	for key := range fields {
		if levelID == 0 || key != "account_id" {
			keys = append(keys, key)
		}
	}
	sort.Strings(keys)
	var values []any
	marks, columns := make([]string, len(keys)), make([]string, len(keys))
	for i, key := range keys {
		columns[i] = "`" + key + "`"
		marks[i] = "?"
		values = append(values, fields[key])
	}
	if levelID == 0 {
		result, e := tx.ExecContext(r.ctx, `INSERT INTO levels(`+strings.Join(columns, ",")+`) VALUES(`+strings.Join(marks, ",")+`)`, values...)
		if e != nil {
			return "", e
		}
		levelID, err = result.LastInsertId()
	} else {
		sets := make([]string, len(keys))
		for i, key := range keys {
			sets[i] = "`" + key + "`=?"
		}
		values = append(values, levelID, id)
		_, err = tx.ExecContext(r.ctx, `UPDATE levels SET `+strings.Join(sets, ",")+`,updated_at=CURRENT_TIMESTAMP WHERE level_id=? AND account_id=? AND is_deleted=0`, values...)
	}
	if err != nil {
		return "", err
	}
	if err = tx.Commit(); err != nil {
		return "", err
	}
	h.recordRevision(r, levelID, id, fields, payload, reason)
	h.indexLevel(r, levelID)
	q := jobs.Queue{DB: h.db}
	_, _ = q.Enqueue(r.ctx, "level.index", map[string]any{"level_id": levelID}, 0)
	_, _ = q.Enqueue(r.ctx, "webhook.dispatch", map[string]any{"event": "level." + map[string]string{"upload": "uploaded", "update": "updated"}[reason], "request_id": "", "data": map[string]any{"level_id": levelID, "account_id": id, "name": name, "game_version": fields["game_version"]}}, 0)
	return strconv.FormatInt(levelID, 10), nil
}

func (h *Handler) recordRevision(r request, levelID, accountID int64, fields map[string]any, payload, reason string) {
	hash := fmt.Sprintf("%x", sha256.Sum256([]byte(payload)))
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return
	}
	defer tx.Rollback()
	var owner int64
	if err = tx.QueryRowContext(r.ctx, `SELECT account_id FROM levels WHERE level_id=? FOR UPDATE`, levelID).Scan(&owner); err != nil {
		return
	}
	var revision int
	var previous string
	err = tx.QueryRowContext(r.ctx, `SELECT revision_no,payload_sha256 FROM mucho_level_revisions WHERE level_id=? ORDER BY revision_no DESC LIMIT 1`, levelID).Scan(&revision, &previous)
	if err != nil && !errors.Is(err, sql.ErrNoRows) {
		return
	}
	if previous == hash {
		return
	}
	var b bytes.Buffer
	w := gzip.NewWriter(&b)
	_, _ = w.Write([]byte(payload))
	if w.Close() != nil {
		return
	}
	snapshot := map[string]any{}
	for k, v := range fields {
		if k != "account_id" && k != "level_data" && k != "auto_level" {
			snapshot[k] = v
		}
	}
	data, err := json.Marshal(snapshot)
	if err != nil {
		return
	}
	_, err = tx.ExecContext(r.ctx, `INSERT INTO mucho_level_revisions(level_id,revision_no,payload_sha256,payload_gzip,snapshot_json,changed_by_account_id,reason) VALUES(?,?,?,?,?,?,?)`, levelID, revision+1, hash, b.Bytes(), data, accountID, reason)
	if err == nil {
		_ = tx.Commit()
	}
}
func (h *Handler) indexLevel(r request, id int64) error {
	level, err := dbutil.One(r.ctx, h.db, `SELECT l.*,COALESCE(a.username,'') AS username FROM levels l LEFT JOIN accounts a ON a.account_id=l.account_id WHERE l.level_id=? LIMIT 1`, id)
	if err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			_, _ = h.db.ExecContext(r.ctx, `DELETE FROM mucho_level_search_index WHERE level_id=?`, id)
		}
		return err
	}
	normalize := func(s string) string { return strings.ToLower(strings.Join(strings.Fields(s), " ")) }
	name, creator := normalize(level["name"]), normalize(level["username"])
	_, err = h.db.ExecContext(r.ctx, `INSERT INTO mucho_level_search_index(level_id,account_id,username,normalized_name,normalized_creator,search_text,game_version,is_deleted,is_unlisted)
		VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE account_id=VALUES(account_id),username=VALUES(username),normalized_name=VALUES(normalized_name),normalized_creator=VALUES(normalized_creator),search_text=VALUES(search_text),game_version=VALUES(game_version),is_deleted=VALUES(is_deleted),is_unlisted=VALUES(is_unlisted),updated_at=CURRENT_TIMESTAMP`, id, level.Int("account_id"), level["username"], name, creator, strings.TrimSpace(name+" "+creator), level.Int("game_version"), level.Int("is_deleted"), level.Int("is_unlisted"))
	return err
}

func (h *Handler) checkLevelUpdate(r request) (string, error) {
	version := r.int("levelVersion")
	if _, ok := r.form["levelVersion"]; !ok {
		version = 1
	}
	if r.int("levelID") <= 0 || version <= 0 {
		return "-1", nil
	}
	level, err := h.findLevel(r, r.int("levelID"))
	if err != nil {
		return "", err
	}
	if version >= max(1, level.Int("level_version")) {
		return "1", nil
	}
	return "2", nil
}
func (h *Handler) updateLevelDescription(r request) (string, error) {
	me, err := h.auth(r)
	if err != nil {
		return "", err
	}
	id := r.int("levelID")
	level, err := h.findLevel(r, id)
	if err != nil {
		return "", err
	}
	if level.Int("account_id") != me.ID {
		return "-1", nil
	}
	desc, err := r.limited("levelDesc", "", 8192)
	if err != nil {
		return "", err
	}
	game := r.version.Effective()
	if game == 0 {
		game = 22
	}
	if game < 20 {
		decoded := protocol.WireDecode(desc)
		if decoded != desc {
			desc = protocol.DescriptionResponse(decoded, 22)
		} else {
			desc = strings.NewReplacer("+", "-", "/", "_").Replace(desc)
		}
	} else {
		desc = protocol.WireDecode(desc)
	}
	_, err = h.db.ExecContext(r.ctx, `UPDATE levels SET description=?,updated_at=CURRENT_TIMESTAMP WHERE level_id=? AND account_id=? AND is_deleted=0`, desc, id, me.ID)
	if err == nil {
		h.indexLevel(r, id)
	}
	return "1", err
}
func (h *Handler) deleteLevel(r request) (string, error) {
	me, err := h.auth(r)
	if err != nil {
		return "", err
	}
	id := r.int("levelID")
	result, err := h.db.ExecContext(r.ctx, `UPDATE levels SET is_deleted=1,updated_at=CURRENT_TIMESTAMP WHERE level_id=? AND account_id=? AND is_deleted=0 AND stars=0`, id, me.ID)
	status, err := success(result, err)
	if err != nil || status != "1" {
		return status, err
	}
	_, _ = h.db.ExecContext(r.ctx, `DELETE FROM mucho_level_search_index WHERE level_id=?`, id)
	_, _ = (jobs.Queue{DB: h.db}).Enqueue(r.ctx, "webhook.dispatch", map[string]any{"event": "level.deleted", "request_id": "", "data": map[string]any{"level_id": id, "account_id": me.ID}}, 0)
	return "1", nil
}
func (h *Handler) downloadLevel(r request) (string, error) {
	id := r.int("levelID")
	if id == 0 || id < -3 {
		return "-1", nil
	}
	timely := int64(0)
	if id < 0 {
		kind := map[int64]string{-1: "daily", -2: "weekly", -3: "event"}[id]
		row, err := dbutil.One(r.ctx, h.db, `SELECT id,level_id FROM mucho_daily_rotation WHERE kind=? AND enabled=1 AND starts_at<=UTC_TIMESTAMP() AND ends_at>UTC_TIMESTAMP() ORDER BY starts_at DESC,id DESC LIMIT 1`, kind)
		if err != nil {
			return "", err
		}
		timely = row.Int("id")
		if id == -2 {
			timely += 100001
		} else if id == -3 {
			timely += 200001
		}
		id = row.Int("level_id")
	}
	level, err := h.findLevel(r, id)
	if err != nil {
		return "", err
	}
	if level.Int("unlisted2") != 0 {
		me, err := h.auth(r)
		if err != nil {
			return "", err
		}
		if me.ID != level.Int("account_id") {
			var n int
			err = h.db.QueryRowContext(r.ctx, `SELECT COUNT(*) FROM friends WHERE (account_id=? AND friend_account_id=?) OR (account_id=? AND friend_account_id=?)`, me.ID, level.Int("account_id"), level.Int("account_id"), me.ID).Scan(&n)
			if err != nil || n == 0 {
				return "-1", err
			}
		}
	}
	if r.int("inc") == 1 {
		if err = h.trackDownload(r, id); err != nil {
			return "", err
		}
	}
	game := r.version.Effective()
	if game == 0 {
		game = 22
	}
	body, err := protocol.LevelDownload(level, game, r.version.Binary, r.int("extras") == 1)
	if err != nil {
		return "", err
	}
	if timely > 0 {
		parts := strings.Split(body, "#")
		parts[0] += protocol.Pairs("", 41, timely)
		password, _ := strconv.ParseInt(level["copy_password"], 10, 64)
		metadata := fmt.Sprintf("%d%d%d%d%d%d%d%d", level.Int("user_id"), level.Int("stars"), level.Int("demon"), id, level.Int("coins_verified"), level.Int("featured"), password, timely)
		parts[2] = protocol.MetadataHash(metadata)
		creator, err := dbutil.One(r.ctx, h.db, `SELECT a.account_id,a.username,COALESCE(p.user_id,a.account_id) AS user_id FROM accounts a LEFT JOIN profiles p ON p.account_id=a.account_id WHERE a.account_id=? LIMIT 1`, level.Int("account_id"))
		if err == nil {
			parts = append(parts, protocol.Pairs(creator.Int("user_id"), protocol.Username(creator["username"]), creator.Int("account_id")))
		}
		body = strings.Join(parts, "#")
	}
	return body, nil
}
func (h *Handler) trackDownload(r request, id int64) error {
	if _, err := netip.ParseAddr(r.ip); err != nil {
		return nil
	}
	mac := hmac.New(sha256.New, h.downloadKey)
	_, _ = mac.Write([]byte(r.ip))
	hash := fmt.Sprintf("%x", mac.Sum(nil))
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	_, err = tx.ExecContext(r.ctx, `DELETE FROM mucho_level_downloads WHERE level_id=? AND client_hash=? AND created_at<CURRENT_TIMESTAMP-INTERVAL ? SECOND`, id, hash, h.options.DownloadWindow)
	if err != nil {
		return err
	}
	for slot := 1; slot <= 2; slot++ {
		result, err := tx.ExecContext(r.ctx, `INSERT IGNORE INTO mucho_level_downloads(level_id,client_hash,slot) VALUES(?,?,?)`, id, hash, slot)
		if err != nil {
			return err
		}
		n, err := result.RowsAffected()
		if err != nil {
			return err
		}
		if n == 1 {
			result, err = tx.ExecContext(r.ctx, `UPDATE levels SET downloads=downloads+1 WHERE level_id=? AND is_deleted=0`, id)
			if err != nil {
				return err
			}
			if n, err = result.RowsAffected(); err != nil || n != 1 {
				return errors.New("download target disappeared")
			}
			break
		}
	}
	return tx.Commit()
}
