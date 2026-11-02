package core

import (
	"database/sql"
	"errors"
	"strconv"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
)

func (h *Handler) clanQuests(r request) reply {
	if r.raw.Method != "POST" {
		return jsonReply(map[string]any{"ok": false, "error": "POST required."}, 405)
	}
	data, err := h.clanQuestData(r)
	if err != nil {
		var exposed publicError
		if !errors.As(err, &exposed) {
			err = publicError("Clan quests are unavailable. Update MuchoCore and run migrations.")
		}
	}
	return apiReply(data, err)
}

func (h *Handler) clanQuestData(r request) (map[string]any, error) {
	me, err := h.auth(r)
	if err != nil {
		return nil, publicError("Unauthorized.")
	}
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()
	id, err := lockScoreClan(r, tx, me.ID)
	if err != nil {
		return nil, err
	}
	if id == 0 {
		return nil, publicError("You are not in a clan.")
	}
	member, err := clanMember(r.ctx, tx, id, me.ID)
	if err != nil {
		return nil, err
	}
	manage := clanOfficer(member)
	if (r.path == "/clans/quests/create" || r.path == "/clans/quests/cancel") && !manage {
		return nil, publicError("Clan officer permission required.")
	}
	offset, limit := max(0, min(100000, r.int("offset"))), max(1, min(50, r.defaultInt("limit", 20)))
	data := map[string]any{}
	if r.path != "/clans/activity" {
		if err = settleScoreClan(r, tx, id); err != nil {
			return nil, err
		}
	}
	switch r.path {
	case "/clans/quests/create":
		metric, target := r.str("metric"), r.int("target")
		if !((metric == "stars" && target >= 100 && target <= 100000) || (metric == "demons" && target >= 5 && target <= 1000)) {
			return nil, publicError("Quest goal: 100-100000 stars or 5-1000 demons.")
		}
		rows, err := dbutil.Query(r.ctx, tx, `SELECT quest_id FROM mucho_clan_quests WHERE clan_id=? AND metric=? AND status='active' AND expires_at>UTC_TIMESTAMP() LIMIT 1 FOR UPDATE`, id, metric)
		if err != nil {
			return nil, err
		}
		if len(rows) > 0 {
			return nil, publicError("An active quest for this metric already exists.")
		}
		result, err := tx.ExecContext(r.ctx, `INSERT INTO mucho_clan_quests(clan_id,metric,target,expires_at) VALUES(?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY))`, id, metric, target)
		if err != nil {
			return nil, err
		}
		questID, err := result.LastInsertId()
		if err != nil {
			return nil, err
		}
		_, err = tx.ExecContext(r.ctx, `INSERT INTO mucho_clan_quest_members(quest_id,account_id,last_score) SELECT ?,m.account_id,GREATEST(COALESCE(p.`+metric+`,0),0) FROM mucho_clan_members m LEFT JOIN profiles p ON p.account_id=m.account_id WHERE m.clan_id=?`, questID, id)
		if err != nil {
			return nil, err
		}
		if err = clanEvent(r, tx, id, clanPlayer(r, tx, me.ID)+" started a quest: "+strconv.FormatInt(target, 10)+" "+metric); err != nil {
			return nil, err
		}
		data["quest_id"] = questID
	case "/clans/quests/cancel":
		result, err := tx.ExecContext(r.ctx, `UPDATE mucho_clan_quests SET status='cancelled' WHERE quest_id=? AND clan_id=? AND status='active' AND expires_at>UTC_TIMESTAMP()`, r.int("questID"), id)
		if err != nil {
			return nil, err
		}
		n, err := result.RowsAffected()
		if err != nil {
			return nil, err
		}
		if n != 1 {
			return nil, publicError("Active quest not found.")
		}
		if err = clanEvent(r, tx, id, clanPlayer(r, tx, me.ID)+" cancelled quest #"+strconv.FormatInt(r.int("questID"), 10)); err != nil {
			return nil, err
		}
		data["cancelled"] = true
	case "/clans/quests":
		rows, err := dbutil.TypedQuery(r.ctx, tx, `SELECT q.*,IF(q.status='active' AND q.expires_at<=UTC_TIMESTAMP(),'expired',q.status) AS state FROM mucho_clan_quests q WHERE clan_id=? ORDER BY (q.status='active' AND q.expires_at>UTC_TIMESTAMP()) DESC,q.quest_id DESC LIMIT 20 FOR UPDATE`, id)
		if err != nil {
			return nil, err
		}
		for _, row := range rows {
			castQuest(row)
		}
		data["quests"] = rows
	case "/clans/quests/contributions":
		questID := r.int("questID")
		quest, err := dbutil.TypedOne(r.ctx, tx, `SELECT q.*,IF(q.status='active' AND q.expires_at<=UTC_TIMESTAMP(),'expired',q.status) AS state FROM mucho_clan_quests q WHERE quest_id=? AND clan_id=? FOR UPDATE`, questID, id)
		if errors.Is(err, sql.ErrNoRows) {
			return nil, publicError("Quest not found.")
		}
		if err != nil {
			return nil, err
		}
		castQuest(quest)
		rows, err := dbutil.TypedQuery(r.ctx, tx, `SELECT s.account_id,a.username,s.contribution,COALESCE(u.nickname_color,'') AS nickname_color,(m.account_id IS NOT NULL) AS is_member FROM mucho_clan_quest_members s JOIN accounts a ON a.account_id=s.account_id LEFT JOIN mucho_clan_members m ON m.account_id=s.account_id AND m.clan_id=? LEFT JOIN mucho_user_appearance u ON u.account_id=s.account_id WHERE s.quest_id=? ORDER BY s.contribution DESC,s.account_id ASC LIMIT ? OFFSET ? FOR UPDATE`, id, questID, limit+1, offset)
		if err != nil {
			return nil, err
		}
		for _, row := range rows {
			row["account_id"] = anyInt(row["account_id"])
			row["contribution"] = anyInt(row["contribution"])
			row["is_member"] = anyInt(row["is_member"]) != 0
		}
		data = map[string]any{"quest_id": questID, "metric": quest["metric"], "quest": quest, "members": rows[:min(len(rows), int(limit))], "has_more": len(rows) > int(limit), "offset": offset}
	case "/clans/activity":
		rows, err := dbutil.TypedQuery(r.ctx, tx, `SELECT event_id,message,created_at FROM mucho_clan_events WHERE clan_id=? ORDER BY event_id DESC LIMIT ? OFFSET ? FOR UPDATE`, id, limit+1, offset)
		if err != nil {
			return nil, err
		}
		data = map[string]any{"events": rows[:min(len(rows), int(limit))], "has_more": len(rows) > int(limit), "offset": offset}
	}
	data["can_manage"] = manage
	if err = tx.Commit(); err != nil {
		return nil, err
	}
	return data, nil
}

func castQuest(row map[string]any) {
	for _, key := range []string{"quest_id", "clan_id", "target", "progress"} {
		row[key] = anyInt(row[key])
	}
}
