package core

import (
	"context"
	"database/sql"
	"errors"
	"net/http"
	"net/http/httptest"
	"net/url"
	"regexp"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/IZKGMD/GMDmucho-core/go/internal/admin"
)

type adminTestBrowser struct {
	t       *testing.T
	h       *Handler
	cookies map[string]*http.Cookie
	csrf    string
}

var browserCSRFPattern = regexp.MustCompile(`name="csrf" value="([a-f0-9]{64})"`)

func newAdminTestBrowser(t *testing.T, h *Handler) *adminTestBrowser {
	return &adminTestBrowser{t: t, h: h, cookies: map[string]*http.Cookie{}}
}
func (b *adminTestBrowser) request(method, path string, form url.Values) *httptest.ResponseRecorder {
	b.t.Helper()
	r := httptest.NewRequest(method, path, strings.NewReader(form.Encode()))
	r.RemoteAddr = "198.18.44.1:1234"
	if method == "POST" {
		r.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	}
	for _, cookie := range b.cookies {
		r.AddCookie(cookie)
	}
	w := httptest.NewRecorder()
	b.h.ServeHTTP(w, r)
	for _, cookie := range w.Result().Cookies() {
		if cookie.MaxAge < 0 {
			delete(b.cookies, cookie.Name)
		} else {
			b.cookies[cookie.Name] = cookie
		}
	}
	if match := browserCSRFPattern.FindStringSubmatch(w.Body.String()); len(match) == 2 {
		b.csrf = match[1]
	}
	return w
}
func (b *adminTestBrowser) post(page, action string, form url.Values) *httptest.ResponseRecorder {
	b.t.Helper()
	if form == nil {
		form = url.Values{}
	}
	form.Set("csrf", b.csrf)
	form.Set("action", action)
	return b.request("POST", "/admin/?page="+page, form)
}
func (b *adminTestBrowser) login(name, password, otp string) string {
	b.t.Helper()
	if w := b.request("GET", "/admin/", nil); w.Code != 200 {
		b.t.Fatalf("login page: %d %s", w.Code, w.Body)
	}
	w := b.post("dashboard", "login", url.Values{"username": {name}, "password": {password}, "otp": {otp}})
	if w.Code != 303 || w.Header().Get("Location") != "/admin/" {
		b.t.Fatalf("browser sign-in: %d %s", w.Code, w.Body)
	}
	cookie := b.cookies[adminSessionCookie]
	if cookie == nil || !cookie.HttpOnly || cookie.Path != "/admin" || cookie.SameSite != http.SameSiteStrictMode {
		b.t.Fatal("unsafe administrator session cookie")
	}
	if w = b.request("GET", "/admin/", nil); w.Code != 200 || !strings.Contains(w.Body.String(), "Welcome, "+name) {
		b.t.Fatalf("overview: %d %s", w.Code, w.Body)
	}
	return cookie.Value
}
func expectAdminCode(t *testing.T, err error, code string) {
	t.Helper()
	var denied *admin.Error
	if !errors.As(err, &denied) || denied.Code != code {
		t.Fatalf("expected %s, got %v", code, err)
	}
}

// Called against a new schema created by the Go initializer, independently of
// the stateful PHP differential fixture and existing installation data.
func testNativeAdministration(t *testing.T, db *sql.DB) {
	ctx := context.Background()
	h := New(db, Options{DisableProtect: true, Now: func() time.Time { return time.Unix(1700000000, 0) }})
	service := admin.Service{DB: db, Now: h.now}
	const password = "owner-password-native"
	var wg sync.WaitGroup
	owners := make(chan admin.Identity, 2)
	denials := make(chan error, 2)
	for _, name := range []string{"FirstOwnerA", "FirstOwnerB"} {
		wg.Add(1)
		go func(name string) {
			defer wg.Done()
			identity, err := service.CreateInitialOwner(ctx, name, password)
			if err != nil {
				denials <- err
			} else {
				owners <- identity
			}
		}(name)
	}
	wg.Wait()
	close(owners)
	close(denials)
	if len(owners) != 1 || len(denials) != 1 {
		t.Fatalf("bootstrap: %d owners, %d refusals", len(owners), len(denials))
	}
	owner := <-owners
	expectAdminCode(t, <-denials, "already_initialized")
	service.Cost = 4
	h.accounts.Cost = 4
	var count int
	if err := db.QueryRow(`SELECT COUNT(*) FROM admin_users`).Scan(&count); err != nil || count != 1 {
		t.Fatal("bootstrap created multiple owners", err)
	}
	browser := newAdminTestBrowser(t, h)
	w := browser.request("GET", "/admin/", nil)
	if w.Code != 200 || !strings.Contains(w.Header().Get("Content-Security-Policy"), "frame-ancestors 'none'") || w.Header().Get("Referrer-Policy") != "no-referrer" {
		t.Fatal("missing administrator page protection")
	}
	w = browser.request("POST", "/admin/", url.Values{"action": {"login"}, "username": {owner.Username}, "password": {password}})
	if w.Code != 403 {
		t.Fatal("sign-in accepted without CSRF")
	}
	ownerToken := browser.login(owner.Username, password, "")
	for _, page := range []string{"players", "levels", "admins", "roles", "security", "audit"} {
		w = browser.request("GET", "/admin/?page="+page, nil)
		if w.Code != 200 {
			t.Fatalf("page %s: %d %s", page, w.Code, w.Body)
		}
	}
	if w = browser.request("PUT", "/admin/", nil); w.Code != 405 {
		t.Fatal("unsupported browser method")
	}
	if w = browser.request("GET", "/admin/?page=unknown", nil); w.Code != 404 {
		t.Fatal("unknown page")
	}
	if w = browser.request("GET", "/assets/admin.css", nil); w.Code != 200 || !strings.Contains(w.Header().Get("Content-Type"), "text/css") {
		t.Fatal("native administrator CSS missing")
	}
	if _, err := db.Exec(`CREATE TABLE admin_audit_logs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,username VARCHAR(64),action VARCHAR(128),target VARCHAR(255),metadata TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)`); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`INSERT INTO admin_audit_logs(username,action,target,metadata) VALUES('LegacyOwner','legacy.import','test','<script>unsafe</script>')`); err != nil {
		t.Fatal(err)
	}
	w = browser.request("GET", "/admin/?page=audit", nil)
	if w.Code != 200 || !strings.Contains(w.Body.String(), "legacy.import") || strings.Contains(w.Body.String(), "<script>unsafe</script>") {
		t.Fatalf("legacy audit integration: %d %s", w.Code, w.Body)
	}
	w = browser.post("roles", "role-create", url.Values{"code": {"native_reader"}, "name": {"Native <script>reader</script>"}, "permissions[]": {"dashboard.view", "self.security", "users.view"}})
	if w.Code != 200 || strings.Contains(w.Body.String(), "Native <script>reader</script>") {
		t.Fatalf("role create or HTML escaping: %d %s", w.Code, w.Body)
	}
	permissions, err := admin.RolePermissions(ctx, db, "native_reader")
	if err != nil || len(permissions) != 3 || strings.Join(permissions, ",") != "dashboard.view,players.view,self.security" {
		t.Fatalf("multi-value permissions lost: %v %v", permissions, err)
	}
	var readerRole int64
	if err = db.QueryRow(`SELECT id FROM admin_roles WHERE code='native_reader'`).Scan(&readerRole); err != nil {
		t.Fatal(err)
	}
	if err = service.SaveRole(ctx, owner, "test", 1, "", "Changed", nil); err == nil {
		t.Fatal("built-in role edited")
	}
	if err = service.DeleteRole(ctx, owner, "test", 1); err == nil {
		t.Fatal("built-in role deleted")
	}
	w = browser.post("admins", "admin-create", url.Values{"username": {"InvitedReader"}, "role": {"native_reader"}})
	if w.Code != 200 {
		t.Fatalf("invite: %d %s", w.Code, w.Body)
	}
	invitePattern := regexp.MustCompile(`setup=([A-Za-z0-9_-]{64})`)
	match := invitePattern.FindStringSubmatch(w.Body.String())
	if len(match) != 2 {
		t.Fatal("missing one-time invitation")
	}
	invite := match[1]
	var stored string
	if err = db.QueryRow(`SELECT password_setup_token_hash FROM admin_users WHERE username='InvitedReader'`).Scan(&stored); err != nil || stored == invite || len(stored) != 64 {
		t.Fatal("unsafe stored invitation", err)
	}
	if _, _, err = service.Login(ctx, "InvitedReader", invite, "", "test", "198.18.44.2"); err == nil {
		t.Fatal("invitation token accepted as login password")
	}
	if err = service.DeleteRole(ctx, owner, "test", readerRole); err == nil {
		t.Fatal("assigned role deleted")
	}
	setup := newAdminTestBrowser(t, h)
	w = setup.request("GET", "/admin/?setup="+invite, nil)
	if w.Code != 200 {
		t.Fatal("password setup page")
	}
	w = setup.post("dashboard", "admin-password-setup", url.Values{"setup": {invite}, "password": {"reader-password-native"}, "password_confirmation": {"reader-password-native"}})
	if w.Code != 200 || !strings.Contains(w.Body.String(), "Password created.") {
		t.Fatalf("password setup: %d %s", w.Code, w.Body)
	}
	if err = service.CompleteSetup(ctx, invite, "changed-password-native"); err == nil {
		t.Fatal("invitation replay")
	}
	readerBrowser := newAdminTestBrowser(t, h)
	readerToken := readerBrowser.login("InvitedReader", "reader-password-native", "")
	w = readerBrowser.request("GET", "/admin/?page=roles", nil)
	if w.Code != 403 {
		t.Fatal("reader accessed roles page")
	}
	w = readerBrowser.post("roles", "role-create", url.Values{"code": {"escalation"}, "name": {"Bad"}, "permissions[]": {"settings.manage"}})
	if w.Code != 403 {
		t.Fatal("reader created a privileged role")
	}
	if err = service.SaveRole(ctx, owner, "test", 0, "role_editor", "Role editor", []string{"roles.manage", "admins.manage", "dashboard.view", "self.security"}); err != nil {
		t.Fatal(err)
	}
	editorInvite, err := service.Invite(ctx, owner, "test", "NativeEditor", "role_editor")
	if err != nil {
		t.Fatal(err)
	}
	if err = service.CompleteSetup(ctx, editorInvite, "editor-password-native"); err != nil {
		t.Fatal(err)
	}
	editor, _, err := service.Login(ctx, "NativeEditor", "editor-password-native", "", "test", "198.18.44.3")
	if err != nil {
		t.Fatal(err)
	}
	if err = service.SaveRole(ctx, editor, "test", 0, "escalation", "Bad", []string{"settings.manage"}); err == nil {
		t.Fatal("role editor escalated permissions")
	}
	if _, err = service.Invite(ctx, editor, "test", "FalseOwner", "owner"); err == nil {
		t.Fatal("custom role created an owner")
	}
	if err = service.UpdateAdministrator(ctx, owner, "test", owner.ID, "viewer", false); err == nil {
		t.Fatal("self disable accepted")
	}
	reader, err := service.Authorize(ctx, "Bearer "+readerToken, "players.view")
	if err != nil {
		t.Fatal(err)
	}
	if err = service.UpdateAdministrator(ctx, owner, "test", reader.ID, "native_reader", false); err != nil {
		t.Fatal(err)
	}
	if _, err = service.Authorize(ctx, "Bearer "+readerToken, "players.view"); err == nil {
		t.Fatal("disabled administrator retained token access")
	}
	if err = service.UpdateAdministrator(ctx, owner, "test", reader.ID, "native_reader", true); err != nil {
		t.Fatal(err)
	}
	if _, err = service.Authorize(ctx, "Bearer "+readerToken, "players.view"); err == nil {
		t.Fatal("reactivation resurrected revoked token")
	}
	// Permission revocation is visible to an already signed-in browser.
	readerBrowser = newAdminTestBrowser(t, h)
	readerBrowser.login("InvitedReader", "reader-password-native", "")
	if _, err = db.Exec(`DELETE FROM admin_role_permissions WHERE role_id=? AND permission='players.view'`, readerRole); err != nil {
		t.Fatal(err)
	}
	if w = readerBrowser.request("GET", "/admin/?page=players", nil); w.Code != 403 {
		t.Fatal("browser cached revoked role permission")
	}
	if err = service.SaveRole(ctx, owner, "test", readerRole, "", "Reader restored", []string{"dashboard.view", "self.security", "players.view"}); err != nil {
		t.Fatal(err)
	}
	// Force an audit failure; the preceding role write must roll back.
	if _, err = db.Exec(`CREATE TRIGGER native_admin_audit_failure BEFORE INSERT ON mucho_admin_client_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test audit failure'`); err != nil {
		t.Fatal(err)
	}
	err = service.SaveRole(ctx, owner, "test", readerRole, "", "Should roll back", nil)
	if _, dropErr := db.Exec(`DROP TRIGGER native_admin_audit_failure`); dropErr != nil {
		t.Fatal(dropErr)
	}
	if err == nil {
		t.Fatal("role write succeeded without an audit record")
	}
	permissions, err = admin.RolePermissions(ctx, db, "native_reader")
	if err != nil || len(permissions) != 3 {
		t.Fatal("role mutation escaped rollback", err)
	}
	// Only one simultaneous recipient may complete the same invitation.
	concurrentInvite, err := service.Invite(ctx, owner, "test", "ConcurrentInvite", "viewer")
	if err != nil {
		t.Fatal(err)
	}
	completed := make(chan error, 2)
	for range 2 {
		wg.Add(1)
		go func() {
			defer wg.Done()
			completed <- service.CompleteSetup(ctx, concurrentInvite, "concurrent-password-native")
		}()
	}
	wg.Wait()
	close(completed)
	successes := 0
	for err := range completed {
		if err == nil {
			successes++
		} else {
			expectAdminCode(t, err, "invalid_setup")
		}
	}
	if successes != 1 {
		t.Fatal("parallel invitation redemption", successes)
	}
	expiredInvite, err := service.Invite(ctx, owner, "test", "ExpiredInvite", "viewer")
	if err != nil {
		t.Fatal(err)
	}
	if _, err = db.Exec(`UPDATE admin_users SET password_setup_expires_at=NOW()-INTERVAL 1 SECOND WHERE username='ExpiredInvite'`); err != nil {
		t.Fatal(err)
	}
	expectAdminCode(t, service.CompleteSetup(ctx, expiredInvite, "expired-password-native"), "invalid_setup")
	// Native TOTP enrollment is encrypted and bound to the browser session.
	browser.request("GET", "/admin/?page=security", nil)
	w = browser.post("security", "2fa-generate", nil)
	if w.Code != 200 {
		t.Fatalf("TOTP setup: %d %s", w.Code, w.Body)
	}
	pending := browser.cookies[adminTOTPCookie]
	if pending == nil {
		t.Fatal("missing pending authenticator")
	}
	secret := h.openAdminTOTP(pending.Value, ownerToken)
	if len(secret) != 32 || strings.Contains(pending.Value, secret) || h.openAdminTOTP(pending.Value, "different-session") != "" || h.openAdminTOTP(pending.Value+"X", ownerToken) != "" {
		t.Fatal("unsafe pending authenticator")
	}
	clock := h.now
	h.now = func() time.Time { return clock().Add(601 * time.Second) }
	if h.openAdminTOTP(pending.Value, ownerToken) != "" {
		t.Fatal("expired pending authenticator accepted")
	}
	h.now = clock
	otp := admin.TOTP(secret, h.now().Unix()/30)
	w = browser.post("security", "2fa-enable", url.Values{"current_password": {"incorrect"}, "otp": {otp}})
	if w.Code != 422 {
		t.Fatal("TOTP enabled without current password")
	}
	w = browser.post("security", "2fa-enable", url.Values{"current_password": {password}, "otp": {otp}})
	if w.Code != 200 || !strings.Contains(w.Body.String(), "An authenticator is enabled") {
		t.Fatalf("TOTP enrollment: %d %s", w.Code, w.Body)
	}
	if _, _, err = service.Login(ctx, owner.Username, password, "", "test", "198.18.44.4"); err == nil {
		t.Fatal("TOTP bypass after enrollment")
	}
	w = browser.post("security", "recovery-generate", url.Values{"current_password": {password}, "otp": {otp}})
	codePattern := regexp.MustCompile(`<code>([A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4})</code>`)
	codes := codePattern.FindAllStringSubmatch(w.Body.String(), -1)
	if w.Code != 200 || len(codes) != 10 {
		t.Fatalf("recovery codes: %d %s", w.Code, w.Body)
	}
	if err = db.QueryRow(`SELECT COUNT(*) FROM admin_recovery_codes WHERE admin_user_id=? AND code_hash=?`, owner.ID, codes[0][1]).Scan(&count); err != nil || count != 0 {
		t.Fatal("recovery code stored in plaintext", err)
	}
	if _, _, err = service.Login(ctx, owner.Username, password, codes[0][1], "test", "198.18.44.7"); err == nil {
		t.Fatal("recovery code accepted by API client login")
	}
	// The browser accepts PHP-compatible one-time backup codes.
	recovered := newAdminTestBrowser(t, h)
	recovered.login(owner.Username, password, codes[0][1])
	if _, _, err = service.LoginBrowser(ctx, owner.Username, password, codes[0][1], "198.18.44.8"); err == nil {
		t.Fatal("recovery code reused")
	}
	// Race the same code from separate IPs: exactly one transaction consumes it.
	recoveries := make(chan error, 2)
	for _, ip := range []string{"198.18.44.9", "198.18.44.10"} {
		wg.Add(1)
		go func(ip string) {
			defer wg.Done()
			_, _, err := service.LoginBrowser(ctx, owner.Username, password, codes[1][1], ip)
			recoveries <- err
		}(ip)
	}
	wg.Wait()
	close(recoveries)
	recoveredCount := 0
	for err := range recoveries {
		if err == nil {
			recoveredCount++
		}
	}
	if recoveredCount != 1 {
		t.Fatal("parallel recovery code reuse", recoveredCount)
	}
	if w = browser.request("GET", "/admin/?page=security", nil); strings.Contains(w.Body.String(), codes[0][1]) {
		t.Fatal("recovery codes redisplayed after refresh")
	}
	_, otherToken, err := service.Login(ctx, owner.Username, password, otp, "test", "198.18.44.4")
	if err != nil {
		t.Fatal(err)
	}
	const newPassword = "new-owner-password-native"
	w = browser.post("security", "password-change", url.Values{"current_password": {password}, "password": {newPassword}, "password_confirmation": {newPassword}, "otp": {otp}})
	if w.Code != 200 {
		t.Fatalf("password change: %d %s", w.Code, w.Body)
	}
	if _, err = service.Authorize(ctx, "Bearer "+otherToken, "self.security"); err == nil {
		t.Fatal("other session survived password change")
	}
	if _, err = service.AuthorizeBrowser(ctx, ownerToken); err != nil {
		t.Fatal("current browser session lost", err)
	}
	if _, _, err = service.Login(ctx, owner.Username, password, otp, "test", "198.18.44.4"); err == nil {
		t.Fatal("old administrator password survived")
	}
	if _, _, err = service.Login(ctx, owner.Username, newPassword, otp, "test", "198.18.44.4"); err != nil {
		t.Fatal("new administrator password rejected", err)
	}
	// Browser idle expiry cannot be extended by calling AuthorizeBrowser.
	idle := newAdminTestBrowser(t, h)
	idleToken := idle.login(owner.Username, newPassword, otp)
	if _, err = db.Exec(`UPDATE mucho_admin_client_tokens SET last_used_at=NOW()-INTERVAL 31 MINUTE WHERE token_hash=SHA2(?,256)`, idleToken); err != nil {
		t.Fatal(err)
	}
	w = idle.request("GET", "/admin/?page=security", nil)
	if w.Code != 200 || strings.Contains(w.Body.String(), "Active sessions") || idle.cookies[adminSessionCookie] != nil {
		t.Fatal("idle browser session was renewed")
	}
	if _, err = service.AuthorizeBrowser(ctx, idleToken); err == nil {
		t.Fatal("idle token remained usable as browser session")
	}
	browser.request("GET", "/admin/?page=security", nil)
	w = browser.post("security", "logout", nil)
	if w.Code != 303 || browser.cookies[adminSessionCookie] != nil {
		t.Fatal("browser logout")
	}
	if _, err = service.Authorize(ctx, "Bearer "+ownerToken, "self.security"); err == nil {
		t.Fatal("logout did not revoke browser token")
	}
}
