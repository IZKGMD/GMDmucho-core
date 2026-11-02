package core

import (
	"database/sql"
	"errors"
	"fmt"
	"strings"
	"unicode/utf8"

	"github.com/IZKGMD/GMDmucho-core/go/internal/admin"
	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
)

func (h *Handler) adminPlayersWrite(r request, input map[string]any, action string, identity admin.Identity) (v2Result, error) {
	id, err := adminInt(input, "account_id", 1, 1<<63-1)
	if err != nil {
		return v2Result{}, err
	}
	if action == "players.update_profile" {
		return h.adminProfileWrite(r, input, identity, id)
	}
	role := ""
	banned := false
	if action == "players.set_ban" {
		banned, err = adminBool(input, "banned")
		if err != nil {
			return v2Result{}, err
		}
	} else {
		switch strings.ToLower(v2Trim(v2String(input["role"]))) {
		case "player", "user":
			role = "user"
		case "mod", "moderator", "helper":
			role = "moderator"
		case "admin", "administrator", "elder", "elder_mod", "elder-moderator", "elder_moderator":
			role = "elder_moderator"
		case "owner":
			role = "owner"
		default:
			return v2Result{}, fmt.Errorf("invalid account role")
		}
	}
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return v2Result{}, err
	}
	defer tx.Rollback()
	before, err := dbutil.One(r.ctx, tx, `SELECT a.username,COALESCE(ro.code,'user') AS role,a.is_banned FROM accounts a LEFT JOIN roles ro ON ro.id=a.role_id WHERE a.account_id=? LIMIT 1 FOR UPDATE`, id)
	if errors.Is(err, sql.ErrNoRows) {
		return adminFailure("not_found", "Player not found.", 404)
	}
	if err != nil {
		return v2Result{}, err
	}
	if (action == "players.set_ban" && strings.EqualFold(before["role"], "owner")) || role == "owner" {
		allowed, err := admin.Can(r.ctx, tx, identity, "settings.manage")
		if err != nil {
			return v2Result{}, err
		}
		if !allowed {
			message := "Only an owner can assign the owner game role."
			if action == "players.set_ban" {
				message = "Only an owner can moderate an owner account."
			}
			return adminFailure("forbidden", message, 403)
		}
	}
	response := map[string]any{"ok": true, "account_id": id}
	details := map[string]any{"username": before["username"]}
	auditAction := "player.role"
	if action == "players.set_ban" {
		value := 0
		if banned {
			value = 1
		}
		if _, err = tx.ExecContext(r.ctx, `UPDATE accounts SET is_banned=? WHERE account_id=?`, value, id); err != nil {
			return v2Result{}, err
		}
		auditAction = "player.unban"
		if banned {
			auditAction = "player.ban"
		}
		details["previous"], details["current"] = before.Int("is_banned") != 0, banned
		response["is_banned"] = banned
	} else {
		// Both representations must change together: legacy moderator commands
		// may consult accounts.role when a migrated role resolves to "user".
		if _, err = tx.ExecContext(r.ctx, `UPDATE accounts a JOIN roles ro ON ro.code=? SET a.role_id=ro.id,a.role=ro.code WHERE a.account_id=?`, role, id); err != nil {
			return v2Result{}, err
		}
		details["previous"], details["current"] = before["role"], role
		response["role"] = role
	}
	if err = admin.Audit(r.ctx, tx, identity, r.ip, auditAction, "account", fmt.Sprint(id), details); err != nil {
		return v2Result{}, err
	}
	return v2OK(response), tx.Commit()
}
func (h *Handler) adminProfileWrite(r request, input map[string]any, identity admin.Identity, id int64) (v2Result, error) {
	values := map[string]any{}
	args := []any{}
	sets := []string{}
	for _, field := range []struct {
		key     string
		maximum int64
	}{{"stars", 100000000}, {"moons", 100000000}, {"diamonds", 1000000000}, {"secret_coins", 1000000}, {"user_coins", 100000000}, {"demons", 10000000}, {"creator_points", 10000000}} {
		value, err := adminInt(input, field.key, 0, field.maximum)
		if err != nil {
			return v2Result{}, err
		}
		values[field.key] = value
		args = append(args, value)
		sets = append(sets, field.key+"=?")
	}
	bio := v2Trim(v2String(input["bio"]))
	if len(bio) > 2000 {
		bio = bio[:2000]
		for !utf8.ValidString(bio) {
			bio = bio[:len(bio)-1]
		}
	}
	values["bio"] = bio
	args = append(args, bio)
	sets = append(sets, "bio=?")
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return v2Result{}, err
	}
	defer tx.Rollback()
	clanID, err := lockScoreClan(r, tx, id)
	if err != nil {
		return v2Result{}, err
	}
	before, err := dbutil.TypedOne(r.ctx, tx, `SELECT a.username,p.stars,p.moons,p.diamonds,p.secret_coins,p.user_coins,p.demons,p.creator_points,p.bio FROM accounts a JOIN profiles p ON p.account_id=a.account_id WHERE a.account_id=? LIMIT 1 FOR UPDATE`, id)
	if errors.Is(err, sql.ErrNoRows) {
		return adminFailure("not_found", "Player profile not found.", 404)
	}
	if err != nil {
		return v2Result{}, err
	}
	if _, err = tx.ExecContext(r.ctx, `UPDATE profiles SET `+strings.Join(sets, ",")+` WHERE account_id=?`, append(args, id)...); err != nil {
		return v2Result{}, err
	}
	if clanID > 0 {
		if err = settleScoreClan(r, tx, clanID); err != nil {
			return v2Result{}, err
		}
	}
	if err = admin.Audit(r.ctx, tx, identity, r.ip, "player.profile.update", "account", fmt.Sprint(id), map[string]any{"username": before["username"], "previous": before, "current": values}); err != nil {
		return v2Result{}, err
	}
	return v2OK(map[string]any{"ok": true, "account_id": id}), tx.Commit()
}
func (h *Handler) adminLevelWrite(r request, input map[string]any, identity admin.Identity) (v2Result, error) {
	id, err := adminInt(input, "level_id", 1, 1<<63-1)
	if err != nil {
		return v2Result{}, err
	}
	values := map[string]any{}
	sets := []string{}
	args := []any{}
	for _, field := range []struct {
		key     string
		maximum int64
	}{{"stars", 10}, {"difficulty", 100}, {"demon_difficulty", 6}, {"epic", 3}} {
		value, err := adminInt(input, field.key, 0, field.maximum)
		if err != nil {
			return v2Result{}, err
		}
		values[field.key] = value
		sets = append(sets, field.key+"=?")
		args = append(args, value)
	}
	for _, key := range []string{"demon", "featured", "is_deleted"} {
		value, err := adminBool(input, key)
		if err != nil {
			return v2Result{}, err
		}
		values[key] = value
		sets = append(sets, key+"=?")
		args = append(args, value)
	}
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return v2Result{}, err
	}
	defer tx.Rollback()
	before, err := dbutil.TypedOne(r.ctx, tx, `SELECT level_id,name,stars,difficulty,demon,demon_difficulty,featured,epic,is_deleted FROM levels WHERE level_id=? LIMIT 1 FOR UPDATE`, id)
	if errors.Is(err, sql.ErrNoRows) {
		return adminFailure("not_found", "Level not found.", 404)
	}
	if err != nil {
		return v2Result{}, err
	}
	if _, err = tx.ExecContext(r.ctx, `UPDATE levels SET `+strings.Join(sets, ",")+` WHERE level_id=?`, append(args, id)...); err != nil {
		return v2Result{}, err
	}
	if _, err = tx.ExecContext(r.ctx, `UPDATE profiles p JOIN levels target ON target.account_id=p.account_id AND target.level_id=? SET p.creator_points=(SELECT COALESCE(SUM(CASE WHEN stars>0 THEN 1 ELSE 0 END+CASE WHEN featured>0 THEN 1 ELSE 0 END+epic),0) FROM levels WHERE account_id=target.account_id AND is_deleted=0)`, id); err != nil {
		return v2Result{}, err
	}
	if err = admin.Audit(r.ctx, tx, identity, r.ip, "level.update", "level", fmt.Sprint(id), map[string]any{"name": before["name"], "previous": before, "current": values}); err != nil {
		return v2Result{}, err
	}
	if err = tx.Commit(); err != nil {
		return v2Result{}, err
	}
	_ = h.indexLevel(r, id)
	return v2OK(map[string]any{"ok": true, "level_id": id}), nil
}
