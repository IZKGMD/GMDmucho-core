package core

import (
	"fmt"
	"strings"
	"unicode/utf8"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
)

func (h *Handler) sendThreadMessage(r request, actor int64) (string, error) {
	body, err := decodeThread(r.str("body"))
	if err != nil {
		return "", err
	}
	id := r.threadID()
	start := id == 0
	if !start {
		body = strings.Trim(body, " \t\r\n\x00\x0b")
	}
	if strings.Trim(body, " \t\r\n\x00\x0b") == "" || len(body) > 16000 || utf8.RuneCountInString(body) > 4000 {
		return "-1", nil
	}
	if restricted, err := h.restricted(r, actor, "message"); err != nil || restricted {
		return "-1", err
	}
	dm, peer, title, description, emote := false, r.int("toAccountID"), "", "", ""
	targets := []int64{}
	if start {
		if r.int("isDM") != 0 && r.int("isDM") != 1 {
			return "-1", nil
		}
		dm = r.int("isDM") == 1
		if dm {
			if peer <= 0 || peer == actor {
				return "-1", nil
			}
			if b, err := blocked(r.ctx, h.db, actor, peer); err != nil || b {
				return "-1", err
			}
			var state int
			if err = h.db.QueryRowContext(r.ctx, `SELECT messages_state FROM accounts WHERE account_id=? AND is_active=1 AND is_banned=0`, peer).Scan(&state); err != nil {
				return "", err
			}
			if state == 2 {
				return "-1", nil
			}
			if state == 1 {
				if friend, err := h.isFriend(r, actor, peer); err != nil || !friend {
					return "-1", err
				}
			}
		} else {
			title, err = decodeThread(r.str("subject"))
			if err != nil {
				return "", err
			}
			if raw := r.str("description"); raw != "" {
				description, err = decodeThread(raw)
				if err != nil {
					return "", err
				}
			}
			if raw := r.str("emote"); raw != "" {
				emote, err = decodeThread(raw)
				if err != nil {
					return "", err
				}
			}
			if raw := r.str("targets"); raw != "" {
				value, err := decodeThread(raw)
				if err != nil {
					return "", err
				}
				if value != "" {
					targets, err = threadIDs(value, 31, 320)
					if err != nil {
						return "", err
					}
				}
			}
			if peer != 0 || title == "" || utf8.RuneCountInString(title) > 120 || len(title) > 480 || len(description) > 1024 || utf8.RuneCountInString(description) > 512 || len(emote) > 60 || utf8.RuneCountInString(emote) > 30 || threadControls.MatchString(title+description+emote) {
				return "-1", nil
			}
		}
	}
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return "", err
	}
	defer tx.Rollback()
	// The account lock makes the shared per-sender budget hold across
	// simultaneous sends to different chats as well as new conversations.
	var active int64
	if err = tx.QueryRowContext(r.ctx, `SELECT account_id FROM accounts WHERE account_id=? AND is_active=1 AND is_banned=0 FOR UPDATE`, actor).Scan(&active); err != nil {
		return "", err
	}
	var recent int
	if err = tx.QueryRowContext(r.ctx, `SELECT COUNT(*) FROM mucho_thread_messages WHERE sender_account_id=? AND created_at>UTC_TIMESTAMP()-INTERVAL 60 SECOND`, actor).Scan(&recent); err != nil {
		return "", err
	}
	if recent >= 20 {
		return "-1", nil
	}
	if start {
		for _, target := range targets {
			if target <= 0 || target == actor {
				return "-1", nil
			}
			var found int
			if err = tx.QueryRowContext(r.ctx, `SELECT 1 FROM accounts WHERE account_id=? AND is_active=1 AND is_banned=0`, target).Scan(&found); err != nil {
				return "", err
			}
			if b, err := blocked(r.ctx, tx, actor, target); err != nil || b {
				return "-1", err
			}
		}
		var low, high any
		isDM := 0
		if dm {
			low, high, isDM = min(actor, peer), max(actor, peer), 1
		}
		result, err := tx.ExecContext(r.ctx, `INSERT INTO mucho_threads(owner_account_id,title,is_dm,dm_low,dm_high,description,emote) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)`, actor, title, isDM, low, high, description, emote)
		if err != nil {
			return "", err
		}
		id, err = result.LastInsertId()
		if err != nil || id <= 0 {
			return "-1", err
		}
		state, err := dbutil.One(r.ctx, tx, `SELECT is_closed,is_dm FROM mucho_threads WHERE id=? FOR UPDATE`, id)
		if err != nil {
			return "", err
		}
		if state.Int("is_closed") != 0 || state.Int("is_dm") != int64(isDM) {
			return "-1", nil
		}
		if _, err = tx.ExecContext(r.ctx, `INSERT IGNORE INTO mucho_thread_members(thread_id,account_id) VALUES(?,?)`, id, actor); err != nil {
			return "", err
		}
		if dm {
			if _, err = tx.ExecContext(r.ctx, `INSERT IGNORE INTO mucho_thread_members(thread_id,account_id) VALUES(?,?)`, id, peer); err != nil {
				return "", err
			}
		}
		for _, target := range targets {
			if _, err = tx.ExecContext(r.ctx, `INSERT INTO mucho_thread_invites(thread_id,inviter_account_id,invitee_account_id,status) VALUES(?,?,?,'pending')`, id, actor, target); err != nil {
				return "", err
			}
		}
	} else {
		state, err := threadFor(r, tx, actor, id, true)
		if err != nil {
			return "", err
		}
		if state.Int("is_closed") != 0 {
			return "-1", nil
		}
	}
	result, err := tx.ExecContext(r.ctx, `INSERT INTO mucho_thread_messages(thread_id,sender_account_id,body) VALUES(?,?,?)`, id, actor, body)
	if err != nil {
		return "", err
	}
	message, err := result.LastInsertId()
	if err != nil {
		return "", err
	}
	if !start {
		if _, err = tx.ExecContext(r.ctx, `UPDATE mucho_threads SET updated_at=UTC_TIMESTAMP() WHERE id=?`, id); err != nil {
			return "", err
		}
	}
	if err = tx.Commit(); err != nil {
		return "", err
	}
	if !start {
		return fmt.Sprintf("%d:%d", id, message), nil
	}
	if dm {
		return fmt.Sprintf("%d:%d:0", id, message), nil
	}
	return fmt.Sprintf("%d:%d:%d:0", id, message, len(targets)), nil
}

func (h *Handler) createThread(r request, actor int64, title string) (string, error) {
	title = strings.Trim(title, " \t\r\n\x00\x0b")
	if title == "" || !utf8.ValidString(title) || utf8.RuneCountInString(title) > 120 || threadControls.MatchString(title) {
		return "-1", nil
	}
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return "", err
	}
	defer tx.Rollback()
	result, err := tx.ExecContext(r.ctx, `INSERT INTO mucho_threads(owner_account_id,title) VALUES(?,?)`, actor, title)
	if err != nil {
		return "", err
	}
	id, err := result.LastInsertId()
	if err != nil {
		return "", err
	}
	if _, err = tx.ExecContext(r.ctx, `INSERT INTO mucho_thread_members(thread_id,account_id) VALUES(?,?)`, id, actor); err != nil {
		return "", err
	}
	return fmt.Sprint(id), tx.Commit()
}
