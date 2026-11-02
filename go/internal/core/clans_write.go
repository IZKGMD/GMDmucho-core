package core

import (
	"database/sql"
	"errors"

	"github.com/IZKGMD/GMDmucho-core/go/internal/account"
	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
)

func (h *Handler) createClan(r request, me account.Identity) (any, error) {
	existing, err := clanFor(r.ctx, h.db, me.ID)
	if err != nil {
		return nil, err
	}
	if existing != nil {
		return nil, publicError("Account is already in a clan.")
	}
	fields, err := clanFields(r)
	if err != nil {
		return nil, err
	}
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()
	result, err := tx.ExecContext(r.ctx, `INSERT INTO mucho_clans(name,tag,description,owner_account_id,is_open,max_members) VALUES(?,?,?,?,?,?)`, fields.name, fields.tag, fields.description, me.ID, fields.open, fields.max)
	if err != nil {
		return nil, err
	}
	id, err := result.LastInsertId()
	if err != nil {
		return nil, err
	}
	_, err = tx.ExecContext(r.ctx, `INSERT INTO mucho_clan_members(clan_id,account_id,role) VALUES(?,?,'owner')`, id, me.ID)
	if err != nil {
		return nil, err
	}
	if err = clanEvent(r, tx, id, clanPlayer(r, tx, me.ID)+" created the clan."); err != nil {
		return nil, err
	}
	if err = tx.Commit(); err != nil {
		return nil, err
	}
	return h.clanDetails(r.ctx, h.db, id, false)
}
func (h *Handler) updateClan(r request, me account.Identity, id int64, fields clanSettings) (any, error) {
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()
	clan, err := clanLocked(r, tx, id)
	if err != nil {
		return nil, err
	}
	if clan.Int("owner_account_id") != me.ID {
		return nil, publicError("Clan owner permission required.")
	}
	if clan.Int("member_count") > fields.max {
		return nil, publicError("Maximum members cannot be below current membership.")
	}
	_, err = tx.ExecContext(r.ctx, `UPDATE mucho_clans SET name=?,tag=?,description=?,is_open=?,max_members=? WHERE clan_id=?`, fields.name, fields.tag, fields.description, fields.open, fields.max, id)
	if err != nil {
		return nil, err
	}
	if err = clanEvent(r, tx, id, clanPlayer(r, tx, me.ID)+" updated clan settings."); err != nil {
		return nil, err
	}
	if err = tx.Commit(); err != nil {
		return nil, err
	}
	data, err := h.clanDetails(r.ctx, h.db, id, false)
	if err == nil {
		h.clanAudit(r, me.ID, "clan.settings.updated", "clan", id, map[string]any{"is_open": fields.open == 1, "max_members": fields.max})
	}
	return data, err
}
func (h *Handler) joinClan(r request, me account.Identity, id, inviteID int64) (any, error) {
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()
	clan, err := clanLocked(r, tx, id)
	if err != nil {
		if inviteID > 0 {
			var exposed publicError
			if errors.As(err, &exposed) {
				return nil, publicError("Clan is full or unavailable.")
			}
		}
		return nil, err
	}
	if inviteID == 0 && clan.Int("is_open") != 1 {
		return nil, publicError("Clan is not open for joining.")
	}
	if clan.Int("member_count") >= clan.Int("max_members") {
		if inviteID > 0 {
			return nil, publicError("Clan is full or unavailable.")
		}
		return nil, publicError("Clan is full.")
	}
	if inviteID > 0 {
		var existing int64
		err = tx.QueryRowContext(r.ctx, `SELECT invite_id FROM mucho_clan_invites WHERE invite_id=? AND account_id=? AND clan_id=? AND expires_at>UTC_TIMESTAMP() LIMIT 1 FOR UPDATE`, inviteID, me.ID, id).Scan(&existing)
		if errors.Is(err, sql.ErrNoRows) {
			return nil, publicError("Clan invitation not found or expired.")
		}
		if err != nil {
			return nil, err
		}
	}
	if banned, err := clanBanned(r, tx, id, me.ID); err != nil {
		return nil, err
	} else if banned {
		return nil, publicError("You are banned from this clan.")
	}
	if existing, err := clanFor(r.ctx, tx, me.ID); err != nil {
		return nil, err
	} else if existing != nil {
		return nil, publicError("Account is already in a clan.")
	}
	if err = settleScoreClan(r, tx, id); err != nil {
		return nil, err
	}
	_, err = tx.ExecContext(r.ctx, `INSERT INTO mucho_clan_members(clan_id,account_id,role) VALUES(?,?,'member')`, id, me.ID)
	if err != nil {
		return nil, err
	}
	if inviteID > 0 {
		_, err = tx.ExecContext(r.ctx, `DELETE FROM mucho_clan_invites WHERE invite_id=? AND account_id=?`, inviteID, me.ID)
	} else {
		_, err = tx.ExecContext(r.ctx, `DELETE FROM mucho_clan_invites WHERE clan_id=? AND account_id=?`, id, me.ID)
	}
	if err != nil {
		return nil, err
	}
	if err = enrollClan(r, tx, id, me.ID); err != nil {
		return nil, err
	}
	if err = clanEvent(r, tx, id, clanPlayer(r, tx, me.ID)+" joined the clan."); err != nil {
		return nil, err
	}
	if err = tx.Commit(); err != nil {
		return nil, err
	}
	if inviteID > 0 {
		h.clanAudit(r, me.ID, "clan.invite.accepted", "clan_invite", inviteID, nil)
		return map[string]any{"accepted": true}, nil
	}
	return map[string]any{"joined": true}, nil
}
func (h *Handler) clanInvitationAction(r request, me account.Identity) (any, error) {
	id := r.int("inviteID")
	var clanID int64
	if r.path == "/clans/invite/accept" {
		err := h.db.QueryRowContext(r.ctx, `SELECT clan_id FROM mucho_clan_invites WHERE invite_id=? AND account_id=? AND expires_at>UTC_TIMESTAMP() LIMIT 1`, id, me.ID).Scan(&clanID)
		if errors.Is(err, sql.ErrNoRows) {
			return nil, publicError("Clan invitation not found or expired.")
		}
		if err != nil {
			return nil, err
		}
		return h.joinClan(r, me, clanID, id)
	}
	err := h.db.QueryRowContext(r.ctx, `SELECT clan_id FROM mucho_clan_invites WHERE invite_id=? LIMIT 1`, id).Scan(&clanID)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, publicError("Clan invitation not found.")
	}
	if err != nil {
		return nil, err
	}
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()
	if _, err = clanLocked(r, tx, clanID); err != nil {
		return nil, err
	}
	actor, err := clanMember(r.ctx, tx, clanID, me.ID)
	if err != nil {
		return nil, err
	}
	if !clanOfficer(actor) {
		return nil, publicError("Clan officer permission required.")
	}
	result, err := tx.ExecContext(r.ctx, `DELETE FROM mucho_clan_invites WHERE invite_id=? AND clan_id=?`, id, clanID)
	if err != nil {
		return nil, err
	}
	n, err := result.RowsAffected()
	if err != nil {
		return nil, err
	}
	if err = tx.Commit(); err != nil {
		return nil, err
	}
	if n > 0 {
		h.clanAudit(r, me.ID, "clan.invite.revoked", "clan_invite", id, nil)
	}
	return map[string]any{"revoked": n > 0}, nil
}
func clanOfficer(member dbutil.Row) bool {
	return member != nil && (member["role"] == "owner" || member["role"] == "officer")
}

func (h *Handler) mutateClan(r request, me account.Identity, id int64) (any, error) {
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()
	clan, err := clanLocked(r, tx, id)
	if err != nil {
		return nil, err
	}
	actor, err := clanMember(r.ctx, tx, id, me.ID)
	if err != nil {
		return nil, err
	}
	targetID := r.int("targetAccountID")
	target, err := clanMember(r.ctx, tx, id, targetID)
	if err != nil {
		return nil, err
	}
	key, event, audit, targetType := "", "", "", "account"
	auditTarget := targetID
	var metadata any
	changed := true
	switch r.path {
	case "/clans/leave":
		key = "left"
		if actor == nil {
			return map[string]any{key: false}, nil
		}
		if actor["role"] == "owner" {
			return nil, publicError("Clan owner cannot leave the clan.")
		}
		if err = settleScoreClan(r, tx, id); err != nil {
			return nil, err
		}
		_, err = tx.ExecContext(r.ctx, `DELETE FROM mucho_clan_members WHERE clan_id=? AND account_id=?`, id, me.ID)
		event = clanPlayer(r, tx, me.ID) + " left the clan."
	case "/clans/invite":
		key = "invited"
		if !clanOfficer(actor) {
			return nil, publicError("Clan officer permission required.")
		}
		if targetID <= 0 || targetID == me.ID {
			return nil, publicError("Invalid invite target.")
		}
		var exists int
		if err = tx.QueryRowContext(r.ctx, `SELECT 1 FROM accounts WHERE account_id=? LIMIT 1`, targetID).Scan(&exists); errors.Is(err, sql.ErrNoRows) {
			return nil, publicError("Target account not found.")
		}
		if err != nil {
			return nil, err
		}
		if member, err := clanFor(r.ctx, tx, targetID); err != nil {
			return nil, err
		} else if member != nil {
			return nil, publicError("Target account is already in a clan.")
		}
		if clan.Int("member_count") >= clan.Int("max_members") {
			return nil, publicError("Clan is full.")
		}
		if banned, err := clanBanned(r, tx, id, targetID); err != nil {
			return nil, err
		} else if banned {
			return nil, publicError("Target account is banned from this clan.")
		}
		_, err = tx.ExecContext(r.ctx, `INSERT INTO mucho_clan_invites(clan_id,account_id,invited_by_account_id,expires_at) VALUES(?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY)) ON DUPLICATE KEY UPDATE invited_by_account_id=VALUES(invited_by_account_id),created_at=CURRENT_TIMESTAMP,expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY)`, id, targetID, me.ID)
	case "/clans/kick":
		key = "kicked"
		if !clanOfficer(actor) || target == nil {
			return nil, publicError("Clan officer permission required.")
		}
		if target["role"] == "owner" {
			return nil, publicError("The clan owner cannot be kicked.")
		}
		if actor["role"] == "officer" && target["role"] != "member" {
			return nil, publicError("Officers cannot remove other officers.")
		}
		if err = settleScoreClan(r, tx, id); err != nil {
			return nil, err
		}
		_, err = tx.ExecContext(r.ctx, `DELETE FROM mucho_clan_members WHERE clan_id=? AND account_id=?`, id, targetID)
		event = clanPlayer(r, tx, me.ID) + " kicked " + clanPlayer(r, tx, targetID) + "."
		audit = "clan.member.kicked"
		metadata = map[string]any{"clan_id": id}
	case "/clans/role":
		key = "updated"
		if clan.Int("owner_account_id") != me.ID {
			return nil, publicError("Clan owner permission required.")
		}
		role := r.str("role")
		if role != "officer" && role != "member" {
			return nil, publicError("Invalid clan role.")
		}
		if target == nil || target["role"] == "owner" {
			return nil, publicError("Target is not eligible for role changes.")
		}
		var result sql.Result
		result, err = tx.ExecContext(r.ctx, `UPDATE mucho_clan_members SET role=? WHERE clan_id=? AND account_id=?`, role, id, targetID)
		if err == nil {
			n, e := result.RowsAffected()
			changed, err = n > 0, e
		}
		event = clanPlayer(r, tx, me.ID) + " changed " + clanPlayer(r, tx, targetID) + " to " + role + "."
		audit = "clan.member.role_changed"
		metadata = map[string]any{"clan_id": id, "role": role}
	case "/clans/transfer":
		key = "transferred"
		if clan.Int("owner_account_id") != me.ID {
			return nil, publicError("Clan owner permission required.")
		}
		if targetID == me.ID {
			return nil, publicError("Target is already the clan owner.")
		}
		if target == nil {
			return nil, publicError("Target is not a member of the clan.")
		}
		_, err = tx.ExecContext(r.ctx, `UPDATE mucho_clan_members SET role='owner' WHERE clan_id=? AND account_id=?`, id, targetID)
		if err == nil {
			_, err = tx.ExecContext(r.ctx, `UPDATE mucho_clan_members SET role='officer' WHERE clan_id=? AND account_id=?`, id, me.ID)
		}
		if err == nil {
			_, err = tx.ExecContext(r.ctx, `UPDATE mucho_clans SET owner_account_id=? WHERE clan_id=?`, targetID, id)
		}
		event = clanPlayer(r, tx, me.ID) + " transferred ownership to " + clanPlayer(r, tx, targetID) + "."
		audit, targetType, auditTarget = "clan.ownership.transferred", "clan", id
		metadata = map[string]any{"new_owner_account_id": targetID}
	case "/clans/disband":
		key = "disbanded"
		if clan.Int("owner_account_id") != me.ID {
			return nil, publicError("Clan owner permission required.")
		}
		_, err = tx.ExecContext(r.ctx, `DELETE FROM mucho_clans WHERE clan_id=?`, id)
		audit, targetType, auditTarget = "clan.disbanded", "clan", id
	case "/clans/ban":
		key = "banned"
		if !clanOfficer(actor) {
			return nil, publicError("Clan officer permission required.")
		}
		if targetID <= 0 || targetID == me.ID {
			return nil, publicError("Invalid clan ban target.")
		}
		var exists int
		if err = tx.QueryRowContext(r.ctx, `SELECT 1 FROM accounts WHERE account_id=? LIMIT 1`, targetID).Scan(&exists); errors.Is(err, sql.ErrNoRows) {
			return nil, publicError("Target account not found.")
		}
		if err != nil {
			return nil, err
		}
		if target != nil {
			if target["role"] == "owner" {
				return nil, publicError("The clan owner cannot be banned.")
			}
			if actor["role"] == "officer" && target["role"] != "member" {
				return nil, publicError("Officers cannot ban other officers.")
			}
			if err = settleScoreClan(r, tx, id); err != nil {
				return nil, err
			}
			_, err = tx.ExecContext(r.ctx, `DELETE FROM mucho_clan_members WHERE clan_id=? AND account_id=?`, id, targetID)
			if err != nil {
				return nil, err
			}
		}
		_, err = tx.ExecContext(r.ctx, `DELETE FROM mucho_clan_invites WHERE clan_id=? AND account_id=?`, id, targetID)
		if err == nil {
			_, err = tx.ExecContext(r.ctx, `INSERT INTO mucho_clan_bans(clan_id,account_id,banned_by_account_id,reason) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE banned_by_account_id=VALUES(banned_by_account_id),reason=VALUES(reason),created_at=CURRENT_TIMESTAMP,expires_at=NULL`, id, targetID, me.ID, normalizeClanDescription(r.str("reason")))
		}
		event = clanPlayer(r, tx, me.ID) + " banned " + clanPlayer(r, tx, targetID) + "."
		audit = "clan.member.banned"
		metadata = map[string]any{"clan_id": id}
	case "/clans/unban":
		key = "unbanned"
		if !clanOfficer(actor) {
			return nil, publicError("Clan officer permission required.")
		}
		var result sql.Result
		result, err = tx.ExecContext(r.ctx, `DELETE FROM mucho_clan_bans WHERE clan_id=? AND account_id=?`, id, targetID)
		if err == nil {
			n, e := result.RowsAffected()
			changed, err = n > 0, e
		}
		event = clanPlayer(r, tx, me.ID) + " unbanned " + clanPlayer(r, tx, targetID) + "."
		audit = "clan.member.unbanned"
		metadata = map[string]any{"clan_id": id}
	default:
		return nil, publicError("Unknown clan action.")
	}
	if err != nil {
		return nil, err
	}
	if changed && event != "" {
		if err = clanEvent(r, tx, id, event); err != nil {
			return nil, err
		}
	}
	if err = tx.Commit(); err != nil {
		return nil, err
	}
	if changed && audit != "" {
		h.clanAudit(r, me.ID, audit, targetType, auditTarget, metadata)
	}
	return map[string]any{key: changed}, nil
}
