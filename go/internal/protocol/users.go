package protocol

import (
	"fmt"
	"math"
	"strings"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
)

func creatorPoints(value float64) int64 {
	if value < 0 {
		return int64(math.Floor(value + 0.5))
	}
	return int64(math.Ceil(value - 0.5))
}
func UserProfile(r dbutil.Row, game, binary int) string {
	badge := 0
	switch r["role_code"] {
	case "moderator":
		badge = 1
	case "elder_moderator", "owner":
		badge = 2
	}
	item := Pairs(1, DisplayName(r.Value("username", "Player"), r["clan_tag"]), 2, r.Value("user_id", r.Value("account_id", "0")), 13, r.Int("secret_coins"), 17, r.Int("user_coins"), 10, r.Int("color1"), 11, r.Value("color2", "3"), 51, r.Int("color3"), 3, r.Int("stars"), 46, r.Int("diamonds"), 52, r.Int("moons"), 4, r.Int("demons"), 8, creatorPoints(r.Float("creator_points")), 18, r.Int("message_state"), 19, r.Int("friend_request_state"), 50, r.Int("comment_history_state"), 20, r["youtube"], 21, r.Value("icon_id", r.Value("cube", "1")), 22, r.Value("ship", "1"), 23, r.Value("ball", "1"), 24, r.Value("ufo", "1"), 25, r.Value("wave", "1"), 26, r.Value("robot", "1"), 28, r.Int("glow"), 43, r.Value("spider", "1"), 48, r.Value("explosion", "1"), 53, r.Value("swing", "1"), 54, r.Value("jetpack", "1"), 30, r.Int("rank"), 16, r.Int("account_id"), 31, r.Int("friend_state"), 44, r["twitter"], 45, r["twitch"], 49, badge, 55, r["demon_info"], 56, r["star_info"], 57, r["platformer_info"], 58, r["discord"], 59, r["instagram"], 60, r["tiktok"], 61, r["custom_link"])
	if r.Int("request_id") > 0 {
		item += Pairs("", 32, r.Int("request_id"), 35, r["request_comment"], 37, r["request_date"])
	}
	if _, ok := r["messages_count"]; ok {
		item += Pairs("", 38, r.Int("messages_count"), 39, r.Int("friend_requests_count"), 40, r.Int("friends_count"))
	}
	if game >= 22 && binary >= 51 {
		for _, f := range []struct {
			key    int
			column string
		}{{67, "gd2209_followers"}, {68, "gd2209_following"}, {69, "gd2209_is_following"}, {66, "gd2209_notifications"}} {
			if _, ok := r[f.column]; ok {
				n := max(0, r.Int(f.column))
				if f.key == 69 && n > 0 {
					n = 1
				}
				item += Pairs("", f.key, n)
			}
		}
	}
	return item + ":29:1"
}
func UserList(rows []dbutil.Row, leaderboard bool, total, offset int) string {
	if len(rows) == 0 {
		if leaderboard {
			return "-1"
		}
		return "#0:0:0"
	}
	items := make([]string, 0, len(rows))
	for _, r := range rows {
		item := Pairs(1, DisplayName(r.Value("username", "Player"), r["clan_tag"]), 2, r.Value("user_id", r.Value("account_id", "0")), 13, r.Int("secret_coins"), 17, r.Int("user_coins"))
		if leaderboard {
			item += Pairs("", 6, r.Int("rank"))
		}
		item += Pairs("", 9, r.Value("icon_id", "1"), 10, r.Int("color1"), 11, r.Value("color2", "3"), 51, r.Int("color3"), 14, r.Int("icon_type"), 15, r.Int("special"), 16, r.Int("account_id"), 3, r.Int("stars"), 8, creatorPoints(r.Float("creator_points")), 4, r.Int("demons"))
		if leaderboard {
			item += Pairs("", 7, r.Int("account_id"))
		}
		item += Pairs("", 46, r.Int("diamonds"), 52, r.Int("moons"))
		items = append(items, item)
	}
	result := strings.Join(items, "|")
	if !leaderboard {
		result += fmt.Sprintf("#%d:%d:10", total, offset)
	}
	return result
}
