package core

import (
	"bytes"
	"compress/zlib"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"fmt"
	"math"
	"net/url"
	"regexp"
	"strconv"
	"strings"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
)

var audioIDsPattern = regexp.MustCompile(`^[1-9][0-9]*(?:,[1-9][0-9]*){0,63}$`)

func audioIDs(raw string) ([]int64, error) {
	ids := []int64{}
	if raw == "" {
		return ids, nil
	}
	if len(raw) > 1024 || !audioIDsPattern.MatchString(raw) {
		return nil, fmt.Errorf("invalid audio IDs")
	}
	seen := map[int64]bool{}
	for _, part := range strings.Split(raw, ",") {
		id, err := strconv.ParseInt(part, 10, 64)
		if err != nil || id <= 0 {
			return nil, fmt.Errorf("invalid audio ID")
		}
		if !seen[id] {
			seen[id] = true
			ids = append(ids, id)
		}
	}
	return ids, nil
}
func randomHex(size int) (string, error) {
	data := make([]byte, size)
	if _, err := rand.Read(data); err != nil {
		return "", err
	}
	return hex.EncodeToString(data), nil
}

func (h *Handler) utility(r request) (string, error) {
	if r.raw.Method != "POST" {
		return "-1", nil
	}
	switch r.path {
	case "/getgjaudiofilesize":
		songs, err := audioIDs(r.str("songIDs"))
		if err != nil {
			return "", err
		}
		sounds, err := audioIDs(r.str("sfxIDs"))
		if err != nil {
			return "", err
		}
		if len(songs)+len(sounds) == 0 {
			return "-1", nil
		}
		a, b := []string{}, []string{}
		for _, id := range songs {
			var size float64
			if err = h.db.QueryRowContext(r.ctx, `SELECT size FROM songs WHERE id=? AND is_verified=1 LIMIT 1`, id).Scan(&size); err != nil {
				return "", err
			}
			if size <= 0 || size > 10000 || math.IsNaN(size) || math.IsInf(size, 0) {
				return "-1", nil
			}
			a = append(a, fmt.Sprintf("%d:%d", id, int64(math.Round(size*1048576))))
		}
		for _, id := range sounds {
			var size int64
			if err = h.db.QueryRowContext(r.ctx, `SELECT size_bytes FROM mucho_editor_sounds WHERE id=? LIMIT 1`, id).Scan(&size); err != nil {
				return "", err
			}
			if size < 1 {
				return "-1", nil
			}
			b = append(b, fmt.Sprintf("%d:%d", id, size))
		}
		return strings.Join(a, ",") + "#" + strings.Join(b, ","), nil
	case "/getgjcontentstatus":
		me, err := h.auth(r)
		if err != nil {
			return "", err
		}
		if special := r.int("special"); special != 0 && special != 1 {
			return "-1", nil
		}
		flags := []int{0, 0, 0}
		for i, kind := range []string{"comment", "upload", "message"} {
			active, err := h.restricted(r, me.ID, kind)
			if err != nil {
				return "", err
			}
			if active {
				flags[i] = 1
			}
		}
		return fmt.Sprintf("1:%d:2:%d:3:%d:4:%d", me.ID, flags[0], flags[1], flags[2]), nil
	case "/getlevelrateinfo":
		for _, key := range []string{"accountID", "levelID"} {
			if !digits.MatchString(r.str(key)) || len(r.str(key)) > 18 || r.int(key) <= 0 {
				return "-1", nil
			}
		}
		if _, err := h.auth(r); err != nil {
			return "", err
		}
		if allowed, err := h.canReadLevel(r, r.int("levelID")); err != nil || !allowed {
			return "-1", err
		}
		rows, err := dbutil.Query(r.ctx, h.db, `SELECT s.stars,s.feature_tier FROM moderation_suggestions s WHERE s.level_id=? AND NOT EXISTS(SELECT 1 FROM moderation_suggestions newer WHERE newer.level_id=s.level_id AND newer.account_id=s.account_id AND newer.id>s.id)`, r.int("levelID"))
		if err != nil {
			return "", err
		}
		stars, quality := make([]int, 10), make([]int, 5)
		for _, row := range rows {
			star, tier := row.Int("stars"), row.Int("feature_tier")
			if star >= 1 && star <= 10 {
				stars[star-1]++
			}
			if tier >= 1 && tier <= 4 {
				quality[tier-1]++
			}
		}
		join := func(values []int) string {
			out := make([]string, len(values))
			for i, v := range values {
				out[i] = strconv.Itoa(v)
			}
			return strings.Join(out, ",")
		}
		return join(stars) + ":" + join(quality), nil
	case "/getsavedata":
		if r.str("secret") != "Wmfd2893gb7" {
			return "-1", nil
		}
		rows, err := dbutil.Query(r.ctx, h.db, `SELECT level_id,stars FROM levels WHERE is_deleted=0 AND is_unlisted=0 AND unlisted2=0 AND stars BETWEEN 1 AND 10 ORDER BY level_id LIMIT 100001`)
		if err != nil {
			return "", err
		}
		if len(rows) > 100000 {
			return "-1", nil
		}
		pairs := make([]string, 0, len(rows)*2)
		for _, row := range rows {
			pairs = append(pairs, row["level_id"], row["stars"])
		}
		var compressed bytes.Buffer
		writer, err := zlib.NewWriterLevel(&compressed, zlib.BestCompression)
		if err != nil {
			return "", err
		}
		if _, err = writer.Write([]byte(strings.Join(pairs, ","))); err != nil {
			return "", err
		}
		if err = writer.Close(); err != nil {
			return "", err
		}
		prefix, err := randomHex(10)
		if err != nil {
			return "", err
		}
		suffix, err := randomHex(10)
		if err != nil {
			return "", err
		}
		return prefix + base64.URLEncoding.EncodeToString(compressed.Bytes()) + suffix, nil
	case "/openedregister":
		if r.str("secret") != "Wmfv3899gc9" {
			return "-1", nil
		}
		token, err := randomHex(16)
		if err != nil {
			return "", err
		}
		hash := sha256.Sum256([]byte(token))
		anonymous := make([]byte, 32)
		if _, err = rand.Read(anonymous); err != nil {
			return "", err
		}
		if _, err = h.db.ExecContext(r.ctx, `DELETE FROM mucho_gd2209_registration_opens WHERE expires_at<UTC_TIMESTAMP() LIMIT 1000`); err != nil {
			return "", err
		}
		_, err = h.db.ExecContext(r.ctx, `INSERT INTO mucho_gd2209_registration_opens(token_hash,actor_hash,last_seen_at,expires_at) VALUES(?,?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR))`, hash[:], anonymous)
		return token, err
	}
	return "-1", nil
}

func (h *Handler) songUsage(r request) (string, error) {
	id := r.int("songID")
	if id <= 0 {
		return "-1", nil
	}
	summary, err := dbutil.One(r.ctx, h.db, `SELECT COUNT(*) AS total,COALESCE(SUM(CASE WHEN stars>0 THEN 1 ELSE 0 END),0) AS rated,COALESCE(SUM(CASE WHEN stars>0 THEN downloads ELSE 0 END),0) AS rated_downloads FROM levels WHERE song_id=? AND is_deleted=0 AND is_unlisted=0`, id)
	if err != nil {
		return "", err
	}
	rows, err := dbutil.Query(r.ctx, h.db, `SELECT CASE WHEN epic=3 THEN 4 WHEN epic=2 THEN 3 WHEN epic=1 THEN 2 WHEN featured>0 THEN 1 ELSE 0 END AS tier,CASE WHEN auto_level>0 THEN 0 WHEN demon>0 THEN 6 WHEN difficulty>=10 AND difficulty<=60 AND MOD(difficulty,10)=0 THEN LEAST(5,difficulty DIV 10) ELSE LEAST(5,GREATEST(0,difficulty)) END AS difficulty_bucket,COUNT(*) AS amount FROM levels WHERE song_id=? AND is_deleted=0 AND is_unlisted=0 AND stars>0 GROUP BY tier,difficulty_bucket ORDER BY tier ASC,difficulty_bucket ASC`, id)
	if err != nil {
		return "", err
	}
	groups := make([]string, 0, len(rows))
	for _, row := range rows {
		groups = append(groups, fmt.Sprintf("%d:%d:%d", row.Int("tier"), row.Int("difficulty_bucket"), row.Int("amount")))
	}
	return fmt.Sprintf("%d,%d,%d#%s", summary.Int("total"), summary.Int("rated"), summary.Int("rated_downloads"), strings.Join(groups, "|")), nil
}

func (h *Handler) topArtists(r request) (string, error) {
	offset := min(1000, max(0, r.int("page"))) * 20
	const filter = `author_name IS NOT NULL AND author_name<>'' AND author_name NOT LIKE '%Reupload%' AND LOWER(author_name)<>'unknown'`
	rows, err := dbutil.Query(r.ctx, h.db, `SELECT author_name AS authorName,MIN(download_url) AS download,COUNT(*) AS song_count FROM songs WHERE `+filter+` GROUP BY author_name ORDER BY song_count DESC,author_name ASC LIMIT 20 OFFSET ?`, offset)
	if err != nil {
		return "", err
	}
	if len(rows) == 0 {
		return "-1", nil
	}
	var total int64
	if err = h.db.QueryRowContext(r.ctx, `SELECT COUNT(DISTINCT author_name) FROM songs WHERE `+filter).Scan(&total); err != nil {
		return "", err
	}
	parts := make([]string, 0, len(rows))
	for _, row := range rows {
		name := strings.NewReplacer(":", "", "#", "", "|", "").Replace(row["authorName"])
		item := "4:" + name
		if strings.HasPrefix(row["download"], "https://api.soundcloud.com") {
			item += ":7:../redirect?q=" + strings.ReplaceAll(url.QueryEscape("https://soundcloud.com/search/people?q="+name), "+", "%20")
		}
		parts = append(parts, item)
	}
	return fmt.Sprintf("%s#%d:%d:20", strings.Join(parts, "|"), total, offset), nil
}
