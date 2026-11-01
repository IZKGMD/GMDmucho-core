package protocol

import (
	"fmt"
	"strconv"
	"strings"
	"time"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
)

func Pairs(values ...any) string {
	return joined(":", values...)
}
func joined(separator string, values ...any) string {
	v := make([]string, len(values))
	for i, x := range values {
		v[i] = fmt.Sprint(x)
	}
	return strings.Join(v, separator)
}
func DisplayName(name, tag string) string {
	tag = strings.ToUpper(strings.TrimSpace(tag))
	if tag == "" {
		return Username(name)
	}
	prefix := "[" + tag + "]"
	if len(name) > max(1, 20-len(prefix)) {
		name = name[:max(1, 20-len(prefix))]
	}
	return Username(prefix + name)
}
func Date(value string) string {
	t, err := time.ParseInLocation("2006-01-02 15:04:05", value, time.UTC)
	if err != nil {
		return ""
	}
	return t.Format("02/01/2006 ") + strconv.Itoa(t.Hour()) + t.Format(".04")
}
func Message(r dbutil.Row, sent bool) string {
	name, uid, aid := r["username"], r.Int("user_id"), r.Int("account_id")
	tag := r["clan_tag"]
	sender := 0
	if sent {
		name, uid, aid, tag = r["to_username"], r.Int("to_user_id"), r.Int("to_account_id"), r["to_clan_tag"]
		sender = 1
	}
	result := Pairs(6, DisplayName(name, tag), 3, uid, 2, aid, 1, r.Int("id"), 4, r["subject"], 8, r.Int("is_read"), 9, sender)
	if body, ok := r["body"]; ok {
		result += Pairs("", 5, body)
	}
	return result + Pairs("", 7, Date(r["created_at"]))
}
func RelationshipUsers(rows []dbutil.Row, stats bool) string {
	if len(rows) == 0 {
		return "-2"
	}
	items := make([]string, 0, len(rows))
	for _, r := range rows {
		uid := r.Value("user_id", r.Value("account_id", "0"))
		icon := r.Value("icon_id", r.Value("cube", "1"))
		item := Pairs(1, DisplayName(r.Value("username", "Player"), r["clan_tag"]), 2, uid, 9, icon, 10, r.Value("color1", "0"), 11, r.Value("color2", "3"), 14, r.Int("icon_type"), 15, r.Int("special"), 16, r.Int("account_id"), 18, 0, 41, r.Int("is_new"))
		if stats {
			item += Pairs("", 3, r.Int("stars"), 4, r.Int("demons"), 8, int64(r.Float("creator_points")), 13, r.Int("secret_coins"), 17, r.Int("user_coins"), 46, r.Int("diamonds"), 52, r.Int("moons"))
		}
		items = append(items, item)
	}
	return strings.Join(items, "|")
}
func FriendRequests(rows []dbutil.Row, total, offset int, sent bool) string {
	if len(rows) == 0 {
		return "-2"
	}
	items := make([]string, 0, len(rows))
	for _, r := range rows {
		peer := r.Int("account_id")
		if sent {
			peer = r.Int("to_account_id")
		}
		unread := 0
		if r.Int("is_read") == 0 {
			unread = 1
		}
		items = append(items, Pairs(1, DisplayName(r.Value("username", "Player"), r["clan_tag"]), 2, r.Value("user_id", strconv.FormatInt(peer, 10)), 9, r.Value("icon_id", r.Value("cube", "1")), 10, r.Value("color1", "0"), 11, r.Value("color2", "3"), 14, r.Int("icon_type"), 15, r.Int("special"), 16, peer, 32, r.Int("id"), 35, r["comment"], 41, unread, 37, Date(r["created_at"])))
	}
	return fmt.Sprintf("%s#%d:%d:10", strings.Join(items, "|"), total, offset)
}
