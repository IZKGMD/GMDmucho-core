package core

import (
	"crypto/sha256"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"strings"
	"sync"
	"sync/atomic"
	"testing"

	"github.com/IZKGMD/GMDmucho-core/go/internal/security"
)

func testV2Public(t *testing.T, h *Handler) {
	t.Helper()
	call := func(handler *Handler, method, path, body, contentType string) (*httptest.ResponseRecorder, map[string]any) {
		r := httptest.NewRequest(method, path, strings.NewReader(body))
		r.Header.Set("Content-Type", contentType)
		r.Header.Set("X-Request-ID", "native-v2-test-0001")
		r.RemoteAddr = "198.51.100.17:4000"
		w := httptest.NewRecorder()
		handler.ServeHTTP(w, r)
		var payload map[string]any
		if err := json.Unmarshal(w.Body.Bytes(), &payload); err != nil {
			t.Errorf("invalid API response: %d %q", w.Code, w.Body.String())
			return w, nil
		}
		if payload["request_id"] != "native-v2-test-0001" || w.Header().Get("X-Request-ID") != "native-v2-test-0001" || w.Header().Get("Cache-Control") != "no-store" {
			t.Error("missing API request metadata")
		}
		return w, payload
	}
	t.Run("public schemas and privacy", func(t *testing.T) {
		if _, err := h.db.Exec(`INSERT INTO mucho_profile_customization(account_id,display_name,badges,pinned_levels,showcase,online_visible) VALUES(1,'Native Profile','["one","one",false,1.0,{"ignored":true}]','4,4 6,7,2','one,two,one',1),(2,'Hidden Presence','{}','','',0) ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),badges=VALUES(badges),pinned_levels=VALUES(pinned_levels),showcase=VALUES(showcase),online_visible=VALUES(online_visible)`); err != nil {
			t.Fatal(err)
		}
		if _, err := h.db.Exec(`INSERT INTO mucho_profile_presence(account_id,last_seen) VALUES(1,NOW()),(2,NOW()) ON DUPLICATE KEY UPDATE last_seen=VALUES(last_seen)`); err != nil {
			t.Fatal(err)
		}
		w, body := call(h, "GET", "/api/v2/health.php", "", "")
		if w.Code != 200 || body["database"] != "connected" {
			t.Fatalf("health %s", w.Body)
		}
		w, body = call(h, "GET", "/api/v2/profile.php?account_id=1", "", "")
		if w.Code != 200 {
			t.Fatalf("profile %s", w.Body)
		}
		data := body["data"].(map[string]any)
		presence := data["presence"].(map[string]any)
		if presence["online"] != true || presence["visible"] != true || presence["last_seen"] == nil {
			t.Fatal("visible presence missing")
		}
		if got := fmt.Sprint(data["badges"]); got != "[one 1]" {
			t.Fatalf("badge parsing %s", got)
		}
		if got := fmt.Sprint(data["pinned_levels"]); got != "[4 6 7]" {
			t.Fatalf("pinned levels %s", got)
		}
		w, body = call(h, "GET", "/api/v2/profile.php?account_id=2", "", "")
		if w.Code != 200 {
			t.Fatalf("profile %s", w.Body)
		}
		presence = body["data"].(map[string]any)["presence"].(map[string]any)
		if presence["online"] != false || presence["last_seen"] != nil {
			t.Fatal("hidden presence leaked")
		}
		for _, tc := range []struct {
			path   string
			status int
			code   string
		}{{"/api/v2/profile.php?account_id=01", 400, "invalid_account_id"}, {"/api/v2/profile.php?account_id=999999", 404, "profile_not_found"}, {"/api/v2/client-config.php?platform=invalid", 400, "invalid_platform"}} {
			w, payload := call(h, "GET", tc.path, "", "")
			if w.Code != tc.status || payload["error"] != tc.code {
				t.Fatalf("%s: %d %s", tc.path, w.Code, w.Body)
			}
		}
		w, body = call(h, "POST", "/api/v2/health.php", "", "")
		if w.Code != 405 || body["error"] != "method_not_allowed" || w.Header().Get("Allow") != "GET" {
			t.Fatal("API method restriction")
		}
		w, body = call(h, "GET", "/api/v2/dashboard.php", "", "")
		if w.Code != 200 {
			t.Fatalf("dashboard %s", w.Body)
		}
		for _, value := range body["latest_levels"].([]any) {
			id := value.(map[string]any)["id"].(float64)
			if id == 1 || id == 2 || id == 3 {
				t.Fatal("deleted/private level in public dashboard")
			}
		}
		w, body = call(h, "GET", "/api/v2/branding.php", "", "")
		if w.Code != 200 || body["server_name"] == "" {
			t.Fatal("missing branding")
		}
		if _, err := h.db.Exec(`INSERT INTO mucho_client_releases(platform,current_version,minimum_version,maintenance) VALUES('windows','1.2.3','1.0.0',1) ON DUPLICATE KEY UPDATE current_version=VALUES(current_version),minimum_version=VALUES(minimum_version),maintenance=VALUES(maintenance)`); err != nil {
			t.Fatal(err)
		}
		w, body = call(h, "GET", "/api/v2/client-config.php?platform=windows&version=1.0.0RC1", "", "")
		if w.Code != 200 {
			t.Fatalf("config %s", w.Body)
		}
		client := body["client"].(map[string]any)
		if client["update_available"] != true || client["update_required"] != true || client["maintenance"] != true {
			t.Fatal("incorrect release flags")
		}
		w, body = call(h, "GET", "/api/v2/music.php", "", "")
		if w.Code != 200 || body["songs"] == nil {
			t.Fatal("missing music catalog")
		}
	})
	t.Run("presence tokens and simultaneous heartbeat", func(t *testing.T) {
		token := "native-presence-test-token-01234567890123456789"
		hash := fmt.Sprintf("%x", sha256.Sum256([]byte(token)))
		if _, err := h.db.Exec(`UPDATE mucho_profile_presence SET client_token_hash=?,last_seen=DATE_SUB(NOW(),INTERVAL 10 SECOND) WHERE account_id=1`, hash); err != nil {
			t.Fatal(err)
		}
		w, body := call(h, "POST", "/api/v2/heartbeat.php", `{"account_id":1,"token":"wrong-token-but-at-least-32-characters"}`, "application/json")
		if w.Code != 401 || body["error"] != "unauthorized" {
			t.Fatal("invalid presence token accepted")
		}
		var wg sync.WaitGroup
		var accepted atomic.Int32
		for range 8 {
			wg.Go(func() {
				w, body := call(h, "POST", "/api/v2/heartbeat.php", fmt.Sprintf(`{"account_id":1,"token":%q}`, token), "application/json")
				if w.Code == 200 {
					accepted.Add(1)
				} else if w.Code != 429 || body["error"] != "heartbeat_rate_limited" || w.Header().Get("Retry-After") != "5" {
					t.Errorf("heartbeat %d %s", w.Code, w.Body)
				}
			})
		}
		wg.Wait()
		if accepted.Load() != 1 {
			t.Fatalf("concurrent heartbeats accepted %d times", accepted.Load())
		}
		w, body = call(h, "POST", "/api/v2/heartbeat.php", strings.Repeat("x", 65537), "application/json")
		if w.Code != 413 || body["error"] != "invalid_request" {
			t.Fatal("unbounded JSON body")
		}
	})
	t.Run("JSON security failures and retry headers", func(t *testing.T) {
		protected := New(h.db, Options{})
		protected.protect.Policies = map[string]security.Policy{"/v2/health": {Limit: 1, Window: 60, Burst: 10, BurstWindow: 10}}
		w, _ := call(protected, "GET", "/api/v2/health.php", "", "")
		if w.Code != 200 {
			t.Fatalf("initial protected request %s", w.Body)
		}
		w, body := call(protected, "GET", "/api/v2/health.php", "", "")
		if w.Code != 429 || body["error"] != "rate_limited" || w.Header().Get("Retry-After") != "60" {
			t.Fatalf("rate response %d %s", w.Code, w.Body)
		}
		w, body = call(protected, "GET", "/api/v2/health.php", "", "")
		if w.Code != 429 || body["retry_after_seconds"] != float64(15) {
			t.Fatal("penalty retry interval")
		}
		var events int
		if err := h.db.QueryRow(`SELECT COUNT(*) FROM mucho_security_events WHERE request_id='native-v2-test-0001' AND event_type='rate_limit'`).Scan(&events); err != nil || events != 2 {
			t.Fatalf("security audit %d: %v", events, err)
		}
		var requests int
		if err := h.db.QueryRow(`SELECT SUM(requests) FROM mucho_api_metrics_minute WHERE route='/api/v2/health.php' AND status=429`).Scan(&requests); err != nil || requests != 2 {
			t.Fatalf("API metric %d: %v", requests, err)
		}
	})
}
