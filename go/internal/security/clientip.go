package security

import (
	"net"
	"net/http"
	"net/netip"
	"strings"
)

var privateCIDRs = ParseProxies("10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 127.0.0.0/8 ::1/128 fc00::/7 fe80::/10")
var cloudflareCIDRs = ParseProxies("173.245.48.0/20 103.21.244.0/22 103.22.200.0/22 103.31.4.0/22 141.101.64.0/18 108.162.192.0/18 190.93.240.0/20 188.114.96.0/20 197.234.240.0/22 198.41.128.0/17 162.158.0.0/15 104.16.0.0/13 104.24.0.0/14 172.64.0.0/13 131.0.72.0/22 2400:cb00::/32 2606:4700::/32 2803:f800::/32 2405:b500::/32 2405:8100::/32 2a06:98c0::/29 2c0f:f248::/32")

func ParseProxies(value string) []netip.Prefix {
	var result []netip.Prefix
	for _, part := range strings.Fields(strings.ReplaceAll(value, ",", " ")) {
		if !strings.Contains(part, "/") {
			addr, err := netip.ParseAddr(part)
			if err != nil {
				continue
			}
			result = append(result, netip.PrefixFrom(addr, addr.BitLen()))
			continue
		}
		if prefix, err := netip.ParsePrefix(part); err == nil {
			result = append(result, prefix)
		}
	}
	return result
}
func contains(prefixes []netip.Prefix, addr netip.Addr) bool {
	for _, prefix := range prefixes {
		if prefix.Contains(addr) {
			return true
		}
	}
	return false
}
func ClientIP(r *http.Request, trusted []netip.Prefix) string {
	remote, _, err := net.SplitHostPort(r.RemoteAddr)
	if err != nil {
		remote = r.RemoteAddr
	}
	addr, err := netip.ParseAddr(strings.TrimSpace(remote))
	if err != nil {
		return "0.0.0.0"
	}
	cf := contains(cloudflareCIDRs, addr)
	if !cf && !contains(trusted, addr) && !(len(trusted) == 0 && contains(privateCIDRs, addr)) {
		return addr.String()
	}
	if cf {
		if client, err := netip.ParseAddr(strings.TrimSpace(r.Header.Get("CF-Connecting-IP"))); err == nil {
			return client.String()
		}
	}
	for _, value := range strings.Split(r.Header.Get("X-Forwarded-For"), ",") {
		if client, err := netip.ParseAddr(strings.TrimSpace(value)); err == nil {
			return client.String()
		}
	}
	return addr.String()
}
