package discovery

import (
	"database/sql"
	"encoding/json"
	"os"
	"testing"

	_ "github.com/go-sql-driver/mysql"
)

// The fixture generator applies the existing PHP migrations to an EMPTY,
// disposable MariaDB, then obtains expected HTTP bodies from PHP controllers.
func TestMySQLPHPParity(t *testing.T) {
	dsn := os.Getenv("MUCHO_GO_TEST_DSN")
	if dsn == "" {
		t.Skip("set MUCHO_GO_TEST_DSN and MUCHO_GO_TEST_FIXTURES; see go/README.md")
	}
	data, err := os.ReadFile(os.Getenv("MUCHO_GO_TEST_FIXTURES"))
	if err != nil {
		t.Fatal(err)
	}
	var cases []struct {
		Path, Form, Body string
		Status           int
	}
	if err := json.Unmarshal(data, &cases); err != nil {
		t.Fatal(err)
	}
	if len(cases) == 0 {
		t.Fatal("no PHP fixtures")
	}
	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatal(err)
	}
	defer db.Close()
	// Session-scoped fixed clock, also used by the PHP reference generator.
	db.SetMaxOpenConns(1)
	if _, err := db.Exec("SET timestamp=1700000000"); err != nil {
		t.Fatal(err)
	}
	h := Handler{Store: SQLStore{DB: db}}
	for _, tc := range cases {
		t.Run(tc.Path+"/"+tc.Form, func(t *testing.T) {
			w := request(h, "POST", tc.Path, tc.Form)
			if w.Code != tc.Status || w.Body.String() != tc.Body {
				t.Fatalf("Go: %d %q; PHP: %d %q", w.Code, w.Body.String(), tc.Status, tc.Body)
			}
		})
	}
	if w := request(h, "GET", "/ready", ""); w.Code != 200 || w.Body.String() != "1" {
		t.Fatal("readiness failed with live DB")
	}
}
