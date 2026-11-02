// Package security implements shared database-backed MuchoProtect budgets.
package security

import (
	"context"
	"crypto/sha256"
	"database/sql"
	_ "embed"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"net/netip"
	"net/url"
	"strconv"
	"strings"
	"time"

	"github.com/IZKGMD/GMDmucho-core/go/internal/protocol"
)

//go:embed policies.json
var policyJSON []byte

type Policy struct{ Limit, Window, Burst, BurstWindow, IdentityLimit, IdentityWindow, DeviceLimit, DeviceWindow int }

func Policies() map[string]Policy {
	var p map[string]Policy
	if err := json.Unmarshal(policyJSON, &p); err != nil {
		panic(err)
	}
	return p
}

type Protect struct {
	DB       *sql.DB
	Policies map[string]Policy
}

func digest(value string) string { return fmt.Sprintf("%x", sha256.Sum256([]byte(value))) }

func (p Protect) Allow(ctx context.Context, key string, limit, window int) (bool, error) {
	if limit < 1 || window < 1 {
		return false, nil
	}
	tx, err := p.DB.BeginTx(ctx, nil)
	if err != nil {
		return false, err
	}
	defer tx.Rollback()
	key = digest(key)
	now := time.Now().Unix()
	_, err = tx.ExecContext(ctx, `INSERT INTO mucho_security_rate_limits(bucket_key,window_start,request_count) VALUES(?,?,0)
		ON DUPLICATE KEY UPDATE bucket_key=VALUES(bucket_key)`, key, now)
	if err != nil {
		return false, err
	}
	var start, count int64
	err = tx.QueryRowContext(ctx, `SELECT window_start,request_count FROM mucho_security_rate_limits WHERE bucket_key=? FOR UPDATE`, key).Scan(&start, &count)
	if err != nil {
		return false, err
	}
	if start <= 0 || now-start >= int64(window) {
		start, count = now, 0
	}
	count++
	_, err = tx.ExecContext(ctx, `UPDATE mucho_security_rate_limits SET window_start=?,request_count=?,updated_at=CURRENT_TIMESTAMP WHERE bucket_key=?`, start, count, key)
	if err != nil {
		return false, err
	}
	return count <= int64(limit), tx.Commit()
}

func (p Protect) penalty(ctx context.Context, key string) (bool, error) {
	var expiry int64
	err := p.DB.QueryRowContext(ctx, `SELECT expires_at FROM mucho_security_penalties WHERE bucket_key=? LIMIT 1`, digest(key)).Scan(&expiry)
	if err == sql.ErrNoRows {
		return false, nil
	}
	return expiry > time.Now().Unix(), err
}
func (p Protect) penalize(ctx context.Context, key string) error {
	tx, err := p.DB.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	key = digest(key)
	now := time.Now().Unix()
	_, err = tx.ExecContext(ctx, `INSERT INTO mucho_security_penalties(bucket_key,expires_at,last_at,strikes) VALUES(?,0,0,0) ON DUPLICATE KEY UPDATE bucket_key=VALUES(bucket_key)`, key)
	if err != nil {
		return err
	}
	var last, strikes int64
	err = tx.QueryRowContext(ctx, `SELECT last_at,strikes FROM mucho_security_penalties WHERE bucket_key=? FOR UPDATE`, key).Scan(&last, &strikes)
	if err != nil {
		return err
	}
	if last > 0 && now-last <= 600 {
		strikes = min(8, max(0, strikes)+1)
	} else {
		strikes = 1
	}
	seconds := min(int64(900), 15*(int64(1)<<min(7, strikes-1)))
	_, err = tx.ExecContext(ctx, `UPDATE mucho_security_penalties SET expires_at=?,last_at=?,strikes=?,updated_at=CURRENT_TIMESTAMP WHERE bucket_key=?`, now+seconds, now, strikes, key)
	if err != nil {
		return err
	}
	return tx.Commit()
}

func network(ip string) string {
	addr, err := netip.ParseAddr(ip)
	if err != nil {
		return ""
	}
	addr = addr.Unmap()
	if addr.Is4() {
		return netip.PrefixFrom(addr, 24).Masked().String()
	}
	b := addr.As16()
	return hex.EncodeToString(b[:8]) + "/64"
}

func (p Protect) Inspect(ctx context.Context, path, ip string, form url.Values, version protocol.Version) (bool, error) {
	allowed, _, err := p.InspectReason(ctx, path, ip, form, version)
	return allowed, err
}
func (p Protect) InspectReason(ctx context.Context, path, ip string, form url.Values, version protocol.Version) (bool, string, error) {
	for _, exempt := range []string{"/health", "/ready", "/checkifserveronline", "/getaccounturl", "/getcustomcontenturl"} {
		if path == exempt {
			return true, "", nil
		}
	}
	identities := map[string]string{}
	if id, _ := strconv.ParseInt(form.Get("accountID"), 10, 64); id > 0 {
		if credential := version.Credential(form); credential != "" {
			identities["account"] = digest(fmt.Sprintf("account:%d:%s", id, credential))
		}
	}
	for kind, key := range map[string]string{"username": "userName", "email": "email", "device": "udid"} {
		value := strings.TrimSpace(form.Get(key))
		if kind == "username" && value == "" {
			value = strings.TrimSpace(form.Get("username"))
		}
		if value != "" && len(value) <= 256 {
			prefix := kind
			if kind == "device" {
				prefix = "udid"
			} else {
				value = strings.ToLower(value)
			}
			identities[kind] = digest(prefix + ":" + value)
		}
	}
	ipKey := "ip:" + ip + ":endpoint:" + path
	networkKey := ""
	if n := network(ip); n != "" {
		networkKey = "network:" + n + ":endpoint:" + path
	}
	keys := []string{ipKey}
	if networkKey != "" {
		keys = append(keys, networkKey)
	}
	for kind, id := range identities {
		keys = append(keys, kind+":"+id+":endpoint:"+path)
	}
	for _, key := range keys {
		blocked, err := p.penalty(ctx, key)
		if err != nil || blocked {
			reason := "temporary_penalty"
			for _, kind := range []string{"network", "account", "username", "email", "device"} {
				if strings.HasPrefix(key, kind+":") {
					reason = kind + "_penalty"
					break
				}
			}
			return false, reason, err
		}
	}
	check := func(key string, limit, window int, penaltyKey string) (bool, error) {
		allowed, err := p.Allow(ctx, key, limit, window)
		if err != nil {
			return false, err
		}
		if !allowed {
			_ = p.penalize(ctx, penaltyKey)
		}
		return allowed, nil
	}
	global := "ip:" + ip + ":global"
	for _, b := range []struct {
		key           string
		limit, window int
	}{{global, 900, 60}, {global + ":burst", 180, 10}} {
		if ok, err := check(b.key, b.limit, b.window, global); err != nil || !ok {
			return false, func() string {
				if b.window == 10 {
					return "global_burst_limit"
				}
				return "global_rate_limit"
			}(), err
		}
	}
	policy, ok := p.Policies[path]
	if !ok {
		return true, "", nil
	}
	if networkKey != "" {
		if ok, err := check(networkKey, max(policy.Limit+1, policy.Limit*3), policy.Window, networkKey); err != nil || !ok {
			return false, "network_rate_limit", err
		}
		if ok, err := check(networkKey+":burst", max(policy.Burst+1, policy.Burst*3), policy.BurstWindow, networkKey); err != nil || !ok {
			return false, "network_burst_limit", err
		}
	}
	if ok, err := check(ipKey, policy.Limit, policy.Window, ipKey); err != nil || !ok {
		return false, "ip_rate_limit", err
	}
	if ok, err := check(ipKey+":burst", policy.Burst, policy.BurstWindow, ipKey); err != nil || !ok {
		return false, "burst_limit", err
	}
	for kind, id := range identities {
		limit, window := policy.IdentityLimit, policy.IdentityWindow
		if kind == "device" && policy.DeviceLimit > 0 {
			limit, window = policy.DeviceLimit, policy.DeviceWindow
		} else if path == "/registergjaccount" && limit == 0 {
			continue
		}
		if limit == 0 {
			limit = policy.Limit
		}
		if window == 0 {
			window = policy.Window
		}
		key := kind + ":" + id + ":endpoint:" + path
		if ok, err := check(key, limit, window, key); err != nil || !ok {
			return false, kind + "_rate_limit", err
		}
	}
	return true, "", nil
}
