package core

import (
	"bytes"
	"crypto/hmac"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/hex"
	"html/template"
	"net/http"
	"strconv"
	"strings"
)

func (h *Handler) accountCSRF(r request) (string, bool) {
	cookie, err := r.raw.Cookie("MUCHO_GO_ACCOUNT")
	if err != nil {
		return "", false
	}
	parts := strings.Split(cookie.Value, ".")
	if len(parts) != 3 {
		return "", false
	}
	token, err := hex.DecodeString(parts[0])
	if err != nil || len(token) != 32 {
		return "", false
	}
	expiry, err := strconv.ParseInt(parts[1], 10, 64)
	if err != nil || expiry < h.now().Unix() {
		return "", false
	}
	signature, err := hex.DecodeString(parts[2])
	if err != nil {
		return "", false
	}
	mac := hmac.New(sha256.New, h.downloadKey)
	mac.Write([]byte("account-management|" + parts[0] + "." + parts[1]))
	return parts[0], hmac.Equal(signature, mac.Sum(nil))
}
func (h *Handler) accountCookie(r request, token string) *http.Cookie {
	value := token + "." + strconv.FormatInt(h.now().Unix()+7200, 10)
	mac := hmac.New(sha256.New, h.downloadKey)
	mac.Write([]byte("account-management|" + value))
	value += "." + hex.EncodeToString(mac.Sum(nil))
	return &http.Cookie{Name: "MUCHO_GO_ACCOUNT", Value: value, Path: "/", HttpOnly: true, Secure: h.options.SecureCookies || r.raw.TLS != nil, SameSite: http.SameSiteLaxMode}
}
func (h *Handler) accountManagement(r request) reply {
	headers := http.Header{"Cache-Control": {"no-store, private"}, "X-Content-Type-Options": {"nosniff"}, "X-Frame-Options": {"DENY"}, "Referrer-Policy": {"no-referrer"}, "Content-Security-Policy": {"default-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'"}}
	if r.raw.Method != "GET" && r.raw.Method != "POST" {
		headers.Set("Allow", "GET, POST")
		return reply{Body: "Method not allowed.", Status: 405, Headers: headers}
	}
	token, valid := h.accountCSRF(r)
	if !valid {
		var err error
		token, err = randomHex(32)
		if err != nil {
			return reply{Status: 503, Headers: headers}
		}
		headers.Add("Set-Cookie", h.accountCookie(r, token).String())
	}
	status, success, message := 200, false, ""
	if r.raw.Method == "POST" {
		if !valid || subtle.ConstantTimeCompare([]byte(token), []byte(r.str("csrf"))) != 1 {
			status, message = 403, "This page has expired. Refresh the page and try again."
		} else {
			var err error
			success, err = h.accounts.ChangePassword(r.ctx, r.str("username"), r.str("current_password"), r.str("password"), r.str("password_confirmation"), r.ip, h.protect.Allow)
			if err != nil {
				status, message = 503, "Account management is temporarily unavailable. Try again later."
			} else if success {
				message = "Password changed. Return to Geometry Dash and sign in with your new password."
				token, err = randomHex(32)
				if err != nil {
					return reply{Status: 503, Headers: headers}
				}
				headers.Add("Set-Cookie", h.accountCookie(r, token).String())
			} else {
				message = "Unable to change the password. Check your details or try again later."
			}
		}
	}
	recovery := h.options.RecoveryPath
	if recovery == "" {
		recovery = "/api/api/accounts/lostusername.php"
	}
	data := struct {
		CSRF, AssetBase, Recovery, Message string
		Success                            bool
	}{token, strings.TrimRight(h.options.AssetBase, "/"), recovery, message, success}
	var body bytes.Buffer
	if err := accountPage.Execute(&body, data); err != nil {
		return reply{Status: 503, Headers: headers}
	}
	return reply{Body: body.String(), Status: status, Headers: headers, ContentType: "text/html; charset=utf-8"}
}

var accountPage = template.Must(template.New("account-management").Parse(`<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>MuchoCore — account management</title><link rel="icon" href="{{.AssetBase}}/assets/muchocore-favicon-v4.png"><link rel="stylesheet" href="{{.AssetBase}}/recovery/recovery.css"><link rel="stylesheet" href="{{.AssetBase}}/muchocore-theme.css?v=3"><link rel="stylesheet" href="{{.AssetBase}}/assets/product-ui.css?v=2"><script src="{{.AssetBase}}/muchocore-theme.js?v=5" defer></script></head><body class="mc-workspace"><main class="shell"><section class="card"><div class="brand"><img class="mc-brand-mark" src="{{.AssetBase}}/assets/muchocore-icon.png" alt="" width="44" height="44"><span>MuchoCore</span></div><div class="eyebrow">PLAYER ACCOUNT</div><h1>Account management</h1><p class="lead">Change your Geometry Dash account password. Enter your current password to confirm your identity.</p>{{if .Message}}<div role="status" class="flash {{if .Success}}success{{else}}error{{end}}">{{.Message}}</div>{{end}}<form method="post"><input type="hidden" name="csrf" value="{{.CSRF}}"><label for="username">Username</label><input id="username" name="username" maxlength="20" autocomplete="username" required><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" maxlength="256" autocomplete="current-password" required><label for="password">New password</label><input id="password" name="password" type="password" minlength="8" maxlength="72" autocomplete="new-password" required><label for="password_confirmation">Confirm new password</label><input id="password_confirmation" name="password_confirmation" type="password" minlength="8" maxlength="72" autocomplete="new-password" required><p class="hint">Use a strong password with at least 8 characters. Your old password and recovery links will stop working.</p><button type="submit">Change password</button></form><p><a href="{{.Recovery}}">Forgot your password?</a></p></section><footer>Account management · Powered by MuchoCore</footer></main></body></html>`))
