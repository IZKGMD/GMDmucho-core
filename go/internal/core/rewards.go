package core

import (
	"crypto/sha1"
	"crypto/sha256"
	"encoding/base64"
	"errors"
	"fmt"
	"net/netip"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
	"github.com/IZKGMD/GMDmucho-core/go/internal/protocol"
)

func decodeCheck(value, key string) string {
	if len(value) <= 5 {
		return ""
	}
	value = value[5:]
	value = strings.NewReplacer("-", "+", "_", "/").Replace(value)
	value += strings.Repeat("=", (4-len(value)%4)%4)
	b, err := base64.StdEncoding.DecodeString(value)
	if err != nil {
		return ""
	}
	return protocol.XOR(string(b), key)
}
func rewardPack(prefix, plain, key, salt string) string {
	encoded := base64.URLEncoding.EncodeToString([]byte(protocol.XOR(plain, key)))
	return fmt.Sprintf("%s%s|%x", prefix, encoded, sha1.Sum([]byte(encoded+salt)))
}
func rewardTuple(id, typ, number int64) string {
	seed := fmt.Sprintf("%x", sha256.Sum256([]byte(fmt.Sprintf("MUCHO_V75|%d|%d|%d", id, typ, number))))
	rangeValue := func(offset, min, max int) int {
		n, _ := strconv.ParseUint(seed[offset:offset+8], 16, 64)
		return min + int(n%uint64(max-min+1))
	}
	minOrbs, maxOrbs, minDiamonds, maxDiamonds := 200, 400, 2, 10
	if typ == 2 {
		minOrbs, maxOrbs, minDiamonds, maxDiamonds = 2000, 4000, 20, 100
	}
	items := []int{1, 2, 3, 4, 5, 6, 10, 11, 12, 13, 14}
	return fmt.Sprintf("%d,%d,%d,%d", rangeValue(0, minOrbs, maxOrbs), rangeValue(8, minDiamonds, maxDiamonds), items[rangeValue(24, 0, len(items)-1)], rangeValue(16, 1, 6))
}
func (h *Handler) rewards(r request) (string, error) {
	id, typ := r.int("accountID"), r.int("rewardType")
	if typ < 0 || typ > 2 {
		return "-1", nil
	}
	uid := int64(0)
	state := dbutil.Row{"small_last_at": "0", "small_count": "0", "big_last_at": "0", "big_count": "0"}
	if id <= 0 {
		if typ != 0 {
			return "-1", nil
		}
	} else {
		me, err := h.auth(r)
		if err != nil {
			return "", err
		}
		uid = me.UserID
		_, err = h.db.ExecContext(r.ctx, `INSERT IGNORE INTO mucho_reward_state(account_id) VALUES(?)`, id)
		if err != nil {
			return "", err
		}
		state, err = dbutil.One(r.ctx, h.db, `SELECT * FROM mucho_reward_state WHERE account_id=? LIMIT 1`, id)
		if err != nil {
			return "", err
		}
	}
	small, big := rewardTuple(id, 1, state.Int("small_count")+1), rewardTuple(id, 2, state.Int("big_count")+1)
	now := h.now().Unix()
	left := func(last, wait int64) int64 {
		if last <= 0 {
			return 0
		}
		return max(0, wait-(now-last))
	}
	if typ > 0 {
		tx, err := h.db.BeginTx(r.ctx, nil)
		if err != nil {
			return "", err
		}
		defer tx.Rollback()
		locked, err := dbutil.One(r.ctx, tx, `SELECT * FROM mucho_reward_state WHERE account_id=? FOR UPDATE`, id)
		if err != nil {
			return "", err
		}
		last, count, wait := "small_last_at", "small_count", int64(3600)
		if typ == 2 {
			last, count, wait = "big_last_at", "big_count", 14400
		}
		if left(locked.Int(last), wait) > 0 {
			return "-1", nil
		}
		_, err = tx.ExecContext(r.ctx, `UPDATE mucho_reward_state SET `+last+`=?,`+count+`=`+count+`+1 WHERE account_id=?`, now, id)
		if err != nil {
			return "", err
		}
		locked[last], locked[count] = strconv.FormatInt(now, 10), strconv.FormatInt(locked.Int(count)+1, 10)
		state = locked
		if err = tx.Commit(); err != nil {
			return "", err
		}
	}
	plain := protocol.Pairs(1, uid, decodeCheck(r.str("chk"), "59182"), r.str("udid"), id, left(state.Int("small_last_at"), 3600), small, state.Int("small_count"), left(state.Int("big_last_at"), 14400), big, state.Int("big_count"), typ)
	return rewardPack("SaKuJ", plain, "59182", "pC26fpYaQCtg"), nil
}
func (h *Handler) challenges(r request) (string, error) {
	id, uid := r.int("accountID"), int64(0)
	if id > 0 {
		me, err := h.auth(r)
		if err != nil {
			return "", err
		}
		uid = me.UserID
	}
	pool, err := dbutil.Query(r.ctx, h.db, `SELECT challenge_id,type,amount,reward,name FROM mucho_challenge_pool WHERE active=1 ORDER BY challenge_id`)
	if err != nil {
		return "", err
	}
	if len(pool) < 3 {
		return "-1", nil
	}
	now := h.now().UTC()
	day := now.Format("2006-01-02")
	digest := func(q dbutil.Row) string { return fmt.Sprintf("%x", sha256.Sum256([]byte(day+"|"+q["challenge_id"]))) }
	sort.Slice(pool, func(i, j int) bool { return digest(pool[i]) < digest(pool[j]) })
	base := time.Date(2000, 12, 17, 0, 0, 0, 0, time.UTC)
	questBase := int64(now.Sub(base).Hours()/24) * 3
	midnight := time.Date(now.Year(), now.Month(), now.Day()+1, 0, 0, 0, 0, time.UTC)
	quests := make([]string, 3)
	clean := strings.NewReplacer(":", " ", ",", " ")
	for i, q := range pool[:3] {
		quests[i] = fmt.Sprintf("%d,%d,%d,%d,%s", questBase+int64(i), q.Int("type"), q.Int("amount"), q.Int("reward"), clean.Replace(q["name"]))
	}
	plain := protocol.Pairs("SaKuJ", uid, decodeCheck(r.str("chk"), "19847"), r.str("udid"), id, max(0, midnight.Unix()-now.Unix()), strings.Join(quests, ":"))
	return rewardPack("SaKuJ", plain, "19847", "oC36fpYaPtdg"), nil
}
func (h *Handler) secretReward(r request) (string, error) {
	key := strings.TrimSpace(r.str("rewardKey"))
	if r.str("secret") != "Wmfd2893gb7" || key == "" || len(key) > 128 {
		return "-1", nil
	}
	id := r.int("accountID")
	if id > 0 {
		if _, err := h.auth(r); err != nil {
			return "", err
		}
	}
	check := decodeCheck(r.str("chk"), "59182")
	if check == "" {
		return "-1", nil
	}
	claimKey := fmt.Sprintf("a:%d", id)
	if id <= 0 {
		if r.str("udid") == "" {
			return "-1", nil
		}
		if _, err := netip.ParseAddr(r.ip); err != nil {
			return "-1", nil
		}
		claimKey = fmt.Sprintf("u:%x", sha256.Sum256([]byte(r.ip+"|"+r.str("udid"))))
	}
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return "", err
	}
	defer tx.Rollback()
	reward, err := dbutil.One(r.ctx, tx, `SELECT * FROM mucho_secret_rewards WHERE reward_key=? AND active=1 LIMIT 1 FOR UPDATE`, key)
	if err != nil {
		return "", err
	}
	if reward.Int("uses") <= 0 || (reward.Int("expires_at") > 0 && reward.Int("expires_at") <= h.now().Unix()) {
		return "-1", nil
	}
	result, err := tx.ExecContext(r.ctx, `INSERT IGNORE INTO mucho_secret_reward_claims(reward_id,claim_key) VALUES(?,?)`, reward.Int("reward_id"), claimKey)
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
	result, err = tx.ExecContext(r.ctx, `UPDATE mucho_secret_rewards SET uses=uses-1 WHERE reward_id=? AND uses>0`, reward.Int("reward_id"))
	if err != nil {
		return "", err
	}
	n, err = result.RowsAffected()
	if err != nil {
		return "", err
	}
	if n != 1 {
		return "", errors.New("reward no longer available")
	}
	if err = tx.Commit(); err != nil {
		return "", err
	}
	plain := protocol.Pairs("Mucho", check, reward.Int("reward_id"), reward.Int("chest_type"), reward["rewards"])
	return rewardPack("Mucho", plain, "59182", "pC26fpYaQCtg"), nil
}
