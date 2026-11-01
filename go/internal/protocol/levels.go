package protocol

import (
	"bytes"
	"compress/zlib"
	"encoding/base64"
	"fmt"
	"regexp"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
)

var fieldStrip = strings.NewReplacer("\x00", "", "\r", "", "\n", "", ":", "", "|", "", "#", "", "~", "")

func Field(s string, limit int) string {
	s = fieldStrip.Replace(s)
	if len(s) > max(0, limit) {
		s = s[:max(0, limit)]
	}
	return s
}
func WireDecode(s string) string {
	v := strings.NewReplacer("-", "+", "_", "/").Replace(s)
	v += strings.Repeat("=", (4-len(v)%4)%4)
	b, err := base64.StdEncoding.DecodeString(v)
	if err != nil || len(b) == 0 || !utf8.Valid(b) {
		return s
	}
	for _, c := range b {
		if c <= 8 || c == 11 || c == 12 || (c >= 14 && c <= 31) || c == 127 {
			return s
		}
	}
	return string(b)
}
func DescriptionStorage(s string, game int) string {
	if game < 20 {
		return base64.URLEncoding.EncodeToString([]byte(s))
	}
	return WireDecode(s)
}
func DescriptionResponse(s string, game int) string {
	if game < 20 {
		return strings.NewReplacer("+", "-", "/", "_").Replace(s)
	}
	return base64.URLEncoding.EncodeToString([]byte(s))
}

var wirePattern = regexp.MustCompile(`^[A-Za-z0-9+/_-]+={0,2}$`)

func CommentResponse(s string, game int) string {
	if game < 20 {
		return WireDecode(s)
	}
	if wirePattern.MatchString(s) && WireDecode(s) != s {
		return strings.NewReplacer("+", "-", "/", "_").Replace(s)
	}
	return base64.URLEncoding.EncodeToString([]byte(s))
}
func Epoch(s string) int64 {
	t, err := time.ParseInLocation("2006-01-02 15:04:05", s, time.UTC)
	if err != nil {
		return 0
	}
	return max(0, t.Unix())
}
func difficulty(v int64) int64 {
	if v >= 1 && v <= 6 {
		return v * 10
	}
	return max(0, v)
}

func LevelList(rows []dbutil.Row, total, offset, game, binary int) string {
	if len(rows) == 0 || total == 0 {
		return "-2"
	}
	var levels, users, songs []string
	seenUsers, seenSongs := map[string]bool{}, map[string]bool{}
	hash := ""
	for _, r := range rows {
		id, uid, aid := r["level_id"], r.Value("user_id", r.Value("account_id", "1")), r.Value("account_id", "1")
		item := Pairs(1, id, 2, Field(r["name"], 65535), 5, r.Int("level_version"), 6, uid, 8, 10, 9, difficulty(r.Int("difficulty")), 10, r.Int("downloads"), 12, r.Int("audio_track"), 13, r.Int("game_version"), 14, r.Int("likes"), 17, r.Int("demon"), 43, r.Int("demon_difficulty"), 25, r.Int("auto_level"), 18, r.Int("stars"), 19, r.Int("featured"), 42, r.Int("epic"), 45, r.Int("object_count"), 3, Field(DescriptionResponse(r["description"], game), 65535), 15, r.Int("length"), 30, r.Int("original_level_id"), 31, r.Int("two_player"), 37, r.Int("coins"), 38, r.Int("coins_verified"), 39, r.Int("requested_stars"), 46, 1, 47, 2, 40, r.Int("ldm"), 35, r.Int("song_id"))
		if game >= 22 && binary >= 51 {
			item += Pairs("", 62, Epoch(r["created_at"]), 63, Epoch(r["updated_at"]))
		}
		levels = append(levels, item)
		if !seenUsers[uid] {
			seenUsers[uid] = true
			users = append(users, Pairs(uid, Username(r.Value("username", "Player")), aid))
		}
		if sid := r["song_protocol_id"]; game > 18 && r.Int("song_protocol_id") > 0 && !seenSongs[sid] {
			seenSongs[sid] = true
			songs = append(songs, strings.Join([]string{"1", sid, "2", Field(r["song_name"], 65535), "3", strconv.FormatInt(r.Int("song_author_id"), 10), "4", Field(r["song_author_name"], 65535), "5", roundedSize(r.Float("song_size")), "6", r["song_youtube_video_id"], "10", r["song_download_url"], "7", r["song_youtube_channel_id"], "8", "1"}, "~|~"))
		}
		hash += fmt.Sprintf("%c%c%d%d", id[0], id[len(id)-1], max(0, r.Int("stars")), r.Int("coins"))
	}
	return fmt.Sprintf("%s#%s#%s#%d:%d:10#%s", strings.Join(levels, "|"), strings.Join(users, "|"), strings.Join(songs, "~:~"), total, offset, MetadataHash(hash))
}

func LevelDownload(r dbutil.Row, game, binary int, extras bool) (string, error) {
	data := r["level_data"]
	if game > 18 && strings.HasPrefix(data, "kS1") {
		var b bytes.Buffer
		w := zlib.NewWriter(&b)
		if _, err := w.Write([]byte(data)); err != nil {
			return "", err
		}
		if err := w.Close(); err != nil {
			return "", err
		}
		data = base64.URLEncoding.EncodeToString(b.Bytes())
	}
	desc := DescriptionResponse(r["description"], game)
	if game < 20 {
		desc = WireDecode(r["description"])
	}
	password := r["copy_password"]
	if game > 19 {
		password = CopyPassword(password)
	}
	date := func(s string) string {
		t, err := time.ParseInLocation("2006-01-02 15:04:05", s, time.UTC)
		if err != nil {
			return ""
		}
		return t.Format("02-01-2006 ") + strconv.Itoa(t.Hour()) + t.Format("-04")
	}
	item := Pairs(1, r["level_id"], 2, Field(r["name"], 64), 3, Field(desc, 8192), 4, data, 5, r.Int("level_version"), 6, r.Int("user_id"), 8, 10, 9, difficulty(r.Int("difficulty")), 10, r.Int("downloads"), 11, 1, 12, r.Int("audio_track"), 13, r.Int("game_version"), 14, r.Int("likes"), 17, r.Int("demon"), 43, r.Int("demon_difficulty"), 25, r.Int("auto_level"), 18, r.Int("stars"), 19, r.Int("featured"), 42, r.Int("epic"), 45, r.Int("object_count"), 15, r.Int("length"), 30, r.Int("original_level_id"), 31, r.Int("two_player"), 28, date(r["created_at"]), 29, date(r["updated_at"]), 35, r.Int("song_id"), 36, r["extra_string"], 37, r.Int("coins"), 38, r.Int("coins_verified"), 39, r.Int("requested_stars"), 46, r.Int("wt"), 47, r.Int("wt2"), 48, r["settings_string"], 40, r.Int("ldm"), 27, password, 52, r["song_ids"], 53, r["sfx_ids"], 57, r.Int("ts"))
	if game >= 22 && binary >= 51 {
		item += Pairs("", 62, Epoch(r["created_at"]), 63, Epoch(r["updated_at"]))
	}
	if extras {
		item += Pairs("", 26, r["level_info"])
	}
	metadata := fmt.Sprintf("%d,%d,%d,%d,%d,%d,%s,0", r.Int("user_id"), r.Int("stars"), r.Int("demon"), r.Int("level_id"), r.Int("coins"), r.Int("featured"), r["copy_password"])
	return item + "#" + LevelHash(data) + "#" + MetadataHash(metadata), nil
}
