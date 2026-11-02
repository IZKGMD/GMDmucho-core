package core

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"regexp"
	"strconv"
	"strings"
	"unicode/utf8"

	"github.com/IZKGMD/GMDmucho-core/go/internal/account"
	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
	"github.com/go-sql-driver/mysql"
)

var clanNamePattern = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9 _.-]{1,23}$`)
var clanTagPattern = regexp.MustCompile(`^[A-Za-z0-9]{2,6}$`)
var asciiWhitespace = regexp.MustCompile(`[\t\n\r\f\v ]+`)

type clanSettings struct {
	name, tag, description string
	open, max              int64
}

func (r request) defaultInt(key string, fallback int64) int64 {
	if !strictInt.MatchString(r.str(key)) {
		return fallback
	}
	return r.int(key)
}
func normalizeClanDescription(value string) string {
	if !utf8.ValidString(value) {
		return ""
	}
	value = strings.Join(strings.Fields(value), " ")
	runes := []rune(value)
	if len(runes) > 160 {
		value = string(runes[:160])
	}
	return value
}
func clanFields(r request) (clanSettings, error) {
	name := strings.Trim(asciiWhitespace.ReplaceAllString(r.str("clanName"), " "), " \t\r\n\x00\x0b")
	tag := strings.Trim(r.str("clanTag"), " \t\r\n\x00\x0b")
	if !clanNamePattern.MatchString(name) || !clanTagPattern.MatchString(tag) {
		return clanSettings{}, publicError("Invalid clan name or tag.")
	}
	open := int64(0)
	if r.defaultInt("clanOpen", 1) == 1 {
		open = 1
	}
	return clanSettings{name, strings.ToUpper(tag), normalizeClanDescription(r.str("clanDescription")), open, max(2, min(500, r.defaultInt("clanMaxMembers", 50)))}, nil
}
func (h *Handler) clans(r request) reply {
	data, err := h.clanData(r)
	if errors.Is(err, account.ErrUnauthorized) {
		err = publicError("Unauthorized.")
	}
	if err != nil {
		var message publicError
		if !errors.As(err, &message) {
			var duplicate *mysql.MySQLError
			if errors.As(err, &duplicate) && duplicate.Number == 1062 {
				err = publicError("Clan name or tag already exists.")
			} else {
				err = publicError("Clan storage is unavailable. Check the server logs.")
			}
		}
	}
	return apiReply(data, err)
}
func (h *Handler) clanData(r request) (any, error) {
	if r.path == "/clans/leaderboard" {
		return h.clanLeaderboard(r)
	}
	me, err := h.auth(r)
	if err != nil {
		return nil, err
	}
	switch r.path {
	case "/clans/create":
		return h.createClan(r, me)
	case "/clans/get":
		return h.clanDetails(r.ctx, h.db, r.int("clanID"), true)
	case "/clans/my":
		clan, err := dbutil.TypedOne(r.ctx, h.db, clanForSQL, me.ID)
		if errors.Is(err, sql.ErrNoRows) {
			return nil, nil
		}
		if err != nil {
			return nil, err
		}
		members, err := clanMembers(r.ctx, h.db, anyInt(clan["clan_id"]))
		if err != nil {
			return nil, err
		}
		clan["members"] = members
		return clan, nil
	case "/clans/search":
		offset, limit := max(0, min(100000, r.int("offset"))), max(1, min(50, r.defaultInt("limit", 20)))
		query := strings.Trim(r.str("query"), " \t\r\n\x00\x0b")
		if len(query) > 48 {
			query = query[:48]
		}
		rows, err := dbutil.TypedQuery(r.ctx, h.db, clanDetailsSQL+` WHERE c.name LIKE ? OR c.tag LIKE ? ORDER BY c.created_at DESC,c.clan_id DESC LIMIT ? OFFSET ?`, "%"+query+"%", "%"+query+"%", limit+1, offset)
		if err != nil {
			return nil, err
		}
		return map[string]any{"clans": rows[:min(len(rows), int(limit))], "has_more": len(rows) > int(limit), "offset": offset}, nil
	case "/clans/invites":
		rows, err := dbutil.TypedQuery(r.ctx, h.db, `SELECT i.invite_id,i.clan_id,i.created_at,i.expires_at,c.name,c.tag,a.username AS invited_by_username FROM mucho_clan_invites i JOIN mucho_clans c ON c.clan_id=i.clan_id JOIN accounts a ON a.account_id=i.invited_by_account_id WHERE i.account_id=? AND i.expires_at>UTC_TIMESTAMP() ORDER BY i.created_at DESC`, me.ID)
		return map[string]any{"invites": rows}, err
	case "/clans/invite/decline":
		result, err := h.db.ExecContext(r.ctx, `DELETE FROM mucho_clan_invites WHERE invite_id=? AND account_id=?`, r.int("inviteID"), me.ID)
		if err != nil {
			return nil, err
		}
		n, err := result.RowsAffected()
		return map[string]any{"declined": n > 0}, err
	case "/clans/invite/accept", "/clans/invite/revoke":
		return h.clanInvitationAction(r, me)
	}
	clan, err := clanFor(r.ctx, h.db, me.ID)
	if err != nil {
		return nil, err
	}
	if r.path == "/clans/leave" && clan == nil {
		return map[string]any{"left": false}, nil
	}
	if r.path == "/clans/join" {
		if clan != nil {
			return nil, publicError("Account is already in a clan.")
		}
		joining, e := dbutil.One(r.ctx, h.db, `SELECT is_open FROM mucho_clans WHERE clan_id=?`, r.int("clanID"))
		if errors.Is(e, sql.ErrNoRows) || (e == nil && joining.Int("is_open") != 1) {
			return nil, publicError("Clan is not open for joining.")
		}
		if e != nil {
			return nil, e
		}
		return h.joinClan(r, me, r.int("clanID"), 0)
	}
	if r.path == "/clans/kick" {
		target, e := clanFor(r.ctx, h.db, r.int("targetAccountID"))
		if e != nil {
			return nil, e
		}
		if clan == nil || target == nil || target.Int("clan_id") != clan.Int("clan_id") {
			return nil, publicError("Target is not in your clan.")
		}
	}
	if r.path == "/clans/ban" && (r.int("targetAccountID") <= 0 || r.int("targetAccountID") == me.ID) {
		return nil, publicError("Invalid ban target.")
	}
	if r.path == "/clans/bans" || r.path == "/clans/invites/sent" {
		if clan == nil || (clan["role"] != "owner" && clan["role"] != "officer") {
			return nil, publicError("Clan officer permission required.")
		}
		if r.path == "/clans/bans" {
			rows, err := dbutil.TypedQuery(r.ctx, h.db, `SELECT b.ban_id,b.account_id,b.banned_by_account_id,b.reason,b.created_at,b.expires_at,a.username,ab.username AS banned_by_username FROM mucho_clan_bans b JOIN accounts a ON a.account_id=b.account_id JOIN accounts ab ON ab.account_id=b.banned_by_account_id WHERE b.clan_id=? AND (b.expires_at IS NULL OR b.expires_at>UTC_TIMESTAMP()) ORDER BY b.created_at DESC`, clan.Int("clan_id"))
			return map[string]any{"bans": rows}, err
		}
		rows, err := dbutil.TypedQuery(r.ctx, h.db, `SELECT i.invite_id,i.account_id,i.created_at,i.expires_at,a.username,sender.username AS invited_by_username FROM mucho_clan_invites i JOIN accounts a ON a.account_id=i.account_id JOIN accounts sender ON sender.account_id=i.invited_by_account_id WHERE i.clan_id=? AND i.expires_at>UTC_TIMESTAMP() ORDER BY i.created_at DESC,i.invite_id DESC`, clan.Int("clan_id"))
		return map[string]any{"invites": rows}, err
	}
	if r.path == "/clans/settings" {
		fields, err := clanFields(r)
		if err != nil {
			return nil, err
		}
		return h.updateClan(r, me, r.int("clanID"), fields)
	}
	if clan == nil {
		if r.path == "/clans/invite" {
			return nil, publicError("Clan officer permission required.")
		}
		return nil, publicError("You are not in a clan.")
	}
	return h.mutateClan(r, me, clan.Int("clan_id"))
}

const clanDetailsSQL = `SELECT c.*,COALESCE(s.prefix_color,'FFD166') AS prefix_color,a.username AS owner_username,(SELECT COUNT(*) FROM mucho_clan_members m WHERE m.clan_id=c.clan_id) AS member_count FROM mucho_clans c JOIN accounts a ON a.account_id=c.owner_account_id LEFT JOIN mucho_clan_appearance s ON s.clan_id=c.clan_id`
const clanForSQL = `SELECT c.*,COALESCE(s.prefix_color,'FFD166') AS prefix_color,m.role,(SELECT COUNT(*) FROM mucho_clan_members mc WHERE mc.clan_id=c.clan_id) AS member_count FROM mucho_clan_members m JOIN mucho_clans c ON c.clan_id=m.clan_id LEFT JOIN mucho_clan_appearance s ON s.clan_id=c.clan_id WHERE m.account_id=? LIMIT 1`

func anyInt(value any) int64 {
	switch v := value.(type) {
	case int64:
		return v
	case int:
		return int64(v)
	case string:
		n, _ := strconv.ParseInt(v, 10, 64)
		return n
	}
	return 0
}
func clanFor(ctx context.Context, q dbutil.Querier, id int64) (dbutil.Row, error) {
	row, err := dbutil.One(ctx, q, clanForSQL, id)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	return row, err
}
func clanMember(ctx context.Context, q dbutil.Querier, clan, id int64) (dbutil.Row, error) {
	row, err := dbutil.One(ctx, q, `SELECT m.*,a.username FROM mucho_clan_members m JOIN accounts a ON a.account_id=m.account_id WHERE m.clan_id=? AND m.account_id=? LIMIT 1`, clan, id)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	return row, err
}
func clanMembers(ctx context.Context, q dbutil.Querier, id int64) ([]map[string]any, error) {
	return dbutil.TypedQuery(ctx, q, `SELECT m.account_id,m.role,m.joined_at,a.username,p.user_id,COALESCE(u.nickname_color,'') AS nickname_color FROM mucho_clan_members m JOIN accounts a ON a.account_id=m.account_id LEFT JOIN profiles p ON p.account_id=a.account_id LEFT JOIN mucho_user_appearance u ON u.account_id=a.account_id WHERE m.clan_id=? ORDER BY CASE m.role WHEN 'owner' THEN 0 WHEN 'officer' THEN 1 ELSE 2 END,a.username ASC`, id)
}
func (h *Handler) clanDetails(ctx context.Context, q dbutil.Querier, id int64, members bool) (map[string]any, error) {
	data, err := dbutil.TypedOne(ctx, q, clanDetailsSQL+` WHERE c.clan_id=? LIMIT 1`, id)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, publicError("Clan not found.")
	}
	if err != nil {
		return nil, err
	}
	if members {
		data["members"], err = clanMembers(ctx, q, id)
	}
	return data, err
}
func clanLocked(r request, tx *sql.Tx, id int64) (dbutil.Row, error) {
	clan, err := dbutil.One(r.ctx, tx, `SELECT * FROM mucho_clans WHERE clan_id=? LIMIT 1 FOR UPDATE`, id)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, publicError("Clan not found.")
	}
	if err != nil {
		return nil, err
	}
	// Read member rows only after acquiring the parent lock, using a current
	// locking read so concurrent invitation acceptance cannot overfill a clan.
	rows, err := dbutil.Query(r.ctx, tx, `SELECT account_id FROM mucho_clan_members WHERE clan_id=? FOR UPDATE`, id)
	clan["member_count"] = strconv.Itoa(len(rows))
	return clan, err
}
func clanBanned(r request, q dbutil.Querier, clan, id int64) (bool, error) {
	rows, err := dbutil.Query(r.ctx, q, `SELECT 1 FROM mucho_clan_bans WHERE clan_id=? AND account_id=? AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP()) LIMIT 1`, clan, id)
	return len(rows) > 0, err
}
func clanEvent(r request, tx *sql.Tx, id int64, message string) error {
	if len(message) > 200 {
		message = message[:200]
	}
	_, err := tx.ExecContext(r.ctx, `INSERT INTO mucho_clan_events(clan_id,message) VALUES(?,?)`, id, message)
	return err
}
func clanPlayer(r request, q dbutil.Querier, id int64) string {
	row, err := dbutil.One(r.ctx, q, `SELECT username FROM accounts WHERE account_id=? LIMIT 1`, id)
	if err != nil || row["username"] == "" {
		return "Player"
	}
	return row["username"]
}
func enrollClan(r request, tx *sql.Tx, clan, id int64) error {
	for _, metric := range []string{"stars", "demons"} {
		_, err := tx.ExecContext(r.ctx, `INSERT INTO mucho_clan_quest_members(quest_id,account_id,last_score) SELECT q.quest_id,m.account_id,GREATEST(COALESCE(p.`+metric+`,0),0) FROM mucho_clan_quests q JOIN mucho_clan_members m ON m.clan_id=q.clan_id AND m.account_id=? LEFT JOIN profiles p ON p.account_id=m.account_id WHERE q.clan_id=? AND q.metric=? AND q.status='active' AND q.expires_at>UTC_TIMESTAMP() ON DUPLICATE KEY UPDATE last_score=GREATEST(last_score,VALUES(last_score))`, id, clan, metric)
		if err != nil {
			return err
		}
	}
	return nil
}
func (h *Handler) clanAudit(r request, id int64, action, targetType string, targetID int64, metadata any) {
	if metadata == nil {
		metadata = []any{}
	}
	data, _ := json.Marshal(metadata)
	_, _ = h.db.ExecContext(r.ctx, `INSERT INTO audit_logs(account_id,action,target_type,target_id,metadata) VALUES(?,?,?,?,?)`, id, action, targetType, targetID, data)
}

func (h *Handler) clanLeaderboard(r request) (any, error) {
	metrics := []string{"stars", "demons", "moons", "diamonds", "user_coins", "secret_coins", "creator_points"}
	metric := r.str("metric")
	if metric == "" || metric == "0" {
		metric = "stars"
	}
	valid := false
	var sums []string
	for _, key := range metrics {
		valid = valid || key == metric
		sums = append(sums, `COALESCE(SUM(CASE WHEN a.is_active=1 AND a.is_banned=0 THEN GREATEST(COALESCE(p.`+key+`,0),0) ELSE 0 END),0) AS `+key)
	}
	if !valid {
		return nil, publicError("Invalid clan leaderboard metric.")
	}
	offset, limit := max(0, min(100000, r.int("offset"))), max(1, min(50, r.defaultInt("limit", 20)))
	query := `WITH totals AS (SELECT c.clan_id,c.name,c.tag,COALESCE(MAX(s.prefix_color),'FFD166') AS prefix_color,COUNT(m.account_id) AS member_count,MAX(CASE WHEN m.account_id=? THEN 1 ELSE 0 END) AS is_own,` + strings.Join(sums, ",") + ` FROM mucho_clans c LEFT JOIN mucho_clan_members m ON m.clan_id=c.clan_id LEFT JOIN accounts a ON a.account_id=m.account_id LEFT JOIN profiles p ON p.account_id=m.account_id LEFT JOIN mucho_clan_appearance s ON s.clan_id=c.clan_id GROUP BY c.clan_id,c.name,c.tag),ranked AS (SELECT totals.*,ROW_NUMBER() OVER(ORDER BY ` + metric + ` DESC,clan_id ASC) AS ` + "`rank`" + `,COUNT(*) OVER() AS total_clans FROM totals) SELECT * FROM ranked WHERE (` + "`rank`" + `>? AND ` + "`rank`" + `<=?) OR is_own=1 ORDER BY ` + "`rank`"
	rows, err := dbutil.Query(r.ctx, h.db, query, max(0, r.int("accountID")), offset, offset+limit+1)
	if err != nil {
		return nil, err
	}
	clans := []map[string]any{}
	var own any
	total := int64(0)
	hasMore := false
	for _, row := range rows {
		total = row.Int("total_clans")
		item := map[string]any{"name": row["name"], "tag": row["tag"], "prefix_color": row["prefix_color"], "is_own": row.Int("is_own") != 0}
		for _, key := range append(append([]string{}, metrics...), "clan_id", "rank", "member_count") {
			item[key] = int64(row.Float(key))
		}
		item["score"] = item[metric]
		if row.Int("is_own") != 0 {
			own = item
		}
		rank := row.Int("rank")
		if rank > offset && rank <= offset+limit {
			clans = append(clans, item)
		}
		hasMore = hasMore || rank == offset+limit+1
	}
	if total == 0 {
		if err = h.db.QueryRowContext(r.ctx, `SELECT COUNT(*) FROM mucho_clans`).Scan(&total); err != nil {
			return nil, err
		}
	}
	return map[string]any{"metric": metric, "offset": offset, "limit": limit, "total_clans": total, "has_more": hasMore, "clans": clans, "own_clan": own}, nil
}
