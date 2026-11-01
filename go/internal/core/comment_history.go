package core

import (
	"fmt"
	"strings"
	"time"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
	"github.com/IZKGMD/GMDmucho-core/go/internal/protocol"
)

func (h *Handler) commentHistory(r request) (string, error) {
	id := r.v71Int("userID", 0)
	if id <= 0 {
		id = r.v71Int("accountID", 0)
	}
	if id <= 0 {
		return "-1", nil
	}
	game, binary := max(20, r.v71Int("gameVersion", 22)), r.v71Int("binaryVersion", 0)
	count := max(1, min(50, r.v71Int("count", 10)))
	offset := max(0, min(1000, r.v71Int("page", 0))) * count
	const from = ` FROM comments c LEFT JOIN levels l ON l.level_id=c.level_id WHERE c.account_id=? AND COALESCE(l.is_deleted,0)=0 AND COALESCE(l.is_unlisted,0)=0 AND COALESCE(l.unlisted2,0)=0`
	var total int64
	if err := h.db.QueryRowContext(r.ctx, `SELECT COUNT(*)`+from, id).Scan(&total); err != nil {
		return "", err
	}
	if total == 0 {
		return "-2", nil
	}
	order := "c.id DESC"
	if r.v71Int("mode", 0) != 0 {
		order = "c.likes DESC,c.id DESC"
	}
	rows, err := dbutil.Query(r.ctx, h.db, `SELECT c.*,UNIX_TIMESTAMP(c.created_at) AS timestamp,COALESCE(a.username,'Unknown') AS username FROM comments c LEFT JOIN accounts a ON a.account_id=c.account_id LEFT JOIN levels l ON l.level_id=c.level_id WHERE c.account_id=? AND COALESCE(l.is_deleted,0)=0 AND COALESCE(l.is_unlisted,0)=0 AND COALESCE(l.unlisted2,0)=0 ORDER BY `+order+` LIMIT ? OFFSET ?`, id, count, offset)
	if err != nil {
		return "", err
	}
	comments, users := []string{}, []string{}
	seen := map[string]bool{}
	for _, row := range rows {
		stamp := row.Int("timestamp")
		date := "01/01/1970 0.00"
		if stamp > 0 {
			date = time.Unix(stamp, 0).UTC().Format("02/01/2006 15.04")
		}
		base := fmt.Sprintf("1~%s~2~%s~3~%s~4~%s~5~0~7~%s~9~%s~6~%s~10~%s", row["level_id"], protocol.CommentResponse(row["content"], int(game)), row["account_id"], row["likes"], row["is_spam"], date, row["id"], row["percent"])
		if game >= 22 && binary >= 51 {
			base += fmt.Sprintf("~15~%d", stamp)
		}
		name := strings.ReplaceAll(protocolField(row["username"]), "~", "")
		if name != "" {
			if binary > 31 {
				base += "~11~0:1~" + name + "~7~1~9~0~10~0~11~3~14~0~15~0~16~" + row["account_id"]
			} else if !seen[row["account_id"]] {
				seen[row["account_id"]] = true
				users = append(users, row["account_id"]+":"+name+":"+row["account_id"])
			}
		}
		comments = append(comments, base)
	}
	out := strings.Join(comments, "|")
	if binary < 32 {
		out += "#" + strings.Join(users, "|")
	}
	return fmt.Sprintf("%s#%d:%d:%d", out, total, offset, count), nil
}
