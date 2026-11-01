package core

import (
	"compress/zlib"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"io"
	"strings"
	"testing"
)

func utilityReference(t *testing.T, h *Handler, path, got, want string) (bool, bool) {
	t.Helper()
	switch path {
	case "/openedRegister.php":
		bytes, err := hex.DecodeString(got)
		if err != nil || len(bytes) != 16 {
			t.Fatalf("invalid registration token %q", got)
		}
		hash := sha256.Sum256([]byte(got))
		var count int
		if err = h.db.QueryRow(`SELECT COUNT(*) FROM mucho_gd2209_registration_opens WHERE token_hash=? AND OCTET_LENGTH(actor_hash)=32 AND expires_at>UTC_TIMESTAMP()`, hash[:]).Scan(&count); err != nil || count != 1 {
			t.Fatalf("registration token not persisted: %d %v", count, err)
		}
		return true, true
	case "/getSaveData.php":
		decode := func(body string) string {
			if len(body) <= 40 {
				t.Fatalf("short rating refresh: %q", body)
			}
			for _, part := range []string{body[:20], body[len(body)-20:]} {
				if data, err := hex.DecodeString(part); err != nil || len(data) != 10 {
					t.Fatal("invalid rating refresh envelope")
				}
			}
			compressed, err := base64.URLEncoding.DecodeString(body[20 : len(body)-20])
			if err != nil {
				t.Fatal(err)
			}
			reader, err := zlib.NewReader(strings.NewReader(string(compressed)))
			if err != nil {
				t.Fatal(err)
			}
			defer reader.Close()
			data, err := io.ReadAll(io.LimitReader(reader, 4*1024*1024+1))
			if err != nil || len(data) > 4*1024*1024 {
				t.Fatalf("invalid compressed rating refresh: %v", err)
			}
			return string(data)
		}
		return decode(got) == decode(want), true
	}
	return false, false
}
