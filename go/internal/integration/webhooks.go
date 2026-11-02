// Package integration delivers configured MuchoCore event webhooks.
package integration

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

type Webhooks struct {
	URL, Events, Secret string
	Client              *http.Client
	Now                 func() time.Time
}

func (w Webhooks) Enabled(event string) bool {
	u, err := url.Parse(strings.TrimSpace(w.URL))
	if err != nil || u.Host == "" || (u.Scheme != "http" && u.Scheme != "https") {
		return false
	}
	if strings.TrimSpace(w.Events) == "" || strings.TrimSpace(w.Events) == "*" {
		return true
	}
	for _, allowed := range strings.Split(w.Events, ",") {
		if strings.TrimSpace(allowed) == event {
			return true
		}
	}
	return false
}
func (w Webhooks) Dispatch(ctx context.Context, event string, data map[string]any, requestID string) error {
	if !w.Enabled(event) {
		return nil
	}
	now := time.Now()
	if w.Now != nil {
		now = w.Now()
	}
	envelope := struct {
		Event     string         `json:"event"`
		RequestID string         `json:"request_id"`
		SentAt    string         `json:"sent_at"`
		Data      map[string]any `json:"data"`
	}{event, requestID, now.UTC().Format("2006-01-02T15:04:05+00:00"), data}
	var body bytes.Buffer
	encoder := json.NewEncoder(&body)
	encoder.SetEscapeHTML(false)
	if err := encoder.Encode(envelope); err != nil {
		return errors.New("invalid webhook payload")
	}
	payload := bytes.TrimSuffix(body.Bytes(), []byte("\n"))
	ctx, cancel := context.WithTimeout(ctx, 5*time.Second)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, "POST", strings.TrimSpace(w.URL), bytes.NewReader(payload))
	if err != nil {
		return errors.New("invalid webhook URL")
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", "MuchoCore-Webhook/1.0")
	req.Header.Set("X-MuchoCore-Event", event)
	if requestID != "" {
		req.Header.Set("X-MuchoCore-Request-Id", requestID)
	}
	if w.Secret != "" {
		mac := hmac.New(sha256.New, []byte(w.Secret))
		_, _ = mac.Write(payload)
		req.Header.Set("X-MuchoCore-Signature", fmt.Sprintf("sha256=%x", mac.Sum(nil)))
	}
	client := w.Client
	if client == nil {
		client = &http.Client{Timeout: 5 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	}
	response, err := client.Do(req)
	if err != nil {
		return errors.New("webhook delivery failed")
	}
	defer response.Body.Close()
	_, _ = io.Copy(io.Discard, io.LimitReader(response.Body, 65536))
	if response.StatusCode < 200 || response.StatusCode >= 300 {
		return errors.New("webhook returned an unsuccessful status")
	}
	return nil
}
