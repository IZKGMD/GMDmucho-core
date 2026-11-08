package core

import (
	"context"
	"fmt"
	"strings"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
	"github.com/IZKGMD/GMDmucho-core/go/internal/protocol"
)

const clanTagSQL = `COALESCE((SELECT c.tag FROM mucho_clan_members cm JOIN mucho_clans c ON c.clan_id=cm.clan_id WHERE cm.account_id=a.account_id LIMIT 1),'') AS clan_tag`

func blocked(ctx context.Context, q dbutil.Querier, a, b int64) (bool, error) {
	rows, err := dbutil.Query(ctx, q, `SELECT 1 FROM blocks WHERE (account_id=? AND blocked_account_id=?) OR (account_id=? AND blocked_account_id=?) LIMIT 1`, a, b, b, a)
	return len(rows) > 0, err
}
func (h *Handler) isFriend(r request, a, b int64) (bool, error) {
	rows, err := dbutil.Query(r.ctx, h.db, `SELECT 1 FROM friends WHERE account_id=? AND friend_account_id=? LIMIT 1`, a, b)
	return len(rows) > 0, err
}
func (h *Handler) messages(r request) (string, error) {
	me, err := h.auth(r)
	if err != nil {
		return "", err
	}
	sent := r.int("getSent") == 1
	owner, peer, deleted := "to_account_id", "account_id", "is_receiver_deleted"
	if sent {
		owner, peer, deleted = "account_id", "to_account_id", "is_sender_deleted"
	}
	var total int
	err = h.db.QueryRowContext(r.ctx, `SELECT COUNT(*) FROM messages WHERE `+owner+`=? AND `+deleted+`=0`, me.ID).Scan(&total)
	if err != nil {
		return "", err
	}
	rows, err := dbutil.Query(r.ctx, h.db, `SELECT m.id,m.account_id,m.to_account_id,m.subject,m.is_read,m.created_at,p.user_id,a.username,`+clanTagSQL+` FROM messages m JOIN profiles p ON p.account_id=m.`+peer+` JOIN accounts a ON a.account_id=m.`+peer+` WHERE m.`+owner+`=? AND m.`+deleted+`=0 ORDER BY m.id DESC LIMIT 10 OFFSET ?`, me.ID, r.page()*10)
	if err != nil {
		return "", err
	}
	if len(rows) == 0 {
		return "-2", nil
	}
	items := make([]string, len(rows))
	for i, row := range rows {
		if sent {
			row["to_username"], row["to_user_id"], row["to_clan_tag"] = row["username"], row["user_id"], row["clan_tag"]
		}
		items[i] = protocol.Message(row, sent)
	}
	return fmt.Sprintf("%s#%d:%d:10", strings.Join(items, "|"), total, r.page()*10), nil
}
func (h *Handler) readMessage(r request) (string, error) {
	me, err := h.auth(r)
	if err != nil {
		return "", err
	}
	m, err := dbutil.One(r.ctx, h.db, `SELECT m.*,pf.user_id,af.username,pt.user_id AS to_user_id,at.username AS to_username
		FROM messages m JOIN profiles pf ON pf.account_id=m.account_id JOIN accounts af ON af.account_id=m.account_id
		JOIN profiles pt ON pt.account_id=m.to_account_id JOIN accounts at ON at.account_id=m.to_account_id
		WHERE m.id=? AND ((m.account_id=? AND m.is_sender_deleted=0) OR (m.to_account_id=? AND m.is_receiver_deleted=0)) LIMIT 1`, r.int("messageID"), me.ID, me.ID)
	if err != nil {
		return "", err
	}
	if m.Int("to_account_id") == me.ID && m.Int("is_read") == 0 {
		_, err = h.db.ExecContext(r.ctx, `UPDATE messages SET is_read=1 WHERE id=?`, m.Int("id"))
		if err != nil {
			return "", err
		}
		m["is_read"] = "1"
	}
	return protocol.Message(m, m.Int("account_id") == me.ID), nil
}
func (h *Handler) sendMessage(r request) (string, error) {
	me, err := h.auth(r)
	if err != nil {
		return "", err
	}
	target := r.int("toAccountID")
	subject, ok := protocol.DecodeMessageSubject(r.str("subject"))
	if !ok {
		return "-1", nil
	}
	body, ok := protocol.DecodeMessageBody(r.str("body"))
	if !ok {
		return "-1", nil
	}
	if target <= 0 || target == me.ID || subject == "" || body == "" || len(subject) > 255 || len(body) > 65535 {
		return "-1", nil
	}
	var restrictions int
	if err = h.db.QueryRowContext(r.ctx, `SELECT COUNT(*) FROM mucho_gd2209_restrictions WHERE account_id=? AND kind='message' AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP())`, me.ID).Scan(&restrictions); err != nil {
		return "", err
	}
	if restrictions > 0 {
		return "-1", nil
	}
	if b, err := blocked(r.ctx, h.db, me.ID, target); err != nil || b {
		return "-1", err
	}
	var state int
	err = h.db.QueryRowContext(r.ctx, `SELECT messages_state FROM accounts WHERE account_id=? AND is_active=1 AND is_banned=0`, target).Scan(&state)
	if err != nil {
		return "", err
	}
	if state == 2 {
		return "-1", nil
	}
	if state == 1 {
		if yes, err := h.isFriend(r, me.ID, target); err != nil || !yes {
			return "-1", err
		}
	}
	_, err = h.db.ExecContext(r.ctx, `INSERT INTO messages(account_id,to_account_id,subject,body) VALUES(?,?,?,?)`, me.ID, target, subject, body)
	return "1", err
}
func (h *Handler) deleteMessage(r request) (string, error) {
	me, err := h.auth(r)
	if err != nil {
		return "", err
	}
	owner, field := "to_account_id", "is_receiver_deleted"
	if r.int("isSender") == 1 {
		owner, field = "account_id", "is_sender_deleted"
	}
	return success(h.db.ExecContext(r.ctx, `UPDATE messages SET `+field+`=1 WHERE id=? AND `+owner+`=?`, r.int("messageID"), me.ID))
}

func (h *Handler) friendRequest(r request) (string, error) {
	me, err := h.auth(r)
	if err != nil {
		return "", err
	}
	target, comment := r.int("toAccountID"), r.str("comment")
	if target <= 0 || target == me.ID || len(comment) > 140 {
		return "-1", nil
	}
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return "", err
	}
	defer tx.Rollback()
	// Serialize opposite-direction requests by locking account IDs in order.
	if _, err = dbutil.Query(r.ctx, tx, `SELECT account_id FROM accounts WHERE account_id IN (?,?) ORDER BY account_id FOR UPDATE`, me.ID, target); err != nil {
		return "", err
	}
	if b, err := blocked(r.ctx, tx, me.ID, target); err != nil || b {
		return "-1", err
	}
	var state int
	err = tx.QueryRowContext(r.ctx, `SELECT friend_requests_state FROM accounts WHERE account_id=? AND is_active=1 AND is_banned=0`, target).Scan(&state)
	if err != nil {
		return "", err
	}
	if state != 0 {
		return "-1", nil
	}
	var count int
	err = tx.QueryRowContext(r.ctx, `SELECT COUNT(*) FROM friends WHERE account_id=? AND friend_account_id=?`, me.ID, target).Scan(&count)
	if err != nil {
		return "", err
	}
	if count > 0 {
		return "-1", nil
	}
	err = tx.QueryRowContext(r.ctx, `SELECT COUNT(*) FROM friend_requests WHERE (account_id=? AND to_account_id=?) OR (account_id=? AND to_account_id=?)`, me.ID, target, target, me.ID).Scan(&count)
	if err != nil {
		return "", err
	}
	if count > 0 {
		return "-1", nil
	}
	_, err = tx.ExecContext(r.ctx, `INSERT INTO friend_requests(account_id,to_account_id,comment) VALUES(?,?,?)`, me.ID, target, comment)
	if err != nil {
		return "", err
	}
	return "1", tx.Commit()
}
func (h *Handler) friendRequests(r request) (string, error) {
	me, err := h.auth(r)
	if err != nil {
		return "", err
	}
	sent := r.int("getSent") == 1
	owner, peer, filter := "to_account_id", "account_id", " AND fr.is_read<>2"
	if sent {
		owner, peer, filter = "account_id", "to_account_id", ""
	}
	var total int
	err = h.db.QueryRowContext(r.ctx, `SELECT COUNT(*) FROM friend_requests fr WHERE fr.`+owner+`=?`+filter, me.ID).Scan(&total)
	if err != nil {
		return "", err
	}
	rows, err := dbutil.Query(r.ctx, h.db, `SELECT fr.*,a.username,p.user_id,p.cube,p.color1,p.color2,p.special,`+clanTagSQL+` FROM friend_requests fr JOIN accounts a ON a.account_id=fr.`+peer+` JOIN profiles p ON p.account_id=fr.`+peer+` WHERE fr.`+owner+`=?`+filter+` ORDER BY fr.id DESC LIMIT 10 OFFSET ?`, me.ID, r.page()*10)
	return protocol.FriendRequests(rows, total, r.page()*10, sent), err
}
func (h *Handler) readFriendRequest(r request) (string, error) {
	me, err := h.auth(r)
	if err != nil {
		return "", err
	}
	var n int
	err = h.db.QueryRowContext(r.ctx, `SELECT 1 FROM friend_requests WHERE id=? AND to_account_id=? LIMIT 1`, r.int("requestID"), me.ID).Scan(&n)
	if err != nil {
		return "", err
	}
	_, err = h.db.ExecContext(r.ctx, `UPDATE friend_requests SET is_read=1 WHERE id=? AND to_account_id=?`, r.int("requestID"), me.ID)
	return "1", err
}
func (h *Handler) acceptFriendRequest(r request) (string, error) {
	me, err := h.auth(r)
	if err != nil {
		return "", err
	}
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return "", err
	}
	defer tx.Rollback()
	var other int64
	err = tx.QueryRowContext(r.ctx, `SELECT account_id FROM friend_requests WHERE id=? AND to_account_id=? FOR UPDATE`, r.int("requestID"), me.ID).Scan(&other)
	if err != nil {
		return "", err
	}
	if b, err := blocked(r.ctx, tx, me.ID, other); err != nil || b {
		return "-1", err
	}
	_, err = tx.ExecContext(r.ctx, `INSERT IGNORE INTO friends(account_id,friend_account_id,is_new) VALUES(?,?,1),(?,?,1)`, me.ID, other, other, me.ID)
	if err != nil {
		return "", err
	}
	_, err = tx.ExecContext(r.ctx, `DELETE FROM friend_requests WHERE id=?`, r.int("requestID"))
	if err != nil {
		return "", err
	}
	return "1", tx.Commit()
}
func (h *Handler) deleteFriendRequest(r request) (string, error) {
	me, err := h.auth(r)
	if err != nil {
		return "", err
	}
	owner, peer := "to_account_id", "account_id"
	if r.int("isSender") == 1 {
		owner, peer = "account_id", "to_account_id"
	}
	return success(h.db.ExecContext(r.ctx, `DELETE FROM friend_requests WHERE `+owner+`=? AND `+peer+`=?`, me.ID, r.int("targetAccountID")))
}
func (h *Handler) removeFriend(r request) (string, error) {
	me, err := h.auth(r)
	if err != nil {
		return "", err
	}
	target := r.int("targetAccountID")
	return success(h.db.ExecContext(r.ctx, `DELETE FROM friends WHERE (account_id=? AND friend_account_id=?) OR (account_id=? AND friend_account_id=?)`, me.ID, target, target, me.ID))
}
func (h *Handler) block(r request) (string, error) {
	me, err := h.auth(r)
	if err != nil {
		return "", err
	}
	target := r.int("targetAccountID")
	if target <= 0 || target == me.ID {
		return "-1", nil
	}
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return "", err
	}
	defer tx.Rollback()
	_, err = tx.ExecContext(r.ctx, `INSERT IGNORE INTO blocks(account_id,blocked_account_id) VALUES(?,?)`, me.ID, target)
	if err != nil {
		return "", err
	}
	_, err = tx.ExecContext(r.ctx, `DELETE FROM friends WHERE (account_id=? AND friend_account_id=?) OR (account_id=? AND friend_account_id=?)`, me.ID, target, target, me.ID)
	if err != nil {
		return "", err
	}
	_, err = tx.ExecContext(r.ctx, `DELETE FROM friend_requests WHERE (account_id=? AND to_account_id=?) OR (account_id=? AND to_account_id=?)`, me.ID, target, target, me.ID)
	if err != nil {
		return "", err
	}
	return "1", tx.Commit()
}
func (h *Handler) unblock(r request) (string, error) {
	me, err := h.auth(r)
	if err != nil {
		return "", err
	}
	return success(h.db.ExecContext(r.ctx, `DELETE FROM blocks WHERE account_id=? AND blocked_account_id=?`, me.ID, r.int("targetAccountID")))
}
func (h *Handler) userList(r request) (string, error) {
	me, err := h.auth(r)
	if err != nil {
		return "", err
	}
	typ := r.int("type")
	table, owner, peer, newValue := "friends", "account_id", "friend_account_id", "f.is_new"
	switch typ {
	case 0:
	case 1:
		table, peer, newValue = "blocks", "blocked_account_id", "0"
	case 3:
		table, owner, peer, newValue = "mucho_follows", "follower_account_id", "followed_account_id", "0"
	case 4:
		table, peer, newValue = "mucho_muted_users", "muted_account_id", "0"
	default:
		return "-2", nil
	}
	filter := ""
	tag := clanTagSQL
	if typ == 3 || typ == 4 {
		filter = " AND a.is_active=1 AND a.is_banned=0"
		tag = "'' AS clan_tag"
	}
	rows, err := dbutil.Query(r.ctx, h.db, `SELECT a.username,p.user_id,p.cube,p.color1,p.color2,p.special,p.stars,p.demons,p.diamonds,p.moons,p.creator_points,p.secret_coins,p.user_coins,a.account_id,`+newValue+` AS is_new,`+tag+` FROM `+table+` f JOIN accounts a ON a.account_id=f.`+peer+` JOIN profiles p ON p.account_id=a.account_id WHERE f.`+owner+`=?`+filter+` ORDER BY a.username ASC`, me.ID)
	if err != nil {
		return "", err
	}
	if typ == 0 && len(rows) > 0 {
		_, err = h.db.ExecContext(r.ctx, `UPDATE friends SET is_new=0 WHERE account_id=?`, me.ID)
		if err != nil {
			return "", err
		}
	}
	return protocol.RelationshipUsers(rows, r.version.Effective() >= 22 && r.version.Binary >= 51), nil
}
