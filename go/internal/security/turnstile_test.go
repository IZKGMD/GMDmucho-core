package security

import (
	"context"
	"io"
	"net/http"
	"strings"
	"testing"
)

type turnstileTransport func(*http.Request) (*http.Response, error)

func (f turnstileTransport) RoundTrip(r *http.Request) (*http.Response, error) { return f(r) }
func TestTurnstileBindsActionAndHostname(t *testing.T) {
	for _, tc := range []struct {
		body   string
		status int
		want   bool
	}{{`{"success":true,"action":"recovery","hostname":"example.test"}`, 200, true}, {`{"success":true,"action":"login","hostname":"example.test"}`, 200, false}, {`{"success":true,"action":"recovery","hostname":"attacker.test"}`, 200, false}, {`{"success":false}`, 200, false}, {`{"success":true}`, 302, false}, {`broken`, 200, false}} {
		captcha := Turnstile{SiteKey: "test-site", Secret: "test-secret", Client: &http.Client{Transport: turnstileTransport(func(r *http.Request) (*http.Response, error) {
			if r.URL.String() != "https://challenges.cloudflare.com/turnstile/v0/siteverify" || r.Method != "POST" {
				t.Fatal("unexpected verification destination")
			}
			return &http.Response{StatusCode: tc.status, Body: io.NopCloser(strings.NewReader(tc.body)), Header: http.Header{}}, nil
		})}}
		if got := captcha.Verify(context.Background(), "test-response", "recovery", "example.test", "192.0.2.1"); got != tc.want {
			t.Errorf("%s => %v", tc.body, got)
		}
	}
}
