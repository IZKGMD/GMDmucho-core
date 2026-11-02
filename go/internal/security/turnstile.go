package security

import (
	"bytes"
	"context"
	"encoding/json"
	"io"
	"net/http"
	"strings"
	"time"
)

type Turnstile struct {
	SiteKey, Secret string
	Client          *http.Client
}

func (t Turnstile) Enabled() bool {
	return strings.TrimSpace(t.SiteKey) != "" && strings.TrimSpace(t.Secret) != ""
}
func (t Turnstile) Verify(ctx context.Context, token, action, hostname, ip string) bool {
	token = strings.TrimSpace(token)
	if t.Secret == "" || token == "" || len(token) > 2048 {
		return false
	}
	payload := map[string]string{"secret": strings.TrimSpace(t.Secret), "response": token}
	if ip != "0.0.0.0" {
		payload["remoteip"] = ip
	}
	data, err := json.Marshal(payload)
	if err != nil {
		return false
	}
	ctx, cancel := context.WithTimeout(ctx, 5*time.Second)
	defer cancel()
	request, err := http.NewRequestWithContext(ctx, "POST", "https://challenges.cloudflare.com/turnstile/v0/siteverify", bytes.NewReader(data))
	if err != nil {
		return false
	}
	request.Header.Set("Content-Type", "application/json")
	request.Header.Set("Accept", "application/json")
	client := http.Client{Timeout: 5 * time.Second}
	if t.Client != nil {
		client = *t.Client
	}
	client.CheckRedirect = func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }
	response, err := client.Do(request)
	if err != nil {
		return false
	}
	defer response.Body.Close()
	if response.StatusCode != 200 {
		return false
	}
	body, err := io.ReadAll(io.LimitReader(response.Body, 16385))
	if err != nil || len(body) > 16384 {
		return false
	}
	var result struct {
		Success          bool
		Action, Hostname string
	}
	if err = json.Unmarshal(body, &result); err != nil || !result.Success {
		return false
	}
	return (action == "" || strings.TrimSpace(result.Action) == action) && (hostname == "" || strings.EqualFold(strings.TrimSpace(result.Hostname), strings.TrimSpace(hostname)))
}
