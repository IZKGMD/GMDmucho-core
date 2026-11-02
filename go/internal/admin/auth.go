package admin

import (
	"context"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha1"
	"crypto/sha256"
	"crypto/subtle"
	"database/sql"
	"encoding/base64"
	"encoding/binary"
	"encoding/json"
	"errors"
	"fmt"
	"regexp"
	"strings"
	"time"

	"github.com/IZKGMD/GMDmucho-core/go/internal/account"
	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
)

const TokenTTL = 28800
const dummyAdminHash = "$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi."

type Identity struct {
	ID, TokenID    int64
	Username, Role string
}
type Error struct {
	Code, Message string
	Status        int
}

func (e *Error) Error() string                       { return e.Code }
func failure(code, message string, status int) error { return &Error{code, message, status} }

type Service struct {
	DB   *sql.DB
	Now  func() time.Time
	Cost int // zero selects bcrypt cost 12; tests may lower this explicitly
}

func (s Service) passwordHash(password string) (string, error) {
	cost := s.Cost
	if cost == 0 {
		cost = 12
	}
	return account.Hash(password, cost)
}

var bearerPattern = regexp.MustCompile(`^Bearer\s+([A-Za-z0-9_-]{32,128})$`)
var nonOTP = regexp.MustCompile(`[^0-9]`)

func hash(value string) string { return fmt.Sprintf("%x", sha256.Sum256([]byte(value))) }
func (s Service) now() time.Time {
	if s.Now != nil {
		return s.Now()
	}
	return time.Now()
}
func TOTP(secret string, counter int64) string {
	var key []byte
	var buffer uint64
	bits := 0
	alphabet := "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567"
	for _, c := range strings.ToUpper(secret) {
		position := strings.IndexRune(alphabet, c)
		if position < 0 {
			continue
		}
		buffer = buffer<<5 | uint64(position)
		bits += 5
		if bits >= 8 {
			bits -= 8
			key = append(key, byte(buffer>>bits))
		}
	}
	if len(key) == 0 {
		return ""
	}
	var input [8]byte
	binary.BigEndian.PutUint64(input[:], uint64(counter))
	mac := hmac.New(sha1.New, key)
	mac.Write(input[:])
	digest := mac.Sum(nil)
	offset := int(digest[19] & 15)
	value := binary.BigEndian.Uint32(digest[offset:offset+4]) & 0x7fffffff
	return fmt.Sprintf("%06d", value%1000000)
}
func VerifyTOTP(secret, candidate string, now time.Time) bool {
	if len(candidate) != 6 || nonOTP.MatchString(candidate) {
		return false
	}
	counter := now.Unix() / 30
	for delta := int64(-1); delta <= 1; delta++ {
		expected := TOTP(secret, counter+delta)
		if expected != "" && subtle.ConstantTimeCompare([]byte(expected), []byte(candidate)) == 1 {
			return true
		}
	}
	return false
}

type Execer interface {
	ExecContext(context.Context, string, ...any) (sql.Result, error)
}

func Audit(ctx context.Context, q Execer, identity Identity, ip, action, targetType, targetID string, details any) error {
	var encoded any
	if details != nil {
		data, err := json.Marshal(details)
		if err != nil {
			return err
		}
		encoded = data
	}
	_, err := q.ExecContext(ctx, `INSERT INTO mucho_admin_client_audit(admin_user_id,admin_username,action,target_type,target_id,details,ip_hash) VALUES(?,?,?,?,?,?,?)`, identity.ID, identity.Username, action, targetType, targetID, encoded, hash(ip))
	return err
}
func (s Service) Authorize(ctx context.Context, authorization, permission string) (Identity, error) {
	var identity Identity
	match := bearerPattern.FindStringSubmatch(authorization)
	if len(match) != 2 {
		return identity, failure("unauthorized", "Bearer token required.", 401)
	}
	row, err := dbutil.One(ctx, s.DB, `SELECT a.id,a.username,a.role,t.id AS token_id FROM mucho_admin_client_tokens t JOIN admin_users a ON a.id=t.admin_user_id WHERE t.token_hash=? AND t.revoked_at IS NULL AND t.expires_at>NOW() AND a.is_active=1 AND a.password_must_set=0 LIMIT 1`, hash(match[1]))
	if errors.Is(err, sql.ErrNoRows) {
		return identity, failure("unauthorized", "Session expired or invalid.", 401)
	}
	if err != nil {
		return identity, err
	}
	identity = Identity{ID: row.Int("id"), TokenID: row.Int("token_id"), Username: row["username"], Role: row["role"]}
	allowed, err := Can(ctx, s.DB, identity, permission)
	if err != nil {
		return Identity{}, err
	}
	if !allowed {
		return Identity{}, failure("forbidden", "Insufficient administrator permissions.", 403)
	}
	if _, err = s.DB.ExecContext(ctx, `UPDATE mucho_admin_client_tokens SET last_used_at=NOW() WHERE id=?`, identity.TokenID); err != nil {
		return Identity{}, err
	}
	return identity, nil
}
func (s Service) Login(ctx context.Context, username, password, otp, clientVersion, ip string) (Identity, string, error) {
	return s.login(ctx, username, password, otp, clientVersion, ip, false)
}
func (s Service) LoginBrowser(ctx context.Context, username, password, otp, ip string) (Identity, string, error) {
	return s.login(ctx, username, password, otp, "go-browser", ip, true)
}
func (s Service) login(ctx context.Context, username, password, otp, clientVersion, ip string, allowRecovery bool) (Identity, string, error) {
	var identity Identity
	recoveryCandidate := recoveryCode(otp)
	var recoveryID int64
	username = strings.Trim(username, " \t\r\n\x00\x0b")
	if len(username) > 64 {
		username = username[:64]
	}
	if len(clientVersion) > 32 {
		clientVersion = clientVersion[:32]
	}
	otp = nonOTP.ReplaceAllString(otp, "")
	if username == "" || password == "" || len(password) > 1024 {
		return identity, "", failure("invalid_credentials", "Invalid username or password.", 401)
	}
	var failed int
	countSQL := `SELECT COUNT(*) FROM mucho_admin_client_login_attempts WHERE ip_hash=? AND success=0 AND created_at>=NOW()-INTERVAL 15 MINUTE`
	if err := s.DB.QueryRowContext(ctx, countSQL, hash(ip)).Scan(&failed); err != nil {
		return identity, "", err
	}
	if failed >= 8 {
		return identity, "", failure("rate_limited", "Too many login attempts. Try again later.", 429)
	}
	row, err := dbutil.One(ctx, s.DB, `SELECT id,username,password_hash,role,totp_secret,is_active,password_must_set FROM admin_users WHERE username=? LIMIT 1`, username)
	if err != nil && !errors.Is(err, sql.ErrNoRows) {
		return identity, "", err
	}
	stored := dummyAdminHash
	if row != nil {
		stored = row["password_hash"]
	}
	passwordOK := account.Verify(password, stored)
	denial := failure("invalid_credentials", "Invalid username or password.", 401)
	if passwordOK && row != nil && row.Int("is_active") == 1 && row.Int("password_must_set") == 0 {
		identity = Identity{ID: row.Int("id"), Username: row["username"], Role: row["role"]}
		allowed, err := Can(ctx, s.DB, identity, "dashboard.view")
		if err != nil {
			return Identity{}, "", err
		}
		if !allowed {
			denial = failure("invalid_credentials", "Invalid administrator account.", 401)
		} else {
			secret := strings.TrimSpace(row["totp_secret"])
			switch {
			case secret != "" && otp == "":
				denial = failure("totp_required", "Two-factor authentication code required.", 401)
			case secret != "" && !VerifyTOTP(secret, otp, s.now()):
				denial = failure("invalid_totp", "Invalid two-factor authentication code.", 401)
			default:
				denial = nil
			}
			if denial != nil && allowRecovery && secret != "" && recoveryCandidate != "" {
				codes, err := dbutil.Query(ctx, s.DB, `SELECT id,code_hash FROM admin_recovery_codes WHERE admin_user_id=? AND used_at IS NULL ORDER BY id LIMIT 20`, identity.ID)
				if err != nil {
					return Identity{}, "", err
				}
				for _, code := range codes {
					if account.Verify(recoveryCandidate, code["code_hash"]) {
						recoveryID = code.Int("id")
						denial = nil
						break
					}
				}
			}
		}
	}
	tx, err := s.DB.BeginTx(ctx, nil)
	if err != nil {
		return Identity{}, "", err
	}
	defer tx.Rollback()
	// An exclusive per-IP guard makes eight failed attempts a real bound
	// even when login requests arrive concurrently on different connections.
	if _, err = tx.ExecContext(ctx, `INSERT INTO mucho_security_rate_limits(bucket_key,window_start,request_count) VALUES(?,0,0) ON DUPLICATE KEY UPDATE bucket_key=VALUES(bucket_key)`, hash("admin-login|"+ip)); err != nil {
		return Identity{}, "", err
	}
	if err = tx.QueryRowContext(ctx, countSQL, hash(ip)).Scan(&failed); err != nil {
		return Identity{}, "", err
	}
	if failed >= 8 {
		return Identity{}, "", failure("rate_limited", "Too many login attempts. Try again later.", 429)
	}
	if denial == nil {
		latest, err := dbutil.One(ctx, tx, `SELECT password_hash,totp_secret,is_active,role,password_must_set FROM admin_users WHERE id=? FOR UPDATE`, identity.ID)
		if err != nil {
			return Identity{}, "", err
		}
		if latest.Int("is_active") != 1 || latest.Int("password_must_set") != 0 || latest["password_hash"] != row["password_hash"] || latest["totp_secret"] != row["totp_secret"] || latest["role"] != row["role"] {
			denial = failure("invalid_credentials", "Invalid username or password.", 401)
		} else {
			allowed, err := Can(ctx, tx, identity, "dashboard.view")
			if err != nil {
				return Identity{}, "", err
			}
			if !allowed {
				denial = failure("invalid_credentials", "Invalid administrator account.", 401)
			}
		}
	}
	if denial == nil && recoveryID > 0 {
		result, err := tx.ExecContext(ctx, `UPDATE admin_recovery_codes SET used_at=NOW() WHERE id=? AND admin_user_id=? AND used_at IS NULL`, recoveryID, identity.ID)
		if err != nil {
			return Identity{}, "", err
		}
		affected, err := result.RowsAffected()
		if err != nil {
			return Identity{}, "", err
		}
		if affected != 1 {
			denial = failure("invalid_totp", "This recovery code has already been used.", 401)
		}
	}
	success := 0
	if denial == nil {
		success = 1
	}
	if _, err = tx.ExecContext(ctx, `INSERT INTO mucho_admin_client_login_attempts(username,ip_hash,success) VALUES(?,?,?)`, username, hash(ip), success); err != nil {
		return Identity{}, "", err
	}
	if denial != nil {
		if err = tx.Commit(); err != nil {
			return Identity{}, "", err
		}
		return Identity{}, "", denial
	}
	if _, err = tx.ExecContext(ctx, `DELETE FROM mucho_admin_client_login_attempts WHERE ip_hash=? AND success=0`, hash(ip)); err != nil {
		return Identity{}, "", err
	}
	data := make([]byte, 32)
	if _, err = rand.Read(data); err != nil {
		return Identity{}, "", err
	}
	token := base64.RawURLEncoding.EncodeToString(data)
	result, err := tx.ExecContext(ctx, `INSERT INTO mucho_admin_client_tokens(admin_user_id,token_hash,client_version,created_ip_hash,expires_at) VALUES(?,?,?,?,DATE_ADD(NOW(),INTERVAL 28800 SECOND))`, identity.ID, hash(token), clientVersion, hash(ip))
	if err != nil {
		return Identity{}, "", err
	}
	identity.TokenID, err = result.LastInsertId()
	if err != nil {
		return Identity{}, "", err
	}
	action := "auth.login"
	if recoveryID > 0 {
		action = "auth.login.recovery_code"
	}
	if err = Audit(ctx, tx, identity, ip, action, "", "", nil); err != nil {
		return Identity{}, "", err
	}
	if err = tx.Commit(); err != nil {
		return Identity{}, "", err
	}
	_, _ = s.DB.ExecContext(ctx, `DELETE FROM mucho_admin_client_tokens WHERE expires_at<NOW()-INTERVAL 7 DAY LIMIT 1000`)
	return identity, token, nil
}
