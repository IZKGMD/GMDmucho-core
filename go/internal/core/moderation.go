package core

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"strconv"
	"strings"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
)

func (h *Handler) reportLevel(r request) (string, error) {
	id := r.int("levelID")
	if id <= 0 {
		return "-1", nil
	}
	rows, err := dbutil.Query(r.ctx, h.db, `SELECT 1 FROM levels WHERE level_id=? AND is_deleted=0`, id)
	if err != nil {
		return "", err
	}
	if len(rows) == 0 {
		return "-1", nil
	}
	hash := sha256.Sum256([]byte("MUCHO_V76_REPORT|" + r.ip))
	result, err := h.db.ExecContext(r.ctx, `INSERT IGNORE INTO mucho_level_reports(level_id,reporter_hash) VALUES(?,?)`, id, hex.EncodeToString(hash[:]))
	if err != nil {
		return "", err
	}
	n, err := result.RowsAffected()
	if err != nil {
		return "", err
	}
	if n != 1 {
		return "-1", nil
	}
	report, err := result.LastInsertId()
	return strconv.FormatInt(report, 10), err
}

func (h *Handler) moderateLevel(r request) (string, error) {
	me, err := h.auth(r)
	if err != nil {
		return "", err
	}
	id := r.int("levelID")
	if id <= 0 {
		return "-1", nil
	}
	row, err := dbutil.One(r.ctx, h.db, `SELECT COALESCE(ro.code,'user') AS role FROM accounts a LEFT JOIN roles ro ON ro.id=a.role_id WHERE a.account_id=? AND a.is_active=1 AND a.is_banned=0`, me.ID)
	if err != nil {
		return "", err
	}
	role := strings.ToLower(row["role"])
	direct := role == "elder_moderator" || role == "owner"
	if role != "moderator" && !direct {
		return "-1", nil
	}
	isDemon := r.path == "/rategjdemon21"
	suggest := r.path == "/suggestgjstars20"
	stars, feature, coins := r.int("stars"), r.int("feature"), int64(0)
	if r.int("coins") > 0 {
		coins = 1
	}
	rating := r.int("rating")
	if isDemon {
		if rating < 1 || rating > 8 {
			return "-1", nil
		}
	} else if stars < 1 || stars > 10 || (!suggest && !direct) || (suggest && (feature < 0 || feature > 4)) {
		return "-1", nil
	}
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return "", err
	}
	defer tx.Rollback()
	var author int64
	if err = tx.QueryRowContext(r.ctx, `SELECT account_id FROM levels WHERE level_id=? AND is_deleted=0 FOR UPDATE`, id).Scan(&author); err != nil {
		return "", err
	}
	action := ""
	details := map[string]any{}
	switch {
	case isDemon:
		difficulty := []int64{3, 4, 0, 5, 6, 8, 9, 10}[rating-1]
		custom := max(0, rating-5)
		stars = []int64{10, 15, 30, 50}[custom]
		_, err = tx.ExecContext(r.ctx, `UPDATE levels SET stars=?,demon=1,difficulty=50,auto_level=0,demon_difficulty=?,custom_difficulty=? WHERE level_id=? AND is_deleted=0`, stars, difficulty, custom, id)
		action = "DEMON_DIFFICULTY_APPLIED"
		details["rating"] = rating
	case suggest && !direct:
		_, err = tx.ExecContext(r.ctx, `INSERT INTO moderation_suggestions(level_id,account_id,stars,feature_tier,coins_verified) VALUES(?,?,?,?,?)`, id, me.ID, stars, feature, coins)
		action = "SUGGESTION_SENT"
		details = map[string]any{"stars": stars, "feature_tier": feature, "coins_verified": coins}
	default:
		state := [][3]int64{{50, 1, 0}, {10, 0, 0}, {20, 0, 0}, {30, 0, 0}, {30, 0, 0}, {40, 0, 0}, {40, 0, 0}, {50, 0, 0}, {50, 0, 0}, {50, 0, 1}}[stars-1]
		sets := `demon_difficulty=CASE WHEN ?=1 AND demon=1 AND custom_difficulty=0 THEN demon_difficulty ELSE 0 END,custom_difficulty=0,stars=?,difficulty=?,auto_level=?,demon=?`
		args := []any{state[2], stars, state[0], state[1], state[2]}
		action = "STAR_RATE_APPLIED"
		details = map[string]any{"stars": stars, "author_account_id": author}
		if suggest {
			featured := int64(0)
			if feature >= 1 {
				featured = 1
			}
			sets += `,featured=?,epic=?,coins_verified=?`
			args = append(args, featured, max(0, feature-1), coins)
			action = "RATE_APPLIED"
			details["feature_tier"] = feature
			details["coins_verified"] = coins
		}
		_, err = tx.ExecContext(r.ctx, `UPDATE levels SET `+sets+` WHERE level_id=? AND is_deleted=0`, append(args, id)...)
		if err == nil {
			_, err = tx.ExecContext(r.ctx, `UPDATE profiles SET creator_points=(SELECT COALESCE(SUM(CASE WHEN stars>0 THEN 1 ELSE 0 END+CASE WHEN featured>0 THEN 1 ELSE 0 END+epic),0) FROM levels WHERE account_id=? AND is_deleted=0) WHERE account_id=?`, author, author)
		}
	}
	if err != nil {
		return "", err
	}
	data, err := json.Marshal(details)
	if err != nil {
		return "", err
	}
	_, err = tx.ExecContext(r.ctx, `INSERT INTO moderation_logs(moderator_account_id,level_id,action,details) VALUES(?,?,?,?)`, me.ID, id, action, data)
	if err != nil {
		return "", err
	}
	if err = tx.Commit(); err != nil {
		return "", err
	}
	if isDemon {
		return strconv.FormatInt(id, 10), nil
	}
	return "1", nil
}
