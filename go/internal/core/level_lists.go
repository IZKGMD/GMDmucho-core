package core

import (
	"crypto/sha256"
	"crypto/subtle"
	"encoding/hex"
	"fmt"
	"regexp"
	"strconv"
	"strings"

	"github.com/IZKGMD/GMDmucho-core/go/internal/account"
	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
)

var v71Integer = regexp.MustCompile(`^-?[0-9]+$`)

func (r request) v71Int(key string, fallback int64) int64 {
	value := r.str(key)
	if !v71Integer.MatchString(value) {
		return fallback
	}
	n, _ := strconv.ParseInt(value, 10, 64)
	return n
}
func (r request) v71String(key string) string { return strings.Trim(r.str(key), " \t\r\n\x00\x0b") }
func v71IDs(raw string, limit int) []int64 {
	ids := []int64{}
	seen := map[int64]bool{}
	for _, part := range strings.Split(raw, ",") {
		part = strings.Trim(part, " \t\r\n\x00\x0b")
		if !digits.MatchString(part) {
			continue
		}
		id, _ := strconv.ParseInt(part, 10, 64)
		if id > 0 && !seen[id] {
			seen[id] = true
			ids = append(ids, id)
			if len(ids) >= limit {
				break
			}
		}
	}
	return ids
}
func v71Text(raw string, limit int) string {
	value := strings.NewReplacer("\x00", "", "\r", "", "#", "", "|", "").Replace(raw)
	runes := []rune(value)
	if len(runes) > limit {
		return string(runes[:limit])
	}
	return value
}
func protocolField(raw string) string {
	return strings.NewReplacer(":", "", "#", "", "|", "").Replace(raw)
}
func (h *Handler) v71Auth(r request) (int64, error) {
	id := r.v71Int("accountID", 0)
	credential := r.v71String("gjp2")
	if id <= 0 || credential == "" {
		return 0, account.ErrUnauthorized
	}
	row, err := dbutil.One(r.ctx, h.db, `SELECT gjp2_hash,is_active,is_banned FROM accounts WHERE account_id=? LIMIT 1`, id)
	if err != nil {
		return 0, err
	}
	if row.Int("is_active") != 1 || row.Int("is_banned") == 1 || row["gjp2_hash"] == "" {
		return 0, account.ErrUnauthorized
	}
	valid := account.Verify(credential, row["gjp2_hash"])
	if !strings.HasPrefix(row["gjp2_hash"], "$") {
		hash := sha256.Sum256([]byte(credential))
		valid = subtle.ConstantTimeCompare([]byte(row["gjp2_hash"]), []byte(hex.EncodeToString(hash[:]))) == 1
	}
	if !valid {
		return 0, account.ErrUnauthorized
	}
	return id, nil
}
func (h *Handler) uploadLevelList(r request) (string, error) {
	if r.v71String("secret") != "Wmfd2893gb7" {
		return "-100", nil
	}
	me, err := h.v71Auth(r)
	if err != nil {
		return "", err
	}
	ids := v71IDs(r.v71String("listLevels"), 1000)
	if len(ids) == 0 {
		return "-6", nil
	}
	args := make([]any, len(ids))
	parts := make([]string, len(ids))
	for i, id := range ids {
		args[i] = id
		parts[i] = strconv.FormatInt(id, 10)
	}
	var count int
	if err = h.db.QueryRowContext(r.ctx, `SELECT COUNT(DISTINCT level_id) FROM levels WHERE level_id IN (`+strings.TrimRight(strings.Repeat("?,", len(ids)), ",")+`) AND COALESCE(is_deleted,0)=0`, args...).Scan(&count); err != nil {
		return "", err
	}
	if count != len(ids) {
		return "-6", nil
	}
	name := v71Text(r.v71String("listName"), 64)
	if name == "" {
		name = "Unnamed list"
	}
	desc := v71Text(r.v71String("listDesc"), 500)
	version := max(1, r.v71Int("listVersion", 1))
	unlisted := int64(0)
	if r.v71Int("unlisted", 0) == 1 {
		unlisted = 1
	}
	now := h.now().Unix()
	id := max(0, r.v71Int("listID", 0))
	values := []any{name, desc, version, strings.Join(parts, ","), r.v71Int("difficulty", 0), max(0, r.v71Int("original", 0)), unlisted, now}
	if id > 0 {
		result, err := h.db.ExecContext(r.ctx, `UPDATE mucho_level_lists SET list_name=?,list_desc=?,list_version=?,level_ids=?,difficulty=?,original_id=?,unlisted=?,updated_at=? WHERE list_id=? AND account_id=?`, append(values, id, me)...)
		if err != nil {
			return "", err
		}
		n, err := result.RowsAffected()
		if err != nil {
			return "", err
		}
		if n == 0 {
			if err = h.db.QueryRowContext(r.ctx, `SELECT COUNT(*) FROM mucho_level_lists WHERE list_id=? AND account_id=?`, id, me).Scan(&n); err != nil {
				return "", err
			}
		}
		if n == 0 {
			return "-1", nil
		}
		return strconv.FormatInt(id, 10), nil
	}
	result, err := h.db.ExecContext(r.ctx, `INSERT INTO mucho_level_lists(list_name,list_desc,list_version,level_ids,difficulty,original_id,unlisted,updated_at,created_at,account_id,downloads,likes,featured,stars,count_for_reward) VALUES(?,?,?,?,?,?,?,?,?,?,0,0,0,0,0)`, append(values, now, me)...)
	if err != nil {
		return "", err
	}
	id, err = result.LastInsertId()
	return strconv.FormatInt(id, 10), err
}
func (h *Handler) deleteLevelList(r request) (string, error) {
	if r.v71String("secret") != "Wmfd2893gb7" {
		return "-1", nil
	}
	me, err := h.v71Auth(r)
	if err != nil {
		return "", err
	}
	return success(h.db.ExecContext(r.ctx, `DELETE FROM mucho_level_lists WHERE list_id=? AND account_id=?`, r.v71Int("listID", 0), me))
}

func (h *Handler) getLevelLists(r request) (string, error) {
	typ, offset := r.v71Int("type", 0), max(0, min(1000, r.v71Int("page", 0)))*10
	search := v71Text(r.v71String("str"), 128)
	where := []string{"l.unlisted=0"}
	args := []any{}
	order := "l.created_at DESC,l.list_id DESC"
	join := ""
	direct := false
	add := func(query string, values ...any) { where = append(where, query); args = append(args, values...) }
	in := func(column string, ids []int64) {
		marks := make([]string, len(ids))
		for i, id := range ids {
			marks[i] = "?"
			args = append(args, id)
		}
		where = append(where, column+" IN ("+strings.Join(marks, ",")+")")
	}
	switch typ {
	case 0:
		order = "l.likes DESC,l.list_id DESC"
		if search != "" {
			if digits.MatchString(search) {
				direct = true
				where = []string{"l.list_id=?"}
				id, _ := strconv.ParseInt(search, 10, 64)
				args = append(args, id)
			} else {
				add("l.list_name LIKE ?", "%"+search+"%")
			}
		}
	case 1:
		order = "l.downloads DESC,l.list_id DESC"
	case 2:
		order = "l.likes DESC,l.list_id DESC"
	case 3:
		add("l.created_at>=?", h.now().Unix()-604800)
		order = "l.downloads DESC,l.list_id DESC"
	case 5:
		if !digits.MatchString(search) {
			return "-1", nil
		}
		id, _ := strconv.ParseInt(search, 10, 64)
		if id <= 0 {
			return "-1", nil
		}
		add("l.account_id=?", id)
	case 6:
		add("l.stars>0")
		add("l.featured>0")
		order = "l.downloads DESC,l.list_id DESC"
	case 11:
		add("l.stars>0")
		order = "l.downloads DESC,l.list_id DESC"
	case 12:
		ids := v71IDs(r.v71String("followed"), 500)
		if len(ids) == 0 {
			return "-1", nil
		}
		in("l.account_id", ids)
	case 13:
		me, err := h.v71Auth(r)
		if err != nil {
			return "", err
		}
		rows, err := dbutil.Query(r.ctx, h.db, `SELECT friend_account_id AS id FROM friends WHERE account_id=? UNION SELECT account_id AS id FROM friends WHERE friend_account_id=?`, me, me)
		if err != nil {
			return "", err
		}
		ids := []int64{}
		for _, row := range rows {
			if row.Int("id") > 0 && row.Int("id") != me {
				ids = append(ids, row.Int("id"))
			}
		}
		if len(ids) == 0 {
			return "-1", nil
		}
		in("l.account_id", ids)
	case 7, 27:
		join = ` LEFT JOIN(SELECT list_id,MAX(created_at) AS suggested_at FROM mucho_level_list_suggestions GROUP BY list_id) ls ON ls.list_id=l.list_id`
		add("ls.list_id IS NOT NULL")
		order = "ls.suggested_at DESC,l.list_id DESC"
	}
	if !direct {
		if diff := v71IDs(r.v71String("diff"), 20); len(diff) > 0 {
			in("l.difficulty", diff)
		}
		if r.v71Int("star", 0) == 1 {
			add("l.stars>0")
		}
		if r.v71Int("featured", 0) == 1 {
			add("l.featured>0")
		}
	}
	whereSQL := " WHERE " + strings.Join(where, " AND ")
	var total int64
	if err := h.db.QueryRowContext(r.ctx, `SELECT COUNT(*) FROM mucho_level_lists l `+join+whereSQL, args...).Scan(&total); err != nil {
		return "", err
	}
	rows, err := dbutil.Query(r.ctx, h.db, `SELECT l.*,COALESCE(a.username,'Unknown') AS user_name,COALESCE(p.user_id,l.account_id) AS user_id,l.account_id AS ext_id FROM mucho_level_lists l LEFT JOIN profiles p ON p.account_id=l.account_id LEFT JOIN accounts a ON a.account_id=l.account_id `+join+whereSQL+` ORDER BY `+order+` LIMIT 10 OFFSET ?`, append(args, offset)...)
	if err != nil {
		return "", err
	}
	if len(rows) == 0 {
		return "-1", nil
	}
	if direct && len(rows) == 1 {
		if _, err = h.db.ExecContext(r.ctx, `UPDATE mucho_level_lists SET downloads=downloads+1 WHERE list_id=?`, rows[0].Int("list_id")); err != nil {
			return "", err
		}
		rows[0]["downloads"] = strconv.FormatInt(rows[0].Int("downloads")+1, 10)
	}
	lists, users := []string{}, []string{}
	seen := map[string]bool{}
	for _, row := range rows {
		fields := []string{}
		for _, pair := range [][2]string{{"1", "list_id"}, {"2", "list_name"}, {"3", "list_desc"}, {"5", "list_version"}, {"49", "account_id"}, {"50", "user_name"}, {"10", "downloads"}, {"7", "difficulty"}, {"14", "likes"}, {"19", "featured"}, {"51", "level_ids"}, {"55", "stars"}, {"56", "count_for_reward"}, {"28", "created_at"}, {"29", "updated_at"}} {
			fields = append(fields, pair[0], protocolField(row[pair[1]]))
		}
		lists = append(lists, strings.Join(fields, ":"))
		key := row["user_id"] + ":" + row["ext_id"]
		if !seen[key] {
			seen[key] = true
			users = append(users, row["user_id"]+":"+protocolField(row["user_name"])+":"+row["ext_id"])
		}
	}
	return fmt.Sprintf("%s#%s#%d:%d:10#Sa1ntSosetHuiHelloFromGreenCatsServerLOL", strings.Join(lists, "|"), strings.Join(users, "|"), total, offset), nil
}
