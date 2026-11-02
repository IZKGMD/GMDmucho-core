// muchocore runs the standalone Go edition. It never invokes PHP.
package main

import (
	"context"
	"crypto/sha256"
	"database/sql"
	"errors"
	"flag"
	"fmt"
	"io"
	"log/slog"
	"net"
	"net/http"
	"os"
	"os/signal"
	"strconv"
	"strings"
	"syscall"
	"time"

	"github.com/IZKGMD/GMDmucho-core/go/internal/admin"
	"github.com/IZKGMD/GMDmucho-core/go/internal/core"
	"github.com/IZKGMD/GMDmucho-core/go/internal/integration"
	"github.com/IZKGMD/GMDmucho-core/go/internal/schema"
	"github.com/IZKGMD/GMDmucho-core/go/internal/security"
	"github.com/go-sql-driver/mysql"
)

func main() {
	initDB := flag.Bool("init-db", false, "initialize a new empty MuchoCore database and exit")
	createAdmin := flag.Bool("create-admin", false, "create the first owner in an initialized database and exit; refuses existing administrators")
	adminUser := flag.String("admin-user", "", "first owner username for --create-admin")
	adminPasswordFile := flag.String("admin-password-file", "-", "read first owner password from this file, or - for piped standard input")
	worker := flag.Bool("worker", false, "process background jobs instead of starting HTTP")
	loop := flag.Bool("loop", false, "keep the background worker running")
	sleep := flag.Int("sleep", 2, "idle worker polling interval in seconds")
	flag.Parse()
	logger := slog.New(slog.NewJSONHandler(os.Stderr, nil))
	if err := run(logger, commandOptions{InitDB: *initDB, CreateAdmin: *createAdmin, AdminUser: *adminUser, AdminPasswordFile: *adminPasswordFile, Worker: *worker, Loop: *loop, Sleep: time.Duration(max(1, min(60, *sleep))) * time.Second}); err != nil {
		// Driver errors may include connection details; do not emit raw DSNs.
		logger.Error("MuchoCore stopped", "reason", err.Error())
		os.Exit(1)
	}
}

type commandOptions struct {
	InitDB, Worker, Loop, CreateAdmin bool
	AdminUser, AdminPasswordFile      string
	Sleep                             time.Duration
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
	commands := 0
	for _, enabled := range []bool{options.InitDB, options.Worker, options.CreateAdmin} {
		if enabled {
			commands++
		}
	}
	if commands > 1 {
		return errors.New("choose one of --init-db, --create-admin or --worker")
	}
	if !options.CreateAdmin && (options.AdminUser != "" || options.AdminPasswordFile != "" && options.AdminPasswordFile != "-") {
		return errors.New("administrator options require --create-admin")
	}
	if options.CreateAdmin && options.AdminUser == "" {
		return errors.New("--create-admin requires --admin-user")
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
	if options.CreateAdmin {
		password, err := readAdminPassword(options.AdminPasswordFile)
		if err != nil {
			return err
		}
		setup, cancel := context.WithTimeout(ctx, 30*time.Second)
		defer cancel()
		_, err = (admin.Service{DB: db}).CreateInitialOwner(setup, options.AdminUser, password)
		if err != nil {
			var denied *admin.Error
			if errors.As(err, &denied) {
				return errors.New(denied.Message)
			}
			return errors.New("administrator bootstrap failed; check schema and database permissions")
		}
		logger.Info("First owner created; sign in at /admin/")
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
	smtpPort, _ := strconv.Atoi(os.Getenv("MUCHO_SMTP_PORT"))
	smtpFrom := os.Getenv("MUCHO_SMTP_FROM")
	if smtpFrom == "" {
		smtpFrom = os.Getenv("MUCHO_RECOVERY_FROM")
	}
	application := core.New(db, core.Options{Logger: logger, MusicDir: os.Getenv("MUCHO_MUSIC_DIR"), ServerName: os.Getenv("MUCHO_SERVER_NAME"), PublicURL: os.Getenv("MUCHO_PUBLIC_URL"), Turnstile: security.Turnstile{SiteKey: os.Getenv("TURNSTILE_SITEKEY"), Secret: os.Getenv("TURNSTILE_SECRET")}, RecoveryMailer: integration.SMTP{Host: os.Getenv("MUCHO_SMTP_HOST"), Port: smtpPort, From: smtpFrom, Username: os.Getenv("MUCHO_SMTP_USER"), Password: os.Getenv("MUCHO_SMTP_PASSWORD"), ImplicitTLS: smtpPort == 465 || envTrue("MUCHO_SMTP_TLS")}, SecureCookies: envTrue("MUCHO_GO_COOKIE_SECURE"), AssetBase: os.Getenv("MUCHO_PUBLIC_ASSET_BASE"), RecoveryPath: os.Getenv("MUCHO_ACCOUNT_RECOVERY_PATH"), AccountURL: os.Getenv("MUCHO_ACCOUNT_URL"), ContentURL: os.Getenv("MUCHO_CUSTOM_CONTENT_URL"), CloudKeyFile: os.Getenv("MUCHO_CLOUDSAVE_KEY_FILE"), DownloadKey: downloadKey, DownloadWindow: window, TrustedProxies: security.ParseProxies(os.Getenv("MUCHO_TRUSTED_PROXY_CIDRS")), DisableProtect: envFalse("MUCHO_PROTECT"), QuarantineScores: envTrue("MUCHO_ANTICHEAT_QUARANTINE"), Webhooks: integration.Webhooks{URL: os.Getenv("MUCHO_WEBHOOK_URL"), Events: os.Getenv("MUCHO_WEBHOOK_EVENTS"), Secret: os.Getenv("MUCHO_WEBHOOK_SECRET")}})
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

func readAdminPassword(path string) (string, error) {
	input := os.Stdin
	if path != "" && path != "-" {
		var err error
		input, err = os.Open(path)
		if err != nil {
			return "", errors.New("cannot open administrator password file")
		}
		defer input.Close()
		info, err := input.Stat()
		if err != nil || !info.Mode().IsRegular() {
			return "", errors.New("administrator password file must be a regular file")
		}
	} else {
		info, err := input.Stat()
		if err != nil || info.Mode()&os.ModeCharDevice != 0 {
			return "", errors.New("pipe the administrator password on standard input or provide --admin-password-file; terminal input is refused to prevent password echo")
		}
	}
	return decodeAdminPassword(input)
}
func decodeAdminPassword(input io.Reader) (string, error) {
	data, err := io.ReadAll(io.LimitReader(input, 75))
	if err != nil {
		return "", errors.New("cannot read administrator password")
	}
	password := strings.TrimSuffix(strings.TrimSuffix(string(data), "\n"), "\r")
	if strings.ContainsAny(password, "\r\n") {
		return "", errors.New("administrator password must occupy one line")
	}
	if err = admin.ValidatePassword(password); err != nil {
		return "", err
	}
	return password, nil
}
