package core

import (
	"database/sql"
	"errors"
	"fmt"
	"net/netip"
	"strconv"
	"strings"
	"unicode/utf8"

	"github.com/IZKGMD/GMDmucho-core/go/internal/account"
	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
	"github.com/IZKGMD/GMDmucho-core/go/internal/protocol"
)

func (h *Handler) commenter(r request) (int64, error) {
	if r.credential() != "" {
		me, err := h.auth(r)
		return me.ID, err
	}
	game := r.version.Effective()
	if game > 0 && game < 19 {
		return h.accounts.LegacyIdentity(r.ctx, r.str("udid"), "", r.ip)
	}
	if game == 19 && r.int("accountID") > 0 {
		me, err := h.accounts.AuthenticateLegacyUpload(r.ctx, r.int("accountID"), r.str("udid"), r.ip)
		return me.ID, err
	}
	return 0, account.ErrUnauthorized
}
func normalizeComment(value string, game int) (string, error) {
	if game >= 20 {
		value = protocol.WireDecode(value)
	}
	value = strings.NewReplacer("\\0", "", "\x00", "").Replace(value)
	if game < 20 {
		value = strings.NewReplacer("~", "", "|", "", "#", "", ":", "").Replace(value)
	}
	var clean strings.Builder
	for i := 0; i < len(value); i++ {
		c := value[i]
		if c <= 8 || c == 11 || c == 12 || (c >= 14 && c <= 31) || c == 127 {
			continue
		}
		clean.WriteByte(c)
	}
	value = strings.Trim(clean.String(), " \t\r\n\x00\x0b")
	if !utf8.ValidString(value) || utf8.RuneCountInString(value) > 4096 {
		return "", errors.New("invalid comment text")
	}
	return value, nil
}
func (h *Handler) canReadLevel(r request, id int64) (bool, error) {
	level, err := h.findLevel(r, id)
	if errors.Is(err, sql.ErrNoRows) {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	if level.Int("unlisted2") == 0 {
		return true, nil
	}
	me, err := h.auth(r)
	if err != nil {
		return false, nil
	}
	if me.ID == level.Int("account_id") {
		return true, nil
	}
	var n int
	err = h.db.QueryRowContext(r.ctx, `SELECT COUNT(*) FROM friends WHERE (account_id=? AND friend_account_id=?) OR (account_id=? AND friend_account_id=?)`, me.ID, level.Int("account_id"), level.Int("account_id"), me.ID).Scan(&n)
	return n > 0, err
}
func (h *Handler) uploadComment(r request) (string, error) {
	id, err := h.commenter(r)
	if err != nil {
		return "", err
	}
	game := r.version.Effective()
	if banned, err := h.restricted(r, id, "comment"); err != nil || banned {
		return "-1", err
	}
	content, err := normalizeComment(r.str("comment"), game)
	if err != nil || content == "" {
		return "-1", err
	}
	accountComment := r.path == "/uploadgjacccomment20"
	if accountComment {
		if r.int("accountID") <= 0 {
			return "-1", nil
		}
		_, err = h.db.ExecContext(r.ctx, `INSERT INTO account_comments(account_id,content,created_at) VALUES(?,?,NOW())`, id, content)
		return "1", err
	}
	if yes, err := h.canReadLevel(r, r.int("levelID")); err != nil || !yes {
		return "-1", err
	}
	if strings.HasPrefix(content, "!") {
		return h.commentCommand(r, id, content)
	}
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return "", err
	}
	defer tx.Rollback()
	percent := max(0, min(100, r.int("percent")))
	result, err := tx.ExecContext(r.ctx, `INSERT INTO comments(level_id,account_id,content,percent,created_at) VALUES(?,?,?,?,NOW())`, r.int("levelID"), id, content, percent)
	if err != nil {
		return "", err
	}
	commentID, err := result.LastInsertId()
	if err != nil {
		return "", err
	}
	if percent > 0 {
		now := h.now().Unix()
		_, err = tx.ExecContext(r.ctx, `INSERT INTO mucho_level_scores(account_id,level_id,is_daily,daily_id,percent,coins,attempts,clicks,play_time,progresses,created_at,updated_at) VALUES(?,?,0,0,?,0,0,0,0,'',?,?) ON DUPLICATE KEY UPDATE percent=GREATEST(percent,VALUES(percent)),updated_at=CASE WHEN VALUES(percent)>percent THEN VALUES(updated_at) ELSE updated_at END`, id, r.int("levelID"), percent, now, now)
		if err != nil {
			return "", err
		}
	}
	if err = tx.Commit(); err != nil {
		return "", err
	}
	if game >= 21 {
		return strconv.FormatInt(commentID, 10), nil
	}
	return "1", nil
}
func (h *Handler) comments(r request) (string, error) {
	accountComment := r.path == "/getgjaccountcomments20"
	userHistory := !accountComment && r.int("levelID") <= 0 && r.int("userID") > 0
	limit := 10
	if !accountComment {
		if _, ok := r.form["count"]; ok {
			limit = int(max(1, min(100, r.int("count"))))
		}
	}
	offset := r.page() * limit
	order := "c.id DESC"
	if accountComment {
		order = "c.created_at DESC"
	} else if r.int("mode") != 0 {
		order = "c.likes DESC,c.id DESC"
	}
	from := ` FROM comments c JOIN accounts a ON a.account_id=c.account_id LEFT JOIN profiles p ON p.account_id=a.account_id`
	filter := "c.level_id=?"
	id := r.int("levelID")
	if accountComment {
		from = ` FROM account_comments c JOIN accounts a ON a.account_id=c.account_id LEFT JOIN profiles p ON p.account_id=a.account_id`
		filter = "c.account_id=?"
		id = r.int("accountID")
	} else if userHistory {
		from += ` JOIN levels l ON l.level_id=c.level_id`
		filter = "COALESCE(p.user_id,a.account_id)=? AND l.is_unlisted=0 AND l.unlisted2=0 AND l.is_deleted=0"
		id = r.int("userID")
	} else {
		yes, err := h.canReadLevel(r, id)
		if err != nil {
			return "", err
		}
		if !yes {
			return "-2", nil
		}
	}
	var total int
	err := h.db.QueryRowContext(r.ctx, "SELECT COUNT(*)"+from+" WHERE "+filter, id).Scan(&total)
	if err != nil {
		return "", err
	}
	selectSQL := `SELECT c.*,a.username,a.role,COALESCE(p.user_id,a.account_id) AS user_id,COALESCE(p.cube,1) AS cube,COALESCE(p.color1,0) AS color1,COALESCE(p.color2,3) AS color2,COALESCE(p.special,0) AS special,COALESCE(p.icon_type,0) AS icon_type,` + clanTagSQL
	rows, err := dbutil.Query(r.ctx, h.db, selectSQL+from+" WHERE "+filter+" ORDER BY "+order+" LIMIT ? OFFSET ?", id, limit, offset)
	if err != nil {
		return "", err
	}
	if total == 0 || len(rows) == 0 {
		if accountComment {
			return "#0:0:0", nil
		}
		return "-2", nil
	}
	items, users := make([]string, 0, len(rows)), []string{}
	seen := map[string]bool{}
	for _, row := range rows {
		encoded := protocol.Comment(row, r.version.Effective(), r.version.Binary, accountComment)
		if userHistory {
			encoded = "1~" + row["level_id"] + "~" + encoded
		}
		items = append(items, encoded)
		key := row["user_id"] + ":" + row["account_id"]
		if !seen[key] && (!userHistory || row.Int("user_id") > 0) {
			seen[key] = true
			users = append(users, protocol.Pairs(row["user_id"], protocol.DisplayName(row["username"], row["clan_tag"]), row["account_id"]))
		}
	}
	body := strings.Join(items, "|")
	if !accountComment && r.version.Binary < 32 {
		body += "#" + strings.Join(users, "|")
	}
	return fmt.Sprintf("%s#%d:%d:%d", body, total, offset, limit), nil
}
func (h *Handler) deleteComment(r request) (string, error) {
	id, err := h.commenter(r)
	if err != nil {
		return "", err
	}
	table := "comments"
	typ := 2
	if r.path == "/deletegjacccomment20" {
		table = "account_comments"
		typ = 3
	}
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return "", err
	}
	defer tx.Rollback()
	var n int64
	err = tx.QueryRowContext(r.ctx, `SELECT id FROM `+table+` WHERE id=? AND account_id=? FOR UPDATE`, r.int("commentID"), id).Scan(&n)
	if err != nil {
		return "", err
	}
	_, err = tx.ExecContext(r.ctx, `DELETE FROM likes WHERE item_id=? AND type=?`, n, typ)
	if err != nil {
		return "", err
	}
	_, err = tx.ExecContext(r.ctx, `DELETE FROM `+table+` WHERE id=? AND account_id=?`, n, id)
	if err != nil {
		return "", err
	}
	return "1", tx.Commit()
}
func (h *Handler) like(r request) (string, error) {
	itemID := r.int("itemID")
	if itemID <= 0 {
		itemID = r.int("levelID")
	}
	if itemID <= 0 {
		return "-1", nil
	}
	typ := r.int("type")
	if _, ok := r.form["type"]; !ok {
		typ = 1
	}
	if r.int("levelID") > 0 {
		typ = 1
	}
	target, ok := map[int64][2]string{1: {"levels", "level_id"}, 2: {"comments", "id"}, 3: {"account_comments", "id"}, 4: {"mucho_level_lists", "list_id"}}[typ]
	if !ok {
		return "-1", nil
	}
	id := int64(0)
	ip := r.ip
	isLike := int64(1)
	if r.int("accountID") > 0 && r.credential() != "" {
		me, err := h.auth(r)
		if err != nil {
			return "", err
		}
		id = me.ID
		ip = ""
		if _, ok := r.form["like"]; ok && r.int("like") != 1 {
			isLike = 0
		}
	} else {
		if _, err := netip.ParseAddr(ip); err != nil {
			return "1", nil
		}
	}
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return "", err
	}
	defer tx.Rollback()
	var targetID int64
	err = tx.QueryRowContext(r.ctx, `SELECT `+target[1]+` FROM `+target[0]+` WHERE `+target[1]+`=? FOR UPDATE`, itemID).Scan(&targetID)
	if err != nil {
		return "", err
	}
	where := `item_id=? AND type=? AND account_id=?`
	args := []any{itemID, typ, id}
	if id == 0 {
		where += " AND ip=?"
		args = append(args, ip)
	}
	existing, err := dbutil.One(r.ctx, tx, `SELECT id,is_like FROM likes WHERE `+where+` LIMIT 1 FOR UPDATE`, args...)
	if err != nil && !errors.Is(err, sql.ErrNoRows) {
		return "", err
	}
	delta := isLike
	if existing != nil {
		if existing.Int("is_like") == isLike {
			return "1", nil
		}
		_, err = tx.ExecContext(r.ctx, `UPDATE likes SET is_like=? WHERE id=?`, isLike, existing.Int("id"))
		if isLike == 0 {
			delta = -1
		}
	} else {
		_, err = tx.ExecContext(r.ctx, `INSERT INTO likes(item_id,type,account_id,ip,is_like) VALUES(?,?,?,?,?)`, itemID, typ, id, ip, isLike)
	}
	if err != nil {
		return "", err
	}
	result, err := tx.ExecContext(r.ctx, `UPDATE `+target[0]+` SET likes=GREATEST(0,likes+?) WHERE `+target[1]+`=?`, delta, itemID)
	if err != nil {
		return "", err
	}
	n, err := result.RowsAffected()
	if err != nil || n != 1 {
		return "-1", err
	}
	return "1", tx.Commit()
}
