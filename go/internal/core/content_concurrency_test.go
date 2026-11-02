package core

import (
	"bytes"
	"crypto/sha256"
	"encoding/base64"
	"fmt"
	"net/http/httptest"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"testing"

	"github.com/IZKGMD/GMDmucho-core/go/internal/account"
)

func testContentConcurrency(t *testing.T, h *Handler) {
	t.Helper()
	post := func(actor int64, path string, form url.Values) *httptest.ResponseRecorder {
		form.Set("accountID", fmt.Sprint(actor))
		form.Set("gjp2", account.GJP2("test-password"))
		r := httptest.NewRequest("POST", path, strings.NewReader(form.Encode()))
		r.Header.Set("Content-Type", "application/x-www-form-urlencoded")
		w := httptest.NewRecorder()
		h.ServeHTTP(w, r)
		return w
	}
	t.Run("sender limit across different threads", func(t *testing.T) {
		var existing int
		if err := h.db.QueryRow(`SELECT COUNT(*) FROM mucho_thread_messages WHERE sender_account_id=1 AND created_at>UTC_TIMESTAMP()-INTERVAL 60 SECOND`).Scan(&existing); err != nil {
			t.Fatal(err)
		}
		if existing >= 20 {
			t.Fatal("missing room for concurrent send test")
		}
		var wg sync.WaitGroup
		var accepted atomic.Int32
		for i := range 30 {
			wg.Go(func() {
				id := 2
				if i%2 == 0 {
					id = 4
				}
				w := post(1, "/sendGJThreadMessage.php", url.Values{"threadID": {fmt.Sprint(id)}, "body": {base64.URLEncoding.EncodeToString([]byte("Concurrent message"))}})
				if w.Code == 200 && strings.HasPrefix(w.Body.String(), fmt.Sprintf("%d:", id)) {
					accepted.Add(1)
				} else if w.Body.String() != "-1" {
					t.Errorf("send: %d %s", w.Code, w.Body)
				}
			})
		}
		wg.Wait()
		if accepted.Load() != int32(20-existing) {
			t.Fatalf("accepted %d; budget %d", accepted.Load(), 20-existing)
		}
	})
	t.Run("sound quota and maximum encoded upload", func(t *testing.T) {
		defer h.db.Exec(`DELETE FROM mucho_editor_sounds WHERE account_id IN (4,5)`)
		for i := range 49 {
			data := []byte(fmt.Sprintf("OggS%08d", i))
			hash := fmt.Sprintf("%x", sha256.Sum256(data))
			if _, err := h.db.Exec(`INSERT INTO mucho_editor_sounds(account_id,title,description,mime_type,audio_bytes,size_bytes,sha256) VALUES(4,'Quota fixture','','audio/ogg',?,?,?)`, data, len(data), hash); err != nil {
				t.Fatal(err)
			}
		}
		var wg sync.WaitGroup
		var accepted atomic.Int32
		for i := range 10 {
			wg.Go(func() {
				data := []byte(fmt.Sprintf("ID3quota%08d", i))
				w := post(4, "/uploadGJEditorSound.php", url.Values{"title": {"Concurrent sound"}, "data": {base64.StdEncoding.EncodeToString(data)}})
				id, _ := strconv.ParseInt(w.Body.String(), 10, 64)
				if w.Code == 200 && id > 0 {
					accepted.Add(1)
				} else if w.Body.String() != "-1" {
					t.Errorf("sound: %d %s", w.Code, w.Body)
				}
			})
		}
		wg.Wait()
		if accepted.Load() != 1 {
			t.Fatalf("%d uploads into one free slot", accepted.Load())
		}
		var count int
		if err := h.db.QueryRow(`SELECT COUNT(*) FROM mucho_editor_sounds WHERE account_id=4`).Scan(&count); err != nil || count != 50 {
			t.Fatalf("file quota %d: %v", count, err)
		}
		// Standard Base64 '/' expands threefold in a URL-encoded form. The
		// maximum supported file must fit the HTTP limit in that worst case.
		data := bytes.Repeat([]byte{255}, 2097152)
		w := post(5, "/uploadGJEditorSound.php", url.Values{"title": {"Maximum MP3 signature"}, "data": {base64.StdEncoding.EncodeToString(data)}})
		id, _ := strconv.ParseInt(w.Body.String(), 10, 64)
		if w.Code != 200 || id <= 0 {
			t.Fatalf("maximum encoded audio rejected: %d %s", w.Code, w.Body)
		}
		var size int
		if err := h.db.QueryRow(`SELECT OCTET_LENGTH(audio_bytes) FROM mucho_editor_sounds WHERE id=?`, id).Scan(&size); err != nil || size != len(data) {
			t.Fatalf("stored bytes %d: %v", size, err)
		}
	})
}
