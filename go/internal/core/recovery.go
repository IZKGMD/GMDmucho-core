package core

import (
	"bytes"
	"crypto/subtle"
	"html/template"
	"net/http"
	"net/url"
	"regexp"
	"strings"
)

var recoveryPublicBase = regexp.MustCompile(`^https://[A-Za-z0-9.-]+(?::[0-9]+)?$`)

func (h *Handler) recoveryBase() string {
	base := strings.TrimSpace(h.options.PublicURL)
	if base == "" {
		base = strings.TrimSpace(h.options.AccountURL)
	}
	if !recoveryPublicBase.MatchString(base) {
		return "https://localhost"
	}
	return strings.TrimRight(base, "/")
}
func (h *Handler) recovery(r request) reply {
	headers := http.Header{"Cache-Control": {"no-store"}, "X-Content-Type-Options": {"nosniff"}, "X-Frame-Options": {"DENY"}, "Referrer-Policy": {"no-referrer"}, "Content-Security-Policy": {"default-src 'self'; style-src 'self'; script-src 'self' https://challenges.cloudflare.com; frame-src https://challenges.cloudflare.com; connect-src 'self' https://challenges.cloudflare.com; base-uri 'none'; form-action 'self'; frame-ancestors 'none'"}}
	if r.raw.Method != "GET" && r.raw.Method != "POST" {
		headers.Set("Allow", "GET, POST")
		return reply{Status: 405, Headers: headers}
	}
	csrf, valid := h.browserCSRF(r, "MUCHO_GO_RECOVERY", "account-recovery")
	if !valid {
		var err error
		csrf, err = randomHex(32)
		if err != nil {
			return reply{Status: 503, Headers: headers}
		}
		headers.Add("Set-Cookie", h.browserCookie(r, csrf, "MUCHO_GO_RECOVERY", "account-recovery").String())
	}
	data := struct{ CSRF, Token, Mode, Title, Message, Error, SiteKey, AssetBase string }{CSRF: csrf, Mode: "request", AssetBase: strings.TrimRight(h.options.AssetBase, "/")}
	if h.options.Turnstile.Enabled() {
		data.SiteKey = h.options.Turnstile.SiteKey
	}
	status := 200
	if r.raw.Method == "GET" {
		if token := r.raw.URL.Query().Get("token"); profileVersion.MatchString(strings.ToLower(token)) {
			exists, err := h.accounts.RecoveryTokenValid(r.ctx, token)
			if err != nil {
				return reply{Status: 503, Headers: headers}
			}
			data.Token = token
			data.Mode = "invalid"
			if exists {
				data.Mode = "reset"
			}
		}
	} else if !valid || subtle.ConstantTimeCompare([]byte(csrf), []byte(r.str("csrf"))) != 1 {
		data.Mode, data.Title, data.Message = "message", "This page has expired.", "Refresh the page and try again."
	} else {
		switch r.str("action") {
		case "request":
			if h.options.Turnstile.Enabled() {
				hostname := ""
				if accountURL, err := url.Parse(h.options.AccountURL); err == nil {
					hostname = accountURL.Hostname()
				}
				if !h.options.Turnstile.Verify(r.ctx, r.str("cf-turnstile-response"), "recovery", hostname, r.ip) {
					data.Error = "Complete the security check and try again."
					break
				}
			}
			email, user, token, id, err := h.accounts.IssueRecovery(r.ctx, r.str("identity"), r.ip, h.protect.Allow)
			if err != nil {
				status, data.Mode, data.Title, data.Message = 503, "message", "Recovery unavailable", "Try again later."
				break
			}
			if id > 0 {
				if h.options.RecoveryMailer != nil {
					err = h.options.RecoveryMailer.Recovery(r.ctx, email, user, h.recoveryBase()+"/api/api/accounts/lostusername.php?token="+token)
				}
				if (err != nil || h.options.RecoveryMailer == nil) && h.options.Logger != nil {
					h.options.Logger.Warn("recovery email delivery unavailable")
				}
				_, err = h.db.ExecContext(r.ctx, `INSERT INTO audit_logs(account_id,action,ip_address,metadata) VALUES(?,'account.recovery.requested',?,'[]')`, id, r.ip)
				if err != nil {
					status, data.Mode, data.Title, data.Message = 503, "message", "Recovery unavailable", "Try again later."
					break
				}
			}
			data.Mode, data.Title, data.Message = "message", "Check your email", "If an eligible account matches, a recovery link has been sent to its email address."
		case "reset":
			token := r.str("token")
			ok, err := h.accounts.ResetPassword(r.ctx, token, r.str("password"), r.str("password_confirmation"), r.ip, h.protect.Allow)
			if err != nil {
				status, data.Mode, data.Title, data.Message = 503, "message", "Recovery unavailable", "Try again later."
				break
			}
			if ok {
				data.Mode, data.Title, data.Message = "message", "Password changed", "Your new password has been saved. Return to Geometry Dash and sign in."
			} else {
				exists, err := h.accounts.RecoveryTokenValid(r.ctx, token)
				if err != nil {
					return reply{Status: 503, Headers: headers}
				}
				data.Mode = "invalid"
				if exists {
					data.Mode, data.Token, data.Error = "reset", token, "Check that your passwords match and meet the length limit, or try again later."
				}
			}
		default:
			data.Mode, data.Title, data.Message = "message", "Invalid request", "Go back and open the recovery page again."
		}
	}
	var body bytes.Buffer
	if err := recoveryPage.Execute(&body, data); err != nil {
		return reply{Status: 503, Headers: headers}
	}
	return reply{Body: body.String(), Status: status, ContentType: "text/html; charset=utf-8", Headers: headers}
}

var recoveryPage = template.Must(template.New("recovery").Parse(`<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><link rel="icon" href="{{.AssetBase}}/assets/muchocore-favicon-v4.png"><title>MuchoCore — account recovery</title><link rel="stylesheet" href="{{.AssetBase}}/recovery/recovery.css"><link rel="stylesheet" href="{{.AssetBase}}/muchocore-theme.css?v=3"><link rel="stylesheet" href="{{.AssetBase}}/assets/product-ui.css?v=2"><script src="{{.AssetBase}}/muchocore-theme.js?v=4" defer></script>{{if .SiteKey}}<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>{{end}}</head><body class="mc-workspace"><main class="shell"><section class="card"><div class="brand"><img class="mc-brand-mark" src="{{.AssetBase}}/assets/muchocore-icon.png" alt="" width="44" height="44"><span>MuchoCore</span></div><div class="eyebrow">ACCOUNT RECOVERY</div>{{if eq .Mode "request"}}<h1>Account recovery</h1><p class="lead">Enter your username or the email address you used when registering your account.</p>{{if .Error}}<div class="flash error">{{.Error}}</div>{{end}}<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="{{.CSRF}}"><input type="hidden" name="action" value="request"><label for="identity">Username or email</label><input id="identity" name="identity" type="text" maxlength="254" autocomplete="username" required>{{if .SiteKey}}<div class="turnstile-box"><div class="cf-turnstile" data-sitekey="{{.SiteKey}}" data-theme="auto" data-action="recovery"></div></div>{{end}}<button type="submit">Send recovery link</button></form><div class="hint">Recovery links can be used once and expire after 30 minutes.</div>{{else if eq .Mode "reset"}}<h1>New password</h1><p class="lead">Choose a new password for your account.</p>{{if .Error}}<div class="flash error">{{.Error}}</div>{{end}}<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="{{.CSRF}}"><input type="hidden" name="action" value="reset"><input type="hidden" name="token" value="{{.Token}}"><label for="password">New password</label><input id="password" name="password" type="password" minlength="8" maxlength="72" autocomplete="new-password" required><label for="password_confirmation">Confirm password</label><input id="password_confirmation" name="password_confirmation" type="password" minlength="8" maxlength="72" autocomplete="new-password" required><button type="submit">Save new password</button></form>{{else if eq .Mode "invalid"}}<h1>Invalid link</h1><p class="lead">This link may have already been used or expired.</p><a class="button secondary" href="/api/api/accounts/lostusername.php">Request a new link</a>{{else}}<h1>{{.Title}}</h1><p class="lead">{{.Message}}</p><a class="button secondary" href="/api/api/accounts/lostusername.php">Back to account recovery</a>{{end}}</section><footer>Account Recovery · Powered by MuchoCore</footer></main></body></html>`))
