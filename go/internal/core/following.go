package core

import (
	"fmt"
	"strings"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
	"github.com/IZKGMD/GMDmucho-core/go/internal/protocol"
)

func (r request) target() int64 {
	for _, key := range []string{"targetAccountID", "toAccountID", "followAccountID", "userID"} {
		if n := r.int(key); n > 0 {
			return n
		}
	}
	return 0
}
func (h *Handler) verifyPeer(r request, me, target int64) (bool, error) {
	if target <= 0 || target == me {
		return false, nil
	}
	var n int
	err := h.db.QueryRowContext(r.ctx, `SELECT COUNT(*) FROM accounts WHERE account_id=? AND is_active=1 AND is_banned=0`, target).Scan(&n)
	if err != nil || n == 0 {
		return false, err
	}
	b, err := blocked(r.ctx, h.db, me, target)
	return !b, err
}
func (h *Handler) follow(r request) (string, error) {
	me, err := h.auth(r)
	if err != nil {
		return "", err
	}
	target := r.target()
	if ok, err := h.verifyPeer(r, me.ID, target); err != nil || !ok {
		return "-1", err
	}
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return "", err
	}
	defer tx.Rollback()
	result, err := tx.ExecContext(r.ctx, `INSERT IGNORE INTO mucho_follows(follower_account_id,followed_account_id) VALUES(?,?)`, me.ID, target)
	if err != nil {
		return "", err
	}
	n, err := result.RowsAffected()
	if err != nil {
		return "", err
	}
	if n == 1 {
		_, err = tx.ExecContext(r.ctx, `INSERT IGNORE INTO mucho_notifications(account_id,actor_account_id,notification_type,source_id) VALUES(?,?,'follow',?)`, target, me.ID, me.ID)
		if err != nil {
			return "", err
		}
	}
	return "1", tx.Commit()
}
func (h *Handler) unfollow(r request) (string, error) {
	me, err := h.auth(r)
	if err != nil {
		return "", err
	}
	if r.target() <= 0 {
		return "-1", nil
	}
	_, err = h.db.ExecContext(r.ctx, `DELETE FROM mucho_follows WHERE follower_account_id=? AND followed_account_id=?`, me.ID, r.target())
	return "1", err
}
func (h *Handler) mute(r request) (string, error) {
	me, err := h.auth(r)
	if err != nil {
		return "", err
	}
	target := r.target()
	if target <= 0 || target == me.ID {
		return "-1", nil
	}
	muted := int64(1)
	if _, ok := r.form["isMuted"]; ok {
		muted = r.int("isMuted")
	}
	if _, ok := r.form["mute"]; ok {
		muted = r.int("mute")
	}
	if muted == 0 {
		_, err = h.db.ExecContext(r.ctx, `DELETE FROM mucho_muted_users WHERE account_id=? AND muted_account_id=?`, me.ID, target)
		return "1", err
	}
	if ok, err := h.verifyPeer(r, me.ID, target); err != nil || !ok {
		return "-1", err
	}
	_, err = h.db.ExecContext(r.ctx, `INSERT IGNORE INTO mucho_muted_users(account_id,muted_account_id) VALUES(?,?)`, me.ID, target)
	return "1", err
}
func (h *Handler) following(r request) (string, error) {
	me, err := h.auth(r)
	if err != nil {
		return "", err
	}
	source, peer := "follower_account_id", "followed_account_id"
	if r.int("getFollowers") == 1 {
		source, peer = peer, source
	}
	var total int
	err = h.db.QueryRowContext(r.ctx, `SELECT COUNT(*) FROM mucho_follows WHERE `+source+`=?`, me.ID).Scan(&total)
	if err != nil {
		return "", err
	}
	rows, err := dbutil.Query(r.ctx, h.db, `SELECT a.account_id,a.username,p.user_id,p.cube,p.color1,p.color2,p.special,0 AS is_new FROM mucho_follows f JOIN accounts a ON a.account_id=f.`+peer+` JOIN profiles p ON p.account_id=a.account_id WHERE f.`+source+`=? AND a.is_active=1 AND a.is_banned=0 ORDER BY f.created_at DESC,a.account_id DESC LIMIT 20 OFFSET ?`, me.ID, r.page()*20)
	if err != nil {
		return "", err
	}
	if len(rows) == 0 {
		return "-2", nil
	}
	return fmt.Sprintf("%s#%d:%d:20", protocol.RelationshipUsers(rows, false), total, r.page()*20), nil
}
func (h *Handler) syncFollowing(r request) (string, error) {
	r.form.Set("page", "0")
	r.form.Set("getFollowers", "0")
	return h.following(r)
}
func (h *Handler) notifications(r request) (string, error) {
	me, err := h.auth(r)
	if err != nil {
		return "", err
	}
	var total int
	err = h.db.QueryRowContext(r.ctx, `SELECT COUNT(*) FROM mucho_notifications WHERE account_id=?`, me.ID).Scan(&total)
	if err != nil {
		return "", err
	}
	rows, err := dbutil.Query(r.ctx, h.db, `SELECT n.*,a.username,p.user_id FROM mucho_notifications n JOIN accounts a ON a.account_id=n.actor_account_id LEFT JOIN profiles p ON p.account_id=n.actor_account_id WHERE n.account_id=? ORDER BY n.id DESC LIMIT 20 OFFSET ?`, me.ID, r.page()*20)
	if err != nil {
		return "", err
	}
	if len(rows) == 0 {
		return "-2", nil
	}
	items := make([]string, len(rows))
	for i, n := range rows {
		items[i] = protocol.Pairs(1, n.Int("id"), 2, protocol.Username(n["username"]), 3, n.Int("user_id"), 4, n.Int("actor_account_id"), 5, n["notification_type"], 6, n.Int("source_id"), 7, n.Int("is_read"), 8, n.Int("reward_claimed"), 9, protocol.Epoch(n["created_at"]))
	}
	return fmt.Sprintf("%s#%d:%d:20", strings.Join(items, "|"), total, r.page()*20), nil
}
func (h *Handler) claimNotification(r request) (string, error) {
	me, err := h.auth(r)
	if err != nil {
		return "", err
	}
	id := r.int("notificationID")
	if _, ok := r.form["notificationID"]; !ok {
		id = r.int("id")
	}
	return success(h.db.ExecContext(r.ctx, `UPDATE mucho_notifications SET reward_claimed=1,is_read=1 WHERE id=? AND account_id=? AND reward_claimed=0`, id, me.ID))
}
