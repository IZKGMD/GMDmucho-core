package admin

import (
	"context"
	"strings"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
)

var permissionLabels = map[string]string{
	"dashboard.view": "View dashboard", "players.view": "View players", "players.manage": "Edit players", "players.password_reset": "Reset player passwords", "players.delete": "Delete players", "levels.view": "View levels", "levels.manage": "Edit levels", "levels.rate": "Rate / feature levels", "contentpacks.manage": "Create and publish Gauntlets and Map Packs", "moderation.manage": "Moderate content and players", "comments.manage": "Moderate comments", "messages.manage": "Moderate messages", "social.manage": "Manage social relationships", "music.manage": "Manage music", "analytics.view": "View analytics", "monitoring.view": "View monitoring", "security.view": "View security center", "database.view": "View database browser", "tools.endpoint_test": "Use API tester", "client.manage": "Manage client and feature tools", "backups.view": "View backups", "backups.download": "Download database backups", "settings.manage": "Change server settings", "system.manage": "Run server operations", "admins.manage": "Manage administrators", "roles.manage": "Manage custom roles", "audit.view": "View audit log", "self.security": "Manage own authentication security", "plugins.view": "View custom plugin diagnostics",
}
var permissionAliases = map[string]string{"users.view": "players.view", "users.manage": "players.manage", "users.delete": "players.delete", "users.password_reset": "players.password_reset"}

func NormalizePermission(permission string) string {
	if normalized, ok := permissionAliases[permission]; ok {
		return normalized
	}
	return permission
}
func Rank(role string) int {
	switch strings.ToLower(role) {
	case "helper":
		return 10
	case "moderator":
		return 20
	case "admin":
		return 30
	case "owner":
		return 40
	}
	return 0
}
func Can(ctx context.Context, q dbutil.Querier, identity Identity, permission string) (bool, error) {
	permission = NormalizePermission(permission)
	if identity.ID <= 0 || permissionLabels[permission] == "" {
		return false, nil
	}
	role := strings.ToLower(strings.TrimSpace(identity.Role))
	if role == "owner" {
		return true, nil
	}
	args := []any{role, permission}
	marks := []string{"?"}
	for alias, target := range permissionAliases {
		if target == permission {
			args = append(args, alias)
			marks = append(marks, "?")
		}
	}
	rows, err := dbutil.Query(ctx, q, `SELECT 1 FROM admin_role_permissions p JOIN admin_roles r ON r.id=p.role_id WHERE r.code=? AND p.permission IN (`+strings.Join(marks, ",")+`) LIMIT 1`, args...)
	return len(rows) > 0, err
}

func Permissions() map[string]string {
	result := make(map[string]string, len(permissionLabels))
	for key, value := range permissionLabels {
		result[key] = value
	}
	return result
}
