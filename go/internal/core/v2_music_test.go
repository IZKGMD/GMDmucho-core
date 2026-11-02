package core

import (
	"bytes"
	"encoding/json"
	"fmt"
	"mime/multipart"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"testing"

	"github.com/IZKGMD/GMDmucho-core/go/internal/account"
)

func testV2MusicUpload(t *testing.T, h *Handler) {
	t.Helper()
	if _, err := h.db.Exec(`DELETE FROM mucho_music_rate_limits WHERE ip='203.0.113.71'`); err != nil {
		t.Fatal(err)
	}
	defer h.db.Exec(`DELETE FROM mucho_music_rate_limits WHERE ip='203.0.113.71'`)
	oldDir, oldURL := h.options.MusicDir, h.options.PublicURL
	defer func() { h.options.MusicDir, h.options.PublicURL = oldDir, oldURL }()
	h.options.MusicDir = t.TempDir()
	h.options.PublicURL = "https://uploads.example.invalid"
	audio, err := os.ReadFile("../../testdata/silence.mp3")
	if err != nil {
		t.Fatal(err)
	}
	upload := func(data []byte) *httptest.ResponseRecorder {
		var body bytes.Buffer
		writer := multipart.NewWriter(&body)
		for key, value := range map[string]string{"account_id": "1", "gjp2": account.GJP2("test-password"), "title": "Native MP3", "artist": "Test Artist"} {
			if err := writer.WriteField(key, value); err != nil {
				t.Error(err)
			}
		}
		file, err := writer.CreateFormFile("file", "../../ignored-original-name.mp3")
		if err != nil {
			t.Error(err)
		}
		if _, err = file.Write(data); err != nil {
			t.Error(err)
		}
		if err = writer.Close(); err != nil {
			t.Error(err)
		}
		r := httptest.NewRequest("POST", "/api/v2/music-upload.php", &body)
		r.Header.Set("Content-Type", writer.FormDataContentType())
		r.RemoteAddr = "203.0.113.71:4200"
		w := httptest.NewRecorder()
		h.ServeHTTP(w, r)
		return w
	}
	w := upload([]byte("ID3not a valid audio file"))
	if w.Code != 415 {
		t.Fatalf("invalid MP3: %d %s", w.Code, w.Body)
	}
	// Force the database insert to fail after the file was stored. Both the
	// file and the cooldown transaction must roll back, permitting a retry.
	if _, err = h.db.Exec(`CREATE TRIGGER native_music_upload_failure BEFORE INSERT ON songs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='native upload failure test'`); err != nil {
		t.Fatal(err)
	}
	defer h.db.Exec(`DROP TRIGGER IF EXISTS native_music_upload_failure`)
	w = upload(audio)
	if w.Code != 500 {
		t.Fatalf("database failure: %d %s", w.Code, w.Body)
	}
	files, err := os.ReadDir(h.options.MusicDir)
	if err != nil || len(files) != 0 {
		t.Fatal("failed upload left a public file")
	}
	var cooldowns int
	if err = h.db.QueryRow(`SELECT COUNT(*) FROM mucho_music_rate_limits WHERE ip='203.0.113.71'`).Scan(&cooldowns); err != nil || cooldowns != 0 {
		t.Fatal("failed upload consumed cooldown")
	}
	if _, err = h.db.Exec(`DROP TRIGGER native_music_upload_failure`); err != nil {
		t.Fatal(err)
	}
	var accepted atomic.Int32
	var wg sync.WaitGroup
	responses := make(chan string, 6)
	for range 6 {
		wg.Go(func() {
			w := upload(audio)
			if w.Code == 201 {
				accepted.Add(1)
				responses <- w.Body.String()
			} else if w.Code != 429 || w.Header().Get("Retry-After") != "180" {
				t.Errorf("concurrent upload: %d %s", w.Code, w.Body)
			}
		})
	}
	wg.Wait()
	if accepted.Load() != 1 {
		t.Fatalf("cooldown admitted %d uploads", accepted.Load())
	}
	var result struct {
		Song struct {
			ID          int64
			DownloadURL string `json:"download_url"`
		}
	}
	if err = json.Unmarshal([]byte(<-responses), &result); err != nil {
		t.Fatal(err)
	}
	download, err := url.Parse(result.Song.DownloadURL)
	if err != nil || download.Host != "uploads.example.invalid" {
		t.Fatal("untrusted download origin")
	}
	filename := strings.TrimPrefix(download.Path, "/music/")
	if !musicFilename.MatchString(filename) {
		t.Fatal("upload reused an unsafe client filename")
	}
	var verified int
	if err = h.db.QueryRow(`SELECT is_verified FROM songs WHERE id=?`, result.Song.ID).Scan(&verified); err != nil || verified != 0 {
		t.Fatal("uploaded music bypassed approval")
	}
	r := httptest.NewRequest("GET", download.Path, nil)
	w = httptest.NewRecorder()
	h.ServeHTTP(w, r)
	if w.Code != 200 || !bytes.Equal(w.Body.Bytes(), audio) || w.Header().Get("Content-Type") != "audio/mpeg" {
		t.Fatal("music download differs from upload")
	}
	r = httptest.NewRequest("GET", download.Path, nil)
	r.Header.Set("Range", "bytes=0-9")
	w = httptest.NewRecorder()
	h.ServeHTTP(w, r)
	if w.Code != 206 || !bytes.Equal(w.Body.Bytes(), audio[:10]) {
		t.Fatal("audio byte ranges unavailable")
	}
	r = httptest.NewRequest("HEAD", download.Path, nil)
	w = httptest.NewRecorder()
	h.ServeHTTP(w, r)
	if w.Code != 200 || w.Body.Len() != 0 || w.Header().Get("Content-Length") != strconv.Itoa(len(audio)) {
		t.Fatal("audio HEAD metadata incorrect")
	}
	outside := filepath.Join(t.TempDir(), "private.txt")
	if err = os.WriteFile(outside, []byte("private test data"), 0600); err != nil {
		t.Fatal(err)
	}
	fake := strings.Repeat("f", 40) + ".mp3"
	if err = os.Symlink(outside, filepath.Join(h.options.MusicDir, fake)); err != nil {
		t.Fatal(err)
	}
	if _, err = h.db.Exec(`INSERT INTO songs(name,author_name,download_url,is_verified) VALUES('Symlink test','Test',?,0)`, fmt.Sprintf("https://uploads.example.invalid/music/%s", fake)); err != nil {
		t.Fatal(err)
	}
	w = httptest.NewRecorder()
	h.ServeHTTP(w, httptest.NewRequest("GET", "/music/"+fake, nil))
	if w.Code != 404 || strings.Contains(w.Body.String(), "private test data") {
		t.Fatal("music serving followed a symlink outside storage")
	}
}
