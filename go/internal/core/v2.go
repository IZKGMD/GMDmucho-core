package core

import (
	"encoding/json"
	"io"
	"net/http"
	"regexp"
	"strconv"
	"strings"
	"time"
)

type v2Result struct {
	data    map[string]any
	status  int
	headers http.Header
}

func v2OK(data map[string]any) v2Result { return v2Result{data: data, status: 200} }
func v2Error(code string, status int) v2Result {
	return v2Result{data: map[string]any{"ok": false, "api": "MuchoCore", "version": "2.2", "error": code}, status: status}
}

var v2RequestID = regexp.MustCompile(`^[A-Za-z0-9._-]{8,64}$`)
var v2NumberPrefix = regexp.MustCompile(`^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?`)

func v2TransportError(w http.ResponseWriter, r *http.Request, status int) {
	id := v2Trim(r.Header.Get("X-Request-ID"))
	if !v2RequestID.MatchString(id) {
		var err error
		id, err = randomHex(12)
		if err != nil {
			status = 500
		}
	}
	result := v2Error("invalid_request", status)
	result.data["request_id"] = id
	response := jsonReply(result.data, status)
	w.Header().Set("Content-Type", "application/json; charset=UTF-8")
	w.Header().Set("Cache-Control", "no-store")
	w.Header().Set("X-Content-Type-Options", "nosniff")
	w.Header().Set("X-Request-ID", id)
	w.WriteHeader(status)
	_, _ = w.Write([]byte(response.Body))
}

func v2Trim(value string) string { return strings.Trim(value, " \t\r\n\x00\x0b") }
func v2FloatString(value float64) string {
	text := strconv.FormatFloat(value, 'G', 14, 64)
	if at := strings.IndexByte(text, 'E'); at >= 0 && !strings.Contains(text[:at], ".") {
		text = text[:at] + ".0" + text[at:]
	}
	return text
}

func v2String(value any) string {
	switch v := value.(type) {
	case string:
		return v
	case json.Number:
		if n, err := v.Int64(); err == nil {
			return strconv.FormatInt(n, 10)
		}
		if n, err := v.Float64(); err == nil {
			return v2FloatString(n)
		}
		return ""
	case bool:
		if v {
			return "1"
		}
		return ""
	case float64:
		return v2FloatString(v)
	}
	return ""
}
func v2Int(value any) int64 {
	raw := v2NumberPrefix.FindString(v2Trim(v2String(value)))
	if !strings.ContainsAny(raw, ".eE") {
		n, _ := strconv.ParseInt(raw, 10, 64)
		return n
	}
	n, _ := strconv.ParseFloat(raw, 64)
	return int64(n)
}
func (h *Handler) v2(r request) (response reply) {
	started := time.Now()
	requestID := v2Trim(r.raw.Header.Get("X-Request-ID"))
	if !v2RequestID.MatchString(requestID) {
		var err error
		requestID, err = randomHex(12)
		if err != nil {
			return jsonReply(map[string]any{"error": "internal_error"}, 500)
		}
	}
	result := v2Error("internal_error", 500)
	defer func() {
		if recovered := recover(); recovered != nil {
			result = v2Error("internal_error", 500)
			if h.options.Logger != nil {
				h.options.Logger.Error("API v2 request panicked")
			}
		}
		result.data["request_id"] = requestID
		response = jsonReply(result.data, result.status)
		response.ContentType = "application/json; charset=UTF-8"
		response.Headers = result.headers
		if response.Headers == nil {
			response.Headers = http.Header{}
		}
		response.Headers.Set("X-Request-ID", requestID)
		response.Headers.Set("X-Content-Type-Options", "nosniff")
		response.Headers.Set("Cache-Control", "no-store")
		response.Headers.Set("X-Frame-Options", "DENY")
		response.Headers.Set("Referrer-Policy", "no-referrer")
		response.Headers.Set("Permissions-Policy", "camera=(), microphone=(), geolocation=()")
		route, method := r.raw.URL.Path, r.raw.Method
		if len(route) > 160 {
			route = route[:160]
		}
		if len(method) > 12 {
			method = method[:12]
		}
		_, _ = h.db.ExecContext(r.ctx, `INSERT INTO mucho_api_metrics_minute(minute_start,route,method,status,requests,total_ms) VALUES(DATE_FORMAT(NOW(),'%Y-%m-%d %H:%i:00'),?,?,?,1,?) ON DUPLICATE KEY UPDATE requests=requests+1,total_ms=total_ms+VALUES(total_ms)`, route, method, result.status, max(0, time.Since(started).Milliseconds()))
	}()
	if !h.options.DisableProtect {
		allowed, reason, err := h.protect.InspectReason(r.ctx, r.path, r.ip, r.form, r.version)
		if err != nil {
			result = v2Error("security_unavailable", 503)
			return
		}
		if !allowed {
			retry := 60
			switch reason {
			case "global_burst_limit", "network_burst_limit", "burst_limit":
				retry = 10
			case "account_rate_limit":
				retry = 900
			case "temporary_penalty", "network_penalty", "account_penalty", "username_penalty", "email_penalty", "device_penalty":
				retry = 15
			}
			result = v2Error("rate_limited", 429)
			result.data["retry_after_seconds"] = retry
			result.headers = http.Header{"Retry-After": {strconv.Itoa(retry)}}
			metadata, _ := json.Marshal(map[string]any{"reason": reason, "retry_after": retry})
			route := r.raw.URL.Path
			if len(route) > 160 {
				route = route[:160]
			}
			_, _ = h.db.ExecContext(r.ctx, `INSERT INTO mucho_security_events(event_type,ip,route,request_id,metadata) VALUES('rate_limit',?,?,?,?)`, r.ip, route, requestID, metadata)
			return
		}
	}
	want := "GET"
	if r.path == "/v2/heartbeat" || r.path == "/v2/music-upload" || r.path == "/v2/admin-client" {
		want = "POST"
	}
	if r.raw.Method != want {
		result = v2Error("method_not_allowed", 405)
		result.headers = http.Header{"Allow": {want}}
		return
	}
	input := map[string]any{}
	for key, values := range r.form {
		if len(values) > 0 {
			input[key] = values[len(values)-1]
		}
	}
	if (r.path == "/v2/heartbeat" || r.path == "/v2/admin-client") && strings.Contains(strings.ToLower(r.raw.Header.Get("Content-Type")), "application/json") {
		data, err := io.ReadAll(io.LimitReader(r.raw.Body, 65537))
		if err != nil || len(data) > 65536 {
			result = v2Error("invalid_request", 413)
			return
		}
		decoder := json.NewDecoder(strings.NewReader(string(data)))
		decoder.UseNumber()
		var object map[string]any
		if err = decoder.Decode(&object); err == nil && object != nil {
			var extra any
			if decoder.Decode(&extra) == io.EOF {
				input = object
			}
		}
	}
	var err error
	result, err = h.v2Data(r, input)
	if err != nil {
		result = v2Error("internal_error", 500)
		if h.options.Logger != nil {
			h.options.Logger.Error("API v2 operation failed", "path", r.path)
		}
	}
	return
}
func (h *Handler) v2Data(r request, input map[string]any) (v2Result, error) {
	switch r.path {
	case "/v2", "/v2/index":
		return v2OK(map[string]any{"ok": true, "api": "MuchoCore", "version": "2.2", "endpoints": []string{"/api/v2/health.php", "/api/v2/profile.php?account_id=19"}}), nil
	case "/v2/health":
		var ready int
		err := h.db.QueryRowContext(r.ctx, `SELECT 1`).Scan(&ready)
		return v2OK(map[string]any{"ok": true, "api": "MuchoCore", "version": "2.2", "service": "api", "database": "connected"}), err
	case "/v2/profile":
		return h.v2Profile(r)
	case "/v2/heartbeat":
		return h.v2Heartbeat(r, input)
	case "/v2/dashboard":
		return h.v2Dashboard(r)
	case "/v2/branding":
		data := h.branding(r)
		data["ok"] = true
		return v2OK(data), nil
	case "/v2/client-config":
		return h.v2ClientConfig(r)
	case "/v2/music-upload":
		return h.v2MusicUpload(r, input)
	case "/v2/music":
		return h.v2Music(r)
	}
	return v2Error("not_found", 404), nil
}
