// Package cloudsave reads and writes the PHP core's AES-256-GCM save format.
package cloudsave

import (
	"context"
	"crypto/aes"
	"crypto/cipher"
	"crypto/rand"
	"crypto/sha256"
	"crypto/subtle"
	"database/sql"
	"encoding/base64"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
)

const MaxSaveBytes = 32 * 1024 * 1024

var ErrIntegrity = errors.New("cloud save integrity failure")

type Store struct {
	DB      *sql.DB
	KeyFile string
}

func (s Store) aead() (cipher.AEAD, error) {
	path := s.KeyFile
	if path == "" {
		path = "/var/lib/muchocore/cloudsave.key"
	}
	if !filepath.IsAbs(path) {
		return nil, errors.New("cloud save key path must be absolute")
	}
	for _, c := range path {
		if c < 32 || c == 127 {
			return nil, errors.New("invalid cloud save key path")
		}
	}
	// Never create/rotate a key implicitly: that would orphan existing saves.
	raw, err := os.ReadFile(path)
	if err != nil {
		return nil, errors.New("cloud save key unavailable")
	}
	key, err := base64.StdEncoding.DecodeString(strings.TrimSpace(string(raw)))
	if err != nil || len(key) != 32 {
		return nil, errors.New("invalid cloud save key")
	}
	block, err := aes.NewCipher(key)
	if err != nil {
		return nil, err
	}
	return cipher.NewGCM(block)
}

func (s Store) Load(ctx context.Context, id int64) (string, error) {
	r, err := dbutil.One(ctx, s.DB, `SELECT ciphertext,nonce,auth_tag,sha256,size_bytes FROM mucho_cloud_saves WHERE account_id=? LIMIT 1`, id)
	if err != nil {
		return "", err
	}
	aead, err := s.aead()
	if err != nil {
		return "", err
	}
	if len(r["nonce"]) != aead.NonceSize() || len(r["auth_tag"]) != aead.Overhead() || r.Int("size_bytes") > MaxSaveBytes {
		return "", ErrIntegrity
	}
	data := append([]byte(r["ciphertext"]), []byte(r["auth_tag"])...)
	plain, err := aead.Open(nil, []byte(r["nonce"]), data, nil)
	if err != nil {
		return "", ErrIntegrity
	}
	hash := fmt.Sprintf("%x", sha256.Sum256(plain))
	if int64(len(plain)) != r.Int("size_bytes") || subtle.ConstantTimeCompare([]byte(hash), []byte(r["sha256"])) != 1 {
		return "", ErrIntegrity
	}
	return string(plain), nil
}

func (s Store) Save(ctx context.Context, id int64, plain string) (int64, error) {
	if len(plain) == 0 || len(plain) > MaxSaveBytes {
		return 0, errors.New("invalid cloud save size")
	}
	aead, err := s.aead()
	if err != nil {
		return 0, err
	}
	nonce := make([]byte, aead.NonceSize())
	if _, err = rand.Read(nonce); err != nil {
		return 0, err
	}
	sealed := aead.Seal(nil, nonce, []byte(plain), nil)
	ciphertext, tag := sealed[:len(sealed)-aead.Overhead()], sealed[len(sealed)-aead.Overhead():]
	hash := fmt.Sprintf("%x", sha256.Sum256([]byte(plain)))
	tx, err := s.DB.BeginTx(ctx, nil)
	if err != nil {
		return 0, err
	}
	defer tx.Rollback()
	// Lock an existing parent even on the first backup, serializing simultaneous
	// first writes without a missing-row gap-lock race or a lost history entry.
	var accountID int64
	if err = tx.QueryRowContext(ctx, `SELECT account_id FROM accounts WHERE account_id=? FOR UPDATE`, id).Scan(&accountID); err != nil {
		return 0, err
	}
	old, err := dbutil.One(ctx, tx, `SELECT * FROM mucho_cloud_saves WHERE account_id=? FOR UPDATE`, id)
	if err != nil && !errors.Is(err, sql.ErrNoRows) {
		return 0, err
	}
	revision := int64(1)
	if old != nil {
		revision = old.Int("revision")
		if old["sha256"] == hash {
			_, err = tx.ExecContext(ctx, `UPDATE mucho_cloud_saves SET backed_up_at=CURRENT_TIMESTAMP WHERE account_id=?`, id)
			if err != nil {
				return 0, err
			}
			return revision, tx.Commit()
		}
		_, err = tx.ExecContext(ctx, `INSERT IGNORE INTO mucho_cloud_save_revisions(account_id,revision,ciphertext,nonce,auth_tag,sha256,size_bytes,original_backed_up_at) VALUES(?,?,?,?,?,?,?,?)`, id, revision, []byte(old["ciphertext"]), []byte(old["nonce"]), []byte(old["auth_tag"]), old["sha256"], old.Int("size_bytes"), old["backed_up_at"])
		if err != nil {
			return 0, err
		}
		revision++
	}
	_, err = tx.ExecContext(ctx, `INSERT INTO mucho_cloud_saves(account_id,ciphertext,nonce,auth_tag,sha256,size_bytes,revision,backed_up_at)
		VALUES(?,?,?,?,?,?,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE ciphertext=VALUES(ciphertext),nonce=VALUES(nonce),auth_tag=VALUES(auth_tag),sha256=VALUES(sha256),size_bytes=VALUES(size_bytes),revision=VALUES(revision),backed_up_at=CURRENT_TIMESTAMP`, id, ciphertext, nonce, tag, hash, len(plain), revision)
	if err != nil {
		return 0, err
	}
	_, err = tx.ExecContext(ctx, `DELETE FROM mucho_cloud_save_revisions WHERE account_id=? AND history_id NOT IN
		(SELECT history_id FROM (SELECT history_id FROM mucho_cloud_save_revisions WHERE account_id=? ORDER BY revision DESC LIMIT 5) AS retained)`, id, id)
	if err != nil {
		return 0, err
	}
	return revision, tx.Commit()
}
