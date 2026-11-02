package security

import (
	"net/http/httptest"
	"testing"
)

func TestForwardedIPTrust(t *testing.T) {
	for _, tc := range []struct{ remote, xff, cf, proxies, want string }{
		{"192.0.2.1:443", "203.0.113.9", "", "", "192.0.2.1"},
		{"127.0.0.1:443", "203.0.113.9", "", "", "203.0.113.9"},
		{"192.0.2.1:443", "203.0.113.9", "", "192.0.2.0/24", "203.0.113.9"},
		{"127.0.0.1:443", "203.0.113.9", "", "192.0.2.0/24", "127.0.0.1"},
		{"173.245.48.1:443", "203.0.113.9", "198.51.100.7", "", "198.51.100.7"},
		{"192.0.2.1:443", "", "198.51.100.7", "", "192.0.2.1"},
		{"[::1]:443", "invalid, 203.0.113.9", "", "", "203.0.113.9"},
	} {
		r := httptest.NewRequest("GET", "/", nil)
		r.RemoteAddr = tc.remote
		r.Header.Set("X-Forwarded-For", tc.xff)
		r.Header.Set("CF-Connecting-IP", tc.cf)
		if got := ClientIP(r, ParseProxies(tc.proxies)); got != tc.want {
			t.Errorf("%s: %s, want %s", tc.remote, got, tc.want)
		}
	}
}
