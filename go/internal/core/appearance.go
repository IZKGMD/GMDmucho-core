package core

import (
	"errors"
	"regexp"
	"strconv"
	"strings"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
)

var appearanceIDPattern = regexp.MustCompile(`^[1-9][0-9]{0,9}$`)
var appearanceColorPattern = regexp.MustCompile(`^[0-9A-F]{6}$`)

func appearanceColor(value string, allowDefault bool) (any, error) {
	value = strings.ToUpper(strings.Trim(value, " \t\r\n\x00\x0b"))
	if allowDefault && (value == "" || value == "DEFAULT") {
		return nil, nil
	}
	value = strings.TrimPrefix(value, "#")
	if !appearanceColorPattern.MatchString(value) {
		return nil, publicError("Use a six-digit HEX color, for example #66CCFF.")
	}
	return value, nil
}
func (h *Handler) appearance(r request) reply {
	if r.raw.Method != "POST" {
		return jsonReply(map[string]any{"ok": false, "error": "Use POST."}, 405)
	}
	data, err := h.appearanceData(r)
	if err != nil {
		var exposed publicError
		if !errors.As(err, &exposed) {
			err = publicError("Appearance storage is unavailable. Update MuchoCore and check the server logs.")
		}
	}
	return apiReply(data, err)
}
func (h *Handler) appearanceData(r request) (any, error) {
	if r.path == "/appearance/batch" {
		parts := strings.Split(r.str("accountIDs"), ",")
		if len(parts) > 50 {
			return nil, publicError("Request at most 50 accounts at once.")
		}
		ids := []any{}
		seen := map[int64]bool{}
		for _, part := range parts {
			part = strings.Trim(part, " \t\r\n\x00\x0b")
			n, err := strconv.ParseInt(part, 10, 64)
			if err != nil || !appearanceIDPattern.MatchString(part) || n > 2147483647 {
				return nil, publicError("Invalid account ID.")
			}
			if !seen[n] {
				seen[n] = true
				ids = append(ids, n)
			}
		}
		rows, err := h.appearances(r, ids)
		return map[string]any{"players": rows}, err
	}
	me, err := h.auth(r)
	if err != nil {
		return nil, publicError("Unauthorized.")
	}
	if r.path == "/appearance/save" {
		nickname, err := appearanceColor(r.str("nicknameColor"), true)
		if err != nil {
			return nil, err
		}
		var prefix any
		if _, ok := r.form["prefixColor"]; ok {
			prefix, err = appearanceColor(r.str("prefixColor"), false)
			if err != nil {
				return nil, err
			}
		}
		tx, err := h.db.BeginTx(r.ctx, nil)
		if err != nil {
			return nil, err
		}
		defer tx.Rollback()
		if prefix != nil {
			id, err := lockScoreClan(r, tx, me.ID)
			if err != nil {
				return nil, err
			}
			if id == 0 {
				return nil, publicError("Only the clan owner can change its prefix color.")
			}
			var owner int64
			if err = tx.QueryRowContext(r.ctx, `SELECT owner_account_id FROM mucho_clans WHERE clan_id=?`, id).Scan(&owner); err != nil {
				return nil, err
			}
			if owner != me.ID {
				return nil, publicError("Only the clan owner can change its prefix color.")
			}
			if _, err = tx.ExecContext(r.ctx, `INSERT INTO mucho_clan_appearance(clan_id,prefix_color) VALUES(?,?) ON DUPLICATE KEY UPDATE prefix_color=VALUES(prefix_color)`, id, prefix); err != nil {
				return nil, err
			}
		}
		if _, err = tx.ExecContext(r.ctx, `INSERT INTO mucho_user_appearance(account_id,nickname_color) VALUES(?,?) ON DUPLICATE KEY UPDATE nickname_color=VALUES(nickname_color)`, me.ID, nickname); err != nil {
			return nil, err
		}
		if err = tx.Commit(); err != nil {
			return nil, err
		}
	}
	rows, err := h.appearances(r, []any{me.ID})
	if err != nil {
		return nil, err
	}
	if len(rows) == 0 {
		return nil, publicError("Account not found.")
	}
	return rows[0], nil
}
func (h *Handler) appearances(r request, ids []any) ([]map[string]any, error) {
	rows, err := dbutil.TypedQuery(r.ctx, h.db, `SELECT a.account_id,a.username,COALESCE(u.nickname_color,'') AS nickname_color,COALESCE(c.clan_id,0) AS clan_id,COALESCE(c.tag,'') AS clan_tag,COALESCE(s.prefix_color,'FFD166') AS prefix_color,CASE WHEN c.owner_account_id=a.account_id THEN 1 ELSE 0 END AS can_edit_prefix FROM accounts a LEFT JOIN mucho_user_appearance u ON u.account_id=a.account_id LEFT JOIN mucho_clan_members m ON m.account_id=a.account_id LEFT JOIN mucho_clans c ON c.clan_id=m.clan_id LEFT JOIN mucho_clan_appearance s ON s.clan_id=c.clan_id WHERE a.account_id IN (`+strings.TrimRight(strings.Repeat("?,", len(ids)), ",")+`) AND a.is_active=1 AND a.is_banned=0 ORDER BY a.account_id`, ids...)
	if err != nil {
		return nil, err
	}
	for _, row := range rows {
		row["account_id"] = anyInt(row["account_id"])
		row["clan_id"] = anyInt(row["clan_id"])
		row["can_edit_prefix"] = anyInt(row["can_edit_prefix"]) != 0
	}
	return rows, nil
}
