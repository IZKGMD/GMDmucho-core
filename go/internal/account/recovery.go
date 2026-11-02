package account

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"crypto/subtle"
	"database/sql"
	"encoding/hex"
	"errors"
	"fmt"
	"net/mail"
	"regexp"
	"strings"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
)

var recoveryTokenPattern = regexp.MustCompile(`(?i)^[a-f0-9]{64}$`)

func tokenDigest(token string) string {
	return fmt.Sprintf("%x", sha256.Sum256([]byte(strings.ToLower(trim(token)))))
}

// IssueRecovery returns delivery data only to the trusted mail adapter. HTTP
// responses never reveal whether an account exists or expose the reset token.
func (s Service) IssueRecovery(ctx context.Context, identity, ip string, allow PasswordLimiter) (recipient, username, token string, accountID int64, err error) {
	identity = trim(identity)
	if identity == "" || len(identity) > 254 {
		return
	}
	for _, rule := range []struct {
		key   string
		limit int
	}{{"recovery-ip|" + ip, 5}, {fmt.Sprintf("recovery-identity|%x", sha256.Sum256([]byte(strings.ToLower(identity)))), 3}} {
		var ok bool
		ok, err = allow(ctx, rule.key, rule.limit, 900)
		if err != nil || !ok {
			return
		}
	}
	row, e := dbutil.One(ctx, s.DB, `SELECT account_id,username,email,is_active,is_banned FROM accounts WHERE username=? OR email=? LIMIT 1`, identity, identity)
	if errors.Is(e, sql.ErrNoRows) {
		return
	}
	if e != nil {
		err = e
		return
	}
	if row.Int("is_active") != 1 || row.Int("is_banned") == 1 {
		return
	}
	address, e := mail.ParseAddress(trim(row["email"]))
	if e != nil || address.Address != trim(row["email"]) {
		return
	}
	random := make([]byte, 32)
	if _, err = rand.Read(random); err != nil {
		return
	}
	token = hex.EncodeToString(random)
	tx, e := s.DB.BeginTx(ctx, nil)
	if e != nil {
		err = e
		return
	}
	defer tx.Rollback()
	var active int
	if err = tx.QueryRowContext(ctx, `SELECT is_active FROM accounts WHERE account_id=? AND is_active=1 AND is_banned=0 FOR UPDATE`, row.Int("account_id")).Scan(&active); err != nil {
		return
	}
	if _, err = tx.ExecContext(ctx, `DELETE FROM account_recovery_tokens WHERE account_id=?`, row.Int("account_id")); err != nil {
		return
	}

	if _, err = tx.ExecContext(ctx, `INSERT INTO account_recovery_tokens(account_id,token_hash,expires_at,created_ip_hash) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE),?)`, row.Int("account_id"), tokenDigest(token), fmt.Sprintf("%x", sha256.Sum256([]byte(ip)))); err != nil {
		return
	}
	if err = tx.Commit(); err != nil {
		return
	}
	_, _ = s.DB.ExecContext(ctx, `DELETE FROM account_recovery_tokens WHERE expires_at<=NOW() OR used_at IS NOT NULL LIMIT 1000`)
	recipient, username, accountID = address.Address, row["username"], row.Int("account_id")
	return
}
func (s Service) RecoveryTokenValid(ctx context.Context, token string) (bool, error) {
	if !recoveryTokenPattern.MatchString(trim(token)) {
		return false, nil
	}
	var count int
	err := s.DB.QueryRowContext(ctx, `SELECT COUNT(*) FROM account_recovery_tokens WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW()`, tokenDigest(token)).Scan(&count)
	return count > 0, err
}
func (s Service) ResetPassword(ctx context.Context, token, password, confirmation, ip string, allow PasswordLimiter) (bool, error) {
	if !recoveryTokenPattern.MatchString(trim(token)) || len(password) < 8 || len(password) > 72 || strings.ContainsRune(password, 0) || subtle.ConstantTimeCompare([]byte(password), []byte(confirmation)) != 1 {
		return false, nil
	}
	ok, err := allow(ctx, "recovery-reset-ip|"+ip, 10, 900)
	if err != nil || !ok {
		return false, err
	}
	passwordHash, err := Hash(password, s.cost())
	if err != nil {
		return false, err
	}
	gjp2Hash, err := Hash(GJP2(password), s.cost())
	if err != nil {
		return false, err
	}
	hash := tokenDigest(token)
	var id int64
	err = s.DB.QueryRowContext(ctx, `SELECT account_id FROM account_recovery_tokens WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW() LIMIT 1`, hash).Scan(&id)
	if errors.Is(err, sql.ErrNoRows) {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	tx, err := s.DB.BeginTx(ctx, nil)
	if err != nil {
		return false, err
	}
	defer tx.Rollback()
	var actor int64
	if err = tx.QueryRowContext(ctx, `SELECT account_id FROM accounts WHERE account_id=? AND is_active=1 AND is_banned=0 FOR UPDATE`, id).Scan(&actor); errors.Is(err, sql.ErrNoRows) {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	if err = tx.QueryRowContext(ctx, `SELECT account_id FROM account_recovery_tokens WHERE token_hash=? AND account_id=? AND used_at IS NULL AND expires_at>NOW() LIMIT 1 FOR UPDATE`, hash, id).Scan(&actor); errors.Is(err, sql.ErrNoRows) {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	if _, err = tx.ExecContext(ctx, `UPDATE accounts SET password_hash=?,gjp2_hash=? WHERE account_id=?`, passwordHash, gjp2Hash, id); err != nil {
		return false, err
	}
	if _, err = tx.ExecContext(ctx, `DELETE FROM mucho_legacy_19_sessions WHERE account_id=?`, id); err != nil {
		return false, err
	}
	result, err := tx.ExecContext(ctx, `UPDATE account_recovery_tokens SET used_at=NOW() WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW()`, hash)
	if err != nil {
		return false, err
	}
	n, err := result.RowsAffected()
	if err != nil || n != 1 {
		return false, err
	}
	if _, err = tx.ExecContext(ctx, `INSERT INTO audit_logs(account_id,action,ip_address,metadata) VALUES(?,'account.recovery.completed',?,'[]')`, id, ip); err != nil {
		return false, err
	}
	return true, tx.Commit()
}
