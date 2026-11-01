// muchocore runs the standalone Go edition. It never invokes PHP.
package main

import (
	"context"
	"crypto/sha256"
	"database/sql"
	"errors"
	"flag"
	"fmt"
	"log/slog"
	"net"
	"net/http"
	"os"
	"os/signal"
	"strconv"
	"strings"
	"syscall"
	"time"

	"github.com/IZKGMD/GMDmucho-core/go/internal/core"
	"github.com/IZKGMD/GMDmucho-core/go/internal/integration"
	"github.com/IZKGMD/GMDmucho-core/go/internal/schema"
	"github.com/IZKGMD/GMDmucho-core/go/internal/security"
	"github.com/go-sql-driver/mysql"
)

func main() {
	initDB := flag.Bool("init-db", false, "initialize a new empty MuchoCore database and exit")
	worker := flag.Bool("worker", false, "process background jobs instead of starting HTTP")
	loop := flag.Bool("loop", false, "keep the background worker running")
	sleep := flag.Int("sleep", 2, "idle worker polling interval in seconds")
	flag.Parse()
	logger := slog.New(slog.NewJSONHandler(os.Stderr, nil))
	if err := run(logger, commandOptions{InitDB: *initDB, Worker: *worker, Loop: *loop, Sleep: time.Duration(max(1, min(60, *sleep))) * time.Second}); err != nil {
		// Driver errors may include connection details; do not emit raw DSNs.
		logger.Error("MuchoCore stopped", "reason", err.Error())
		os.Exit(1)
	}
}

type commandOptions struct {
	InitDB, Worker, Loop bool
	Sleep                time.Duration
}

func envTrue(key string) bool {
	switch strings.ToLower(strings.TrimSpace(os.Getenv(key))) {
	case "1", "true", "yes", "on":
		return true
	}
	return false
}
func envFalse(key string) bool {
	switch strings.ToLower(strings.TrimSpace(os.Getenv(key))) {
	case "0", "false", "no", "off":
		return true
	}
	return false
}
func run(logger *slog.Logger, options commandOptions) error {
	if options.InitDB && options.Worker {
		return errors.New("choose either --init-db or --worker")
	}
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	dsn := os.Getenv("MUCHO_GO_DSN")
	if dsn == "" {
		return errors.New("MUCHO_GO_DSN is required; see go/README.md")
	}
	cfg, err := mysql.ParseDSN(dsn)
	if err != nil {
		return errors.New("invalid MUCHO_GO_DSN")
	}
	// Bound stalled connections and queries even when the DSN omits timeouts.
	if cfg.Timeout == 0 {
		cfg.Timeout = 3 * time.Second
	}
	if cfg.ReadTimeout == 0 {
		cfg.ReadTimeout = 3 * time.Second
	}
	if cfg.WriteTimeout == 0 {
		cfg.WriteTimeout = 3 * time.Second
	}
	connector, err := mysql.NewConnector(cfg)
	if err != nil {
		return errors.New("invalid MySQL connection configuration")
	}
	db := sql.OpenDB(connector)
	defer db.Close()
	db.SetMaxOpenConns(16)
	db.SetMaxIdleConns(4)
	db.SetConnMaxLifetime(5 * time.Minute)
	probe, cancel := context.WithTimeout(ctx, 3*time.Second)
	err = db.PingContext(probe)
	cancel()
	if err != nil {
		return errors.New("database unavailable; check connection settings and schema")
	}
	if options.InitDB {
		migration, cancel := context.WithTimeout(ctx, 60*time.Second)
		defer cancel()
		if err := schema.Init(migration, db); err != nil {
			return err
		}
		logger.Info("MuchoCore database initialized")
		return nil
	}
	downloadKey := os.Getenv("MUCHO_DOWNLOAD_HMAC_KEY")
	if downloadKey == "" && cfg.Passwd != "" {
		downloadKey = fmt.Sprintf("%x", sha256.Sum256([]byte("muchocore-download-dedup|"+cfg.Passwd)))
	}
	window := 300
	if value := os.Getenv("MUCHO_DOWNLOAD_DEDUP_SECONDS"); value != "" {
		n, _ := strconv.Atoi(value)
		window = max(30, min(604800, n))
	}
	application := core.New(db, core.Options{Logger: logger, SecureCookies: envTrue("MUCHO_GO_COOKIE_SECURE"), AssetBase: os.Getenv("MUCHO_PUBLIC_ASSET_BASE"), RecoveryPath: os.Getenv("MUCHO_ACCOUNT_RECOVERY_PATH"), AccountURL: os.Getenv("MUCHO_ACCOUNT_URL"), ContentURL: os.Getenv("MUCHO_CUSTOM_CONTENT_URL"), CloudKeyFile: os.Getenv("MUCHO_CLOUDSAVE_KEY_FILE"), DownloadKey: downloadKey, DownloadWindow: window, TrustedProxies: security.ParseProxies(os.Getenv("MUCHO_TRUSTED_PROXY_CIDRS")), DisableProtect: envFalse("MUCHO_PROTECT"), QuarantineScores: envTrue("MUCHO_ANTICHEAT_QUARANTINE"), Webhooks: integration.Webhooks{URL: os.Getenv("MUCHO_WEBHOOK_URL"), Events: os.Getenv("MUCHO_WEBHOOK_EVENTS"), Secret: os.Getenv("MUCHO_WEBHOOK_SECRET")}})
	if options.Worker {
		return application.RunWorker(ctx, options.Loop, options.Sleep)
	}
	addr := os.Getenv("MUCHO_GO_ADDR")
	if addr == "" {
		addr = "127.0.0.1:8081"
	}
	listener, err := net.Listen("tcp", addr)
	if err != nil {
		return errors.New("cannot bind MUCHO_GO_ADDR")
	}
	server := &http.Server{
		Handler:           application,
		ReadHeaderTimeout: 5 * time.Second, ReadTimeout: 10 * time.Second,
		WriteTimeout: 10 * time.Second, IdleTimeout: 60 * time.Second,
		MaxHeaderBytes: 1 << 16,
	}
	serveErr := make(chan error, 1)
	go func() { serveErr <- server.Serve(listener) }()
	logger.Info("MuchoCore Go native preview listening", "address", listener.Addr().String())
	select {
	case <-ctx.Done():
		shutdown, cancel := context.WithTimeout(context.Background(), 10*time.Second)
		defer cancel()
		if err := server.Shutdown(shutdown); err != nil {
			_ = server.Close()
			return errors.New("graceful shutdown timed out")
		}
		return nil
	case err := <-serveErr:
		if errors.Is(err, http.ErrServerClosed) {
			return nil
		}
		return errors.New("HTTP server failed")
	}
}
