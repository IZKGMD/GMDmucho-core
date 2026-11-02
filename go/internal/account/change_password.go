package account

import (
	"context"
	"crypto/sha256"
	"crypto/subtle"
	"database/sql"
	"errors"
	"fmt"
	"strings"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
)

type PasswordLimiter func(context.Context, string, int, int) (bool, error)

func (s Service) ChangePassword(ctx context.Context, username, current, password, confirmation, ip string, allow PasswordLimiter) (bool, error) {
	username = trim(username)
	if !usernamePattern.MatchString(username) || current == "" || len(current) > 256 || strings.ContainsRune(current, 0) || len(password) < 8 || len(password) > 72 || strings.ContainsRune(password, 0) || subtle.ConstantTimeCompare([]byte(password), []byte(confirmation)) != 1 {
		return false, nil
	}
	for _, rule := range []struct {
		key   string
		limit int
	}{{"account-manage-ip|" + ip, 10}, {fmt.Sprintf("account-manage-user|%x", sha256.Sum256([]byte(strings.ToLower(username)))), 5}} {
		ok, err := allow(ctx, rule.key, rule.limit, 900)
		if err != nil || !ok {
			return false, err
		}
	}
	row, err := dbutil.One(ctx, s.DB, `SELECT account_id,password_hash,gjp2_hash,is_active,is_banned FROM accounts WHERE username=? LIMIT 1`, username)
	if errors.Is(err, sql.ErrNoRows) {
		Verify(current, dummyHash)
		return false, nil
	}
	if err != nil {
		return false, err
	}
	valid := Verify(current, row["password_hash"])
	if !valid && row["gjp2_hash"] != "" {
		valid = Verify(GJP2(current), row["gjp2_hash"]) || Verify(digest(current, LegacySalt), row["gjp2_hash"])
	}
	if !valid || row.Int("is_active") != 1 || row.Int("is_banned") != 0 {
		return false, nil
	}
	passwordHash, err := Hash(password, s.cost())
	if err != nil {
		return false, err
	}
	gjp2Hash, err := Hash(GJP2(password), s.cost())
	if err != nil {
		return false, err
	}
	tx, err := s.DB.BeginTx(ctx, nil)
	if err != nil {
		return false, err
	}
	defer tx.Rollback()
	id := row.Int("account_id")
	latest, err := dbutil.One(ctx, tx, `SELECT password_hash,gjp2_hash,is_active,is_banned FROM accounts WHERE account_id=? FOR UPDATE`, id)
	if err != nil {
		return false, err
	}
	if latest.Int("is_active") != 1 || latest.Int("is_banned") != 0 || subtle.ConstantTimeCompare([]byte(row["password_hash"]), []byte(latest["password_hash"])) != 1 || subtle.ConstantTimeCompare([]byte(row["gjp2_hash"]), []byte(latest["gjp2_hash"])) != 1 {
		return false, nil
	}
	if _, err = tx.ExecContext(ctx, `UPDATE accounts SET password_hash=?,gjp2_hash=? WHERE account_id=?`, passwordHash, gjp2Hash, id); err != nil {
		return false, err
	}
	for _, query := range []string{`DELETE FROM mucho_legacy_19_sessions WHERE account_id=?`, `DELETE FROM account_recovery_tokens WHERE account_id=?`} {
		if _, err = tx.ExecContext(ctx, query, id); err != nil {
			return false, err
		}
	}
	if _, err = tx.ExecContext(ctx, `INSERT INTO audit_logs(account_id,action,ip_address,metadata) VALUES(?,'account.password.changed',?,'[]')`, id, ip); err != nil {
		return false, err
	}
	return true, tx.Commit()
}
