package account

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"errors"
	"net/netip"
	"regexp"
	"strings"
)

var legacyName = regexp.MustCompile(`[^A-Za-z0-9 _.-]`)

func (s Service) UpdateLegacyUsername(ctx context.Context, udid, name string) error {
	udid = trim(udid)
	if udid == "" || name == "" || len(udid) > 256 {
		return ErrUnauthorized
	}
	for _, c := range udid {
		if c < 32 || c == 127 {
			return ErrUnauthorized
		}
	}
	hash := sha256.Sum256([]byte("MUCHO_GD10_UDID|" + udid))
	key := hex.EncodeToString(hash[:])
	var id int64
	err := s.DB.QueryRowContext(ctx, `SELECT account_id FROM legacy_device_identities WHERE udid_hash=? LIMIT 1`, key).Scan(&id)
	if errors.Is(err, sql.ErrNoRows) {
		_, err = s.LegacyIdentity(ctx, udid, name, "")
		return err
	}
	if err != nil {
		return err
	}
	return s.renameLegacy(ctx, id, key, name)
}

func (s Service) LegacyIdentity(ctx context.Context, udid, name, ip string) (int64, error) {
	udid = trim(udid)
	if udid == "" || len(udid) > 256 {
		return 0, ErrUnauthorized
	}
	for _, c := range udid {
		if c < 32 || c == 127 {
			return 0, ErrUnauthorized
		}
	}
	hash := sha256.Sum256([]byte("MUCHO_GD10_UDID|" + udid))
	key := hex.EncodeToString(hash[:])
	var packed any
	if addr, err := netip.ParseAddr(strings.TrimSpace(ip)); err == nil {
		packed = addr.AsSlice()
	}
	var id int64
	err := s.DB.QueryRowContext(ctx, `SELECT account_id FROM legacy_device_identities WHERE udid_hash=? LIMIT 1`, key).Scan(&id)
	if err != nil && !errors.Is(err, sql.ErrNoRows) {
		return 0, err
	}
	if err == nil {
		_, err = s.DB.ExecContext(ctx, `UPDATE legacy_device_identities SET last_ip=?,updated_at=CURRENT_TIMESTAMP WHERE udid_hash=?`, packed, key)
		if err != nil {
			return 0, err
		}
		if name != "" {
			if err = s.renameLegacy(ctx, id, key, name); err != nil {
				return 0, err
			}
		}
		return id, nil
	}
	name, err = s.legacyUsername(ctx, 0, key, name)
	if err != nil {
		return 0, err
	}
	secret := make([]byte, 24)
	if _, err = rand.Read(secret); err != nil {
		return 0, err
	}
	password, err := Hash(hex.EncodeToString(secret), s.cost())
	if err != nil {
		return 0, err
	}
	tx, err := s.DB.BeginTx(ctx, nil)
	if err != nil {
		return 0, err
	}
	defer tx.Rollback()
	r, err := tx.ExecContext(ctx, `INSERT INTO accounts(username,email,password_hash,role_id,is_active,is_banned) VALUES(?,?,?,1,1,0)`, name, "legacy-"+key+"@legacy.invalid", password)
	if err == nil {
		id, err = r.LastInsertId()
	}
	if err == nil {
		_, err = tx.ExecContext(ctx, `INSERT INTO profiles(account_id) VALUES(?)`, id)
	}
	if err == nil {
		_, err = tx.ExecContext(ctx, `INSERT INTO legacy_device_identities(udid_hash,account_id,last_ip) VALUES(?,?,?)`, key, id, packed)
	}
	if err != nil {
		_ = tx.Rollback()
		if e := s.DB.QueryRowContext(ctx, `SELECT account_id FROM legacy_device_identities WHERE udid_hash=? LIMIT 1`, key).Scan(&id); e == nil {
			return id, nil
		}
		return 0, err
	}
	return id, tx.Commit()
}
func (s Service) legacyUsername(ctx context.Context, id int64, key, name string) (string, error) {
	name = legacyName.ReplaceAllString(trim(name), "")
	if name == "" {
		name = "Legacy" + key[:14]
	}
	if len(name) > 20 {
		name = name[:20]
	}
	var owner int64
	err := s.DB.QueryRowContext(ctx, `SELECT account_id FROM accounts WHERE username=? LIMIT 1`, name).Scan(&owner)
	if err != nil && !errors.Is(err, sql.ErrNoRows) {
		return "", err
	}
	if err == nil && owner != id {
		suffix := "~" + key[:5]
		if len(name) > 20-len(suffix) {
			name = name[:20-len(suffix)]
		}
		name += suffix
	}
	return name, nil
}
func (s Service) renameLegacy(ctx context.Context, id int64, key, name string) error {
	var banned int
	err := s.DB.QueryRowContext(ctx, `SELECT COUNT(*) FROM mucho_gd2209_restrictions WHERE account_id=? AND kind='name' AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP())`, id).Scan(&banned)
	if err != nil {
		return err
	}
	if banned > 0 {
		return ErrUnauthorized
	}
	name, err = s.legacyUsername(ctx, id, key, name)
	if err != nil {
		return err
	}
	_, err = s.DB.ExecContext(ctx, `UPDATE accounts SET username=? WHERE account_id=? LIMIT 1`, name, id)
	return err
}
