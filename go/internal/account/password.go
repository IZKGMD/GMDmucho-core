package account

import (
	"crypto/sha1"
	"crypto/subtle"
	"encoding/base64"
	"fmt"
	"strings"

	"github.com/IZKGMD/GMDmucho-core/go/internal/protocol"
	"golang.org/x/crypto/argon2"
	"golang.org/x/crypto/bcrypt"
)

const Salt = "mI29fmAnxgTs"
const LegacySalt = "mPtFakeGJKeyPair"
const dummyHash = "$2y$12$1/MaE58zhdgQZQpBkgNBhuqdaaih/yUIuXVGBWgthDWALLCBtilDC"

func GJP2(password string) string         { return digest(password, Salt) }
func digest(password, salt string) string { return fmt.Sprintf("%x", sha1.Sum([]byte(password+salt))) }

// Hash preserves PHP bcrypt's 72-byte password boundary. GJP2 is derived from
// the complete password before hashing and consequently has no such boundary.
func Hash(password string, cost int) (string, error) {
	if strings.ContainsRune(password, 0) {
		return "", ErrUnauthorized
	}
	if len(password) > 72 {
		password = password[:72]
	}
	h, err := bcrypt.GenerateFromPassword([]byte(password), cost)
	return string(h), err
}

func Verify(password, stored string) bool {
	if strings.HasPrefix(stored, "$2") {
		if len(password) > 72 {
			password = password[:72]
		}
		return bcrypt.CompareHashAndPassword([]byte(stored), []byte(password)) == nil
	}
	// Imported PHP deployments can contain Argon2i/Argon2id password_hash output.
	parts := strings.Split(stored, "$")
	if len(parts) != 6 || (parts[1] != "argon2id" && parts[1] != "argon2i") || parts[2] != "v=19" {
		return false
	}
	var memory, iterations uint32
	var parallelism uint8
	if _, err := fmt.Sscanf(parts[3], "m=%d,t=%d,p=%d", &memory, &iterations, &parallelism); err != nil {
		return false
	}
	if memory < 8 || memory > 256*1024 || iterations < 1 || iterations > 10 || parallelism < 1 || parallelism > 16 {
		return false
	}
	salt, err := base64.RawStdEncoding.DecodeString(parts[4])
	if err != nil {
		return false
	}
	expected, err := base64.RawStdEncoding.DecodeString(parts[5])
	if err != nil || len(expected) < 16 || len(expected) > 128 {
		return false
	}
	var actual []byte
	if parts[1] == "argon2id" {
		actual = argon2.IDKey([]byte(password), salt, iterations, memory, parallelism, uint32(len(expected)))
	} else {
		actual = argon2.Key([]byte(password), salt, iterations, memory, parallelism, uint32(len(expected)))
	}
	return subtle.ConstantTimeCompare(actual, expected) == 1
}

func DecodeGJP(credential string) (string, bool) {
	value := strings.NewReplacer("-", "+", "_", "/").Replace(credential)
	value += strings.Repeat("=", (4-len(value)%4)%4)
	b, err := base64.StdEncoding.DecodeString(value)
	if err != nil || len(b) == 0 {
		return "", false
	}
	plain := protocol.XOR(string(b), "37526")
	if len(plain) > 256 {
		return "", false
	}
	for i := range plain {
		c := plain[i]
		if c <= 8 || c == 11 || c == 12 || (c >= 14 && c <= 31) || c == 127 {
			return "", false
		}
	}
	return plain, true
}
