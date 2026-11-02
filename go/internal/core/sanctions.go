package core

import (
	"database/sql"
	"errors"
	"strings"
	"time"

	"github.com/IZKGMD/GMDmucho-core/go/internal/account"
	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
)

func (h *Handler) moderationRole(r request) (account.Identity, string, error) {
	me, err := h.auth(r)
	if err != nil {
		return me, "", err
	}
	var role string
	err = h.db.QueryRowContext(r.ctx, `SELECT COALESCE(ro.code,'user') FROM accounts a LEFT JOIN roles ro ON ro.id=a.role_id WHERE a.account_id=? AND a.is_active=1 AND a.is_banned=0`, me.ID).Scan(&role)
	role = strings.ToLower(role)
	if err == nil && role != "elder_moderator" && role != "owner" {
		err = account.ErrUnauthorized
	}
	return me, role, err
}
func roleRank(role string) int {
	switch role {
	case "user":
		return 0
	case "moderator":
		return 50
	case "elder_moderator":
		return 75
	case "owner":
		return 100
	}
	return 100
}
func targetAllowed(r request, q dbutil.Querier, actor int64, role string, target int64) error {
	if target <= 0 || target == actor {
		return account.ErrUnauthorized
	}
	row, err := dbutil.One(r.ctx, q, `SELECT COALESCE(ro.code,'user') AS role FROM accounts a LEFT JOIN roles ro ON ro.id=a.role_id WHERE a.account_id=? LIMIT 1`, target)
	if err != nil {
		return err
	}
	if roleRank(role) <= roleRank(row["role"]) {
		return account.ErrUnauthorized
	}
	return nil
}
func caseAudit(r request, tx *sql.Tx, actor, target int64, action string, detail int64) error {
	_, err := tx.ExecContext(r.ctx, `INSERT INTO mucho_gd2209_case_actions(actor_account_id,target_account_id,action,detail) VALUES(?,?,?,?)`, actor, target, action, detail)
	return err
}
func unsignedField(r request, key string, optional bool) bool {
	if _, ok := r.form[key]; !ok {
		return optional
	}
	value := r.str(key)
	return len(value) >= 1 && len(value) <= 18 && digits.MatchString(value)
}

func (h *Handler) sanction(r request) (string, error) {
	if r.raw.Method != "POST" || !unsignedField(r, "accountID", false) || !unsignedField(r, "targetAccountID", false) || !unsignedField(r, "durationMinutes", true) {
		return "-1", nil
	}
	me, role, err := h.moderationRole(r)
	if err != nil {
		return "", err
	}
	target, duration := r.int("targetAccountID"), r.int("durationMinutes")
	if target <= 0 || target == me.ID || duration < 0 || duration > 525600 {
		return "-1", nil
	}
	config := map[string]struct {
		kind   string
		enable bool
	}{
		"/admincommentban": {"comment", true}, "/admincommentunban": {"comment", false}, "/adminuploadban": {"upload", true}, "/adminuploadunban": {"upload", false}, "/adminmessageban": {"message", true}, "/adminmessageunban": {"message", false}, "/adminfreezeaccount": {"freeze", true}, "/adminunfreezeaccount": {"freeze", false}, "/admintrendingban": {"trending", true}, "/admintrendingunban": {"trending", false}, "/adminnamelock": {"name", true}, "/adminnameunlock": {"name", false}, "/adminqueuemute": {"queue", true}, "/adminqueueunmute": {"queue", false},
	}[r.path]
	if config.kind == "" || (config.kind == "freeze" && duration != 0) {
		return "-1", nil
	}
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return "", err
	}
	defer tx.Rollback()
	targetRow, err := dbutil.One(r.ctx, tx, `SELECT a.is_active,COALESCE(ro.code,'user') AS role FROM accounts a LEFT JOIN roles ro ON ro.id=a.role_id WHERE a.account_id=? FOR UPDATE`, target)
	if err != nil {
		return "", err
	}
	if roleRank(role) <= roleRank(targetRow["role"]) {
		return "-1", nil
	}
	state, err := dbutil.One(r.ctx, tx, `SELECT prior_is_active FROM mucho_gd2209_restrictions WHERE account_id=? AND kind=? FOR UPDATE`, target, config.kind)
	exists := err == nil
	if err != nil && !errors.Is(err, sql.ErrNoRows) {
		return "", err
	}
	if config.enable {
		var prior, expiry any
		if config.kind == "freeze" {
			prior = targetRow.Int("is_active")
			if exists {
				prior = state.Int("prior_is_active")
			}
		}
		if duration > 0 {
			expiry = h.now().UTC().Add(time.Duration(duration) * time.Minute).Format("2006-01-02 15:04:05")
		}
		_, err = tx.ExecContext(r.ctx, `INSERT INTO mucho_gd2209_restrictions(account_id,kind,expires_at,created_by,prior_is_active) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE expires_at=VALUES(expires_at),created_by=VALUES(created_by),prior_is_active=VALUES(prior_is_active)`, target, config.kind, expiry, me.ID, prior)
		if err != nil {
			return "", err
		}
		if config.kind == "freeze" {
			_, err = tx.ExecContext(r.ctx, `UPDATE accounts SET is_active=0 WHERE account_id=?`, target)
		}
	} else {
		if !exists {
			return "-1", nil
		}
		_, err = tx.ExecContext(r.ctx, `DELETE FROM mucho_gd2209_restrictions WHERE account_id=? AND kind=?`, target, config.kind)
		if err != nil {
			return "", err
		}
		if config.kind == "freeze" {
			_, err = tx.ExecContext(r.ctx, `UPDATE accounts SET is_active=? WHERE account_id=?`, state.Int("prior_is_active"), target)
		}
	}
	if err != nil {
		return "", err
	}
	suffix := "_UNBAN"
	if config.enable {
		suffix = "_BAN"
	}
	action := strings.ToUpper(config.kind) + suffix
	if config.kind == "queue" {
		action = "QUEUE_UNMUTE"
		if config.enable {
			action = "QUEUE_MUTE"
		}
	}
	if err = caseAudit(r, tx, me.ID, target, action, duration); err != nil {
		return "", err
	}
	return "1", tx.Commit()
}
