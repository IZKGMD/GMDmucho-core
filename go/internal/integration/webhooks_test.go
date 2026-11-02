package integration

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
	"time"
)

func TestSignedWebhook(t *testing.T) {
	var called atomic.Bool
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		called.Store(true)
		body, err := io.ReadAll(r.Body)
		if err != nil {
			t.Error(err)
			return
		}
		mac := hmac.New(sha256.New, []byte("test-secret"))
		_, _ = mac.Write(body)
		if r.Header.Get("X-MuchoCore-Signature") != fmt.Sprintf("sha256=%x", mac.Sum(nil)) {
			t.Error("invalid signature")
		}
		var values map[string]any
		if err = json.Unmarshal(body, &values); err != nil {
			t.Error(err)
		}
		if values["event"] != "account.registered" || values["request_id"] != "test-request" || values["sent_at"] != "2023-11-14T22:13:20+00:00" {
			t.Error("invalid event envelope")
		}
		w.WriteHeader(204)
	}))
	defer server.Close()
	w := Webhooks{URL: server.URL, Events: "level.uploaded,account.registered", Secret: "test-secret", Now: func() time.Time { return time.Unix(1700000000, 0) }}
	if err := w.Dispatch(context.Background(), "account.registered", map[string]any{"username": "Test"}, "test-request"); err != nil {
		t.Fatal(err)
	}
	if !called.Load() {
		t.Fatal("webhook not delivered")
	}
	called.Store(false)
	if err := w.Dispatch(context.Background(), "unsubscribed.event", nil, ""); err != nil || called.Load() {
		t.Fatal("unsubscribed event was delivered")
	}
}
func TestFailuresAndDisabledHooks(t *testing.T) {
	if err := (Webhooks{}).Dispatch(context.Background(), "event", nil, ""); err != nil {
		t.Fatal(err)
	}
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.WriteHeader(500) }))
	defer server.Close()
	if err := (Webhooks{URL: server.URL}).Dispatch(context.Background(), "event", nil, ""); err == nil {
		t.Fatal("failed webhook acknowledged")
	}
}
