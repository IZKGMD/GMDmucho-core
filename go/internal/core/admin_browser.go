package core

import (
	"bytes"
	"crypto/aes"
	"crypto/cipher"
	"crypto/rand"
	"crypto/sha256"
	"crypto/subtle"
	_ "embed"
	"encoding/base64"
	"encoding/json"
	"errors"
	"html/template"
	"net/http"
	"net/url"
	"sort"
	"strconv"
	"strings"

	"github.com/IZKGMD/GMDmucho-core/go/internal/admin"
	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
)

const adminSessionCookie = "MUCHO_GO_ADMIN"
const adminCSRFCookie = "MUCHO_GO_ADMIN_CSRF"
const adminTOTPCookie = "MUCHO_GO_ADMIN_TOTP"

//go:embed admin_page.html
var adminHTML string
var adminPage = template.Must(template.New("administrator").Parse(adminHTML))

type adminRoleView struct {
	ID, Code, Name string
	System         bool
	Permissions    map[string]bool
}
type adminPermissionView struct{ Code, Label string }
type adminBrowserData struct {
	RecoveryCodes                                                  []string
	UnusedRecovery                                                 int
	Page, CSRF, Message, Query, Invite, Setup, TOTPSecret, TOTPURI string
	Identity                                                       admin.Identity
	Can                                                            map[string]bool
	Rows                                                           []dbutil.Row
	Roles                                                          []adminRoleView
	Permissions                                                    []adminPermissionView
	Player                                                         dbutil.Row
	Stats                                                          dbutil.Row
	Success, Authenticated, TOTPEnabled                            bool
	PendingCleared                                                 bool
}

func (h *Handler) adminCookie(r request, name, value string, maxAge int) *http.Cookie {
	return &http.Cookie{Name: name, Value: value, Path: "/admin", HttpOnly: true, Secure: h.options.SecureCookies || r.raw.TLS != nil, SameSite: http.SameSiteStrictMode, MaxAge: maxAge}
}
func adminToken(r request) string {
	cookie, err := r.raw.Cookie(adminSessionCookie)
	if err != nil || len(cookie.Value) != 43 {
		return ""
	}
	return cookie.Value
}
func adminErrorMessage(err error) (int, string) {
	var denied *admin.Error
	if errors.As(err, &denied) {
		return denied.Status, denied.Message
	}
	return 503, "The operation is temporarily unavailable. Try again later."
}
func (h *Handler) adminBrowser(r request) reply {
	headers := http.Header{"Cache-Control": {"no-store, private"}, "Pragma": {"no-cache"}, "X-Content-Type-Options": {"nosniff"}, "X-Frame-Options": {"DENY"}, "Referrer-Policy": {"no-referrer"}, "Content-Security-Policy": {"default-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'"}}
	if r.raw.Method != "GET" && r.raw.Method != "POST" {
		headers.Set("Allow", "GET, POST")
		return reply{Status: 405, Body: "Method not allowed.", Headers: headers}
	}
	if !h.options.DisableProtect {
		ok, err := h.protect.Allow(r.ctx, "native-admin-browser|"+r.ip, 90, 60)
		if err != nil {
			return reply{Status: 503, Body: "Administration is temporarily unavailable.", Headers: headers}
		}
		if !ok {
			headers.Set("Retry-After", "60")
			return reply{Status: 429, Body: "Too many requests. Try again later.", Headers: headers}
		}
	}
	data := adminBrowserData{Page: r.raw.URL.Query().Get("page"), Setup: r.raw.URL.Query().Get("setup"), Can: map[string]bool{}}
	if data.Page == "" {
		data.Page = "dashboard"
	}
	if r.raw.Method == "POST" && r.str("setup") != "" {
		data.Setup = r.str("setup")
	}
	service := admin.Service{DB: h.db, Now: h.now, Cost: h.accounts.Cost}
	token := adminToken(r)
	if token != "" && data.Setup == "" {
		identity, err := service.AuthorizeBrowser(r.ctx, token)
		if err == nil {
			data.Identity = identity
			data.Authenticated = true
		} else {
			var denied *admin.Error
			if !errors.As(err, &denied) {
				return reply{Status: 503, Body: "Administration is temporarily unavailable.", Headers: headers}
			}
			headers.Add("Set-Cookie", h.adminCookie(r, adminSessionCookie, "", -1).String())
			token = ""
		}
	}
	purpose := "admin-browser|" + token + "|" + data.Setup
	csrf, validCSRF := h.browserCSRF(r, adminCSRFCookie, purpose)
	if !validCSRF {
		var err error
		csrf, err = randomHex(32)
		if err != nil {
			return reply{Status: 503, Headers: headers}
		}
		headers.Add("Set-Cookie", h.browserCookie(r, csrf, adminCSRFCookie, purpose).String())
	}
	data.CSRF = csrf
	status := 200
	if r.raw.Method == "POST" {
		if !validCSRF || subtle.ConstantTimeCompare([]byte(csrf), []byte(r.str("csrf"))) != 1 || r.raw.Header.Get("Sec-Fetch-Site") == "cross-site" {
			status, data.Message = 403, "This form expired. Refresh the page and try again."
		} else if data.Setup != "" {
			if r.str("password") != r.str("password_confirmation") {
				status, data.Message = 422, "Passwords do not match."
			} else if err := service.CompleteSetup(r.ctx, data.Setup, r.str("password")); err != nil {
				status, data.Message = adminErrorMessage(err)
			} else {
				data.Success, data.Message, data.Setup = true, "Password created. You can now sign in.", ""
			}
		} else if !data.Authenticated {
			if r.str("action") != "login" {
				status, data.Message = 401, "Please sign in."
			} else {
				_, session, err := service.LoginBrowser(r.ctx, r.str("username"), r.str("password"), r.str("otp"), r.ip)
				if err != nil {
					status, data.Message = adminErrorMessage(err)
				} else {
					headers.Add("Set-Cookie", h.adminCookie(r, adminSessionCookie, session, admin.TokenTTL).String())
					headers.Add("Set-Cookie", h.adminCookie(r, adminTOTPCookie, "", -1).String())
					headers.Set("Location", "/admin/")
					return reply{Status: 303, Headers: headers}
				}
			}
		} else {
			if r.str("action") == "logout" {
				// Cookie possession and CSRF suffice to revoke this session, even
				// after self.security was removed from the role.
				tx, err := h.db.BeginTx(r.ctx, nil)
				if err == nil {
					defer tx.Rollback()
					_, err = tx.ExecContext(r.ctx, `UPDATE mucho_admin_client_tokens SET revoked_at=NOW() WHERE id=?`, data.Identity.TokenID)
					if err == nil {
						err = admin.Audit(r.ctx, tx, data.Identity, r.ip, "auth.logout", "", "", nil)
					}
					if err == nil {
						err = tx.Commit()
					}
				}
				if err != nil {
					status, data.Message = adminErrorMessage(err)
				} else {
					headers.Add("Set-Cookie", h.adminCookie(r, adminSessionCookie, "", -1).String())
					headers.Add("Set-Cookie", h.adminCookie(r, adminTOTPCookie, "", -1).String())
					headers.Set("Location", "/admin/")
					return reply{Status: 303, Headers: headers}
				}
			} else {
				r.raw.Header.Set("Authorization", "Bearer "+token)
				if err := h.adminBrowserWrite(r, &data, headers, token); err != nil {
					status, data.Message = adminErrorMessage(err)
				} else {
					data.Success = true
					if data.Message == "" {
						data.Message = "Changes saved."
					}
				}
			}
		}
	}
	if data.Authenticated {
		if err := h.adminBrowserRead(r, &data, token); err != nil {
			status, data.Message = adminErrorMessage(err)
		}
	}
	var body bytes.Buffer
	if err := adminPage.Execute(&body, data); err != nil {
		if h.options.Logger != nil {
			h.options.Logger.Error("administrator page rendering failed")
		}
		return reply{Status: 503, Body: "Administration is temporarily unavailable.", Headers: headers}
	}
	return reply{Status: status, Body: body.String(), ContentType: "text/html; charset=utf-8", Headers: headers}
}

var adminPagePermissions = map[string]string{"dashboard": "dashboard.view", "players": "players.view", "levels": "levels.view", "admins": "admins.manage", "roles": "roles.manage", "security": "self.security", "audit": "audit.view"}

func (h *Handler) adminBrowserRead(r request, data *adminBrowserData, token string) error {
	permission, known := adminPagePermissions[data.Page]
	if !known {
		data.Page = "unavailable"
		return &admin.Error{Code: "not_found", Message: "Page not found.", Status: 404}
	}
	permissions, err := admin.EffectivePermissions(r.ctx, h.db, data.Identity)
	if err != nil {
		return err
	}
	data.Can = permissions
	if !data.Can[permission] {
		data.Page = "unavailable"
		return &admin.Error{Code: "forbidden", Message: "Insufficient administrator permissions.", Status: 403}
	}
	data.Query = strings.TrimSpace(r.raw.URL.Query().Get("q"))
	if len(data.Query) > 64 {
		data.Query = data.Query[:64]
	}
	switch data.Page {
	case "dashboard":
		data.Stats, err = dbutil.One(r.ctx, h.db, `SELECT (SELECT COUNT(*) FROM accounts) AS players,(SELECT COUNT(*) FROM levels WHERE is_deleted=0) AS levels,(SELECT COUNT(*) FROM accounts WHERE is_banned=1) AS banned,(SELECT COUNT(*) FROM mucho_jobs WHERE status='queued') AS jobs`)
	case "players":
		data.Rows, err = dbutil.Query(r.ctx, h.db, adminPlayerSQL+` WHERE (?='' OR a.username LIKE ? OR a.account_id=?) ORDER BY a.account_id DESC LIMIT 50`, data.Query, "%"+data.Query+"%", data.Query)
		if err != nil {
			return err
		}
		if id, parseErr := strconv.ParseInt(r.raw.URL.Query().Get("id"), 10, 64); parseErr == nil && id > 0 {
			data.Player, err = dbutil.One(r.ctx, h.db, adminPlayerSQL+` WHERE a.account_id=?`, id)
		}
	case "levels":
		data.Rows, err = dbutil.Query(r.ctx, h.db, `SELECT l.level_id,l.name,a.username,l.stars,l.difficulty,l.demon,l.demon_difficulty,l.featured,l.epic,l.is_deleted FROM levels l LEFT JOIN accounts a ON a.account_id=l.account_id WHERE (?='' OR l.name LIKE ? OR l.level_id=?) ORDER BY l.level_id DESC LIMIT 50`, data.Query, "%"+data.Query+"%", data.Query)
	case "admins", "roles":
		roles, e := dbutil.Query(r.ctx, h.db, `SELECT id,code,name,is_system FROM admin_roles ORDER BY is_system DESC,name`)
		if e != nil {
			return e
		}
		for _, role := range roles {
			permissions, e := admin.RolePermissions(r.ctx, h.db, role["code"])
			if e != nil {
				return e
			}
			view := adminRoleView{ID: role["id"], Code: role["code"], Name: role["name"], System: role.Int("is_system") == 1, Permissions: map[string]bool{}}
			for _, p := range permissions {
				view.Permissions[p] = true
			}
			data.Roles = append(data.Roles, view)
		}
		if data.Page == "admins" {
			data.Rows, err = dbutil.Query(r.ctx, h.db, `SELECT id,username,role,is_active,password_must_set,created_at FROM admin_users ORDER BY id LIMIT 200`)
		}
		for code, label := range admin.Permissions() {
			data.Permissions = append(data.Permissions, adminPermissionView{code, label})
		}
		sort.Slice(data.Permissions, func(i, j int) bool { return data.Permissions[i].Code < data.Permissions[j].Code })
	case "security":
		var enabled int
		err = h.db.QueryRowContext(r.ctx, `SELECT CASE WHEN COALESCE(totp_secret,'')<>'' THEN 1 ELSE 0 END FROM admin_users WHERE id=?`, data.Identity.ID).Scan(&enabled)
		data.TOTPEnabled = enabled == 1
		if !data.TOTPEnabled {
			if data.TOTPSecret == "" && !data.PendingCleared {
				cookie, e := r.raw.Cookie(adminTOTPCookie)
				if e == nil {
					data.TOTPSecret = h.openAdminTOTP(cookie.Value, token)
				}
			}
			if data.TOTPSecret != "" {
				data.TOTPURI = "otpauth://totp/" + url.PathEscape("MuchoCore:"+data.Identity.Username) + "?secret=" + url.QueryEscape(data.TOTPSecret) + "&issuer=MuchoCore&algorithm=SHA1&digits=6&period=30"
			}
		}
		if err == nil {
			data.Rows, err = dbutil.Query(r.ctx, h.db, `SELECT id,client_version,created_at,last_used_at,expires_at FROM mucho_admin_client_tokens WHERE admin_user_id=? AND revoked_at IS NULL AND expires_at>NOW() ORDER BY id DESC LIMIT 100`, data.Identity.ID)
		}
	case "audit":
		// PHP creates its browser audit table lazily, outside migrations.
		// New native installations need only the canonical client audit table.
		var legacy int
		err = h.db.QueryRowContext(r.ctx, `SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='admin_audit_logs'`).Scan(&legacy)
		if err != nil {
			return err
		}
		data.Rows, err = dbutil.Query(r.ctx, h.db, `SELECT id,admin_username,action,target_type,target_id,details,created_at FROM mucho_admin_client_audit ORDER BY created_at DESC,id DESC LIMIT 100`)
		if err == nil && legacy > 0 {
			// Old browser tables can use a different collation from API tables.
			// Merge bounded result sets without a cross-collation SQL UNION.
			older, e := dbutil.Query(r.ctx, h.db, `SELECT id,username AS admin_username,action,'legacy' AS target_type,target AS target_id,metadata AS details,created_at FROM admin_audit_logs ORDER BY created_at DESC,id DESC LIMIT 100`)
			if e != nil {
				return e
			}
			data.Rows = append(data.Rows, older...)
			sort.SliceStable(data.Rows, func(i, j int) bool {
				a, b := data.Rows[i], data.Rows[j]
				if a["created_at"] == b["created_at"] {
					return a.Int("id") > b.Int("id")
				}
				return a["created_at"] > b["created_at"]
			})
			data.Rows = data.Rows[:min(100, len(data.Rows))]
		}
	}
	if err == nil && data.Page == "security" {
		err = h.db.QueryRowContext(r.ctx, `SELECT COUNT(*) FROM admin_recovery_codes WHERE admin_user_id=? AND used_at IS NULL`, data.Identity.ID).Scan(&data.UnusedRecovery)
	}
	return err
}

func (h *Handler) adminBrowserWrite(r request, data *adminBrowserData, headers http.Header, token string) error {
	service := admin.Service{DB: h.db, Now: h.now, Cost: h.accounts.Cost}
	identity := data.Identity
	id, _ := strconv.ParseInt(r.str("id"), 10, 64)
	action := r.str("action")
	switch action {
	case "role-create", "role-update":
		data.Page = "roles"
		if action == "role-create" {
			id = 0
		} else if id <= 0 {
			return &admin.Error{Code: "invalid_id", Message: "Choose a role.", Status: 422}
		}
		return service.SaveRole(r.ctx, identity, r.ip, id, r.str("code"), r.str("name"), r.form["permissions[]"])
	case "role-delete":
		data.Page = "roles"
		return service.DeleteRole(r.ctx, identity, r.ip, id)
	case "admin-create":
		data.Page = "admins"
		invite, err := service.Invite(r.ctx, identity, r.ip, r.str("username"), r.str("role"))
		if err != nil {
			return err
		}
		data.Invite = "/admin/?setup=" + url.QueryEscape(invite)
		data.Message = "Administrator invited. Share this one-time link privately; it expires in 24 hours."
		return nil
	case "admin-update":
		data.Page = "admins"
		return service.UpdateAdministrator(r.ctx, identity, r.ip, id, r.str("role"), r.str("active") == "1")
	case "2fa-generate", "2fa-cancel", "2fa-enable", "2fa-disable", "password-change", "sessions-revoke", "recovery-generate":
		data.Page = "security"
		allowed, err := admin.Can(r.ctx, h.db, identity, "self.security")
		if err != nil {
			return err
		}
		if !allowed {
			return &admin.Error{Code: "forbidden", Message: "Insufficient administrator permissions.", Status: 403}
		}
		if action == "recovery-generate" {
			codes, err := service.GenerateRecoveryCodes(r.ctx, identity, r.ip, r.str("current_password"), r.str("otp"))
			if err != nil {
				return err
			}
			data.RecoveryCodes = codes
			data.Message = "Store these recovery codes privately. They will be shown once. Older unused codes have been replaced."
			return nil
		}
		if action == "2fa-generate" {
			var existing string
			if err = h.db.QueryRowContext(r.ctx, `SELECT COALESCE(totp_secret,'') FROM admin_users WHERE id=?`, identity.ID).Scan(&existing); err != nil {
				return err
			}
			if existing != "" {
				return &admin.Error{Code: "totp_enabled", Message: "Disable the current authenticator first.", Status: 409}
			}
			secret, err := admin.NewTOTPSecret()
			if err != nil {
				return err
			}
			cookie, err := h.sealAdminTOTP(secret, token)
			if err != nil {
				return err
			}
			data.TOTPSecret = secret
			headers.Add("Set-Cookie", h.adminCookie(r, adminTOTPCookie, cookie, 600).String())
			return nil
		}
		if action == "2fa-cancel" {
			data.TOTPSecret = ""
			headers.Add("Set-Cookie", h.adminCookie(r, adminTOTPCookie, "", -1).String())
			data.PendingCleared = true
			return nil
		}
		operation, value := "sessions.revoke", ""
		switch action {
		case "2fa-enable":
			cookie, err := r.raw.Cookie(adminTOTPCookie)
			if err == nil {
				value = h.openAdminTOTP(cookie.Value, token)
			}
			if value == "" {
				return &admin.Error{Code: "invalid_setup", Message: "Authenticator setup expired. Generate a new secret.", Status: 422}
			}
			operation = "totp.enable"
		case "2fa-disable":
			operation = "totp.disable"
		case "password-change":
			operation = "password"
			value = r.str("password")
			if value != r.str("password_confirmation") {
				return &admin.Error{Code: "password_mismatch", Message: "Passwords do not match.", Status: 422}
			}
		}
		if err = service.ChangeSecurity(r.ctx, identity, r.ip, r.str("current_password"), r.str("otp"), operation, value); err != nil {
			return err
		}
		if action == "2fa-enable" || action == "2fa-disable" {
			headers.Add("Set-Cookie", h.adminCookie(r, adminTOTPCookie, "", -1).String())
			data.PendingCleared = true
		}
		data.Message = "Security settings saved. Other sessions have been revoked."
		return nil
	case "players.set_ban", "players.set_role", "players.update_profile", "levels.update":
		if strings.HasPrefix(action, "players.") {
			data.Page = "players"
		} else {
			data.Page = "levels"
		}
		input := map[string]any{}
		for key := range r.form {
			input[key] = r.str(key)
		}
		for _, key := range []string{"account_id", "level_id", "stars", "moons", "diamonds", "secret_coins", "user_coins", "demons", "creator_points", "difficulty", "demon_difficulty", "epic"} {
			if raw, ok := input[key]; ok {
				value, err := strconv.ParseInt(raw.(string), 10, 64)
				if err != nil {
					return &admin.Error{Code: "invalid_field", Message: "Invalid number: " + key, Status: 422}
				}
				input[key] = json.Number(strconv.FormatInt(value, 10))
			}
		}
		for _, key := range []string{"banned", "demon", "featured", "is_deleted"} {
			input[key] = r.str(key) == "1"
		}
		result, err := h.adminClientData(r, action, input)
		if err != nil {
			return err
		}
		if result.status >= 400 {
			message, _ := result.data["message"].(string)
			return &admin.Error{Code: "operation_failed", Message: message, Status: result.status}
		}
		return nil
	}
	return &admin.Error{Code: "unknown_action", Message: "Unknown administrator action.", Status: 404}
}

func (h *Handler) adminTOTPBox() (cipher.AEAD, error) {
	key := sha256.Sum256(append([]byte("muchocore-admin-totp|"), h.downloadKey...))
	block, err := aes.NewCipher(key[:])
	if err != nil {
		return nil, err
	}
	return cipher.NewGCM(block)
}
func (h *Handler) sealAdminTOTP(secret, token string) (string, error) {
	box, err := h.adminTOTPBox()
	if err != nil {
		return "", err
	}
	nonce := make([]byte, box.NonceSize())
	if _, err = rand.Read(nonce); err != nil {
		return "", err
	}
	data := strconv.FormatInt(h.now().Unix()+600, 10) + ":" + secret
	sealed := box.Seal(nonce, nonce, []byte(data), []byte("admin-totp|"+token))
	return base64.RawURLEncoding.EncodeToString(sealed), nil
}
func (h *Handler) openAdminTOTP(cookie, token string) string {
	box, err := h.adminTOTPBox()
	if err != nil {
		return ""
	}
	data, err := base64.RawURLEncoding.DecodeString(cookie)
	if err != nil || len(data) < box.NonceSize()+box.Overhead() || len(data) > 256 {
		return ""
	}
	plain, err := box.Open(nil, data[:box.NonceSize()], data[box.NonceSize():], []byte("admin-totp|"+token))
	if err != nil {
		return ""
	}
	parts := strings.Split(string(plain), ":")
	if len(parts) != 2 {
		return ""
	}
	expiry, err := strconv.ParseInt(parts[0], 10, 64)
	if err != nil || expiry <= h.now().Unix() {
		return ""
	}
	return parts[1]
}
