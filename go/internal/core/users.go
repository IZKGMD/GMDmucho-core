package core

import (
	"database/sql"
	"errors"
	"fmt"
	"strconv"
	"strings"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
	"github.com/IZKGMD/GMDmucho-core/go/internal/protocol"
)

func (h *Handler) searchUsers(r request) (string, error) {
	query := r.str("str")
	if r.path == "/getgjusersearch" {
		if query == "" {
			query = r.str("search")
		}
		if query == "" {
			query = r.str("query")
		}
		if len(query) > 100 || r.int("page") < 0 || r.int("page") > 1000 {
			return "-1", nil
		}
	}
	if query == "" {
		return "-1", nil
	}
	numeric := int64(-1)
	if digits.MatchString(query) {
		numeric, _ = strconv.ParseInt(query, 10, 64)
	}
	from := ` FROM accounts a LEFT JOIN profiles p ON p.account_id=a.account_id LEFT JOIN roles ar ON ar.id=a.role_id WHERE (a.username LIKE ? OR COALESCE(p.user_id,0)=?)`
	rows, err := dbutil.Query(r.ctx, h.db, `SELECT p.*,a.account_id,a.username,COALESCE(ar.code,'user') AS role_code,`+clanTagSQL+from+` ORDER BY COALESCE(p.stars,0) DESC LIMIT 10 OFFSET ?`, "%"+query+"%", numeric, r.page()*10)
	if err != nil {
		return "", err
	}
	if len(rows) == 0 {
		return "-1", nil
	}
	var total int
	err = h.db.QueryRowContext(r.ctx, "SELECT COUNT(*)"+from, "%"+query+"%", numeric).Scan(&total)
	return protocol.UserList(rows, false, total, r.page()*10), err
}
func (h *Handler) userProfile(r request) (string, error) {
	target := r.int("targetAccountID")
	if target <= 0 {
		target = r.int("accountID")
	}
	uid := r.int("targetUserID")
	if uid <= 0 {
		uid = r.int("userID")
	}
	if target <= 0 && uid > 0 {
		if err := h.db.QueryRowContext(r.ctx, `SELECT account_id FROM profiles WHERE user_id=? LIMIT 1`, uid).Scan(&target); err != nil {
			return "", err
		}
	}
	if target <= 0 {
		return "-1", nil
	}
	viewer := r.int("accountID")
	if viewer > 0 {
		if _, err := h.auth(r); err != nil {
			return "", err
		}
	}
	var existingUser int64
	err := h.db.QueryRowContext(r.ctx, `SELECT user_id FROM profiles WHERE account_id=? LIMIT 1`, target).Scan(&existingUser)
	if errors.Is(err, sql.ErrNoRows) {
		_, err = h.db.ExecContext(r.ctx, `INSERT IGNORE INTO profiles(account_id) SELECT account_id FROM accounts WHERE account_id=?`, target)
	}
	if err != nil {
		return "", err
	}
	profile, err := dbutil.One(r.ctx, h.db, `SELECT p.*,a.account_id,a.username,COALESCE(ar.code,'user') AS role_code,a.messages_state AS message_state,a.friend_requests_state AS friend_request_state,a.comments_state AS comment_history_state,a.youtube_url AS youtube,a.twitter,a.twitch,a.instagram,a.discord,a.tiktok,a.custom_link,`+clanTagSQL+` FROM accounts a LEFT JOIN profiles p ON p.account_id=a.account_id LEFT JOIN roles ar ON ar.id=a.role_id WHERE a.account_id=? LIMIT 1`, target)
	if err != nil {
		return "", err
	}
	if viewer > 0 {
		if viewer == target {
			for key, query := range map[string]string{"messages_count": `SELECT COUNT(*) FROM messages WHERE to_account_id=? AND is_read=0 AND is_receiver_deleted=0`, "friend_requests_count": `SELECT COUNT(*) FROM friend_requests WHERE to_account_id=?`, "friends_count": `SELECT COUNT(*) FROM friends WHERE account_id=? AND is_new=1`} {
				var count int
				if err := h.db.QueryRowContext(r.ctx, query, viewer).Scan(&count); err == nil {
					profile[key] = strconv.Itoa(count)
				}
			}
		} else {
			if b, err := blocked(r.ctx, h.db, viewer, target); err != nil || b {
				return "-1", err
			}
			if yes, err := h.isFriend(r, viewer, target); err != nil {
				return "", err
			} else if yes {
				profile["friend_state"] = "1"
			} else {
				incoming, err := dbutil.One(r.ctx, h.db, `SELECT id,comment,created_at FROM friend_requests WHERE account_id=? AND to_account_id=? LIMIT 1`, target, viewer)
				if err == nil {
					profile["friend_state"], profile["request_id"], profile["request_comment"], profile["request_date"] = "3", incoming["id"], incoming["comment"], protocol.Date(incoming["created_at"])
				} else if errors.Is(err, sql.ErrNoRows) {
					var n int
					if err = h.db.QueryRowContext(r.ctx, `SELECT COUNT(*) FROM friend_requests WHERE account_id=? AND to_account_id=?`, viewer, target).Scan(&n); err == nil && n > 0 {
						profile["friend_state"] = "4"
					}
				}
			}
		}
	}
	if r.version.Effective() >= 22 && r.version.Binary >= 51 {
		var followers, following, isFollowing, notifications int
		err = h.db.QueryRowContext(r.ctx, `SELECT (SELECT COUNT(*) FROM mucho_follows WHERE followed_account_id=?),(SELECT COUNT(*) FROM mucho_follows WHERE follower_account_id=?),(SELECT COUNT(*) FROM mucho_follows WHERE follower_account_id=? AND followed_account_id=?),(SELECT COUNT(*) FROM mucho_notifications WHERE account_id=? AND is_read=0)`, target, target, viewer, target, viewer).Scan(&followers, &following, &isFollowing, &notifications)
		if err != nil {
			return "", err
		}
		profile["gd2209_followers"], profile["gd2209_is_following"] = strconv.Itoa(followers), strconv.Itoa(isFollowing)
		if viewer == target {
			profile["gd2209_following"], profile["gd2209_notifications"] = strconv.Itoa(following), strconv.Itoa(notifications)
		}
	}
	return protocol.UserProfile(profile, r.version.Effective(), r.version.Binary), nil
}
func (h *Handler) userScores(r request) (string, error) {
	typ := r.str("type")
	id := r.int("accountID")
	if id > 0 {
		if _, err := h.auth(r); err != nil {
			return "", err
		}
	} else if typ == "friends" || typ == "relative" {
		return "-1", nil
	}
	return h.leaderboard(r, typ, id, 100)
}
func (h *Handler) creators(r request) (string, error) { return h.leaderboard(r, "creators", 0, 100) }
func (h *Handler) leaderboard(r request, typ string, id int64, limit int) (string, error) {
	metric := "stars"
	if typ == "creators" {
		metric = "creator_points"
	}
	filter := `p.` + metric + `>0 AND a.is_active=1 AND a.is_banned=0`
	game := r.version.Effective()
	if r.path == "/getgjcreators" || r.path == "/getgjcreators19" {
		game = 0
	}
	if typ == "relative" {
		filter = "1=1"
	}
	if game >= 20 {
		filter += " AND COALESCE(p.game_version,0)>=20"
	} else if game > 0 && typ != "friends" {
		filter += " AND COALESCE(p.game_version,0)>0 AND COALESCE(p.game_version,0)<20"
	}
	var args []any
	if typ == "friends" {
		filter = `a.is_active=1 AND a.is_banned=0`
		if game >= 20 {
			filter += " AND COALESCE(p.game_version,0)>=20"
		}
		filter += ` AND (p.account_id=? OR p.account_id IN (SELECT friend_account_id FROM friends WHERE account_id=? UNION SELECT account_id FROM friends WHERE friend_account_id=?))`
		args = []any{id, id, id}
	}
	// rank is escaped because MySQL 8 reserves that identifier.
	base := `SELECT p.*,a.username,COALESCE(ar.code,'user') AS role_code,` + clanTagSQL + `,ROW_NUMBER() OVER(ORDER BY p.` + metric + ` DESC,p.account_id ASC) AS ` + "`rank`" + ` FROM profiles p JOIN accounts a ON a.account_id=p.account_id LEFT JOIN roles ar ON ar.id=a.role_id WHERE ` + filter
	query := base + " ORDER BY `rank` LIMIT ?"
	if typ == "relative" {
		query = "WITH ranked AS (" + base + ") SELECT r.* FROM ranked r JOIN (SELECT `rank` AS target_rank FROM ranked WHERE account_id=?) t ON 1=1 WHERE r.`rank` BETWEEN t.target_rank-25 AND t.target_rank+25 LIMIT 50"
		args = append(args, id)
	} else {
		args = append(args, limit)
	}
	rows, err := dbutil.Query(r.ctx, h.db, query, args...)
	if err != nil {
		return "", err
	}
	if typ == "relative" && len(rows) == 0 {
		return h.leaderboard(r, "top", id, 50)
	}
	return protocol.UserList(rows, true, 0, 0), nil
}
func (h *Handler) requestAccess(r request) (string, error) {
	me, err := h.auth(r)
	if err != nil {
		return "", err
	}
	var role string
	err = h.db.QueryRowContext(r.ctx, `SELECT ro.code FROM accounts a JOIN roles ro ON ro.id=a.role_id WHERE a.account_id=? LIMIT 1`, me.ID).Scan(&role)
	if err != nil {
		return "", err
	}
	switch role {
	case "owner", "elder_moderator":
		return "2", nil
	case "moderator":
		return "1", nil
	}
	return "-1", nil
}
func (h *Handler) updateSettings(r request) (string, error) {
	me, err := h.auth(r)
	if err != nil {
		return "", err
	}
	var args []any
	for _, key := range []string{"mS", "frS", "cS"} {
		args = append(args, max(0, min(2, r.int(key))))
	}
	for _, f := range []struct {
		key   string
		limit int
	}{{"yt", 255}, {"twitter", 64}, {"twitch", 64}, {"instagram", 64}, {"discord", 64}, {"tiktok", 64}, {"custom", 255}} {
		v := strings.TrimSpace(r.str(f.key))
		if len(v) > f.limit {
			v = v[:f.limit]
		}
		args = append(args, v)
	}
	args = append(args, me.ID)
	_, err = h.db.ExecContext(r.ctx, `UPDATE accounts SET messages_state=?,friend_requests_state=?,comments_state=?,youtube_url=?,twitter=?,twitch=?,instagram=?,discord=?,tiktok=?,custom_link=? WHERE account_id=?`, args...)
	return "1", err
}

func (h *Handler) updateUserScore(r request) (string, error) {
	var id int64
	if r.credential() != "" {
		me, err := h.auth(r)
		if err != nil {
			return "", err
		}
		id = me.ID
	} else if r.int("accountID") <= 0 && r.version.Effective() > 0 && r.version.Effective() < 19 {
		var err error
		id, err = h.accounts.LegacyIdentity(r.ctx, r.str("udid"), r.str("userName"), r.ip)
		if err != nil {
			return "", err
		}
	} else {
		return "-1", nil
	}
	if r.int("gameVersion") == 0 {
		game := r.version.Effective()
		if game == 0 {
			game = 1
		}
		r.form.Set("gameVersion", strconv.Itoa(game))
	}
	fields := map[string]any{}
	for _, f := range []struct {
		column, keys        string
		fallback, low, high int64
	}{
		{"game_version", "gameVersion", 22, 0, 1000}, {"binary_version", "binaryVersion", 0, 0, 10000}, {"stars", "stars", 0, 0, 10000000}, {"moons", "moons", 0, 0, 10000000}, {"demons", "demons", 0, 0, 1000000}, {"diamonds", "diamonds", 0, 0, 100000000}, {"secret_coins", "coins", 0, 0, 1000000}, {"user_coins", "userCoins", 0, 0, 1000000}, {"icon_id", "icon,accIcon", 1, 0, 65535}, {"icon_type", "iconType", 0, 0, 32}, {"cube", "accIcon", 1, 0, 65535}, {"ship", "accShip", 1, 0, 65535}, {"ball", "accBall", 1, 0, 65535}, {"ufo", "accBird", 1, 0, 65535}, {"wave", "accDart", 1, 0, 65535}, {"robot", "accRobot", 1, 0, 65535}, {"spider", "accSpider", 1, 0, 65535}, {"swing", "accSwing", 1, 0, 65535}, {"jetpack", "accJetpack", 1, 0, 65535}, {"explosion", "accExplosion", 1, 0, 65535}, {"color1", "color1", 0, 0, 65535}, {"color2", "color2", 3, 0, 65535}, {"color3", "color3", 0, -1, 32767}, {"special", "special,accSpecial", 0, 0, 1}, {"glow", "accGlow,glow", 0, 0, 65535},
	} {
		value := f.fallback
		for _, key := range strings.Split(f.keys, ",") {
			if _, ok := r.form[key]; ok {
				if r.str(key) != "" {
					text := r.str(key)
					if len(strings.TrimPrefix(text, "-")) > 10 {
						return "-1", nil
					}
					n, err := r.bounded(key, f.fallback, f.low, f.high)
					if err != nil {
						return "", err
					}
					value = n
				}
				break
			}
		}
		fields[f.column] = value
	}
	for column, key := range map[string]string{"demon_info": "dinfo", "star_info": "sinfo", "platformer_info": "pinfo"} {
		value, err := r.limited(key, "", 65535)
		if err != nil {
			return "", err
		}
		fields[column] = value
	}
	if r.version.Effective() >= 20 && r.version.Effective() <= 21 {
		if err := h.normalizeLegacyScore(r, fields); err != nil {
			return "", err
		}
	}
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return "", err
	}
	defer tx.Rollback()
	clanID, err := lockScoreClan(r, tx, id)
	if err != nil {
		return "", err
	}
	var keys, marks, updates []string
	var args []any
	// Fields come from the static server whitelist, never client identifiers.
	for key, value := range fields {
		keys = append(keys, "`"+key+"`")
		marks = append(marks, "?")
		updates = append(updates, "`"+key+"`=VALUES(`"+key+"`)")
		args = append(args, value)
	}
	args = append([]any{id}, args...)
	_, err = tx.ExecContext(r.ctx, `INSERT INTO profiles(account_id,`+strings.Join(keys, ",")+`) VALUES(?,`+strings.Join(marks, ",")+`) ON DUPLICATE KEY UPDATE `+strings.Join(updates, ","), args...)
	if err != nil {
		return "", err
	}
	if clanID > 0 {
		if err = settleScoreClan(r, tx, clanID); err != nil {
			return "", err
		}
	}
	var uid int64
	err = tx.QueryRowContext(r.ctx, `SELECT user_id FROM profiles WHERE account_id=? LIMIT 1`, id).Scan(&uid)
	if err != nil {
		return "", err
	}
	return strconv.FormatInt(uid, 10), tx.Commit()
}

func (h *Handler) normalizeLegacyScore(r request, fields map[string]any) error {
	if raw := strings.TrimSpace(r.str("dinfo")); raw != "" {
		ids := idList(raw, 1000)
		if len(ids) > 0 {
			marks := make([]string, len(ids))
			args := make([]any, len(ids))
			for i, id := range ids {
				marks[i] = "?"
				args[i] = id
			}
			levels, err := dbutil.Query(r.ctx, h.db, `SELECT demon,demon_difficulty,length FROM levels WHERE level_id IN (`+strings.Join(marks, ",")+")", args...)
			if err != nil {
				return err
			}
			counts := make([]int64, 12)
			for _, level := range levels {
				if level.Int("demon") != 1 {
					continue
				}
				index, ok := map[int64]int{3: 0, 4: 1, 0: 2, 5: 3, 6: 4}[level.Int("demon_difficulty")]
				if !ok {
					continue
				}
				if level.Int("length") == 5 {
					index += 5
				}
				counts[index]++
			}
			counts[10] = max(0, min(1000000, r.int("dinfow")))
			counts[11] = max(0, min(1000000, r.int("dinfog")))
			sum := int64(0)
			for _, n := range counts {
				sum += n
			}
			counts[0] += max(0, min(3, fields["demons"].(int64)-sum))
			fields["demon_info"] = joinInts(counts)
		}
	}
	parts := strings.Split(strings.TrimSpace(r.str("sinfo")), ",")
	if len(parts) >= 12 {
		values := make([]int64, 12)
		valid := true
		for i := range values {
			v := strings.TrimSpace(parts[i])
			if !digits.MatchString(v) || len(v) > 10 {
				valid = false
				break
			}
			values[i], _ = strconv.ParseInt(v, 10, 64)
		}
		if valid {
			fields["star_info"] = joinInts(append(values[:6:6], max(0, min(1000000, r.int("sinfod"))), max(0, min(1000000, r.int("sinfog")))))
			fields["platformer_info"] = joinInts(append(values[6:], 0))
		}
	}
	return nil
}
func joinInts(values []int64) string {
	parts := make([]string, len(values))
	for i, n := range values {
		parts[i] = strconv.FormatInt(n, 10)
	}
	return strings.Join(parts, ",")
}
func lockScoreClan(r request, tx *sql.Tx, id int64) (int64, error) {
	var clanID int64
	err := tx.QueryRowContext(r.ctx, `SELECT clan_id FROM mucho_clan_members WHERE account_id=?`, id).Scan(&clanID)
	if errors.Is(err, sql.ErrNoRows) {
		return 0, nil
	}
	if err != nil {
		return 0, err
	}
	if err = tx.QueryRowContext(r.ctx, `SELECT clan_id FROM mucho_clans WHERE clan_id=? FOR UPDATE`, clanID).Scan(&clanID); err != nil {
		return 0, err
	}
	if err = tx.QueryRowContext(r.ctx, `SELECT clan_id FROM mucho_clan_members WHERE account_id=? AND clan_id=? FOR UPDATE`, id, clanID).Scan(&clanID); errors.Is(err, sql.ErrNoRows) {
		return 0, nil
	}
	return clanID, err
}
func settleScoreClan(r request, tx *sql.Tx, clanID int64) error {
	quests, err := dbutil.Query(r.ctx, tx, `SELECT * FROM mucho_clan_quests WHERE clan_id=? AND status='active' AND expires_at>UTC_TIMESTAMP() ORDER BY quest_id FOR UPDATE`, clanID)
	if err != nil {
		return err
	}
	for _, quest := range quests {
		metric := quest["metric"]
		if metric != "stars" && metric != "demons" {
			return errors.New("invalid quest metric")
		}
		scores, err := dbutil.Query(r.ctx, tx, `SELECT s.account_id,s.last_score,GREATEST(COALESCE(p.`+metric+`,0),0) AS score FROM mucho_clan_quest_members s JOIN mucho_clan_members m ON m.account_id=s.account_id AND m.clan_id=? JOIN accounts a ON a.account_id=s.account_id AND a.is_active=1 AND a.is_banned=0 LEFT JOIN profiles p ON p.account_id=s.account_id WHERE s.quest_id=? FOR UPDATE`, clanID, quest.Int("quest_id"))
		if err != nil {
			return err
		}
		delta := int64(0)
		for _, score := range scores {
			gain := max(0, score.Int("score")-score.Int("last_score"))
			if gain > 0 {
				delta += gain
				_, err = tx.ExecContext(r.ctx, `UPDATE mucho_clan_quest_members SET last_score=?,contribution=contribution+? WHERE quest_id=? AND account_id=?`, score.Int("score"), gain, quest.Int("quest_id"), score.Int("account_id"))
				if err != nil {
					return err
				}
			}
		}
		if delta == 0 {
			continue
		}
		progress := quest.Int("progress") + delta
		status := "active"
		completed := 0
		if progress >= quest.Int("target") {
			status = "completed"
			completed = 1
		}
		_, err = tx.ExecContext(r.ctx, `UPDATE mucho_clan_quests SET progress=?,status=?,completed_at=IF(?=1,UTC_TIMESTAMP(),NULL) WHERE quest_id=?`, progress, status, completed, quest.Int("quest_id"))
		if err != nil {
			return err
		}
		if completed == 1 {
			_, err = tx.ExecContext(r.ctx, `INSERT INTO mucho_clan_events(clan_id,message) VALUES(?,?)`, clanID, fmt.Sprintf("Quest completed: %d %s", quest.Int("target"), metric))
			if err != nil {
				return err
			}
		}
	}
	return nil
}
