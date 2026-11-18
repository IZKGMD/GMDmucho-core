package core

import (
	"encoding/base64"
	"encoding/json"
	"fmt"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
	"github.com/IZKGMD/GMDmucho-core/go/internal/protocol"
)

var scoreInteger = regexp.MustCompile(`^-?[0-9]+$`)

func scoreValue(value string, fallback int64, maxDigits int) int64 {
	if !scoreInteger.MatchString(value) || (maxDigits > 0 && len(strings.TrimPrefix(value, "-")) > maxDigits) {
		return fallback
	}
	n, _ := strconv.ParseInt(value, 10, 64)
	return n
}
func scoreDate(ts int64) string {
	t := time.Unix(ts, 0).UTC()
	return fmt.Sprintf("%s %d.%02d", t.Format("02/01/2006"), t.Hour(), t.Minute())
}
func scoreProgresses(value string) (string, bool) {
	if value == "" {
		return "", true
	}
	value = strings.NewReplacer("-", "+", "_", "/").Replace(value)
	value += strings.Repeat("=", (4-len(value)%4)%4)
	raw, err := base64.StdEncoding.DecodeString(value)
	if err != nil {
		return "", false
	}
	out := protocol.XOR(string(raw), "41274")
	return out, len(out) <= 100000
}
func (h *Handler) regularScores(r request) (string, error) {
	id, levelID, percent := scoreValue(r.str("accountID"), 0, 0), scoreValue(r.str("levelID"), 0, 0), scoreValue(r.str("percent"), 0, 0)
	typ := int64(1)
	if _, ok := r.form["type"]; ok {
		typ = scoreValue(r.str("type"), 0, 0)
	}
	if id <= 0 || levelID <= 0 || percent < 0 || percent > 100 || typ < 0 || typ > 2 {
		return "-1", nil
	}
	if _, err := h.auth(r); err != nil {
		return "", err
	}
	if _, err := h.findLevel(r, levelID); err != nil {
		return "", err
	}
	decoded := make([]int64, 4)
	for i, f := range []struct {
		key         string
		offset, max int64
	}{{"s1", 8354, 1000000}, {"s2", 3991, 10000000}, {"s3", 4085, 86400}, {"s9", 5819, 3}} {
		value := r.str(f.key)
		if value == "" {
			continue
		}
		if !scoreInteger.MatchString(value) {
			return "-1", nil
		}
		n, err := strconv.ParseInt(value, 10, 64)
		if err != nil || n < f.offset || n > f.offset+f.max {
			return "-1", nil
		}
		decoded[i] = n - f.offset
	}
	dailyID := scoreValue(r.str("s10"), 0, 0)
	if dailyID < 0 {
		return "-1", nil
	}
	daily := 0
	scoreType := "regular"
	if dailyID > 0 {
		daily = 1
		scoreType = "regular_daily"
	}
	progress, ok := scoreProgresses(r.str("s6"))
	if !ok {
		return "-1", nil
	}
	now := h.now().Unix()
	result, err := h.db.ExecContext(r.ctx, `INSERT INTO mucho_level_scores(account_id,level_id,is_daily,daily_id,percent,coins,attempts,clicks,play_time,progresses,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE score_id=LAST_INSERT_ID(score_id),daily_id=IF(VALUES(percent)>=percent,VALUES(daily_id),daily_id),coins=IF(VALUES(percent)>=percent,VALUES(coins),coins),attempts=IF(VALUES(percent)>=percent,VALUES(attempts),attempts),clicks=IF(VALUES(percent)>=percent,VALUES(clicks),clicks),play_time=IF(VALUES(percent)>=percent,VALUES(play_time),play_time),progresses=IF(VALUES(percent)>=percent,VALUES(progresses),progresses),updated_at=IF(VALUES(percent)>=percent,VALUES(updated_at),updated_at),percent=GREATEST(percent,VALUES(percent))`, id, levelID, daily, dailyID, percent, decoded[3], decoded[0], decoded[1], decoded[2], progress, now, now)
	if err != nil {
		return "", err
	}
	if n, e := result.RowsAffected(); e == nil && n > 0 {
		scoreID, _ := result.LastInsertId()
		risk, reasons := regularRisk(percent, decoded[0], decoded[1], decoded[2], progress)
		h.scoreIntegrity(r, scoreType, scoreID, id, levelID, risk, reasons, map[string]any{"percent": percent, "attempts": decoded[0], "clicks": decoded[1], "play_time": decoded[2], "progress_bytes": len(progress)})
	}
	where := `s.level_id=? AND s.is_daily=? AND a.is_active=1 AND a.is_banned=0`
	args := []any{levelID, daily}
	if h.options.QuarantineScores {
		where += ` AND NOT EXISTS(SELECT 1 FROM mucho_score_integrity_events si WHERE si.score_type=? AND si.score_id=s.score_id AND si.status IN ('suspicious','quarantined') AND si.risk_score>=70)`
		args = append(args, scoreType)
	}
	if typ == 0 {
		where += ` AND (s.account_id=? OR s.account_id IN (SELECT friend_account_id FROM friends WHERE account_id=? UNION SELECT account_id FROM friends WHERE friend_account_id=?))`
		args = append(args, id, id, id)
	} else if typ == 2 {
		where += ` AND s.updated_at>?`
		args = append(args, now-604800)
	}
	rows, err := dbutil.Query(r.ctx, h.db, `SELECT s.*,a.username,p.user_id,p.cube,p.color1,p.color2,p.color3,p.icon_type,p.special FROM mucho_level_scores s JOIN accounts a ON a.account_id=s.account_id LEFT JOIN profiles p ON p.account_id=s.account_id WHERE `+where+` ORDER BY s.percent DESC,s.updated_at ASC LIMIT 100`, args...)
	if err != nil {
		return "", err
	}
	items := make([]string, 0, len(rows))
	for _, row := range rows {
		place := 3
		if row.Int("percent") == 100 {
			place = 1
		} else if row.Int("percent") > 75 {
			place = 2
		}
		items = append(items, protocol.Pairs(1, protocol.Username(row["username"]), 2, row.Value("user_id", row["account_id"]), 9, row.Value("cube", "1"), 10, row.Int("color1"), 11, row.Value("color2", "3"), 51, row.Int("color3"), 14, row.Int("icon_type"), 15, row.Int("special"), 16, row.Int("account_id"), 3, row.Int("percent"), 6, place, 13, row.Int("coins"), 42, scoreDate(row.Int("updated_at"))))
	}
	return strings.Join(items, "|"), nil
}
func regularRisk(percent, attempts, clicks, playTime int64, progress string) (int, []string) {
	risk := 0
	reasons := []string{}
	add := func(n int, reason string) { risk += n; reasons = append(reasons, reason) }
	if percent >= 90 && playTime > 0 && playTime < 2 {
		add(80, "near_complete_in_under_2s")
	} else if percent == 100 && playTime > 0 && playTime < 5 {
		add(60, "complete_in_under_5s")
	}
	if percent >= 95 && attempts <= 1 {
		add(55, "near_complete_with_one_attempt")
	}
	if percent >= 90 && clicks <= 3 {
		add(40, "near_complete_with_three_or_fewer_clicks")
	}
	if percent > 0 && attempts == 0 {
		add(45, "progress_without_attempts")
	}
	if percent >= 90 && progress == "" {
		add(15, "high_progress_without_progress_trace")
	}
	return min(100, risk), reasons
}
func (h *Handler) scoreIntegrity(r request, typ string, scoreID, id, levelID int64, risk int, reasons []string, metrics map[string]any) {
	status := "trusted"
	if risk >= 70 {
		status = "suspicious"
		if h.options.QuarantineScores {
			status = "quarantined"
		}
	}
	reasonsJSON, _ := json.Marshal(reasons)
	metricsJSON, _ := json.Marshal(metrics)
	_, _ = h.db.ExecContext(r.ctx, `INSERT INTO mucho_score_integrity_events(score_type,score_id,account_id,level_id,risk_score,status,reasons,metrics) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE account_id=VALUES(account_id),level_id=VALUES(level_id),risk_score=VALUES(risk_score),status=VALUES(status),reasons=VALUES(reasons),metrics=VALUES(metrics),updated_at=CURRENT_TIMESTAMP`, typ, scoreID, id, levelID, risk, status, reasonsJSON, metricsJSON)
}
func (h *Handler) platformerScores(r request) (string, error) {
	me, err := h.auth(r)
	if err != nil {
		return "", err
	}
	levelID, mode, typ := r.int("levelID"), r.int("mode"), r.int("type")
	if levelID <= 0 || mode < 0 || mode > 1 || typ < 0 || typ > 2 {
		return "-1", nil
	}
	level, err := h.findLevel(r, levelID)
	if err != nil {
		return "", err
	}
	if level.Int("length") != 5 {
		return "-1", nil
	}
	timeMs, points := int64(0), int64(0)
	if _, ok := r.form["time"]; ok {
		timeMs = scoreValue(r.str("time"), -1, 12)
	}
	if _, ok := r.form["points"]; ok {
		points = scoreValue(r.str("points"), -1, 12)
	}
	if timeMs < 0 || timeMs > 86400000 || points < 0 || points > 100000000 {
		return "-1", nil
	}
	now := h.now().Unix()
	if timeMs > 0 {
		updates := `score_id=LAST_INSERT_ID(score_id),points=IF(time_ms<=0 OR VALUES(time_ms)<time_ms,VALUES(points),points),updated_at=IF(time_ms<=0 OR VALUES(time_ms)<time_ms,VALUES(updated_at),updated_at),time_ms=IF(time_ms<=0 OR VALUES(time_ms)<time_ms,VALUES(time_ms),time_ms)`
		if mode == 1 {
			updates = `score_id=LAST_INSERT_ID(score_id),time_ms=IF(VALUES(points)>points,VALUES(time_ms),time_ms),updated_at=IF(VALUES(points)>points,VALUES(updated_at),updated_at),points=IF(VALUES(points)>points,VALUES(points),points)`
		}
		result, err := h.db.ExecContext(r.ctx, `INSERT INTO mucho_platformer_scores(account_id,level_id,time_ms,points,created_at,updated_at) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE `+updates, me.ID, levelID, timeMs, points, now, now)
		if err != nil {
			return "", err
		}
		if n, e := result.RowsAffected(); e == nil && n > 0 {
			scoreID, _ := result.LastInsertId()
			risk, reasons := 0, []string{}
			if timeMs <= 250 && points > 0 {
				risk = 80
				reasons = append(reasons, "platformer_completion_under_250ms")
			} else if timeMs <= 1000 && points > 0 {
				risk = 55
				reasons = append(reasons, "platformer_completion_under_1s")
			}
			h.scoreIntegrity(r, "platformer", scoreID, me.ID, levelID, risk, reasons, map[string]any{"time_ms": timeMs, "points": points})
		}
	}
	where := `s.level_id=? AND s.time_ms>0 AND a.is_active=1 AND a.is_banned=0`
	args := []any{levelID}
	if h.options.QuarantineScores {
		where += ` AND NOT EXISTS(SELECT 1 FROM mucho_score_integrity_events si WHERE si.score_type='platformer' AND si.score_id=s.score_id AND si.status IN ('suspicious','quarantined') AND si.risk_score>=70)`
	}
	if typ == 0 {
		where += ` AND (s.account_id=? OR s.account_id IN (SELECT friend_account_id FROM friends WHERE account_id=? UNION SELECT account_id FROM friends WHERE friend_account_id=?))`
		args = append(args, me.ID, me.ID, me.ID)
	} else if typ == 2 {
		where += ` AND s.updated_at>?`
		args = append(args, now-604800)
	}
	order := "s.time_ms ASC,s.updated_at ASC"
	if mode == 1 {
		order = "s.points DESC,s.updated_at ASC"
	}
	rows, err := dbutil.Query(r.ctx, h.db, `SELECT s.*,a.username,p.user_id,p.cube,p.color1,p.color2,p.color3 FROM mucho_platformer_scores s JOIN accounts a ON a.account_id=s.account_id LEFT JOIN profiles p ON p.account_id=s.account_id WHERE `+where+` ORDER BY `+order+` LIMIT 100`, args...)
	if err != nil {
		return "", err
	}
	items := make([]string, len(rows))
	for i, row := range rows {
		value := row.Int("time_ms")
		if mode == 1 {
			value = row.Int("points")
		}
		items[i] = protocol.Pairs(1, protocol.Username(row["username"]), 2, row.Value("user_id", row["account_id"]), 9, row.Value("cube", "1"), 10, row.Int("color1"), 11, row.Value("color2", "3"), 14, 0, 15, row.Int("color3"), 16, row.Int("account_id"), 3, value, 6, i+1, 42, scoreDate(row.Int("updated_at")))
	}
	return strings.Join(items, "|"), nil
}
