package core

import (
	"context"
	"errors"
	"io"
	"math"
	"net/http"
	"os"
	"regexp"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
	"github.com/IZKGMD/GMDmucho-core/go/internal/media"
)

const maxMusicBytes = 20 * 1024 * 1024

var musicFilename = regexp.MustCompile(`^[a-f0-9]{40}\.mp3$`)

func (h *Handler) musicDirectory() string {
	if h.options.MusicDir != "" {
		return h.options.MusicDir
	}
	return "/var/www/mucho-core/storage/music-public"
}
func (h *Handler) v2MusicUpload(r request, input map[string]any) (v2Result, error) {
	idValue, exists := input["account_id"]
	if !exists {
		idValue = input["accountID"]
	}
	id := v2Int(idValue)
	credentialValue, exists := input["gjp2"]
	if !exists {
		credentialValue = input["gjp"]
	}
	credential := v2Trim(v2String(credentialValue))
	if id <= 0 || credential == "" {
		return v2Error("unauthorized", 401), nil
	}
	if _, err := h.accounts.Authenticate(r.ctx, id, credential); err != nil {
		return v2Result{}, err
	}
	title, artist := v2Trim(v2String(input["title"])), v2Trim(v2String(input["artist"]))
	if title == "" || !utf8.ValidString(title) || utf8.RuneCountInString(title) > 128 {
		return v2Error("invalid_title", 400), nil
	}
	if artist == "" || !utf8.ValidString(artist) || utf8.RuneCountInString(artist) > 128 {
		return v2Error("invalid_artist", 400), nil
	}
	file, header, err := r.raw.FormFile("file")
	if errors.Is(err, http.ErrMissingFile) || r.raw.MultipartForm == nil {
		return v2Error("file_required", 400), nil
	}
	if err != nil {
		return v2Error("upload_failed", 400), nil
	}
	defer file.Close()
	if header.Size <= 0 || header.Size > maxMusicBytes {
		return v2Error("file_too_large", 413), nil
	}
	if !media.MP3(file, header.Size) {
		return v2Error("mp3_only", 415), nil
	}
	base := v2Trim(h.options.PublicURL)
	if base == "" {
		base = v2Trim(h.options.AccountURL)
	}
	if !recoveryPublicBase.MatchString(base) {
		return v2Result{}, errors.New("trusted public URL required")
	}
	tx, err := h.db.BeginTx(r.ctx, nil)
	if err != nil {
		return v2Result{}, err
	}
	defer tx.Rollback()
	if _, err = tx.ExecContext(r.ctx, `INSERT INTO mucho_music_rate_limits(ip,last_upload_at) VALUES(?,NULL) ON DUPLICATE KEY UPDATE ip=VALUES(ip)`, r.ip); err != nil {
		return v2Result{}, err
	}
	rate, err := dbutil.One(r.ctx, tx, `SELECT last_upload_at,UNIX_TIMESTAMP(NOW())-UNIX_TIMESTAMP(last_upload_at) AS elapsed FROM mucho_music_rate_limits WHERE ip=? FOR UPDATE`, r.ip)
	if err != nil {
		return v2Result{}, err
	}
	if rate["last_upload_at"] != "" && rate.Int("elapsed") < 180 {
		remaining := 180 - rate.Int("elapsed")
		return v2Result{data: map[string]any{"ok": false, "error": "music_rate_limited", "retry_after_seconds": remaining}, status: 429, headers: http.Header{"Retry-After": {strconv.FormatInt(remaining, 10)}}}, nil
	}
	if err = os.MkdirAll(h.musicDirectory(), 0770); err != nil {
		return v2Result{}, err
	}
	root, err := os.OpenRoot(h.musicDirectory())
	if err != nil {
		return v2Result{}, err
	}
	defer root.Close()
	filename, err := randomHex(20)
	if err != nil {
		return v2Result{}, err
	}
	filename += ".mp3"
	saved := false
	defer func() {
		if !saved {
			_ = root.Remove(filename)
		}
	}()
	target, err := root.OpenFile(filename, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0640)
	if err != nil {
		return v2Result{}, err
	}
	if _, err = file.Seek(0, io.SeekStart); err != nil {
		target.Close()
		return v2Result{}, err
	}
	copied, err := io.Copy(target, io.LimitReader(file, maxMusicBytes+1))
	closeErr := target.Close()
	if err != nil {
		return v2Result{}, err
	}
	if closeErr != nil {
		return v2Result{}, closeErr
	}
	if copied != header.Size || copied > maxMusicBytes {
		return v2Result{}, errors.New("incomplete music upload")
	}
	download := strings.TrimRight(base, "/") + "/music/" + filename
	size := math.Round(float64(copied)/1048576*100) / 100
	result, err := tx.ExecContext(r.ctx, `INSERT INTO songs(name,author_id,author_name,size,download_url,is_verified) VALUES(?,?,?,?,?,0)`, title, id, artist, size, download)
	if err != nil {
		return v2Result{}, err
	}
	songID, err := result.LastInsertId()
	if err != nil {
		return v2Result{}, err
	}
	if _, err = tx.ExecContext(r.ctx, `UPDATE mucho_music_rate_limits SET last_upload_at=NOW() WHERE ip=?`, r.ip); err != nil {
		return v2Result{}, err
	}
	if err = tx.Commit(); err != nil {
		return v2Result{}, err
	}
	saved = true
	return v2Result{data: map[string]any{"ok": true, "song": map[string]any{"id": songID, "name": title, "artist": artist, "size_mb": size, "download_url": download}, "next_upload_seconds": 180}, status: 201}, nil
}
func (h *Handler) serveMusic(w http.ResponseWriter, r *http.Request) bool {
	if !strings.HasPrefix(r.URL.Path, "/music/") {
		return false
	}
	w.Header().Set("X-Content-Type-Options", "nosniff")
	if r.Method != "GET" && r.Method != "HEAD" {
		w.Header().Set("Allow", "GET, HEAD")
		w.WriteHeader(405)
		return true
	}
	filename := strings.TrimPrefix(r.URL.Path, "/music/")
	if !musicFilename.MatchString(filename) {
		http.NotFound(w, r)
		return true
	}
	ctx, cancel := context.WithTimeout(r.Context(), 3*time.Second)
	defer cancel()
	var found int
	err := h.db.QueryRowContext(ctx, `SELECT COUNT(*) FROM songs WHERE download_url LIKE ?`, "%/music/"+filename).Scan(&found)
	if err != nil {
		w.WriteHeader(503)
		return true
	}
	if found == 0 {
		http.NotFound(w, r)
		return true
	}
	root, err := os.OpenRoot(h.musicDirectory())
	if err != nil {
		http.NotFound(w, r)
		return true
	}
	defer root.Close()
	file, err := root.Open(filename)
	if err != nil {
		http.NotFound(w, r)
		return true
	}
	defer file.Close()
	stat, err := file.Stat()
	if err != nil || !stat.Mode().IsRegular() {
		http.NotFound(w, r)
		return true
	}
	w.Header().Set("Content-Type", "audio/mpeg")
	w.Header().Set("Cache-Control", "public, max-age=31536000, immutable")
	http.ServeContent(w, r, filename, stat.ModTime(), file)
	return true
}
