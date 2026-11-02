package protocol

import "github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"

func Comment(r dbutil.Row, game, binary int, account bool) string {
	fields := []any{"2", CommentResponse(r["content"], game), "3", r.Value("user_id", r.Value("account_id", "0")), "4", r.Value("likes", "0"), "5", "0", "7", r.Value("is_spam", "0"), "9", Date(r["created_at"]), "6", r["id"]}
	if !account {
		fields = append(fields, "10", r.Value("percent", "0"))
	}
	if game >= 22 && binary >= 51 {
		fields = append(fields, "15", Epoch(r["created_at"]))
	}
	if !account && (binary > 31 || game >= 22) {
		badge := 0
		switch r["role"] {
		case "owner", "admin", "elder_moderator":
			badge = 2
		case "moderator":
			badge = 1
		}
		fields = append(fields, "11", badge)
		if badge > 0 {
			fields = append(fields, "12", r.Value("comment_color", "255,255,255"))
		}
		user := joined("~", "1", DisplayName(r.Value("username", "Player"), r["clan_tag"]), "7", "1", "9", r.Value("cube", "1"), "10", r.Value("color1", "0"), "11", r.Value("color2", "3"), "14", r.Value("icon_type", "0"), "15", r.Value("special", "0"), "16", r.Value("account_id", "0"))
		return joined("~", fields...) + ":" + user
	}
	return joined("~", fields...)
}
