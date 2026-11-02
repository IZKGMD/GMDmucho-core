package core

import (
	"regexp"
	"strings"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
)

var commentIDsPattern = regexp.MustCompile(`^[1-9][0-9]*(?:,[1-9][0-9]*){0,49}$`)

func (h *Handler) moderateContent(r request) (string, error) {
	if r.raw.Method != "POST" {
		return "-1", nil
	}
	me, err := h.auth(r)
	if err != nil {
		return "", err
	}
	own := r.path == "/deletegjcomments"
	target := me.ID
	role := ""
	if !own {
		me, role, err = h.moderationRole(r)
		if err != nil {
			return "", err
		}
		target = r.int("targetAccountID")
		if r.path != "/adminthreadaction" {
			if err = targetAllowed(r, h.db, me.ID, role, target); err != nil {
				return "", err
			}
		}
	}
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return "", err
	}
	defer tx.Rollback()
	if r.path == "/adminthreadaction" {
		if r.int("threadID") <= 0 || r.int("messageID") <= 0 || r.str("action") != "delete" {
			return "-1", nil
		}
		if err = tx.QueryRowContext(r.ctx, `SELECT sender_account_id FROM mucho_thread_messages WHERE id=? AND thread_id=? FOR UPDATE`, r.int("messageID"), r.int("threadID")).Scan(&target); err != nil {
			return "", err
		}
		if err = targetAllowed(r, tx, me.ID, role, target); err != nil {
			return "", err
		}
		result, err := tx.ExecContext(r.ctx, `DELETE FROM mucho_thread_messages WHERE id=? AND thread_id=?`, r.int("messageID"), r.int("threadID"))
		if err != nil {
			return "", err
		}
		n, err := result.RowsAffected()
		if err != nil || n != 1 {
			return "-1", err
		}
		if err = caseAudit(r, tx, me.ID, target, "DELETE_THREAD_MSG", 0); err != nil {
			return "", err
		}
		return "1", tx.Commit()
	}
	args := []any{target}
	filter := ""
	wanted := 0
	if own {
		raw := r.str("commentIDs")
		if raw == "" || raw == "0" {
			raw = r.str("items")
		}
		if !commentIDsPattern.MatchString(raw) {
			return "-1", nil
		}
		ids := v71IDs(raw, 50)
		wanted = len(ids)
		if wanted == 0 {
			return "-1", nil
		}
		filter = ` AND id IN (` + strings.TrimRight(strings.Repeat("?,", wanted), ",") + `)`
		for _, id := range ids {
			args = append(args, id)
		}
	}
	rows, err := dbutil.Query(r.ctx, tx, `SELECT id FROM comments WHERE account_id=?`+filter+` ORDER BY id ASC LIMIT 5001 FOR UPDATE`, args...)
	if err != nil {
		return "", err
	}
	if len(rows) == 0 || len(rows) > 5000 || (own && len(rows) != wanted) {
		return "-1", nil
	}
	for start := 0; start < len(rows); start += 500 {
		chunk := rows[start:min(len(rows), start+500)]
		ids := make([]any, len(chunk))
		for i, row := range chunk {
			ids[i] = row.Int("id")
		}
		marks := strings.TrimRight(strings.Repeat("?,", len(ids)), ",")
		if _, err = tx.ExecContext(r.ctx, `DELETE FROM likes WHERE type=2 AND item_id IN (`+marks+`)`, ids...); err != nil {
			return "", err
		}
		result, err := tx.ExecContext(r.ctx, `DELETE FROM comments WHERE account_id=? AND id IN (`+marks+`)`, append([]any{target}, ids...)...)
		if err != nil {
			return "", err
		}
		n, err := result.RowsAffected()
		if err != nil || n != int64(len(chunk)) {
			return "-1", err
		}
	}
	if !own {
		if err = caseAudit(r, tx, me.ID, target, "DELETE_COMMENTS", int64(len(rows))); err != nil {
			return "", err
		}
	}
	return "1", tx.Commit()
}
