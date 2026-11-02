package account

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"net/mail"
	"regexp"
	"strings"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
	"github.com/go-sql-driver/mysql"
)

var ErrUnauthorized = errors.New("unauthorized")
var usernamePattern = regexp.MustCompile(`^[A-Za-z0-9_-]{1,20}$`)
var hashPattern = regexp.MustCompile(`(?i)^[a-f0-9]{40}$`)

type Identity struct {
	ID, UserID int64
	Username   string
}
type Service struct {
	DB   *sql.DB
	Cost int
}

func (s Service) cost() int {
	if s.Cost == 0 {
		return 12
	}
	return s.Cost
}
func trim(v string) string { return strings.Trim(v, " \t\r\n\x00\x0b") }

func (s Service) Authenticate(ctx context.Context, id int64, credential string) (Identity, error) {
	credential = trim(credential)
	if id <= 0 || credential == "" || len(credential) > 1024 {
		return Identity{}, ErrUnauthorized
	}
	r, err := dbutil.One(ctx, s.DB, `SELECT a.account_id,a.username,a.password_hash,a.gjp2_hash,a.is_active,a.is_banned,p.user_id
		FROM accounts a LEFT JOIN profiles p ON p.account_id=a.account_id WHERE a.account_id=? LIMIT 1`, id)
	if err != nil || r.Int("is_active") != 1 || r.Int("is_banned") == 1 {
		Verify(credential, dummyHash)
		return Identity{}, ErrUnauthorized
	}
	valid := Verify(credential, r["password_hash"])
	if !valid && hashPattern.MatchString(credential) {
		valid = Verify(credential, r["gjp2_hash"])
	}
	if !valid {
		if plain, ok := DecodeGJP(credential); ok {
			valid = Verify(plain, r["password_hash"]) || Verify(GJP2(plain), r["gjp2_hash"]) || Verify(digest(plain, LegacySalt), r["gjp2_hash"])
		}
	}
	if !valid {
		return Identity{}, ErrUnauthorized
	}
	uid := r.Int("user_id")
	if uid <= 0 {
		uid, err = s.ensureProfile(ctx, id)
		if err != nil {
			return Identity{}, err
		}
	}
	return Identity{id, uid, r["username"]}, nil
}

func (s Service) ensureProfile(ctx context.Context, id int64) (int64, error) {
	_, err := s.DB.ExecContext(ctx, `INSERT INTO profiles(account_id) VALUES(?) ON DUPLICATE KEY UPDATE user_id=LAST_INSERT_ID(user_id)`, id)
	if err != nil {
		return 0, err
	}
	var uid int64
	err = s.DB.QueryRowContext(ctx, `SELECT user_id FROM profiles WHERE account_id=? LIMIT 1`, id).Scan(&uid)
	if err == nil && uid <= 0 {
		return 0, ErrUnauthorized
	}
	return uid, err
}

func (s Service) Register(ctx context.Context, username, password, email, ip string) (string, error) {
	username, email = trim(username), trim(email)
	if username == "" || password == "" || email == "" {
		return "-1", nil
	}
	if !usernamePattern.MatchString(username) {
		return "-4", nil
	}
	if len(password) > 256 || len(email) > 254 || strings.ContainsRune(password, 0) {
		return "-1", nil
	}
	address, err := mail.ParseAddress(email)
	if err != nil || address.Address != email || !strings.Contains(email, ".") {
		return "-1", nil
	}
	if code, err := s.duplicate(ctx, username, email); err != nil || code != "" {
		return code, err
	}
	passHash, err := Hash(password, s.cost())
	if err != nil {
		return "", err
	}
	gjpHash, err := Hash(GJP2(password), s.cost())
	if err != nil {
		return "", err
	}
	tx, err := s.DB.BeginTx(ctx, nil)
	if err != nil {
		return "", err
	}
	defer tx.Rollback()
	result, err := tx.ExecContext(ctx, `INSERT INTO accounts(username,email,password_hash,gjp2_hash,role_id)
		VALUES(?,?,?,?,(SELECT id FROM roles WHERE code='user' LIMIT 1))`, username, email, passHash, gjpHash)
	if err != nil {
		_ = tx.Rollback()
		var duplicate *mysql.MySQLError
		if errors.As(err, &duplicate) && duplicate.Number == 1062 {
			if code, e := s.duplicate(ctx, username, email); e == nil && code != "" {
				return code, nil
			}
		}
		return "", err
	}
	id, err := result.LastInsertId()
	if err != nil {
		return "", err
	}
	if _, err = tx.ExecContext(ctx, `INSERT INTO profiles(account_id) VALUES(?)`, id); err != nil {
		return "", err
	}
	if _, err = tx.ExecContext(ctx, `INSERT INTO audit_logs(account_id,action,ip_address,metadata) VALUES(?,'account.register',?,'[]')`, id, ip); err != nil {
		return "", err
	}
	if err = tx.Commit(); err != nil {
		return "", err
	}
	// The PHP core treats webhook enqueueing as best-effort after commit.
	payload, _ := json.Marshal(map[string]any{"event": "account.registered", "request_id": "", "data": map[string]any{"account_id": id, "username": username}})
	_, _ = s.DB.ExecContext(ctx, `INSERT INTO mucho_jobs(job_type,payload_json,available_at) VALUES('webhook.dispatch',?,UTC_TIMESTAMP())`, payload)
	return "1", nil
}

func (s Service) duplicate(ctx context.Context, username, email string) (string, error) {
	for _, check := range []struct{ query, arg, code string }{{`SELECT 1 FROM accounts WHERE username=? LIMIT 1`, username, "-2"}, {`SELECT 1 FROM accounts WHERE email=? LIMIT 1`, email, "-3"}} {
		var n int
		err := s.DB.QueryRowContext(ctx, check.query, check.arg).Scan(&n)
		if err == nil {
			return check.code, nil
		}
		if !errors.Is(err, sql.ErrNoRows) {
			return "", err
		}
	}
	return "", nil
}

func (s Service) Login(ctx context.Context, username, password, gjp2, ip, udid string) (string, error) {
	username = trim(username)
	if username == "" || len(username) > 20 || len(password) > 512 || len(gjp2) > 512 {
		return "-1", nil
	}
	r, err := dbutil.One(ctx, s.DB, `SELECT account_id,password_hash,gjp2_hash,is_active,is_banned FROM accounts WHERE username=? LIMIT 1`, username)
	if errors.Is(err, sql.ErrNoRows) {
		return "-1", nil
	}
	if err != nil {
		return "", err
	}
	if r.Int("is_active") != 1 || r.Int("is_banned") == 1 {
		return "-1", nil
	}
	valid := false
	if password != "" {
		valid = Verify(password, r["password_hash"])
		if !valid {
			valid = Verify(GJP2(password), r["gjp2_hash"]) || Verify(digest(password, LegacySalt), r["gjp2_hash"])
		}
		if !valid && hashPattern.MatchString(password) {
			valid = Verify(password, r["gjp2_hash"])
		}
	}
	if !valid && gjp2 != "" {
		valid = Verify(gjp2, r["gjp2_hash"])
	}
	id := r.Int("account_id")
	if !valid {
		_, err = s.DB.ExecContext(ctx, `INSERT INTO audit_logs(account_id,action,ip_address,metadata) VALUES(?,'account.login_failed',?,'[]')`, id, ip)
		return "-1", err
	}
	uid, err := s.ensureProfile(ctx, id)
	if err != nil {
		return "", err
	}
	// Auxiliary bookkeeping must never replace a successful protocol login.
	_, _ = s.DB.ExecContext(ctx, `UPDATE accounts SET last_login_at=CURRENT_TIMESTAMP WHERE account_id=?`, id)
	_, _ = s.DB.ExecContext(ctx, `INSERT INTO audit_logs(account_id,action,ip_address,metadata) VALUES(?,'account.login',?,'[]')`, id, ip)
	udid = trim(udid)
	if udid != "" && len(udid) <= 255 && ip != "" {
		if hash, e := Hash(udid, s.cost()); e == nil {
			_, _ = s.DB.ExecContext(ctx, `DELETE FROM mucho_legacy_19_sessions WHERE expires_at<=UTC_TIMESTAMP() OR (account_id=? AND ip_address=?)`, id, ip)
			_, _ = s.DB.ExecContext(ctx, `INSERT INTO mucho_legacy_19_sessions(account_id,udid_hash,ip_address,expires_at) VALUES(?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR))`, id, hash, ip)
		}
	}
	return fmt.Sprintf("%d,%d", id, uid), nil
}

// AuthenticateLegacyUpload requires all three: account, source IP and UDID.
// Standard endpoints never treat an IP address as account authentication.
func (s Service) AuthenticateLegacyUpload(ctx context.Context, id int64, udid, ip string) (Identity, error) {
	udid = trim(udid)
	if id <= 0 || udid == "" || len(udid) > 255 || ip == "" {
		return Identity{}, ErrUnauthorized
	}
	rows, err := dbutil.Query(ctx, s.DB, `SELECT a.account_id,a.username,a.is_active,a.is_banned,p.user_id,s.id AS session_id,s.udid_hash
		FROM accounts a LEFT JOIN profiles p ON p.account_id=a.account_id JOIN mucho_legacy_19_sessions s ON s.account_id=a.account_id
		WHERE a.account_id=? AND s.ip_address=? AND s.expires_at>UTC_TIMESTAMP() ORDER BY s.created_at DESC LIMIT 16`, id, ip)
	if err != nil {
		return Identity{}, err
	}
	for _, r := range rows {
		if r.Int("is_active") == 1 && r.Int("is_banned") != 1 && Verify(udid, r["udid_hash"]) {
			_, err = s.DB.ExecContext(ctx, `UPDATE mucho_legacy_19_sessions SET last_used_at=UTC_TIMESTAMP(),expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR) WHERE id=?`, r.Int("session_id"))
			return Identity{id, r.Int("user_id"), r["username"]}, err
		}
	}
	return Identity{}, ErrUnauthorized
}
