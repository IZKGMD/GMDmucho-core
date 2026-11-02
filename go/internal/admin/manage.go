package admin

import (
	"context"
	"crypto/rand"
	"database/sql"
	"encoding/base64"
	"errors"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"unicode/utf8"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
)

var usernamePattern = regexp.MustCompile(`^[A-Za-z0-9_.-]{3,32}$`)
var rolePattern = regexp.MustCompile(`^[a-z][a-z0-9_-]{1,31}$`)
var setupPattern = regexp.MustCompile(`^[A-Za-z0-9_-]{64}$`)

func ValidatePassword(password string) error {
	if len(password) < 12 || len(password) > 72 || strings.ContainsRune(password, 0) || !utf8.ValidString(password) {
		return failure("invalid_password", "Use a password of 12–72 bytes without NUL characters.", 422)
	}
	return nil
}

// All native administrator/role management writes share a transaction guard.
// This also serializes first-owner bootstrap and permission delegation checks.
func (s Service) write(ctx context.Context) (*sql.Tx, error) {
	tx, err := s.DB.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	if _, err = tx.ExecContext(ctx, `INSERT INTO mucho_security_rate_limits(bucket_key,window_start,request_count) VALUES(?,0,0) ON DUPLICATE KEY UPDATE bucket_key=VALUES(bucket_key)`, hash("native-admin-management")); err != nil {
		tx.Rollback()
		return nil, err
	}
	return tx, nil
}

// CreateInitialOwner is an explicit CLI operation, never an HTTP bootstrap.
func (s Service) CreateInitialOwner(ctx context.Context, username, password string) (Identity, error) {
	username = strings.TrimSpace(username)
	if !usernamePattern.MatchString(username) {
		return Identity{}, failure("invalid_username", "Use 3–32 letters, digits, dots, underscores or hyphens for the username.", 422)
	}
	if err := ValidatePassword(password); err != nil {
		return Identity{}, err
	}
	encoded, err := s.passwordHash(password)
	if err != nil {
		return Identity{}, err
	}
	tx, err := s.write(ctx)
	if err != nil {
		return Identity{}, err
	}
	defer tx.Rollback()
	var existing int64
	err = tx.QueryRowContext(ctx, `SELECT id FROM admin_users ORDER BY id LIMIT 1 FOR UPDATE`).Scan(&existing)
	if err == nil {
		return Identity{}, failure("already_initialized", "Administrator accounts already exist; bootstrap refused.", 409)
	}
	if !errors.Is(err, sql.ErrNoRows) {
		return Identity{}, err
	}
	result, err := tx.ExecContext(ctx, `INSERT INTO admin_users(username,password_hash,role,is_active) VALUES(?,?,'owner',1)`, username, encoded)
	if err != nil {
		return Identity{}, err
	}
	id, err := result.LastInsertId()
	if err != nil {
		return Identity{}, err
	}
	identity := Identity{ID: id, Username: username, Role: "owner"}
	if err = Audit(ctx, tx, identity, "cli", "admin.bootstrap", "administrator", strconv.FormatInt(id, 10), nil); err != nil {
		return Identity{}, err
	}
	return identity, tx.Commit()
}

func currentActor(ctx context.Context, tx *sql.Tx, identity Identity, permission string) (Identity, error) {
	row, err := dbutil.One(ctx, tx, `SELECT id,username,role,is_active FROM admin_users WHERE id=? FOR UPDATE`, identity.ID)
	if errors.Is(err, sql.ErrNoRows) || err == nil && row.Int("is_active") != 1 {
		return Identity{}, failure("unauthorized", "Administrator account is unavailable.", 401)
	}
	if err != nil {
		return Identity{}, err
	}
	identity.Username, identity.Role = row["username"], row["role"]
	allowed, err := Can(ctx, tx, identity, permission)
	if err != nil {
		return Identity{}, err
	}
	if !allowed {
		return Identity{}, failure("forbidden", "Insufficient administrator permissions.", 403)
	}
	return identity, nil
}

func RolePermissions(ctx context.Context, q dbutil.Querier, code string) ([]string, error) {
	rows, err := dbutil.Query(ctx, q, `SELECT p.permission FROM admin_role_permissions p JOIN admin_roles r ON r.id=p.role_id WHERE r.code=? ORDER BY permission`, code)
	if err != nil {
		return nil, err
	}
	result := []string{}
	seen := map[string]bool{}
	for _, row := range rows {
		p := NormalizePermission(row["permission"])
		if permissionLabels[p] != "" && !seen[p] {
			result = append(result, p)
			seen[p] = true
		}
	}
	sort.Strings(result)
	return result, nil
}
func normalizePermissions(input []string) []string {
	seen := map[string]bool{}
	for _, p := range input {
		p = NormalizePermission(p)
		if permissionLabels[p] != "" {
			seen[p] = true
		}
	}
	result := make([]string, 0, len(seen))
	for p := range seen {
		result = append(result, p)
	}
	sort.Strings(result)
	return result
}
func canDelegate(ctx context.Context, q dbutil.Querier, identity Identity, permissions []string) error {
	for _, p := range permissions {
		ok, err := Can(ctx, q, identity, p)
		if err != nil {
			return err
		}
		if !ok {
			return failure("forbidden", "A role cannot grant permissions you do not have.", 403)
		}
	}
	return nil
}
func builtIn(code string) bool {
	return code == "owner" || code == "admin" || code == "moderator" || code == "viewer"
}
func canAssign(ctx context.Context, q dbutil.Querier, identity Identity, role string) error {
	if _, err := dbutil.One(ctx, q, `SELECT id FROM admin_roles WHERE code=?`, role); errors.Is(err, sql.ErrNoRows) {
		return failure("invalid_role", "Role not found.", 422)
	} else if err != nil {
		return err
	}
	if identity.Role == "owner" {
		return nil
	}
	if builtIn(role) && role != "viewer" {
		return failure("forbidden", "Only an owner can assign this role.", 403)
	}
	permissions, err := RolePermissions(ctx, q, role)
	if err != nil {
		return err
	}
	return canDelegate(ctx, q, identity, permissions)
}

func (s Service) SaveRole(ctx context.Context, identity Identity, ip string, id int64, code, name string, permissions []string) error {
	code, name = strings.ToLower(strings.TrimSpace(code)), strings.TrimSpace(name)
	if !utf8.ValidString(name) || utf8.RuneCountInString(name) < 1 || utf8.RuneCountInString(name) > 64 {
		return failure("invalid_name", "Role name must be 1–64 characters.", 422)
	}
	if id == 0 && (!rolePattern.MatchString(code) || builtIn(code)) {
		return failure("invalid_role", "Use a unique custom role code of 2–32 lowercase letters, digits, underscores or hyphens.", 422)
	}
	permissions = normalizePermissions(permissions)
	tx, err := s.write(ctx)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	identity, err = currentActor(ctx, tx, identity, "roles.manage")
	if err != nil {
		return err
	}
	if err = canDelegate(ctx, tx, identity, permissions); err != nil {
		return err
	}
	action := "role.create"
	if id != 0 {
		role, err := dbutil.One(ctx, tx, `SELECT code,is_system FROM admin_roles WHERE id=? FOR UPDATE`, id)
		if errors.Is(err, sql.ErrNoRows) {
			return failure("not_found", "Role not found.", 404)
		}
		if err != nil {
			return err
		}
		code = role["code"]
		if role.Int("is_system") == 1 || builtIn(code) {
			return failure("forbidden", "Built-in roles cannot be edited here.", 403)
		}
		old, err := RolePermissions(ctx, tx, code)
		if err != nil {
			return err
		}
		if err = canDelegate(ctx, tx, identity, old); err != nil {
			return err
		}
		// A role editor cannot expand their own effective permissions.
		if _, err = tx.ExecContext(ctx, `UPDATE admin_roles SET name=? WHERE id=?`, name, id); err != nil {
			return err
		}
		action = "role.update"
	} else {
		var count int
		if err = tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM admin_roles WHERE code=?`, code).Scan(&count); err != nil {
			return err
		}
		if count != 0 {
			return failure("role_exists", "A role with this code already exists.", 409)
		}
		result, err := tx.ExecContext(ctx, `INSERT INTO admin_roles(code,name,is_system) VALUES(?,?,0)`, code, name)
		if err != nil {
			return err
		}
		id, err = result.LastInsertId()
		if err != nil {
			return err
		}
	}
	if _, err = tx.ExecContext(ctx, `DELETE FROM admin_role_permissions WHERE role_id=?`, id); err != nil {
		return err
	}
	for _, p := range permissions {
		if _, err = tx.ExecContext(ctx, `INSERT INTO admin_role_permissions(role_id,permission) VALUES(?,?)`, id, p); err != nil {
			return err
		}
	}
	if err = Audit(ctx, tx, identity, ip, action, "role", code, map[string]any{"permissions": permissions}); err != nil {
		return err
	}
	return tx.Commit()
}
func (s Service) DeleteRole(ctx context.Context, identity Identity, ip string, id int64) error {
	tx, err := s.write(ctx)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	identity, err = currentActor(ctx, tx, identity, "roles.manage")
	if err != nil {
		return err
	}
	role, err := dbutil.One(ctx, tx, `SELECT code,is_system FROM admin_roles WHERE id=? FOR UPDATE`, id)
	if errors.Is(err, sql.ErrNoRows) {
		return failure("not_found", "Role not found.", 404)
	}
	if err != nil {
		return err
	}
	if role.Int("is_system") == 1 || builtIn(role["code"]) {
		return failure("forbidden", "Built-in roles cannot be deleted.", 403)
	}
	old, err := RolePermissions(ctx, tx, role["code"])
	if err != nil {
		return err
	}
	if err = canDelegate(ctx, tx, identity, old); err != nil {
		return err
	}
	var count int
	if err = tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM admin_users WHERE role=?`, role["code"]).Scan(&count); err != nil {
		return err
	}
	if count > 0 {
		return failure("role_in_use", "Reassign administrators before deleting this role.", 409)
	}
	if _, err = tx.ExecContext(ctx, `DELETE FROM admin_roles WHERE id=?`, id); err != nil {
		return err
	}
	if err = Audit(ctx, tx, identity, ip, "role.delete", "role", role["code"], nil); err != nil {
		return err
	}
	return tx.Commit()
}

// Invite stores only a token hash. The recipient chooses the password once.
func (s Service) Invite(ctx context.Context, identity Identity, ip, username, role string) (string, error) {
	username, role = strings.TrimSpace(username), strings.ToLower(strings.TrimSpace(role))
	if !usernamePattern.MatchString(username) {
		return "", failure("invalid_username", "Use a username of 3–32 letters, digits, dots, underscores or hyphens.", 422)
	}
	data := make([]byte, 48)
	if _, err := rand.Read(data); err != nil {
		return "", err
	}
	token := base64.RawURLEncoding.EncodeToString(data)
	encoded, err := s.passwordHash(token)
	if err != nil {
		return "", err
	}
	tx, err := s.write(ctx)
	if err != nil {
		return "", err
	}
	defer tx.Rollback()
	identity, err = currentActor(ctx, tx, identity, "admins.manage")
	if err != nil {
		return "", err
	}
	if err = canAssign(ctx, tx, identity, role); err != nil {
		return "", err
	}
	var count int
	if err = tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM admin_users WHERE username=?`, username).Scan(&count); err != nil {
		return "", err
	}
	if count > 0 {
		return "", failure("username_exists", "Administrator username already exists.", 409)
	}
	_, err = tx.ExecContext(ctx, `INSERT INTO admin_users(username,password_hash,role,password_must_set,password_setup_token_hash,password_setup_expires_at,is_active) VALUES(?,?,?,1,?,NOW()+INTERVAL 1 DAY,1)`, username, encoded, role, hash(token))
	if err != nil {
		return "", err
	}
	if err = Audit(ctx, tx, identity, ip, "admin.create", "administrator", username, map[string]string{"role": role}); err != nil {
		return "", err
	}
	return token, tx.Commit()
}

func (s Service) CompleteSetup(ctx context.Context, token, password string) error {
	if !setupPattern.MatchString(token) {
		return failure("invalid_setup", "This invitation is invalid or expired.", 422)
	}
	if err := ValidatePassword(password); err != nil {
		return err
	}
	var pending int64
	if err := s.DB.QueryRowContext(ctx, `SELECT id FROM admin_users WHERE password_setup_token_hash=? AND password_must_set=1 AND password_setup_expires_at>NOW() AND is_active=1`, hash(token)).Scan(&pending); errors.Is(err, sql.ErrNoRows) {
		return failure("invalid_setup", "This invitation is invalid or expired.", 422)
	} else if err != nil {
		return err
	}
	encoded, err := s.passwordHash(password)
	if err != nil {
		return err
	}
	tx, err := s.write(ctx)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	row, err := dbutil.One(ctx, tx, `SELECT id,username,role FROM admin_users WHERE password_setup_token_hash=? AND password_must_set=1 AND password_setup_expires_at>NOW() AND is_active=1 FOR UPDATE`, hash(token))
	if errors.Is(err, sql.ErrNoRows) {
		return failure("invalid_setup", "This invitation is invalid or expired.", 422)
	}
	if err != nil {
		return err
	}
	_, err = tx.ExecContext(ctx, `UPDATE admin_users SET password_hash=?,password_must_set=0,password_setup_token_hash=NULL,password_setup_expires_at=NULL WHERE id=?`, encoded, row.Int("id"))
	if err != nil {
		return err
	}
	identity := Identity{ID: row.Int("id"), Username: row["username"], Role: row["role"]}
	if err = Audit(ctx, tx, identity, "setup", "admin.password_setup", "administrator", row["id"], nil); err != nil {
		return err
	}
	return tx.Commit()
}

func (s Service) UpdateAdministrator(ctx context.Context, identity Identity, ip string, id int64, role string, active bool) error {
	role = strings.ToLower(strings.TrimSpace(role))
	tx, err := s.write(ctx)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	identity, err = currentActor(ctx, tx, identity, "admins.manage")
	if err != nil {
		return err
	}
	if id == identity.ID {
		return failure("self_update", "Use your security page to change your own credentials; self-demotion and self-disable are refused.", 422)
	}
	target, err := dbutil.One(ctx, tx, `SELECT id,role FROM admin_users WHERE id=? FOR UPDATE`, id)
	if errors.Is(err, sql.ErrNoRows) {
		return failure("not_found", "Administrator not found.", 404)
	}
	if err != nil {
		return err
	}
	if err = canAssign(ctx, tx, identity, target["role"]); err != nil {
		return err
	}
	if err = canAssign(ctx, tx, identity, role); err != nil {
		return err
	}
	if target["role"] == "owner" && (!active || role != "owner") {
		var count int
		if err = tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM admin_users WHERE role='owner' AND is_active=1 AND password_must_set=0`).Scan(&count); err != nil {
			return err
		}
		if count <= 1 {
			return failure("last_owner", "The last active owner cannot be demoted or disabled.", 409)
		}
	}
	if _, err = tx.ExecContext(ctx, `UPDATE admin_users SET role=?,is_active=? WHERE id=?`, role, active, id); err != nil {
		return err
	}
	if _, err = tx.ExecContext(ctx, `UPDATE mucho_admin_client_tokens SET revoked_at=NOW() WHERE admin_user_id=? AND revoked_at IS NULL`, id); err != nil {
		return err
	}
	if err = Audit(ctx, tx, identity, ip, "admin.update", "administrator", strconv.FormatInt(id, 10), map[string]any{"role": role, "active": active}); err != nil {
		return err
	}
	return tx.Commit()
}
