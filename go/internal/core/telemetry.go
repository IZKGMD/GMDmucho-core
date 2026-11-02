package core

import (
	"database/sql"
	"errors"
	"fmt"
	"regexp"
	"strconv"
	"strings"
	"unicode/utf8"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
)

var positionPattern = regexp.MustCompile(`^-?[0-9]{1,7}(?:\.[0-9]{1,3})?$`)

func positionBucket(raw string) (int64, bool) {
	if !positionPattern.MatchString(raw) {
		return 0, false
	}
	negative := strings.HasPrefix(raw, "-")
	raw = strings.TrimPrefix(raw, "-")
	parts := strings.SplitN(raw, ".", 2)
	whole, _ := strconv.ParseInt(parts[0], 10, 64)
	fraction := int64(0)
	if len(parts) == 2 {
		fraction, _ = strconv.ParseInt(parts[1]+strings.Repeat("0", 3-len(parts[1])), 10, 64)
	}
	milli := whole*1000 + fraction
	if milli > 1000000000 {
		return 0, false
	}
	bucket := (milli + 50) / 100
	if negative {
		bucket = -bucket
	}
	return bucket, true
}
func (h *Handler) levelTelemetry(r request) (string, error) {
	id := r.defaultInt("levelID", r.int("levelId"))
	level, err := dbutil.One(r.ctx, h.db, `SELECT level_id,account_id,level_version,is_deleted,is_unlisted FROM levels WHERE level_id=? AND is_deleted=0 LIMIT 1`, id)
	if r.path == "/getgjdeathdata" {
		if errors.Is(err, sql.ErrNoRows) || (err == nil && level.Int("is_unlisted") == 1) {
			return "-2", nil
		}
		if err != nil {
			return "", err
		}
		rows, err := dbutil.Query(r.ctx, h.db, `SELECT percent_basis_points,x_bucket,y_bucket,SUM(deaths) AS total FROM mucho_level_deaths WHERE level_id=? AND level_version=? GROUP BY percent_basis_points,x_bucket,y_bucket ORDER BY total DESC,percent_basis_points DESC LIMIT 256`, id, level.Int("level_version"))
		if err != nil {
			return "", err
		}
		if len(rows) == 0 {
			return "-2", nil
		}
		items := []string{}
		for _, row := range rows {
			items = append(items, fmt.Sprintf("%d,%d,%d,%d", row.Int("percent_basis_points"), row.Int("x_bucket"), row.Int("y_bucket"), row.Int("total")))
		}
		return strings.Join(items, "|"), nil
	}
	if r.path == "/getgjlevelcontrols" {
		if errors.Is(err, sql.ErrNoRows) {
			return "-2", nil
		}
		if err != nil {
			return "", err
		}
		var data string
		err = h.db.QueryRowContext(r.ctx, `SELECT contents FROM mucho_level_controls WHERE level_id=?`, id).Scan(&data)
		if errors.Is(err, sql.ErrNoRows) {
			return "-2", nil
		}
		return data, err
	}
	if err != nil {
		return "", err
	}
	me, err := h.auth(r)
	if err != nil {
		return "", err
	}
	if r.path != "/uploadgjdeathdata" {
		if level.Int("account_id") != me.ID {
			return "-1", nil
		}
		if r.path == "/deletegjlevelcontrols" {
			return success(h.db.ExecContext(r.ctx, `DELETE FROM mucho_level_controls WHERE level_id=?`, id))
		}
		contents := r.str("controls")
		if contents == "" {
			contents = r.str("data")
		}
		if contents == "" || len(contents) > 16384 || strings.ContainsRune(contents, 0) || !utf8.ValidString(contents) {
			return "-1", nil
		}
		_, err = h.db.ExecContext(r.ctx, `INSERT INTO mucho_level_controls(level_id,contents) VALUES(?,?) ON DUPLICATE KEY UPDATE contents=VALUES(contents)`, id, contents)
		return "1", err
	}
	rawX, rawY := r.str("x"), r.str("y")
	if rawX == "" {
		rawX = r.str("posX")
	}
	if rawY == "" {
		rawY = r.str("posY")
	}
	x, xOK := positionBucket(rawX)
	y, yOK := positionBucket(rawY)
	percent := r.int("percent")
	if !xOK || !yOK || percent < 0 || percent > 100 {
		return "-1", nil
	}
	// Lock the level before checking the per-player/version cap; concurrent
	// distinct positions cannot exceed the bound or use a superseded version.
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return "", err
	}
	defer tx.Rollback()
	var version int64
	if err = tx.QueryRowContext(r.ctx, `SELECT level_version FROM levels WHERE level_id=? AND is_deleted=0 FOR UPDATE`, id).Scan(&version); err != nil {
		return "", err
	}
	version = max(0, version)
	var exists int
	if err = tx.QueryRowContext(r.ctx, `SELECT COUNT(*) FROM mucho_level_deaths WHERE level_id=? AND account_id=? AND level_version=? AND percent_basis_points=? AND x_bucket=? AND y_bucket=?`, id, me.ID, version, percent*100, x, y).Scan(&exists); err != nil {
		return "", err
	}
	if exists == 0 {
		var count int
		if err = tx.QueryRowContext(r.ctx, `SELECT COUNT(*) FROM mucho_level_deaths WHERE level_id=? AND account_id=? AND level_version=?`, id, me.ID, version).Scan(&count); err != nil {
			return "", err
		}
		if count >= 500 {
			return "-1", nil
		}
	}
	if _, err = tx.ExecContext(r.ctx, `INSERT INTO mucho_level_deaths(level_id,account_id,level_version,percent_basis_points,x_bucket,y_bucket,deaths) VALUES(?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE deaths=LEAST(deaths+1,100)`, id, me.ID, version, percent*100, x, y); err != nil {
		return "", err
	}
	return "1", tx.Commit()
}
