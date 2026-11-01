package core

import (
	"database/sql"
	"encoding/base64"
	"errors"
	"fmt"
	"regexp"
	"sort"
	"strings"
	"unicode/utf8"

	"github.com/IZKGMD/GMDmucho-core/go/internal/account"
	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
	"github.com/IZKGMD/GMDmucho-core/go/internal/protocol"
)

var threadB64Pattern = regexp.MustCompile(`^[A-Za-z0-9_-]+={0,2}$`)
var threadIDsPattern = regexp.MustCompile(`^[1-9][0-9]*(?:,[1-9][0-9]*){0,31}$`)
var threadControls = regexp.MustCompile(`[\x00-\x1f\x7f]`)

func decodeThread(raw string) (string, error) {
	if len(raw) == 0 || len(raw) > 22000 || !threadB64Pattern.MatchString(raw) {
		return "", errors.New("invalid thread encoding")
	}
	clean := strings.TrimRight(raw, "=")
	data, err := base64.RawURLEncoding.Strict().DecodeString(clean)
	if err != nil || !utf8.Valid(data) || base64.RawURLEncoding.EncodeToString(data) != clean {
		return "", errors.New("invalid thread encoding")
	}
	return string(data), nil
}
func threadIDs(raw string, limit, bytes int) ([]int64, error) {
	if raw == "" || len(raw) > bytes || !threadIDsPattern.MatchString(raw) {
		return nil, errors.New("invalid thread IDs")
	}
	parts := strings.Split(raw, ",")
	if len(parts) > limit {
		return nil, errors.New("too many thread IDs")
	}
	ids := v71IDs(raw, limit)
	if len(ids) != len(parts) {
		return nil, errors.New("duplicate thread IDs")
	}
	return ids, nil
}
func (r request) threadID() int64 { return r.defaultInt("threadID", r.int("threadId")) }
func threadFor(r request, q dbutil.Querier, actor, id int64, lock bool) (dbutil.Row, error) {
	query := `SELECT t.*,m.account_id AS member,m.last_cleared_message_id,m.last_read_message_id FROM mucho_threads t JOIN mucho_thread_members m ON m.thread_id=t.id WHERE t.id=? AND m.account_id=? LIMIT 1`
	if lock {
		query += " FOR UPDATE"
	}
	row, err := dbutil.One(r.ctx, q, query, id, actor)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, account.ErrUnauthorized
	}
	return row, err
}
func threadRoster(r request, q dbutil.Querier, id int64) ([]dbutil.Row, []dbutil.Row, error) {
	members, err := dbutil.Query(r.ctx, q, `SELECT m.account_id,a.username,p.user_id,p.cube,p.color1,p.color2,p.special FROM mucho_thread_members m JOIN accounts a ON a.account_id=m.account_id JOIN profiles p ON p.account_id=a.account_id WHERE m.thread_id=? ORDER BY m.joined_at ASC,m.account_id ASC LIMIT 32`, id)
	if err != nil {
		return nil, nil, err
	}
	invites, err := dbutil.Query(r.ctx, q, `SELECT i.id,i.inviter_account_id,i.invitee_account_id,a.username,p.user_id,p.cube,p.color1,p.color2,p.special FROM mucho_thread_invites i JOIN accounts a ON a.account_id=i.invitee_account_id JOIN profiles p ON p.account_id=i.invitee_account_id WHERE i.thread_id=? AND i.status='pending' ORDER BY i.id DESC LIMIT 32`, id)
	return members, invites, err
}
func (h *Handler) threads(r request) (string, error) {
	me, err := h.auth(r)
	if err != nil {
		return "", err
	}
	switch r.path {
	case "/getgjthreads":
		return h.threadList(r, me.ID)
	case "/getgjthreadinvites":
		rows, err := dbutil.Query(r.ctx, h.db, `SELECT i.id,i.thread_id,i.inviter_account_id,t.title,t.description,t.emote,t.is_dm,i.created_at,a.username AS inviter_username,p.cube AS inviter_cube,p.color1 AS inviter_color1,p.color2 AS inviter_color2,p.special AS inviter_special FROM mucho_thread_invites i JOIN mucho_threads t ON t.id=i.thread_id JOIN accounts a ON a.account_id=i.inviter_account_id JOIN profiles p ON p.account_id=i.inviter_account_id WHERE i.invitee_account_id=? AND i.status='pending' AND t.is_closed=0 ORDER BY i.id DESC LIMIT 50`, me.ID)
		return protocol.ThreadInbox(rows, h.now()), err
	case "/sendgjthreadmessage":
		return h.sendThreadMessage(r, me.ID)
	case "/updategjthread":
		return h.updateThread(r, me.ID)
	}
	id := r.threadID()
	state, err := threadFor(r, h.db, me.ID, id, false)
	if r.path == "/getgjthreadmembers" {
		if err != nil {
			var count int
			if e := h.db.QueryRowContext(r.ctx, `SELECT COUNT(*) FROM mucho_thread_invites WHERE thread_id=? AND invitee_account_id=? AND status='pending'`, id, me.ID).Scan(&count); e != nil {
				return "", e
			}
			if count == 0 {
				return "", err
			}
		}
		members, invites, err := threadRoster(r, h.db, id)
		if err != nil {
			return "", err
		}
		users := ""
		if len(members) > 0 {
			users = protocol.RelationshipUsers(members, true)
		}
		return users + "##" + protocol.ThreadInvites(invites), nil
	}
	if err != nil {
		return "", err
	}
	if r.path == "/searchgjthreadmessages" {
		needle, err := decodeThread(r.str("query"))
		if err != nil {
			return "", err
		}
		needle = strings.Trim(needle, " \t\r\n\x00\x0b")
		if utf8.RuneCountInString(needle) < 2 || utf8.RuneCountInString(needle) > 120 || r.int("beforeID") < 0 {
			return "-1", nil
		}
		needle = "%" + strings.NewReplacer(`\`, `\\`, `%`, `\%`, `_`, `\_`).Replace(needle) + "%"
		filter := `m.thread_id=? AND m.id>? AND m.body LIKE ?`
		args := []any{id, state.Int("last_cleared_message_id"), needle}
		if r.int("beforeID") > 0 {
			filter += ` AND m.id<?`
			args = append(args, r.int("beforeID"))
		}
		var total int64
		if err = h.db.QueryRowContext(r.ctx, `SELECT COUNT(*) FROM mucho_thread_messages m WHERE `+filter, args...).Scan(&total); err != nil {
			return "", err
		}
		rows, err := dbutil.Query(r.ctx, h.db, `SELECT m.* FROM mucho_thread_messages m JOIN accounts a ON a.account_id=m.sender_account_id WHERE `+filter+` ORDER BY m.id DESC LIMIT 20`, args...)
		return fmt.Sprintf("%s#%d", protocol.ThreadMessages(rows), max(0, (total+19)/20-1)), err
	}
	before, after, around := r.int("beforeID"), r.int("afterID"), r.int("aroundID")
	active := 0
	for _, cursor := range []int64{before, after, around} {
		if cursor < 0 {
			return "-1", nil
		}
		if cursor > 0 {
			active++
		}
	}
	if active > 1 {
		return "-1", nil
	}
	const query = `SELECT m.* FROM mucho_thread_messages m JOIN accounts a ON a.account_id=m.sender_account_id WHERE m.thread_id=? AND m.id>?`
	args := []any{id, state.Int("last_cleared_message_id")}
	var messages []dbutil.Row
	if around > 0 {
		older, e := dbutil.Query(r.ctx, h.db, query+` AND m.id<=? ORDER BY m.id DESC LIMIT 25`, append(args, around)...)
		if e != nil {
			return "", e
		}
		newer, e := dbutil.Query(r.ctx, h.db, query+` AND m.id>? ORDER BY m.id ASC LIMIT 25`, append(args, around)...)
		if e != nil {
			return "", e
		}
		messages = append(older, newer...)
		sort.Slice(messages, func(i, j int) bool { return messages[i].Int("id") > messages[j].Int("id") })
	} else {
		filter := ""
		if before > 0 {
			filter = ` AND m.id<?`
			args = append(args, before)
		} else if after > 0 {
			filter = ` AND m.id>?`
			args = append(args, after)
		}
		messages, err = dbutil.Query(r.ctx, h.db, query+filter+` ORDER BY m.id DESC LIMIT 50`, args...)
		if err != nil {
			return "", err
		}
	}
	if len(messages) > 0 {
		lowest := messages[len(messages)-1].Int("id")
		var older int
		if err = h.db.QueryRowContext(r.ctx, `SELECT COUNT(*) FROM mucho_thread_messages WHERE thread_id=? AND id>? AND id<?`, id, state.Int("last_cleared_message_id"), lowest).Scan(&older); err != nil {
			return "", err
		}
		if older > 0 {
			state["has_older_messages"] = "1"
		}
	}
	members, invites, err := threadRoster(r, h.db, id)
	return protocol.ThreadSnapshot(state, messages, members, invites), err
}

func (h *Handler) threadList(r request, actor int64) (string, error) {
	page, filter := r.int("page"), r.int("filter")
	if page < 0 || page > 1000 || filter < 0 || filter > 2 {
		return "-1", nil
	}
	where := `m.account_id=?`
	args := []any{actor}
	if filter == 1 {
		where += ` AND t.is_dm=1`
	} else if filter == 2 {
		where += ` AND t.is_dm=0`
	}
	if raw := r.str("user"); raw != "" {
		if len(raw) > 300 || !threadIDsPattern.MatchString(raw) || len(strings.Split(raw, ",")) > 31 {
			return "-1", nil
		}
		ids := v71IDs(raw, 31)
		where += ` AND EXISTS(SELECT 1 FROM mucho_thread_members fm WHERE fm.thread_id=t.id AND fm.account_id IN (` + strings.TrimRight(strings.Repeat("?,", len(ids)), ",") + `))`
		for _, id := range ids {
			args = append(args, id)
		}
	}
	var total, invites int64
	if err := h.db.QueryRowContext(r.ctx, `SELECT COUNT(*) FROM mucho_threads t JOIN mucho_thread_members m ON m.thread_id=t.id WHERE `+where, args...).Scan(&total); err != nil {
		return "", err
	}
	if err := h.db.QueryRowContext(r.ctx, `SELECT COUNT(*) FROM mucho_thread_invites i JOIN mucho_threads t ON t.id=i.thread_id WHERE i.invitee_account_id=? AND i.status='pending' AND t.is_closed=0`, actor).Scan(&invites); err != nil {
		return "", err
	}
	query := `SELECT t.*,m.is_muted,m.is_pinned,(SELECT COUNT(*) FROM mucho_thread_members mt WHERE mt.thread_id=t.id) AS member_count,(SELECT COUNT(*) FROM mucho_thread_messages un WHERE un.thread_id=t.id AND un.id>m.last_read_message_id) AS unread_count,(SELECT lm.sender_account_id FROM mucho_thread_messages lm WHERE lm.thread_id=t.id ORDER BY lm.id DESC LIMIT 1) AS last_sender,(SELECT UNIX_TIMESTAMP(lm.created_at) FROM mucho_thread_messages lm WHERE lm.thread_id=t.id ORDER BY lm.id DESC LIMIT 1) AS last_timestamp,(SELECT sa.username FROM mucho_thread_messages lm JOIN accounts sa ON sa.account_id=lm.sender_account_id WHERE lm.thread_id=t.id ORDER BY lm.id DESC LIMIT 1) AS last_username,peer.account_id AS peer_account_id,peer.username AS peer_username,pp.cube AS peer_cube,pp.color1 AS peer_color1,pp.color2 AS peer_color2,pp.special AS peer_special FROM mucho_threads t JOIN mucho_thread_members m ON m.thread_id=t.id LEFT JOIN accounts peer ON peer.account_id=(CASE WHEN t.dm_low=? THEN t.dm_high ELSE t.dm_low END) AND t.is_dm=1 LEFT JOIN profiles pp ON pp.account_id=peer.account_id WHERE ` + where + ` ORDER BY m.is_pinned DESC,t.updated_at DESC,t.id DESC LIMIT 10 OFFSET ?`
	rows, err := dbutil.Query(r.ctx, h.db, query, append(append([]any{actor}, args...), page*10)...)
	return protocol.ThreadList(rows, total, page*10, invites, h.now()), err
}
