package core

import (
	"regexp"
	"strings"

	"github.com/IZKGMD/GMDmucho-core/go/internal/buildinfo"
)

var clientVersionPattern = regexp.MustCompile(`^[0-9]+\.[0-9]+\.[0-9]+$`)

func (h *Handler) clientBridge(r request) reply {
	if r.path == "/muchoclient/manifest" {
		if r.raw.Method != "GET" {
			return jsonReply(map[string]any{"error": "method_not_allowed"}, 405)
		}
		return jsonReply(map[string]any{"schema_version": 1, "core_version": buildinfo.Version(), "client": map[string]any{"id": "izkgmd.muchoclient", "min_version": "0.1.0", "protocol": 1, "required_for_extensions": true, "required_for_legacy_gameplay": false}, "features": []any{map[string]any{"id": "clans", "name": "Clans", "description": "Clan features for authenticated players", "entrypoint": "/api/clans/my", "origin": "core", "requires_client": true}}}, 200)
	}
	if r.raw.Method != "POST" {
		return jsonReply(map[string]any{"error": "method_not_allowed"}, 405)
	}
	version := r.str("client_version")
	if !clientVersionPattern.MatchString(version) {
		return jsonReply(map[string]any{"error": "invalid_client_version"}, 400)
	}
	parts := strings.Split(version, ".")
	compatible := r.int("protocol") == 1 && (strings.TrimLeft(parts[0], "0") != "" || strings.TrimLeft(parts[1], "0") != "")
	status := "upgrade_required"
	if compatible {
		status = "ready"
	}
	return jsonReply(map[string]any{"compatible": compatible, "status": status, "min_version": "0.1.0", "protocol": 1, "session_token": nil}, 200)
}
