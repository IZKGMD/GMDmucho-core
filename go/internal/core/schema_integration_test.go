package core

import (
	"context"
	"database/sql"
	"fmt"
	"os"
	"reflect"
	"testing"
	"time"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
	"github.com/IZKGMD/GMDmucho-core/go/internal/schema"
	"github.com/go-sql-driver/mysql"
)

func TestNativeSchemaInitialization(t *testing.T) {
	dsn := os.Getenv("MUCHO_GO_CORE_DSN")
	if dsn == "" {
		t.Skip("set MUCHO_GO_CORE_DSN")
	}
	cfg, err := mysql.ParseDSN(dsn)
	if err != nil {
		t.Fatal(err)
	}
	admin, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatal(err)
	}
	defer admin.Close()
	name := fmt.Sprintf("muchocore_go_native_test_%d", time.Now().UnixNano())
	if _, err = admin.Exec("CREATE DATABASE `" + name + "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); err != nil {
		t.Fatal(err)
	}
	defer admin.Exec("DROP DATABASE `" + name + "`")
	cfg.DBName = name
	db, err := sql.Open("mysql", cfg.FormatDSN())
	if err != nil {
		t.Fatal(err)
	}
	defer db.Close()
	if err = schema.Init(context.Background(), db); err != nil {
		t.Fatal(err)
	}
	var count int
	if err = db.QueryRow(`SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()`).Scan(&count); err != nil || count < 90 {
		t.Fatalf("tables=%d: %v", count, err)
	}
	if err = db.QueryRow(`SELECT COUNT(*) FROM roles WHERE code='owner'`).Scan(&count); err != nil || count != 1 {
		t.Fatal("canonical roles missing")
	}
	for _, query := range []string{
		`SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION`,
		`SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,INDEX_TYPE,COLLATION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX`,
		`SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION`,
		`SELECT version FROM schema_migrations ORDER BY version`,
	} {
		php, e := dbutil.Query(context.Background(), admin, query)
		if e != nil {
			t.Fatal(e)
		}
		native, e := dbutil.Query(context.Background(), db, query)
		if e != nil {
			t.Fatal(e)
		}
		if !reflect.DeepEqual(php, native) {
			t.Fatalf("native schema differs from current PHP migrations: %s", query)
		}
	}
	if err = schema.Init(context.Background(), db); err == nil {
		t.Fatal("reinitialized a non-empty database")
	}
	t.Run("native administrator bootstrap and browser workflows", func(t *testing.T) { testNativeAdministration(t, db) })
}
