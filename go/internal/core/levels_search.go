package core

import (
	"fmt"
	"regexp"
	"strconv"
	"strings"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
	"github.com/IZKGMD/GMDmucho-core/go/internal/protocol"
)

var listSplit = regexp.MustCompile(`[,\s]+`)
var digits = regexp.MustCompile(`^[0-9]+$`)

func idList(value string, limit int) []int64 {
	var ids []int64
	seen := map[int64]bool{}
	for _, part := range listSplit.Split(strings.TrimSpace(value), -1) {
		if digits.MatchString(part) {
			id, _ := strconv.ParseInt(part, 10, 64)
			if id > 0 && !seen[id] {
				ids = append(ids, id)
				seen[id] = true
			}
		}
		if len(ids) >= limit {
			break
		}
	}
	return ids
}

func (h *Handler) levelList(r request) (string, error) {
	typ := r.int("type")
	game := (protocol.Version{Game: int(max(0, r.int("gameVersion"))), Binary: int(max(0, r.int("binaryVersion")))}).Effective()
	search := strings.TrimSpace(r.str("str"))
	if len(search) > 100 {
		return "-1", nil
	}
	if typ == 8 || typ == 27 || typ == 13 {
		me, err := h.auth(r)
		if err != nil {
			return "", err
		}
		if typ != 13 {
			var role string
			err = h.db.QueryRowContext(r.ctx, `SELECT ro.code FROM accounts a JOIN roles ro ON ro.id=a.role_id WHERE a.account_id=?`, me.ID).Scan(&role)
			if err != nil {
				return "", err
			}
			if role != "moderator" && role != "elder_moderator" && role != "owner" {
				return "-1", nil
			}
		}
	}
	where := []string{"l.is_deleted=0", "l.is_unlisted=0", "l.unlisted2=0"}
	args := []any{}
	join := ""
	order := "l.created_at DESC,l.level_id DESC"
	var fixed []int64
	add := func(condition string, values ...any) {
		where = append(where, condition)
		args = append(args, values...)
	}
	in := func(column string, ids []int64, exclude bool) {
		if len(ids) == 0 {
			return
		}
		marks := make([]string, len(ids))
		for i, id := range ids {
			marks[i] = "?"
			args = append(args, id)
		}
		op := " IN "
		if exclude {
			op = " NOT IN "
		}
		where = append(where, column+op+"("+strings.Join(marks, ",")+")")
	}
	if game > 0 {
		add("l.game_version<=?", game)
	}
	demon := r.int("demonFilter")
	if demon > 0 {
		add("l.demon=1")
		difficulty := map[int64]int{1: 3, 7: 3, 2: 4, 8: 4, 3: 0, 6: 0, 11: 0, 4: 5, 9: 5, 5: 6, 10: 6, 12: 8, 13: 9, 14: 10}
		if v, ok := difficulty[demon]; ok {
			add("COALESCE(l.demon_difficulty,0)=?", v)
		} else {
			add("1=0")
		}
	}
	diff := r.str("diff")
	switch diff {
	case "-2":
		if demon == 0 {
			add("l.demon=1")
		}
	case "-1":
		add("l.difficulty=0 AND l.demon=0")
	case "-3":
		add("l.auto_level=1")
	case "", "-":
	default:
		ids := idList(diff, 20)
		for i, v := range ids {
			if v >= 10 && v%10 == 0 {
				ids[i] = v / 10
			}
		}
		in("l.difficulty", ids, false)
	}
	for key, condition := range map[string]string{"original": "l.original_level_id=0", "coins": "l.coins_verified=1 AND l.coins<>0", "twoPlayer": "l.two_player=1", "star": "l.stars>0", "noStar": "l.stars=0", "featured": "l.featured>0", "epic": "l.epic=1", "mythic": "l.epic=2", "legendary": "l.epic=3"} {
		if r.str(key) == "1" {
			add(condition)
		}
	}
	if length := r.str("len"); length != "-" {
		in("l.length", idList(length, 10), false)
	}
	searchFilter := func() {
		if search != "" {
			if digits.MatchString(search) {
				add("l.level_id=?", search)
			} else {
				add("l.name LIKE ?", "%"+search+"%")
			}
		}
	}
	switch typ {
	case 0, 15:
		order = "l.likes DESC,l.level_id DESC"
		searchFilter()
	case 1:
		order = "l.downloads DESC,l.level_id DESC"
	case 2:
		order = "l.likes DESC,l.level_id DESC"
	case 3:
		add("l.created_at >= CURRENT_TIMESTAMP - INTERVAL 7 DAY")
		add("NOT EXISTS(SELECT 1 FROM mucho_gd2209_restrictions ban WHERE ban.account_id=l.account_id AND ban.kind='trending' AND (ban.expires_at IS NULL OR ban.expires_at>UTC_TIMESTAMP()))")
		order = "l.likes DESC,l.level_id DESC"
	case 5:
		if digits.MatchString(search) {
			add("(p.user_id=? OR l.account_id=?)", search, search)
		}
	case 6, 17:
		if game > 21 {
			add("(l.featured>0 OR l.epic>0)")
		} else {
			add("l.featured>0")
		}
		order = "l.updated_at DESC,l.level_id DESC"
	case 7:
		add("l.object_count>9999")
		order = "l.downloads DESC,l.level_id DESC"
	case 10, 19:
		fixed = idList(search, 100)
		if len(fixed) == 0 {
			return "-2", nil
		}
		in("l.level_id", fixed, false)
	case 11:
		add("l.stars>0")
		order = "l.updated_at DESC,l.level_id DESC"
	case 12:
		ids := idList(r.str("followed"), 500)
		if len(ids) == 0 {
			return "-2", nil
		}
		in("l.account_id", ids, false)
	case 13:
		rows, err := dbutil.Query(r.ctx, h.db, `SELECT friend_account_id AS id FROM friends WHERE account_id=? UNION SELECT account_id AS id FROM friends WHERE friend_account_id=?`, r.int("accountID"), r.int("accountID"))
		if err != nil {
			return "", err
		}
		var ids []int64
		for _, row := range rows {
			if row.Int("id") > 0 {
				ids = append(ids, row.Int("id"))
			}
		}
		if len(ids) == 0 {
			return "-2", nil
		}
		in("l.account_id", ids, false)
	case 16:
		add("l.epic>0")
		order = "l.updated_at DESC,l.level_id DESC"
	case 21, 22, 23:
		kind := map[int64]string{21: "daily", 22: "weekly", 23: "event"}[typ]
		join = " JOIN mucho_daily_rotation mdr ON mdr.level_id=l.level_id AND mdr.enabled=1 AND mdr.kind='" + kind + "'"
		order = "mdr.id DESC"
	case 25, 26:
		fixed = idList(search, 1000)
		if len(fixed) == 1 {
			var value string
			if err := h.db.QueryRowContext(r.ctx, `SELECT level_ids FROM mucho_level_lists WHERE list_id=? LIMIT 1`, fixed[0]).Scan(&value); err == nil && value != "" {
				fixed = idList(value, 1000)
			}
		}
		if len(fixed) == 0 {
			return "-2", nil
		}
		in("l.level_id", fixed, false)
		if typ == 26 {
			where[1] = "1=1"
		}
	case 8, 27:
		filter := ""
		if typ == 27 {
			filter = fmt.Sprintf(" AND ms.account_id=%d", r.int("accountID"))
		} else {
			add("l.stars=0")
		}
		add("EXISTS(SELECT 1 FROM moderation_suggestions ms WHERE ms.level_id=l.level_id" + filter + ")")
		order = "(SELECT MAX(ms.id) FROM moderation_suggestions ms WHERE ms.level_id=l.level_id" + filter + ") DESC,l.level_id DESC"
		searchFilter()
	}
	if r.str("uncompleted") == "1" || r.str("onlyCompleted") == "1" {
		in("l.level_id", idList(r.str("completedLevels"), 1000), r.str("uncompleted") == "1")
	}
	if song := r.str("song"); song != "" && song != "0" {
		if r.str("customSong") == "1" {
			add("l.song_id=?", max(0, r.int("song")))
		} else {
			add("l.audio_track=? AND l.song_id=0", max(0, r.int("song")-1))
		}
	}
	if r.str("gauntlet") != "" {
		g, err := dbutil.One(r.ctx, h.db, `SELECT level1,level2,level3,level4,level5 FROM mucho_gauntlets WHERE id=? AND enabled=1 LIMIT 1`, r.int("gauntlet"))
		if err == nil {
			var ids []int64
			for i := 1; i <= 5; i++ {
				if id := g.Int(fmt.Sprintf("level%d", i)); id > 0 {
					ids = append(ids, id)
				}
			}
			if len(ids) > 0 {
				fixed = ids
				in("l.level_id", ids, false)
			}
		}
	}
	if len(fixed) > 0 {
		v := make([]string, len(fixed))
		for i, id := range fixed {
			v[i] = strconv.FormatInt(id, 10)
		}
		order = "FIELD(l.level_id," + strings.Join(v, ",") + ")"
	}
	from := ` FROM levels l ` + join + ` LEFT JOIN accounts a ON a.account_id=l.account_id LEFT JOIN profiles p ON p.account_id=l.account_id LEFT JOIN songs s ON s.id=l.song_id AND s.is_verified=1 WHERE ` + strings.Join(where, " AND ")
	requested := "l.requested_stars"
	if typ == 8 || typ == 27 {
		filter := ""
		if typ == 27 {
			filter = fmt.Sprintf(" AND ms.account_id=%d", r.int("accountID"))
		}
		requested = "COALESCE((SELECT ms.stars FROM moderation_suggestions ms WHERE ms.level_id=l.level_id" + filter + " ORDER BY ms.id DESC LIMIT 1),l.requested_stars)"
	}
	selectSQL := `SELECT l.level_id,l.account_id,l.name,l.description,l.level_version,l.game_version,l.length,l.difficulty,l.downloads,l.likes,l.audio_track,l.demon,l.demon_difficulty,l.auto_level,l.stars,l.featured,l.epic,l.object_count,l.original_level_id,l.two_player,l.coins,l.coins_verified,l.ldm,l.song_id,l.created_at,l.updated_at,` + requested + ` AS requested_stars,COALESCE(a.username,'Player') AS username,COALESCE(p.user_id,l.account_id) AS user_id,s.id AS song_protocol_id,s.name AS song_name,s.author_id AS song_author_id,s.author_name AS song_author_name,s.size AS song_size,s.download_url AS song_download_url,s.youtube_video_id AS song_youtube_video_id,s.youtube_channel_id AS song_youtube_channel_id`
	queryArgs := append(append([]any{}, args...), r.page()*10)
	rows, err := dbutil.Query(r.ctx, h.db, selectSQL+from+" ORDER BY "+order+" LIMIT 10 OFFSET ?", queryArgs...)
	if err != nil {
		return "", err
	}
	total := len(rows)
	if r.page() > 0 || len(rows) == 10 || join != "" {
		if err = h.db.QueryRowContext(r.ctx, "SELECT COUNT(*)"+from, args...).Scan(&total); err != nil {
			return "", err
		}
	}
	return protocol.LevelList(rows, total, r.page()*10, game, r.version.Binary), nil
}
