package core

import (
	"encoding/json"
	"strconv"
	"strings"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
)

const commandHelp = "MuchoCore commands: !help / !rate <difficulty> <stars> [coins] [featured] / !r <difficulty> <stars> [coins] [featured] / !demon <1-5> / !feature / !epic / !unepic / !verifycoins / !delete / !cp <amount>"

func commandReply(game int, yes bool, message string) string {
	if game >= 21 {
		if message == "" {
			if yes {
				message = "Command executed successfully!"
			} else {
				message = "Command failed!"
			}
		}
		return "temp_0_" + message
	}
	if yes {
		return "1"
	}
	return "-1"
}
func normalizeRole(role string) string {
	switch strings.ToLower(strings.TrimSpace(role)) {
	case "owner":
		return "owner"
	case "elder_moderator", "admin", "elder", "developer":
		return "elder_moderator"
	case "moderator", "mod", "helper":
		return "moderator"
	}
	return "user"
}
func (h *Handler) commentCommand(r request, id int64, content string) (string, error) {
	parts := strings.Fields(content)
	cmd := strings.ToLower(parts[0])
	if cmd == "!help" {
		return commandReply(r.version.Effective(), true, commandHelp), nil
	}
	aliases := map[string]string{"!r": "!rate", "!f": "!feature", "!e": "!epic", "!ue": "!unepic", "!vc": "!verifycoins", "!d": "!delete", "!delet": "!delete"}
	if alias, ok := aliases[cmd]; ok {
		cmd = alias
	}
	fail := commandReply(r.version.Effective(), false, "")
	row, err := dbutil.One(r.ctx, h.db, `SELECT ro.code AS role_code,a.role AS legacy_role FROM accounts a LEFT JOIN roles ro ON ro.id=a.role_id WHERE a.account_id=? AND a.is_active=1 AND a.is_banned=0 LIMIT 1`, id)
	if err != nil {
		return "", err
	}
	role := normalizeRole(row["role_code"])
	if role == "user" {
		role = normalizeRole(row["legacy_role"])
	}
	if role == "user" || (cmd == "!rate" && role == "moderator") {
		return fail, nil
	}
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return "", err
	}
	defer tx.Rollback()
	var author int64
	err = tx.QueryRowContext(r.ctx, `SELECT account_id FROM levels WHERE level_id=? AND is_deleted=0 FOR UPDATE`, r.int("levelID")).Scan(&author)
	if err != nil {
		return "", err
	}
	number := func(index int, fallback int64, unsigned bool) (int64, bool) {
		if index >= len(parts) {
			return fallback, false
		}
		value := parts[index]
		if (unsigned && !digits.MatchString(value)) || (!unsigned && !strictInt.MatchString(value)) {
			return fallback, false
		}
		n, _ := strconv.ParseInt(value, 10, 64)
		return n, true
	}
	details := map[string]any{"command": cmd}
	sets := ""
	var args []any
	switch cmd {
	case "!rate":
		if len(parts) < 2 {
			return fail, nil
		}
		state, ok := map[string][3]int64{"na": {0, 0, 0}, "none": {0, 0, 0}, "auto": {50, 1, 0}, "easy": {10, 0, 0}, "normal": {20, 0, 0}, "hard": {30, 0, 0}, "harder": {40, 0, 0}, "insane": {50, 0, 0}, "demon": {50, 0, 1}}[strings.ToLower(parts[1])]
		if !ok {
			return fail, nil
		}
		stars, _ := number(2, 0, false)
		coins, coinsOK := number(3, 0, false)
		feature, featureOK := number(4, 0, false)
		if stars < 0 || stars > 10 || (coinsOK && (coins < 0 || coins > 3)) || (featureOK && (feature < 0 || feature > 4)) {
			return fail, nil
		}
		if stars == 0 {
			if state[2] == 1 {
				stars = 10
			} else if state[1] == 1 {
				stars = 1
			}
		}
		sets = "stars=?,difficulty=?,demon_difficulty=CASE WHEN ?=1 THEN demon_difficulty ELSE 0 END,demon=?,auto_level=?"
		args = []any{stars, state[0], state[2], state[2], state[1]}
		details["stars"], details["difficulty"], details["demon"], details["auto_level"] = stars, state[0], state[2], state[1]
		if coinsOK {
			value := 0
			if coins > 0 {
				value = 1
			}
			sets += ",coins_verified=?"
			args = append(args, value)
			details["coins_verified"] = value
		}
		if featureOK {
			value := 0
			if feature > 0 {
				value = 1
			}
			sets += ",featured=?"
			args = append(args, value)
			details["featured"] = value
			if feature == 0 {
				sets += ",epic=0"
			} else if feature >= 2 {
				sets += ",epic=?"
				args = append(args, feature-1)
			}
		}
	case "!demon":
		rating, _ := number(1, 3, true)
		if rating < 1 || rating > 5 {
			return fail, nil
		}
		sets = "stars=10,difficulty=50,demon=1,demon_difficulty=?,auto_level=0,custom_difficulty=0"
		args = []any{rating}
		details["demon_difficulty"] = rating
	case "!feature":
		sets = "featured=1"
	case "!epic":
		sets = "epic=1"
	case "!unepic":
		sets = "epic=0"
	case "!verifycoins":
		sets = "coins_verified=1"
	case "!delete":
		sets = "is_deleted=1"
	case "!cp":
		amount, _ := number(1, 1, true)
		if amount < 1 || amount > 100 {
			return fail, nil
		}
		_, err = tx.ExecContext(r.ctx, `INSERT IGNORE INTO profiles(account_id,stars,created_at,updated_at) VALUES(?,0,NOW(),NOW())`, author)
		if err != nil {
			return "", err
		}
		_, err = tx.ExecContext(r.ctx, `UPDATE profiles SET creator_points=creator_points+?,updated_at=NOW() WHERE account_id=?`, amount, author)
		details["amount"], details["target_account_id"] = amount, author
	default:
		return fail, nil
	}
	if err != nil {
		return "", err
	}
	if cmd != "!cp" {
		args = append(args, r.int("levelID"))
		_, err = tx.ExecContext(r.ctx, `UPDATE levels SET `+sets+`,updated_at=NOW() WHERE level_id=? AND is_deleted=0`, args...)
		if err != nil {
			return "", err
		}
		_, err = tx.ExecContext(r.ctx, `UPDATE profiles SET creator_points=(SELECT COALESCE(SUM(CASE WHEN stars>0 THEN 1 ELSE 0 END+CASE WHEN featured>0 THEN 1 ELSE 0 END+epic),0) FROM levels WHERE account_id=? AND is_deleted=0) WHERE account_id=?`, author, author)
		if err != nil {
			return "", err
		}
	}
	details["moderator_account_id"] = id
	data, _ := json.Marshal(details)
	_, _ = tx.ExecContext(r.ctx, `INSERT INTO moderation_logs(moderator_account_id,level_id,action,details) VALUES(?,?,?,?)`, id, r.int("levelID"), "COMMENT_COMMAND_"+strings.ToUpper(strings.TrimPrefix(cmd, "!")), data)
	if err = tx.Commit(); err != nil {
		return "", err
	}
	h.indexLevel(r, r.int("levelID"))
	return commandReply(r.version.Effective(), true, ""), nil
}
