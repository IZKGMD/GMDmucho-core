package protocol

import (
	"encoding/base64"
	"fmt"
	"strings"
	"time"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
)

func ThreadAge(timestamp, now int64) string {
	if timestamp <= 0 {
		return ""
	}
	elapsed := max(0, now-timestamp)
	for _, unit := range []struct {
		seconds int64
		name    string
	}{{31536000, "year"}, {2592000, "month"}, {604800, "week"}, {86400, "day"}, {3600, "hour"}, {60, "minute"}} {
		if elapsed >= unit.seconds {
			amount := elapsed / unit.seconds
			suffix := ""
			if amount != 1 {
				suffix = "s"
			}
			return fmt.Sprintf("%d %s%s", amount, unit.name, suffix)
		}
	}
	return "just now"
}
func ThreadMessages(rows []dbutil.Row) string {
	if len(rows) == 0 {
		return "none"
	}
	items := make([]string, 0, len(rows))
	for _, row := range rows {
		items = append(items, joined(":", row.Int("id"), row.Int("sender_account_id"), base64.StdEncoding.EncodeToString([]byte(row["body"])), Epoch(row["created_at"]), min(1, max(0, row.Int("is_deleted")))))
	}
	return strings.Join(items, "|")
}
func ThreadInvites(rows []dbutil.Row) string {
	items := make([]string, 0, len(rows))
	for _, row := range rows {
		items = append(items, joined(":", row.Int("invitee_account_id"), row.Int("user_id"), base64.StdEncoding.EncodeToString([]byte(row["username"])), row.Int("inviter_account_id"), row.Value("cube", "1"), row.Value("color1", "0"), row.Value("color2", "3"), 0, 0, row.Int("special"), 0, 0, 0, 0, 0, 0, 0))
	}
	return strings.Join(items, "|")
}
func ThreadInbox(rows []dbutil.Row, now time.Time) string {
	if len(rows) == 0 {
		return "none"
	}
	items := make([]string, 0, len(rows))
	for _, row := range rows {
		stamp := Epoch(row["created_at"])
		age := ThreadAge(stamp, now.Unix())
		group := 1
		if row.Int("is_dm") == 1 {
			group = 0
		}
		items = append(items, joined(":", row.Int("thread_id"), row.Int("inviter_account_id"), base64.StdEncoding.EncodeToString([]byte(row["inviter_username"])), base64.StdEncoding.EncodeToString([]byte(row["title"])), group, age, age, "", base64.StdEncoding.EncodeToString([]byte(row["description"])), 0, 0, row.Value("inviter_cube", "1"), row.Int("inviter_color1"), row.Int("inviter_color2"), 0, row.Int("inviter_special"), 0, row["emote"], stamp))
	}
	return strings.Join(items, "|")
}
func ThreadList(rows []dbutil.Row, total, offset, invites int64, now time.Time) string {
	items := make([]string, 0, len(rows))
	for _, row := range rows {
		peer, name, title, description, emote, cube, c1, c2, special, dm := int64(0), "", "", "", "", "0", "0", "0", "0", 0
		if row.Int("is_dm") == 1 {
			dm = 1
			peer = row.Int("peer_account_id")
			name = base64.StdEncoding.EncodeToString([]byte(row["peer_username"]))
			cube = row.Value("peer_cube", "1")
			c1 = row.Value("peer_color1", "0")
			c2 = row.Value("peer_color2", "0")
			special = row.Value("peer_special", "0")
		} else {
			title = base64.StdEncoding.EncodeToString([]byte(row["title"]))
			description = base64.StdEncoding.EncodeToString([]byte(row["description"]))
			emote = row["emote"]
		}
		items = append(items, joined(":", row.Int("id"), peer, name, title, row.Int("unread_count"), row.Int("last_sender"), ThreadAge(row.Int("last_timestamp"), now.Unix()), row.Int("member_count"), base64.StdEncoding.EncodeToString([]byte(row["last_username"])), row.Int("owner_account_id"), row.Int("is_muted"), 0, dm, 0, row.Int("is_pinned"), cube, c1, c2, 0, 0, special, description, emote, row.Int("last_timestamp"), 0, 0))
	}
	return strings.Join(items, "|") + "#" + joined(":", total, offset, 10, invites, 60)
}
func ThreadSnapshot(thread dbutil.Row, messages, members, invites []dbutil.Row) string {
	users := ""
	if len(members) > 0 {
		users = RelationshipUsers(members, true)
	}
	metadata := joined(":", thread.Int("id"), 0, len(members), base64.StdEncoding.EncodeToString([]byte(thread["description"])), 0, thread.Int("has_older_messages"), 0, 0, base64.StdEncoding.EncodeToString([]byte(thread["title"])), thread.Int("is_closed"), 0)
	return strings.Join([]string{ThreadMessages(messages), users, "", ThreadInvites(invites), metadata, "0", "3,1,10,50"}, "#")
}
