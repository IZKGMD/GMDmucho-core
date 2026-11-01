package core

import (
	"context"
	"database/sql"
	"encoding/base64"
	"encoding/json"
	"net/http/httptest"
	"os"
	"reflect"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/IZKGMD/GMDmucho-core/go/internal/account"
	"github.com/IZKGMD/GMDmucho-core/go/internal/protocol"
)

func TestCorePHPParity(t *testing.T) {
	dsn := os.Getenv("MUCHO_GO_CORE_DSN")
	if dsn == "" {
		t.Skip("set MUCHO_GO_CORE_DSN and generate core-reference.php fixtures")
	}
	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatal(err)
	}
	defer db.Close()
	data, err := os.ReadFile(os.Getenv("MUCHO_GO_CORE_FIXTURES"))
	if err != nil {
		t.Fatal(err)
	}
	var cases []struct {
		Path, Form, Body string
		Status           int
		ContentType      string `json:"content_type"`
	}
	if err = json.Unmarshal(data, &cases); err != nil {
		t.Fatal(err)
	}
	if len(cases) < 40 {
		t.Fatal("incomplete reference scenario")
	}
	h := New(db, Options{CloudKeyFile: os.Getenv("MUCHO_GO_TEST_KEY_FILE"), DisableProtect: true, Now: func() time.Time { return time.Unix(1700000000, 0) }})
	h.accounts.Cost = 4
	for _, tc := range cases {
		t.Run(tc.Path+"/"+tc.Form, func(t *testing.T) {
			r := httptest.NewRequest("POST", tc.Path, strings.NewReader(tc.Form))
			r.Header.Set("Content-Type", "application/x-www-form-urlencoded")
			r.RemoteAddr = "192.0.2.1:1234"
			w := httptest.NewRecorder()
			h.ServeHTTP(w, r)
			equal := w.Body.String() == tc.Body
			if tc.Body != "-1" {
				if same, handled := utilityReference(t, h, tc.Path, w.Body.String(), tc.Body); handled {
					equal = same
				}
			}
			if strings.HasPrefix(tc.ContentType, "application/json") {
				var actual, expected any
				if err := json.Unmarshal(w.Body.Bytes(), &actual); err != nil {
					t.Fatal(err)
				}
				if err := json.Unmarshal([]byte(tc.Body), &expected); err != nil {
					t.Fatal(err)
				}
				equal = reflect.DeepEqual(actual, expected)
			}
			if tc.ContentType != "" && w.Header().Get("Content-Type") != tc.ContentType {
				t.Errorf("Content-Type Go %q; PHP %q", w.Header().Get("Content-Type"), tc.ContentType)
			}
			if w.Code != tc.Status || !equal {
				t.Fatalf("Go %d %q; PHP %d %q", w.Code, w.Body.String(), tc.Status, tc.Body)
			}
		})
	}
	t.Run("clan capacity and quest serialization", func(t *testing.T) { testClanConcurrency(t, h) })
	t.Run("native worker services and acknowledgements", func(t *testing.T) { testNativeWorker(t, h) })
	// Duplicate saves must not create history, and Go must retain PHP's
	// original ciphertext as an independently decryptable historical revision.
	var revision, history int
	if err = db.QueryRow(`SELECT revision FROM mucho_cloud_saves WHERE account_id=1`).Scan(&revision); err != nil || revision != 2 {
		t.Fatalf("revision=%d: %v", revision, err)
	}
	if err = db.QueryRow(`SELECT COUNT(*) FROM mucho_cloud_save_revisions WHERE account_id=1`).Scan(&history); err != nil || history != 1 {
		t.Fatalf("history=%d: %v", history, err)
	}
	if _, err = db.Exec(`UPDATE mucho_cloud_saves SET auth_tag=REPEAT('x',16) WHERE account_id=1`); err != nil {
		t.Fatal(err)
	}
	if _, err = h.cloud.Load(context.Background(), 1); err == nil {
		t.Fatal("accepted tampered cloud save")
	}
	if _, err = h.accounts.Authenticate(context.Background(), 1, "wrong"); err == nil {
		t.Fatal("invalid credential accepted")
	}

	t.Run("transactional rate limiter", func(t *testing.T) {
		var wg sync.WaitGroup
		var allowed atomic.Int32
		for i := 0; i < 20; i++ {
			wg.Add(1)
			go func() {
				defer wg.Done()
				yes, err := h.protect.Allow(context.Background(), "native-concurrency-test", 5, 60)
				if err != nil {
					t.Error(err)
				}
				if yes {
					allowed.Add(1)
				}
			}()
		}
		wg.Wait()
		if allowed.Load() != 5 {
			t.Fatalf("allowed %d, expected 5", allowed.Load())
		}
	})
	t.Run("existing PHP password hashes", func(t *testing.T) {
		for _, credential := range []string{"test-password", account.GJP2("test-password"), base64.RawURLEncoding.EncodeToString([]byte(protocol.XOR("test-password", "37526")))} {
			if _, err := h.accounts.Authenticate(context.Background(), 1, credential); err != nil {
				t.Error(err)
			}
		}
	})
}
