package core

import (
	"crypto/sha256"
	"database/sql"
	"encoding/base64"
	"errors"
	"fmt"
	"regexp"
	"strings"
	"unicode/utf8"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
	"github.com/IZKGMD/GMDmucho-core/go/internal/protocol"
)

var audioB64Pattern = regexp.MustCompile(`^[A-Za-z0-9+/_=\-]+$`)

func editorText(raw string, limit int) (string, bool) {
	value := strings.Trim(raw, " \t\r\n\x00\x0b")
	return value, len(value) <= limit*4 && utf8.ValidString(value) && utf8.RuneCountInString(value) <= limit && !threadControls.MatchString(value)
}
func editorMIME(data []byte) string {
	if len(data) < 12 {
		return ""
	}
	if string(data[:4]) == "RIFF" && string(data[8:12]) == "WAVE" {
		return "audio/wav"
	}
	if string(data[:4]) == "OggS" {
		return "audio/ogg"
	}
	if string(data[:3]) == "ID3" || (data[0] == 255 && data[1]&224 == 224) {
		return "audio/mpeg"
	}
	return ""
}
func (h *Handler) editorSounds(r request) (string, error) {
	id := r.defaultInt("soundID", r.int("id"))
	if r.path == "/getgjeditorsounds" {
		filter := `a.is_active=1 AND a.is_banned=0`
		args := []any{}
		if owner := r.defaultInt("creatorID", r.int("targetAccountID")); owner > 0 {
			filter += ` AND s.account_id=?`
			args = append(args, owner)
		}
		rows, err := dbutil.Query(r.ctx, h.db, `SELECT s.id,s.account_id,s.title,s.description,s.mime_type,s.size_bytes,s.sha256 FROM mucho_editor_sounds s JOIN accounts a ON a.account_id=s.account_id WHERE `+filter+` ORDER BY s.id DESC LIMIT 20 OFFSET ?`, append(args, r.page()*20)...)
		if err != nil {
			return "", err
		}
		if len(rows) == 0 {
			return "-2", nil
		}
		items := []string{}
		for _, row := range rows {
			items = append(items, protocol.Pairs(1, row.Int("id"), 2, base64.URLEncoding.EncodeToString([]byte(row["title"])), 3, base64.URLEncoding.EncodeToString([]byte(row["description"])), 4, row.Int("account_id"), 5, row.Int("size_bytes"), 6, row["mime_type"], 7, row["sha256"]))
		}
		return strings.Join(items, "|"), nil
	}
	if r.path == "/downloadgjeditorsound" {
		var data []byte
		err := h.db.QueryRowContext(r.ctx, `SELECT s.audio_bytes FROM mucho_editor_sounds s JOIN accounts a ON a.account_id=s.account_id WHERE s.id=? AND a.is_active=1 AND a.is_banned=0 LIMIT 1`, id).Scan(&data)
		if err != nil {
			return "", err
		}
		return base64.URLEncoding.EncodeToString(data), nil
	}
	me, err := h.auth(r)
	if err != nil {
		return "", err
	}
	if r.path == "/deletegjeditorsound" {
		return success(h.db.ExecContext(r.ctx, `DELETE FROM mucho_editor_sounds WHERE id=? AND account_id=?`, id, me.ID))
	}
	if r.path == "/updategjsounddesc" {
		description, valid := editorText(r.str("description"), 512)
		if !valid || id <= 0 {
			return "-1", nil
		}
		result, err := h.db.ExecContext(r.ctx, `UPDATE mucho_editor_sounds SET description=? WHERE id=? AND account_id=?`, description, id, me.ID)
		if err != nil {
			return "", err
		}
		n, err := result.RowsAffected()
		if err != nil {
			return "", err
		}
		if n == 0 {
			if err = h.db.QueryRowContext(r.ctx, `SELECT COUNT(*) FROM mucho_editor_sounds WHERE id=? AND account_id=?`, id, me.ID).Scan(&n); err != nil {
				return "", err
			}
		}
		if n == 0 {
			return "-1", nil
		}
		return "1", nil
	}
	title := r.str("title")
	if title == "" || title == "0" {
		title = r.str("name")
	}
	title, titleValid := editorText(title, 120)
	description, descValid := editorText(r.str("description"), 512)
	raw := r.str("data")
	if raw == "" {
		raw = r.str("audio")
	}
	if !titleValid || title == "" || !descValid || len(raw) == 0 || len(raw) > 2800000 || !audioB64Pattern.MatchString(raw) {
		return "-1", nil
	}
	data, err := base64.RawStdEncoding.DecodeString(strings.TrimRight(strings.NewReplacer("-", "+", "_", "/").Replace(raw), "="))
	if err != nil || len(data) < 12 || len(data) > 2097152 {
		return "-1", nil
	}
	mime := editorMIME(data)
	if mime == "" {
		return "-1", nil
	}
	hash := fmt.Sprintf("%x", sha256.Sum256(data))
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return "", err
	}
	defer tx.Rollback()
	var actor int64
	if err = tx.QueryRowContext(r.ctx, `SELECT account_id FROM accounts WHERE account_id=? FOR UPDATE`, me.ID).Scan(&actor); err != nil {
		return "", err
	}
	var old int64
	err = tx.QueryRowContext(r.ctx, `SELECT id FROM mucho_editor_sounds WHERE account_id=? AND sha256=? LIMIT 1`, me.ID, hash).Scan(&old)
	if err == nil {
		return fmt.Sprint(old), tx.Commit()
	}
	if !errors.Is(err, sql.ErrNoRows) {
		return "", err
	}
	stats, err := dbutil.One(r.ctx, tx, `SELECT COUNT(*) AS files,COALESCE(SUM(size_bytes),0) AS bytes FROM mucho_editor_sounds WHERE account_id=?`, me.ID)
	if err != nil {
		return "", err
	}
	if stats.Int("files") >= 50 || stats.Int("bytes")+int64(len(data)) > 20971520 {
		return "-1", nil
	}
	result, err := tx.ExecContext(r.ctx, `INSERT INTO mucho_editor_sounds(account_id,title,description,mime_type,audio_bytes,size_bytes,sha256) VALUES(?,?,?,?,?,?,?)`, me.ID, title, description, mime, data, len(data), hash)
	if err != nil {
		return "", err
	}
	id, err = result.LastInsertId()
	if err != nil {
		return "", err
	}
	return fmt.Sprint(id), tx.Commit()
}
