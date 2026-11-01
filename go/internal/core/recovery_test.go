package core

import (
	"context"
	"crypto/sha256"
	"fmt"
	"net/http"
	"net/http/httptest"
	"net/url"
	"regexp"
	"strings"
	"sync"
	"sync/atomic"
	"testing"

	"github.com/IZKGMD/GMDmucho-core/go/internal/account"
)

type recoveryMailFunc func(context.Context, string, string, string) error

func (f recoveryMailFunc) Recovery(ctx context.Context, email, user, link string) error {
	return f(ctx, email, user, link)
}
func testRecoveryWorkflow(t *testing.T, h *Handler) {
	t.Helper()
	oldMailer, oldURL := h.options.RecoveryMailer, h.options.PublicURL
	defer func() { h.options.RecoveryMailer, h.options.PublicURL = oldMailer, oldURL }()
	h.options.PublicURL = "https://recovery.example.test"
	links := make(chan string, 4)
	h.options.RecoveryMailer = recoveryMailFunc(func(_ context.Context, email, user, link string) error {
		if email != "moderator@example.com" || user != "Moderator" {
			t.Errorf("unexpected test mail recipient")
		}
		links <- link
		return nil
	})
	call := func(method, path string, form url.Values, cookie *http.Cookie) *httptest.ResponseRecorder {
		r := httptest.NewRequest(method, "https://untrusted-host.example"+path, strings.NewReader(form.Encode()))
		r.Header.Set("Content-Type", "application/x-www-form-urlencoded")
		if cookie != nil {
			r.AddCookie(cookie)
		}
		w := httptest.NewRecorder()
		h.ServeHTTP(w, r)
		return w
	}
	const path = "/api/api/accounts/lostusername.php"
	w := call("GET", path, nil, nil)
	cookies := w.Result().Cookies()
	if w.Code != 200 || len(cookies) != 1 || cookies[0].Name != "MUCHO_GO_RECOVERY" || !cookies[0].HttpOnly {
		t.Fatalf("recovery form %d %v", w.Code, cookies)
	}
	cookie := cookies[0]
	match := regexp.MustCompile(`name="csrf" value="([a-f0-9]{64})"`).FindStringSubmatch(w.Body.String())
	if len(match) != 2 {
		t.Fatal("missing recovery CSRF")
	}
	csrf := match[1]
	w = call("POST", path, url.Values{"csrf": {"wrong"}, "action": {"request"}, "identity": {"Moderator"}}, cookie)
	if !strings.Contains(w.Body.String(), "This page has expired.") {
		t.Fatal("recovery CSRF bypass")
	}
	request := url.Values{"csrf": {csrf}, "action": {"request"}, "identity": {"does-not-exist"}}
	w = call("POST", path, request, cookie)
	unknown := w.Body.String()
	if w.Code != 200 || !strings.Contains(unknown, "If an eligible account matches") {
		t.Fatalf("unknown identity %s", w.Body)
	}
	if len(links) != 0 {
		t.Fatal("sent mail for unknown account")
	}
	request.Set("identity", "Moderator")
	w = call("POST", path, request, cookie)
	if w.Code != 200 || w.Body.String() != unknown {
		t.Fatal("recovery response reveals account eligibility")
	}
	link := <-links
	parsed, err := url.Parse(link)
	if err != nil {
		t.Fatal(err)
	}
	if parsed.Host != "recovery.example.test" {
		t.Fatal("recovery trusted the request Host")
	}
	token := parsed.Query().Get("token")
	if !profileVersion.MatchString(token) {
		t.Fatal("invalid mail token")
	}
	hash := fmt.Sprintf("%x", sha256.Sum256([]byte(token)))
	var stored string
	if err = h.db.QueryRow(`SELECT token_hash FROM account_recovery_tokens WHERE account_id=4`).Scan(&stored); err != nil || stored != hash || stored == token {
		t.Fatal("recovery token storage must contain only its hash")
	}
	w = call("GET", path+"?token="+strings.ToUpper(token), nil, cookie)
	if w.Code != 200 || !strings.Contains(w.Body.String(), "New password") {
		t.Fatalf("valid link %s", w.Body)
	}
	if _, err = h.db.Exec(`INSERT INTO mucho_legacy_19_sessions(account_id,udid_hash,ip_address,expires_at) VALUES(4,'recovery-session-test','192.0.2.1',DATE_ADD(NOW(),INTERVAL 1 HOUR))`); err != nil {
		t.Fatal(err)
	}
	var wg sync.WaitGroup
	var successful atomic.Int32
	for range 2 {
		wg.Go(func() {
			form := url.Values{"csrf": {csrf}, "action": {"reset"}, "token": {token}, "password": {"recovered-native-password"}, "password_confirmation": {"recovered-native-password"}}
			w := call("POST", path, form, cookie)
			if w.Code != 200 {
				t.Errorf("reset HTTP %d", w.Code)
			}
			if strings.Contains(w.Body.String(), "Your new password has been saved.") {
				successful.Add(1)
			}
		})
	}
	wg.Wait()
	if successful.Load() != 1 {
		t.Fatalf("one-time token accepted %d times", successful.Load())
	}
	if _, err = h.accounts.Authenticate(context.Background(), 4, account.GJP2("recovered-native-password")); err != nil {
		t.Fatal("recovered credentials rejected")
	}
	if _, err = h.accounts.Authenticate(context.Background(), 4, "test-password"); err == nil {
		t.Fatal("old recovered password accepted")
	}
	var sessions int
	if err = h.db.QueryRow(`SELECT COUNT(*) FROM mucho_legacy_19_sessions WHERE account_id=4`).Scan(&sessions); err != nil || sessions != 0 {
		t.Fatal("recovery did not revoke legacy sessions")
	}
	w = call("GET", path+"?token="+token, nil, cookie)
	if !strings.Contains(w.Body.String(), "Invalid link") {
		t.Fatal("used recovery token remained valid")
	}
	w = call("POST", path, request, cookie)
	if w.Code != 200 {
		t.Fatal("second recovery request failed")
	}
	next, _ := url.Parse(<-links)
	expired := next.Query().Get("token")
	if _, err = h.db.Exec(`UPDATE account_recovery_tokens SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE account_id=4`); err != nil {
		t.Fatal(err)
	}
	if valid, err := h.accounts.RecoveryTokenValid(context.Background(), expired); err != nil || valid {
		t.Fatal("expired token valid")
	}
	if ok, err := h.accounts.ResetPassword(context.Background(), expired, "another-password", "another-password", "192.0.2.1", h.protect.Allow); err != nil || ok {
		t.Fatal("expired token consumed")
	}
}
