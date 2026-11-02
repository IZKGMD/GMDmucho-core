package core

import (
	"crypto/sha256"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"strings"
	"sync"
	"sync/atomic"
	"testing"

	"github.com/IZKGMD/GMDmucho-core/go/internal/account"
	"github.com/IZKGMD/GMDmucho-core/go/internal/admin"
)

func testAdminClient(t *testing.T, h *Handler) {
	t.Helper()
	password, err := account.Hash("native-admin-password", 4)
	if err != nil {
		t.Fatal(err)
	}
	const secret = "GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ"
	for _, role := range []struct {
		code        string
		permissions []string
	}{{"native_api_reader", []string{"dashboard.view", "self.security", "users.view"}}, {"native_api_manager", []string{"dashboard.view", "self.security", "players.manage", "players.view", "levels.view", "levels.manage"}}} {
		result, err := h.db.Exec(`INSERT INTO admin_roles(code,name,is_system) VALUES(?,?,0)`, role.code, role.code)
		if err != nil {
			t.Fatal(err)
		}
		id, err := result.LastInsertId()
		if err != nil {
			t.Fatal(err)
		}
		for _, permission := range role.permissions {
			if _, err = h.db.Exec(`INSERT INTO admin_role_permissions(role_id,permission) VALUES(?,?)`, id, permission); err != nil {
				t.Fatal(err)
			}
		}
	}
	for _, user := range []struct{ name, role, otp string }{{"NativeOwner", "owner", secret}, {"NativeReader", "native_api_reader", ""}, {"NativeManager", "native_api_manager", ""}} {
		if _, err = h.db.Exec(`INSERT INTO admin_users(username,password_hash,role,totp_secret,is_active) VALUES(?,?,?,?,1)`, user.name, password, user.role, user.otp); err != nil {
			t.Fatal(err)
		}
	}
	call := func(token, ip string, input map[string]any) (*httptest.ResponseRecorder, map[string]any) {
		data, err := json.Marshal(input)
		if err != nil {
			t.Error(err)
		}
		r := httptest.NewRequest("POST", "/api/v2/admin-client.php", strings.NewReader(string(data)))
		r.Header.Set("Content-Type", "application/json")
		if token != "" {
			r.Header.Set("Authorization", "Bearer "+token)
		}
		r.RemoteAddr = ip + ":4200"
		w := httptest.NewRecorder()
		h.ServeHTTP(w, r)
		var payload map[string]any
		if err = json.Unmarshal(w.Body.Bytes(), &payload); err != nil {
			t.Errorf("admin JSON %d %q", w.Code, w.Body.String())
		}
		if payload["request_id"] == nil || w.Header().Get("X-Request-ID") == "" || w.Header().Get("Cache-Control") != "no-store, max-age=0" {
			t.Error("missing administrator API headers")
		}
		return w, payload
	}
	const ip = "198.18.0.11"
	w, body := call("", ip, map[string]any{"action": "auth.me"})
	if w.Code != 401 || body["error"] != "unauthorized" {
		t.Fatal("anonymous administrator access")
	}
	w, body = call("", ip, map[string]any{"action": "auth.login", "username": "NativeOwner", "password": "native-admin-password"})
	if w.Code != 401 || body["error"] != "totp_required" {
		t.Fatal("administrator TOTP bypass")
	}
	login := func(name string) string {
		input := map[string]any{"action": "auth.login", "username": name, "password": "native-admin-password", "client_version": "1.0.1"}
		if name == "NativeOwner" {
			input["totp"] = admin.TOTP(secret, h.now().Unix()/30)
		}
		w, body := call("", ip, input)
		if w.Code != 200 {
			t.Fatalf("login %s: %d %s", name, w.Code, w.Body)
		}
		token, _ := body["token"].(string)
		if len(token) != 43 {
			t.Fatal("invalid administrator token")
		}
		return token
	}
	owner, reader, manager := login("NativeOwner"), login("NativeReader"), login("NativeManager")
	var stored string
	if err = h.db.QueryRow(`SELECT token_hash FROM mucho_admin_client_tokens WHERE token_hash=?`, fmt.Sprintf("%x", sha256.Sum256([]byte(owner)))).Scan(&stored); err != nil || stored == owner || len(stored) != 64 {
		t.Fatal("administrator token was not stored as a hash")
	}
	w, body = call(reader, ip, map[string]any{"action": "players.search", "query": "Alice"})
	if w.Code != 200 || len(body["players"].([]any)) == 0 {
		t.Fatal("custom role permission alias ignored")
	}
	w, body = call(reader, ip, map[string]any{"action": "players.set_ban", "account_id": 2, "banned": true})
	if w.Code != 403 || body["error"] != "forbidden" {
		t.Fatal("read-only role mutated a player")
	}
	if _, err = h.db.Exec(`DELETE p FROM admin_role_permissions p JOIN admin_roles r ON r.id=p.role_id WHERE r.code='native_api_reader' AND p.permission='users.view'`); err != nil {
		t.Fatal(err)
	}
	w, _ = call(reader, ip, map[string]any{"action": "players.search", "query": "Alice"})
	if w.Code != 403 {
		t.Fatal("revoked permission remained cached")
	}
	w, _ = call(manager, ip, map[string]any{"action": "players.set_ban", "account_id": 6, "banned": true})
	if w.Code != 403 {
		t.Fatal("protected game owner ban")
	}
	w, _ = call(manager, ip, map[string]any{"action": "players.set_role", "account_id": 4, "role": "owner"})
	if w.Code != 403 {
		t.Fatal("custom role assigned game owner")
	}
	w, _ = call(owner, ip, map[string]any{"action": "players.set_ban", "account_id": "2", "banned": true})
	if w.Code != 422 {
		t.Fatal("string accepted as strict integer")
	}
	w, _ = call(owner, ip, map[string]any{"action": "players.set_ban", "account_id": 2, "banned": "false"})
	if w.Code != 422 {
		t.Fatal("string accepted as strict boolean")
	}
	for _, banned := range []bool{true, false} {
		w, _ = call(manager, ip, map[string]any{"action": "players.set_ban", "account_id": 2, "banned": banned})
		if w.Code != 200 {
			t.Fatalf("ban %d %s", w.Code, w.Body)
		}
		var saved int
		if err = h.db.QueryRow(`SELECT is_banned FROM accounts WHERE account_id=2`).Scan(&saved); err != nil || (saved != 0) != banned {
			t.Fatal("ban not persisted")
		}
	}
	for _, role := range []string{"helper", "user"} {
		w, _ = call(owner, ip, map[string]any{"action": "players.set_role", "account_id": 4, "role": role})
		if w.Code != 200 {
			t.Fatalf("role %s", w.Body)
		}
	}
	var legacy, code string
	if err = h.db.QueryRow(`SELECT a.role,r.code FROM accounts a JOIN roles r ON r.id=a.role_id WHERE a.account_id=4`).Scan(&legacy, &code); err != nil || legacy != "user" || code != "user" {
		t.Fatal("demotion left legacy moderator privileges")
	}
	w, _ = call(owner, ip, map[string]any{"action": "players.update_profile", "account_id": 4, "stars": 321, "moons": 5, "diamonds": 700, "secret_coins": 20, "user_coins": 10, "demons": 7, "creator_points": 2, "bio": "Native admin profile"})
	if w.Code != 200 {
		t.Fatalf("profile update %s", w.Body)
	}
	w, body = call(owner, ip, map[string]any{"action": "players.get", "account_id": 4})
	if w.Code != 200 || body["player"].(map[string]any)["stars"] != float64(321) {
		t.Fatal("player profile view stale")
	}
	var cpBefore int64
	if err = h.db.QueryRow(`SELECT creator_points FROM profiles WHERE account_id=1`).Scan(&cpBefore); err != nil {
		t.Fatal(err)
	}
	level := map[string]any{"action": "levels.update", "level_id": 6, "stars": 5, "difficulty": 30, "demon": false, "demon_difficulty": 0, "featured": true, "epic": 1, "is_deleted": false}
	w, _ = call(manager, ip, level)
	if w.Code != 200 {
		t.Fatalf("level update %s", w.Body)
	}
	var cpAfter int64
	if err = h.db.QueryRow(`SELECT creator_points FROM profiles WHERE account_id=1`).Scan(&cpAfter); err != nil || cpAfter != cpBefore+1 {
		t.Fatalf("creator points not recomputed: %d -> %d (%v)", cpBefore, cpAfter, err)
	}
	level["is_deleted"] = true
	w, _ = call(manager, ip, level)
	if w.Code != 200 {
		t.Fatalf("level deletion %s", w.Body)
	}
	var deleted int
	if err = h.db.QueryRow(`SELECT is_deleted FROM mucho_level_search_index WHERE level_id=6`).Scan(&deleted); err != nil || deleted != 1 {
		t.Fatal("search index retained deleted level")
	}
	if _, err = h.db.Exec(`CREATE TRIGGER native_admin_audit_failure BEFORE INSERT ON mucho_admin_client_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='native audit failure test'`); err != nil {
		t.Fatal(err)
	}
	defer h.db.Exec(`DROP TRIGGER IF EXISTS native_admin_audit_failure`)
	w, _ = call(owner, ip, map[string]any{"action": "players.set_ban", "account_id": 2, "banned": true})
	if w.Code != 500 {
		t.Fatal("audit failure accepted")
	}
	var banned int
	if err = h.db.QueryRow(`SELECT is_banned FROM accounts WHERE account_id=2`).Scan(&banned); err != nil || banned != 0 {
		t.Fatal("unaudited administrator mutation committed")
	}
	if _, err = h.db.Exec(`DROP TRIGGER native_admin_audit_failure`); err != nil {
		t.Fatal(err)
	}
	w, body = call(owner, ip, map[string]any{"action": "audit.list"})
	if w.Code != 200 || len(body["entries"].([]any)) < 6 {
		t.Fatal("missing administrator audit records")
	}
	w, _ = call(reader, ip, map[string]any{"action": "auth.logout"})
	if w.Code != 200 {
		t.Fatal("logout failed")
	}
	w, _ = call(reader, ip, map[string]any{"action": "auth.me"})
	if w.Code != 401 {
		t.Fatal("revoked administrator token accepted")
	}
	var wg sync.WaitGroup
	var rejected, limited atomic.Int32
	for range 12 {
		wg.Go(func() {
			w, _ := call("", "198.18.0.77", map[string]any{"action": "auth.login", "username": "NativeOwner", "password": "wrong"})
			switch w.Code {
			case 401:
				rejected.Add(1)
			case 429:
				limited.Add(1)
			default:
				t.Errorf("concurrent login HTTP %d", w.Code)
			}
		})
	}
	wg.Wait()
	if rejected.Load() != 8 || limited.Load() != 4 {
		t.Fatalf("login failure bound: %d rejected, %d limited", rejected.Load(), limited.Load())
	}
}
