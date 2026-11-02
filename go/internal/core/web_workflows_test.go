package core

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/json"
	"fmt"
	"image"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"regexp"
	"strings"
	"testing"

	"github.com/IZKGMD/GMDmucho-core/go/internal/account"
)

func testWebWorkflows(t *testing.T, h *Handler) {
	post := func(path string, fields url.Values, cookie *http.Cookie) *httptest.ResponseRecorder {
		r := httptest.NewRequest("POST", path, strings.NewReader(fields.Encode()))
		r.Header.Set("Content-Type", "application/x-www-form-urlencoded")
		if cookie != nil {
			r.AddCookie(cookie)
		}
		w := httptest.NewRecorder()
		h.ServeHTTP(w, r)
		return w
	}
	t.Run("native profile images", func(t *testing.T) {
		fixture, err := os.ReadFile("../../testdata/profile-images.json")
		if err != nil {
			t.Fatal(err)
		}
		var references []struct{ Encoded string }
		if err = json.Unmarshal(fixture, &references); err != nil {
			t.Fatal(err)
		}
		fields := url.Values{"accountID": {"1"}, "gjp2": {account.GJP2("test-password")}, "kind": {"avatar"}, "imageData": {references[0].Encoded}, "rotation": {"90"}}
		w := post("/api/profile/upload", fields, nil)
		if w.Code != 200 {
			t.Fatalf("upload %d %s", w.Code, w.Body)
		}
		var response struct {
			OK   bool
			Data struct {
				Media map[string]struct {
					Version              string
					Bytes, Width, Height int
				}
			}
		}
		if err = json.Unmarshal(w.Body.Bytes(), &response); err != nil {
			t.Fatal(err)
		}
		meta := response.Data.Media["avatar"]
		if !response.OK || !profileVersion.MatchString(meta.Version) || meta.Width != 256 || meta.Height != 256 || meta.Bytes <= 0 {
			t.Fatalf("metadata %s", w.Body)
		}
		w = post("/api/profile/image", url.Values{"accountID": {"1"}, "kind": {"avatar"}, "version": {meta.Version}}, nil)
		if w.Code != 200 || w.Header().Get("Content-Type") != "image/jpeg" {
			t.Fatalf("download %d %s", w.Code, w.Body)
		}
		if fmt.Sprintf("%x", sha256.Sum256(w.Body.Bytes())) != meta.Version || w.Body.Len() != meta.Bytes {
			t.Fatal("JPEG hash/size mismatch")
		}
		picture, _, err := image.Decode(bytes.NewReader(w.Body.Bytes()))
		if err != nil || picture.Bounds().Dx() != 256 || picture.Bounds().Dy() != 256 {
			t.Fatalf("JPEG: %v", err)
		}
		w = post("/api/profile/remove", url.Values{"accountID": {"1"}, "gjp2": {"wrong"}, "kind": {"avatar"}}, nil)
		if w.Code != 400 {
			t.Fatal("unauthorized image removal")
		}
		w = post("/api/profile/remove", url.Values{"accountID": {"1"}, "gjp2": {account.GJP2("test-password")}, "kind": {"avatar"}}, nil)
		if w.Code != 200 {
			t.Fatalf("remove %s", w.Body)
		}
		w = post("/api/profile/image", url.Values{"accountID": {"1"}, "kind": {"avatar"}, "version": {meta.Version}}, nil)
		if w.Code != 404 {
			t.Fatal("removed image still available")
		}
	})
	t.Run("password form CSRF and credential revocation", func(t *testing.T) {
		r := httptest.NewRequest("GET", "/accountManagement.php", nil)
		w := httptest.NewRecorder()
		h.ServeHTTP(w, r)
		if w.Code != 200 || w.Header().Get("Cache-Control") != "no-store, private" || w.Header().Get("X-Frame-Options") != "DENY" {
			t.Fatalf("form headers %d %v", w.Code, w.Header())
		}
		cookies := w.Result().Cookies()
		if len(cookies) != 1 || !cookies[0].HttpOnly || cookies[0].SameSite != http.SameSiteLaxMode {
			t.Fatal("missing protected CSRF cookie")
		}
		cookie := cookies[0]
		match := regexp.MustCompile(`name="csrf" value="([a-f0-9]{64})"`).FindStringSubmatch(w.Body.String())
		if len(match) != 2 {
			t.Fatal("missing form CSRF token")
		}
		csrf := match[1]
		fields := url.Values{"username": {"Bob"}, "current_password": {"test-password"}, "password": {"native-new-password"}, "password_confirmation": {"native-new-password"}, "csrf": {"forged"}}
		if w = post("/accountManagement.php", fields, cookie); w.Code != 403 {
			t.Fatal("forged CSRF accepted")
		}
		fields.Set("csrf", csrf)
		fields.Set("current_password", "wrong")
		if w = post("/accountManagement.php", fields, cookie); w.Code != 200 || !strings.Contains(w.Body.String(), "Unable to change") {
			t.Fatalf("wrong password %d %s", w.Code, w.Body)
		}
		if _, err := h.db.Exec(`INSERT INTO account_recovery_tokens(account_id,token_hash,expires_at) VALUES(2,REPEAT('e',64),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR))`); err != nil {
			t.Fatal(err)
		}
		if _, err := h.db.Exec(`INSERT INTO mucho_legacy_19_sessions(account_id,udid_hash,ip_address,expires_at) VALUES(2,'browser-password-test','192.0.2.1',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR))`); err != nil {
			t.Fatal(err)
		}
		fields.Set("current_password", "test-password")
		w = post("/accountManagement.php", fields, cookie)
		if w.Code != 200 || !strings.Contains(w.Body.String(), "Password changed.") {
			t.Fatalf("change %d %s", w.Code, w.Body)
		}
		if _, err := h.accounts.Authenticate(context.Background(), 2, account.GJP2("native-new-password")); err != nil {
			t.Fatal("new GJP2 rejected")
		}
		if _, err := h.accounts.Authenticate(context.Background(), 2, "test-password"); err == nil {
			t.Fatal("old password still accepted")
		}
		for _, table := range []string{"account_recovery_tokens", "mucho_legacy_19_sessions"} {
			var count int
			if err := h.db.QueryRow(`SELECT COUNT(*) FROM ` + table + ` WHERE account_id=2`).Scan(&count); err != nil || count != 0 {
				t.Fatalf("%s not revoked: %d %v", table, count, err)
			}
		}
		newCookies := w.Result().Cookies()
		if len(newCookies) != 1 || newCookies[0].Value == cookie.Value {
			t.Fatal("CSRF cookie not rotated")
		}
		if w = post("/accountManagement.php", fields, newCookies[0]); w.Code != 403 {
			t.Fatal("stale form accepted")
		}
		for _, path := range []string{"/recovery/recovery.css", "/assets/product-ui.css", "/assets/muchocore-icon.png", "/muchocore-theme.js"} {
			request := httptest.NewRequest("GET", path, nil)
			w := httptest.NewRecorder()
			h.ServeHTTP(w, request)
			if w.Code != 200 || w.Body.Len() == 0 {
				t.Fatalf("embedded asset %s: %d", path, w.Code)
			}
		}
	})
}
