package core

import (
	"fmt"
	"regexp"
	"strconv"
	"strings"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
	"github.com/IZKGMD/GMDmucho-core/go/internal/protocol"
)

var reportReasons = regexp.MustCompile(`^[0-7](?:,[0-7]){0,7}$`)
var caseItems = regexp.MustCompile(`^[0-9]+(?:,[0-9]+){0,49}$`)

func (h *Handler) moderationCase(r request) (string, error) {
	if r.raw.Method != "POST" {
		return "-1", nil
	}
	if r.path == "/reportgjcontent" {
		me, err := h.auth(r)
		if err != nil {
			return "", err
		}
		reasons := r.str("reasons")
		id := r.int("targetID")
		if r.int("type") != 1 || r.int("special") != 0 || id <= 0 || len(reasons) > 40 || !reportReasons.MatchString(reasons) {
			return "-1", nil
		}
		seen := map[string]bool{}
		for _, reason := range strings.Split(reasons, ",") {
			if reason == "4" || reason == "5" || seen[reason] {
				return "-1", nil
			}
			seen[reason] = true
		}
		var target int64
		if err = h.db.QueryRowContext(r.ctx, `SELECT account_id FROM levels WHERE level_id=? AND is_deleted=0 LIMIT 1`, id).Scan(&target); err != nil {
			return "", err
		}
		if target <= 0 || target == me.ID {
			return "-1", nil
		}
		_, err = h.db.ExecContext(r.ctx, `INSERT INTO mucho_content_reports(reporter_account_id,content_type,content_id,target_account_id,reason,details) VALUES(?,0,?,?,?,?) ON DUPLICATE KEY UPDATE id=id`, me.ID, id, target, int(reasons[0]-'0'), reasons)
		return "1", err
	}
	me, role, err := h.moderationRole(r)
	if err != nil {
		return "", err
	}
	if r.path == "/admingetreportqueue" {
		return h.reportQueue(r)
	}
	target := r.int("targetAccountID")
	if target <= 0 {
		return "-1", nil
	}
	page := r.int("page")
	switch r.path {
	case "/admingetreportedcontent", "/admingetuserhistory":
		if page < 0 || page > 1000 {
			return "-1", nil
		}
		offset := page * 20
		table, filter, selectSQL := "mucho_content_reports", ` AND status='pending'`, `id,content_type,content_id,reason,UNIX_TIMESTAMP(created_at) AS t`
		if r.path == "/admingetuserhistory" {
			table, filter, selectSQL = "mucho_gd2209_case_actions", "", `id,actor_account_id,action,UNIX_TIMESTAMP(created_at) AS t`
		}
		var total int64
		if err = h.db.QueryRowContext(r.ctx, `SELECT COUNT(*) FROM `+table+` WHERE target_account_id=?`+filter, target).Scan(&total); err != nil {
			return "", err
		}
		rows, err := dbutil.Query(r.ctx, h.db, `SELECT `+selectSQL+` FROM `+table+` WHERE target_account_id=?`+filter+` ORDER BY id DESC LIMIT 20 OFFSET ?`, target, offset)
		if err != nil {
			return "", err
		}
		records := []string{}
		for _, row := range rows {
			if r.path == "/admingetuserhistory" {
				records = append(records, fmt.Sprintf("%d:%d:%s:%d", row.Int("id"), row.Int("actor_account_id"), row["action"], row.Int("t")))
			} else {
				records = append(records, fmt.Sprintf("%d:%d:%d:%d:%d", row.Int("id"), row.Int("content_type"), row.Int("content_id"), row.Int("reason"), row.Int("t")))
			}
		}
		return fmt.Sprintf("%d:%d:20#%s", total, offset, strings.Join(records, "|")), nil
	case "/admingetabuseinfo":
		row, err := dbutil.One(r.ctx, h.db, `SELECT COUNT(*) AS reports,COUNT(DISTINCT reporter_account_id) AS reporters,COUNT(DISTINCT content_type,content_id) AS items FROM mucho_content_reports WHERE target_account_id=? AND status='pending'`, target)
		if err != nil {
			return "", err
		}
		return fmt.Sprintf("1:%d:2:%d:3:%d:4:%d:5:%d", target, row.Int("reports"), row.Int("reports")*3, row.Int("reporters"), row.Int("items")), nil
	case "/admingetaccountreview":
		row, err := dbutil.One(r.ctx, h.db, `SELECT account_id,username,is_active,is_banned FROM accounts WHERE account_id=? LIMIT 1`, target)
		if err != nil {
			return "", err
		}
		return fmt.Sprintf("1:%d:2:%s:3:%d:4:%d", row.Int("account_id"), protocol.Username(row["username"]), row.Int("is_active"), row.Int("is_banned")), nil
	case "/adminclearreportedcontent", "/adminclosecase":
		if err = targetAllowed(r, h.db, me.ID, role, target); err != nil {
			return "", err
		}
		filter, action, detail := "", "CLOSE_CASE", r.int("outcome")
		args := []any{target}
		if r.path == "/adminclearreportedcontent" {
			raw := r.str("items")
			if !caseItems.MatchString(raw) {
				return "-1", nil
			}
			ids := v71IDs(raw, 50)
			for _, part := range strings.Split(raw, ",") {
				n, _ := strconv.ParseInt(part, 10, 64)
				if n == 0 {
					return "-1", nil
				}
			}
			if len(ids) == 0 {
				return "-1", nil
			}
			filter = ` AND id IN (` + strings.TrimRight(strings.Repeat("?,", len(ids)), ",") + `)`
			for _, id := range ids {
				args = append(args, id)
			}
			action, detail = "CLEAR_REPORTS", int64(len(ids))
		} else if detail < 0 || detail > 7 {
			return "-1", nil
		}
		tx, err := h.db.BeginTx(r.ctx, nil)
		if err != nil {
			return "", err
		}
		defer tx.Rollback()
		result, err := tx.ExecContext(r.ctx, `UPDATE mucho_content_reports SET status='closed' WHERE target_account_id=? AND status='pending'`+filter, args...)
		if err != nil {
			return "", err
		}
		n, err := result.RowsAffected()
		if err != nil {
			return "", err
		}
		if n < 1 || (action == "CLEAR_REPORTS" && n != detail) {
			return "-1", nil
		}
		if err = caseAudit(r, tx, me.ID, target, action, detail); err != nil {
			return "", err
		}
		return "1", tx.Commit()
	}
	return "-1", nil
}

func (h *Handler) reportQueue(r request) (string, error) {
	page, sort, mode, flags := r.int("page"), r.int("sort"), r.int("mode"), r.int("flags")
	if page < 0 || page > 1000 || sort < 0 || sort > 100 || mode < 0 || mode > 100 || flags < 0 || flags > 65535 {
		return "-1", nil
	}
	const filter = `r.status='pending' AND r.target_account_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM mucho_gd2209_restrictions q WHERE q.account_id=r.target_account_id AND q.kind='queue' AND (q.expires_at IS NULL OR q.expires_at>UTC_TIMESTAMP()))`
	var total int64
	if err := h.db.QueryRowContext(r.ctx, `SELECT COUNT(DISTINCT target_account_id) FROM mucho_content_reports r WHERE `+filter).Scan(&total); err != nil {
		return "", err
	}
	order := "last_report_id DESC"
	if sort == 1 {
		order = "last_report_id ASC"
	}
	if sort == 2 {
		order = "report_count DESC,last_report_id DESC"
	}
	rows, err := dbutil.Query(r.ctx, h.db, `SELECT r.target_account_id,a.username,MAX(r.id) AS last_report_id,COUNT(*) AS report_count,COUNT(DISTINCT r.content_type,r.content_id) AS reported_items,COUNT(DISTINCT r.reporter_account_id) AS reporter_count,COUNT(*)*3 AS report_points FROM mucho_content_reports r JOIN accounts a ON a.account_id=r.target_account_id WHERE `+filter+` GROUP BY r.target_account_id,a.username ORDER BY `+order+` LIMIT 20 OFFSET ?`, page*20)
	if err != nil {
		return "", err
	}
	items := []string{}
	for _, row := range rows {
		items = append(items, fmt.Sprintf("%d:%s:%d:0:0:-1:0::0:0:::%d:0:0:0:0:0:0:%d", row.Int("target_account_id"), protocol.Username(row["username"]), row.Int("reported_items"), row.Int("report_points"), row.Int("reporter_count")))
	}
	return fmt.Sprintf("%d:%d:20#%s", total, page*20, strings.Join(items, "|")), nil
}
