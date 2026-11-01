package account

import (
	"encoding/base64"
	"encoding/json"
	"os"
	"strings"
	"testing"

	"github.com/IZKGMD/GMDmucho-core/go/internal/protocol"
)

func TestPHPPasswordHashes(t *testing.T) {
	data, err := os.ReadFile("../../testdata/passwords.json")
	if err != nil {
		t.Fatal(err)
	}
	var cases []struct {
		Password, Hash string
		Valid          bool
	}
	if err = json.Unmarshal(data, &cases); err != nil {
		t.Fatal(err)
	}
	for _, tc := range cases {
		if Verify(tc.Password, tc.Hash) != tc.Valid {
			t.Fatalf("PHP hash parity failed for %s", strings.Split(tc.Hash, "$")[1])
		}
	}
}
func TestLegacyCredential(t *testing.T) {
	password := "test-password"
	for _, encode := range []*base64.Encoding{base64.StdEncoding, base64.RawURLEncoding} {
		value := encode.EncodeToString([]byte(protocol.XOR(password, "37526")))
		if got, ok := DecodeGJP(value); !ok || got != password {
			t.Fatal("GJP did not decode")
		}
	}
	for _, bad := range []string{"", "%%%%", base64.StdEncoding.EncodeToString([]byte(protocol.XOR("bad\x00data", "37526")))} {
		if _, ok := DecodeGJP(bad); ok {
			t.Fatal("invalid GJP accepted")
		}
	}
}
func TestPHPLongPasswordBoundary(t *testing.T) {
	prefix := strings.Repeat("x", 72)
	hash, err := Hash(prefix+"tail", 4)
	if err != nil {
		t.Fatal(err)
	}
	if !Verify(prefix+"other-tail", hash) {
		t.Fatal("bcrypt boundary differs from PHP")
	}
	if GJP2(prefix+"tail") == GJP2(prefix+"other-tail") {
		t.Fatal("GJP2 truncated password")
	}
	if _, err := Hash("nul\x00password", 4); err == nil {
		t.Fatal("PHP-incompatible NUL password")
	}
}
