package admin

import (
	"context"
	"crypto/rand"
	"database/sql"
	"encoding/base32"
	"encoding/hex"
	"errors"
	"regexp"
	"strconv"
	"strings"

	"github.com/IZKGMD/GMDmucho-core/go/internal/account"
	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
)

var recoveryNoise = regexp.MustCompile(`[^A-Z0-9]`)

func recoveryCode(candidate string) string {
	value := recoveryNoise.ReplaceAllString(strings.ToUpper(strings.TrimSpace(candidate)), "")
	if len(value) != 12 {
		return ""
	}
	return value[:4] + "-" + value[4:8] + "-" + value[8:]
}

func (s Service) GenerateRecoveryCodes(ctx context.Context, identity Identity, ip, password, otp string) ([]string, error) {
	row, err := dbutil.One(ctx, s.DB, `SELECT password_hash,totp_secret FROM admin_users WHERE id=?`, identity.ID)
	if err != nil {
		return nil, err
	}
	if !account.Verify(password, row["password_hash"]) || !VerifyTOTP(row["totp_secret"], otp, s.now()) {
		return nil, failure("invalid_credentials", "Enter your current password and authenticator code.", 422)
	}
	codes := make([]string, 10)
	hashes := make([]string, 10)
	for i := range codes {
		data := make([]byte, 6)
		if _, err = rand.Read(data); err != nil {
			return nil, err
		}
		codes[i] = recoveryCode(strings.ToUpper(hex.EncodeToString(data)))
		hashes[i], err = s.passwordHash(codes[i])
		if err != nil {
			return nil, err
		}
	}
	tx, err := s.write(ctx)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()
	identity, err = currentActor(ctx, tx, identity, "self.security")
	if err != nil {
		return nil, err
	}
	latest, err := dbutil.One(ctx, tx, `SELECT password_hash,totp_secret FROM admin_users WHERE id=?`, identity.ID)
	if err != nil {
		return nil, err
	}
	if latest["password_hash"] != row["password_hash"] || latest["totp_secret"] != row["totp_secret"] {
		return nil, failure("invalid_credentials", "Account security changed. Try again.", 409)
	}
	if _, err = tx.ExecContext(ctx, `DELETE FROM admin_recovery_codes WHERE admin_user_id=? AND used_at IS NULL`, identity.ID); err != nil {
		return nil, err
	}
	for _, encoded := range hashes {
		if _, err = tx.ExecContext(ctx, `INSERT INTO admin_recovery_codes(admin_user_id,code_hash) VALUES(?,?)`, identity.ID, encoded); err != nil {
			return nil, err
		}
	}
	if err = Audit(ctx, tx, identity, ip, "security.recovery_codes", "administrator", strconv.FormatInt(identity.ID, 10), map[string]int{"count": len(codes)}); err != nil {
		return nil, err
	}
	return codes, tx.Commit()
}

func NewTOTPSecret() (string, error) {
	data := make([]byte, 20)
	if _, err := rand.Read(data); err != nil {
		return "", err
	}
	return base32.StdEncoding.WithPadding(base32.NoPadding).EncodeToString(data), nil
}

// Browser sessions use the same hashed token store, with a 30-minute idle
// deadline in addition to the eight-hour absolute token expiration.
func (s Service) AuthorizeBrowser(ctx context.Context, token string) (Identity, error) {
	match := bearerPattern.FindStringSubmatch("Bearer " + token)
	if len(match) != 2 {
		return Identity{}, failure("unauthorized", "Please sign in.", 401)
	}
	var id int64
	err := s.DB.QueryRowContext(ctx, `SELECT id FROM mucho_admin_client_tokens WHERE token_hash=? AND client_version='go-browser' AND COALESCE(last_used_at,created_at)>NOW()-INTERVAL 30 MINUTE`, hash(token)).Scan(&id)
	if errors.Is(err, sql.ErrNoRows) {
		return Identity{}, failure("unauthorized", "Your browser session expired. Please sign in.", 401)
	}
	if err != nil {
		return Identity{}, err
	}
	return s.Authorize(ctx, "Bearer "+token, "dashboard.view")
}

func (s Service) ChangeSecurity(ctx context.Context, identity Identity, ip, password, otp, operation, value string) error {
	if operation == "password" {
		if err := ValidatePassword(value); err != nil {
			return err
		}
	}
	if operation != "password" && operation != "totp.enable" && operation != "totp.disable" && operation != "sessions.revoke" {
		return failure("invalid_action", "Unknown security operation.", 422)
	}
	if operation == "totp.enable" && !VerifyTOTP(value, otp, s.now()) {
		return failure("invalid_totp", "Invalid or expired authenticator code.", 422)
	}
	var encoded string
	var err error
	if operation == "password" {
		encoded, err = s.passwordHash(value)
		if err != nil {
			return err
		}
	}
	tx, err := s.write(ctx)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	identity, err = currentActor(ctx, tx, identity, "self.security")
	if err != nil {
		return err
	}
	row, err := dbutil.One(ctx, tx, `SELECT password_hash,totp_secret FROM admin_users WHERE id=?`, identity.ID)
	if err != nil {
		return err
	}
	if !account.Verify(password, row["password_hash"]) {
		return failure("invalid_credentials", "Current password is incorrect.", 422)
	}
	secret := strings.TrimSpace(row["totp_secret"])
	if operation == "totp.enable" {
		if secret != "" {
			return failure("totp_enabled", "Disable the current authenticator before starting a new setup.", 409)
		}
		if _, err = tx.ExecContext(ctx, `UPDATE admin_users SET totp_secret=? WHERE id=?`, value, identity.ID); err != nil {
			return err
		}
	} else {
		if secret != "" && !VerifyTOTP(secret, otp, s.now()) {
			return failure("invalid_totp", "Enter your current six-digit authenticator code.", 422)
		}
		switch operation {
		case "password":
			_, err = tx.ExecContext(ctx, `UPDATE admin_users SET password_hash=? WHERE id=?`, encoded, identity.ID)
		case "totp.disable":
			_, err = tx.ExecContext(ctx, `UPDATE admin_users SET totp_secret=NULL WHERE id=?`, identity.ID)
			if err == nil {
				_, err = tx.ExecContext(ctx, `DELETE FROM admin_recovery_codes WHERE admin_user_id=?`, identity.ID)
			}
		}
		if err != nil {
			return err
		}
	}
	if _, err = tx.ExecContext(ctx, `UPDATE mucho_admin_client_tokens SET revoked_at=NOW() WHERE admin_user_id=? AND id<>? AND revoked_at IS NULL`, identity.ID, identity.TokenID); err != nil {
		return err
	}
	if err = Audit(ctx, tx, identity, ip, "security."+operation, "administrator", strconv.FormatInt(identity.ID, 10), nil); err != nil {
		return err
	}
	return tx.Commit()
}
