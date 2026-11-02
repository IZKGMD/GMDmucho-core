package core

import (
	"database/sql"
	"fmt"
	"strconv"
	"strings"
	"unicode/utf8"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
)

func (h *Handler) updateThread(r request, actor int64) (string, error) {
	id := r.threadID()
	mode := int64(-100)
	value := ""
	if _, exists := r.form["mode"]; exists {
		mode = r.defaultInt("mode", -1)
		switch mode {
		case 2, 3, 4, 6, 7, 10, 11, 12, 13, 14, 15, 16:
			var err error
			value, err = decodeThread(r.str("value"))
			if err != nil {
				return "", err
			}
		}
		if mode == 2 {
			mode = -2
		}
		if mode == 5 {
			mode = -5
		}
		if mode == 8 || mode == 9 {
			return h.respondThreadInvite(r, actor, id, 0, mode == 8)
		}
	} else {
		action := strings.ToLower(strings.Trim(r.str("action"), " \t\r\n\x00\x0b"))
		value = r.str("title")
		if value == "" || value == "0" {
			value = r.str("name")
		}
		if action == "create" || (id == 0 && action == "" && value != "") {
			return h.createThread(r, actor, value)
		}
		switch action {
		case "invite":
			mode = -4
			value = fmt.Sprint(r.defaultInt("targetAccountID", r.int("toAccountID")))
		case "accept", "decline":
			return h.respondThreadInvite(r, actor, 0, r.defaultInt("inviteID", r.int("invitationID")), action == "accept")
		case "rename":
			mode = -2
		case "close":
			mode = -3
		case "leave":
			mode = -5
		}
	}
	if mode == 3 {
		return h.markThreadsRead(r, actor, id, value)
	}
	if id <= 0 {
		return "-1", nil
	}
	if mode == 18 {
		return success(h.db.ExecContext(r.ctx, `DELETE FROM mucho_thread_former_members WHERE thread_id=? AND account_id=? AND reason='kicked'`, id, actor))
	}
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return "", err
	}
	defer tx.Rollback()
	state, err := threadFor(r, tx, actor, id, true)
	if err != nil {
		return "", err
	}
	owner, group := state.Int("owner_account_id") == actor, state.Int("is_dm") == 0
	if mode >= 0 && (mode != 12 && state.Int("is_closed") == 1) {
		return "-1", nil
	}
	if (mode == 6 || mode == 7 || mode == 11 || mode == 13 || mode == 14 || mode == 16) && (!owner || !group) {
		return "-1", nil
	}
	if mode == 4 && (!group || (state.Int("invite_owner_only") == 1 && !owner)) {
		return "-1", nil
	}
	switch mode {
	case -2:
		value = strings.Trim(value, " \t\r\n\x00\x0b")
		if !owner || state.Int("is_closed") == 1 || value == "" || !utf8.ValidString(value) || utf8.RuneCountInString(value) > 120 || threadControls.MatchString(value) {
			return "-1", nil
		}
		_, err = tx.ExecContext(r.ctx, `UPDATE mucho_threads SET title=? WHERE id=? AND owner_account_id=? AND is_closed=0`, value, id, actor)
	case -3:
		if !owner {
			return "-1", nil
		}
		_, err = tx.ExecContext(r.ctx, `UPDATE mucho_threads SET is_closed=1 WHERE id=? AND owner_account_id=?`, id, actor)
	case -5:
		if owner {
			return "-1", nil
		}
		if err = rememberFormer(r, tx, id, actor, "left"); err != nil {
			return "", err
		}
		_, err = tx.ExecContext(r.ctx, `DELETE FROM mucho_thread_members WHERE thread_id=? AND account_id=?`, id, actor)
	case 1:
		_, err = tx.ExecContext(r.ctx, `UPDATE mucho_thread_members SET last_cleared_message_id=GREATEST(last_cleared_message_id,(SELECT COALESCE(MAX(id),0) FROM mucho_thread_messages WHERE thread_id=?)),last_read_message_id=GREATEST(last_read_message_id,(SELECT COALESCE(MAX(id),0) FROM mucho_thread_messages WHERE thread_id=?)) WHERE thread_id=? AND account_id=?`, id, id, id, actor)
	case 10, 15:
		if value != "0" && value != "1" {
			return "-1", nil
		}
		field := "is_muted"
		if mode == 15 {
			field = "is_pinned"
		}
		_, err = tx.ExecContext(r.ctx, `UPDATE mucho_thread_members SET `+field+`=? WHERE account_id=? AND thread_id=?`, value, actor, id)
	case 13:
		if value != "0" && value != "1" {
			return "-1", nil
		}
		_, err = tx.ExecContext(r.ctx, `UPDATE mucho_threads SET invite_owner_only=? WHERE id=?`, value, id)
	case 14, 16:
		limit, field := 1024, "description"
		if mode == 16 {
			limit, field = 60, "emote"
		}
		if len(value) > limit || !utf8.ValidString(value) || threadControls.MatchString(value) {
			return "-1", nil
		}
		_, err = tx.ExecContext(r.ctx, `UPDATE mucho_threads SET `+field+`=? WHERE id=?`, value, id)
	case 6, 7, 11, 12:
		var target int64
		if mode == 12 {
			if !digits.MatchString(value) {
				return "-1", nil
			}
			target, _ = strconv.ParseInt(value, 10, 64)
			if target <= 0 {
				return "-1", nil
			}
		} else {
			ids, e := threadIDs(value, 32, 360)
			if e != nil {
				return "", e
			}
			if len(ids) != 1 {
				return "-1", nil
			}
			target = ids[0]
		}
		if mode == 11 {
			return commitThreadResult(tx, r, `DELETE FROM mucho_thread_invites WHERE thread_id=? AND invitee_account_id=? AND status='pending'`, id, target)
		}
		if mode == 12 {
			return commitThreadResult(tx, r, `DELETE FROM mucho_thread_messages WHERE id=? AND thread_id=? AND sender_account_id=?`, target, id, actor)
		}
		if target == actor {
			return "-1", nil
		}
		var exists int
		if err = tx.QueryRowContext(r.ctx, `SELECT 1 FROM mucho_thread_members WHERE thread_id=? AND account_id=? FOR UPDATE`, id, target).Scan(&exists); err != nil {
			return "", err
		}
		if mode == 7 {
			_, err = tx.ExecContext(r.ctx, `UPDATE mucho_threads SET owner_account_id=? WHERE id=?`, target, id)
		} else {
			if err = rememberFormer(r, tx, id, target, "kicked"); err != nil {
				return "", err
			}
			_, err = tx.ExecContext(r.ctx, `DELETE FROM mucho_thread_members WHERE thread_id=? AND account_id=?`, id, target)
		}
	case -4, 4:
		if mode == -4 && (!owner || state.Int("is_closed") == 1) {
			return "-1", nil
		}
		ids, err := threadIDs(value, 32, 360)
		if err != nil {
			return "", err
		}
		var count int
		if err = tx.QueryRowContext(r.ctx, `SELECT COUNT(*) FROM mucho_thread_members WHERE thread_id=?`, id).Scan(&count); err != nil {
			return "", err
		}
		if mode == 4 {
			var pending int
			if err = tx.QueryRowContext(r.ctx, `SELECT COUNT(*) FROM mucho_thread_invites WHERE thread_id=? AND status='pending'`, id).Scan(&pending); err != nil {
				return "", err
			}
			count += pending
		}
		if count+len(ids) > 32 {
			return "-1", nil
		}
		for _, target := range ids {
			if target == actor {
				return "-1", nil
			}
			var found int
			if err = tx.QueryRowContext(r.ctx, `SELECT 1 FROM accounts WHERE account_id=? AND is_active=1 AND is_banned=0 LIMIT 1`, target).Scan(&found); err != nil {
				return "", err
			}
			if b, err := blocked(r.ctx, tx, actor, target); err != nil || b {
				return "-1", err
			}
			rows, err := dbutil.Query(r.ctx, tx, `SELECT 1 FROM mucho_thread_members WHERE thread_id=? AND account_id=? LIMIT 1`, id, target)
			if err != nil {
				return "", err
			}
			if len(rows) > 0 {
				return "-1", nil
			}
		}
		for _, target := range ids {
			if _, err = tx.ExecContext(r.ctx, `INSERT INTO mucho_thread_invites(thread_id,inviter_account_id,invitee_account_id,status) VALUES(?,?,?,'pending') ON DUPLICATE KEY UPDATE inviter_account_id=VALUES(inviter_account_id),status='pending'`, id, actor, target); err != nil {
				return "", err
			}
		}
	default:
		return "-1", nil
	}
	if err != nil {
		return "", err
	}
	return "1", tx.Commit()
}

func rememberFormer(r request, tx *sql.Tx, id, actor int64, reason string) error {
	_, err := tx.ExecContext(r.ctx, `INSERT INTO mucho_thread_former_members(thread_id,account_id,reason,last_visible_message_id) VALUES(?,?,?,(SELECT COALESCE(MAX(id),0) FROM mucho_thread_messages WHERE thread_id=?)) ON DUPLICATE KEY UPDATE reason=VALUES(reason),last_visible_message_id=VALUES(last_visible_message_id),left_at=UTC_TIMESTAMP()`, id, actor, reason, id)
	return err
}
func commitThreadResult(tx *sql.Tx, r request, query string, args ...any) (string, error) {
	result, err := tx.ExecContext(r.ctx, query, args...)
	if err != nil {
		return "", err
	}
	n, err := result.RowsAffected()
	if err != nil || n != 1 {
		return "-1", err
	}
	return "1", tx.Commit()
}

func (h *Handler) markThreadsRead(r request, actor, id int64, value string) (string, error) {
	if id != 0 {
		return "-1", nil
	}
	ids, err := threadIDs(value, 32, 360)
	if err != nil {
		return "", err
	}
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return "", err
	}
	defer tx.Rollback()
	for _, id := range ids {
		var member int
		if err = tx.QueryRowContext(r.ctx, `SELECT 1 FROM mucho_thread_members WHERE thread_id=? AND account_id=? FOR UPDATE`, id, actor).Scan(&member); err != nil {
			return "", err
		}
		if _, err = tx.ExecContext(r.ctx, `UPDATE mucho_thread_members SET last_read_message_id=GREATEST(last_read_message_id,(SELECT COALESCE(MAX(id),0) FROM mucho_thread_messages WHERE thread_id=?)) WHERE account_id=? AND thread_id=?`, id, actor, id); err != nil {
			return "", err
		}
	}
	return "1", tx.Commit()
}
func (h *Handler) respondThreadInvite(r request, actor, id, invite int64, accept bool) (string, error) {
	if invite <= 0 && id <= 0 {
		return "-1", nil
	}
	if invite > 0 {
		if err := h.db.QueryRowContext(r.ctx, `SELECT thread_id FROM mucho_thread_invites WHERE id=? AND invitee_account_id=? AND status='pending'`, invite, actor).Scan(&id); err != nil {
			return "", err
		}
	}
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return "", err
	}
	defer tx.Rollback()
	var closed int
	if err = tx.QueryRowContext(r.ctx, `SELECT is_closed FROM mucho_threads WHERE id=? FOR UPDATE`, id).Scan(&closed); err != nil {
		return "", err
	}
	if err = tx.QueryRowContext(r.ctx, `SELECT id FROM mucho_thread_invites WHERE thread_id=? AND invitee_account_id=? AND status='pending' FOR UPDATE`, id, actor).Scan(&invite); err != nil {
		return "", err
	}
	status := "declined"
	if accept {
		if closed == 1 {
			return "-1", nil
		}
		members, err := dbutil.Query(r.ctx, tx, `SELECT account_id FROM mucho_thread_members WHERE thread_id=? FOR UPDATE`, id)
		if err != nil {
			return "", err
		}
		if len(members) >= 32 {
			return "-1", nil
		}
		if _, err = tx.ExecContext(r.ctx, `INSERT IGNORE INTO mucho_thread_members(thread_id,account_id) VALUES(?,?)`, id, actor); err != nil {
			return "", err
		}
		status = "accepted"
	}
	if _, err = tx.ExecContext(r.ctx, `UPDATE mucho_thread_invites SET status=? WHERE id=? AND invitee_account_id=?`, status, invite, actor); err != nil {
		return "", err
	}
	return "1", tx.Commit()
}
