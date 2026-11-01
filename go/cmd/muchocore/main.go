// muchocore runs the standalone Go discovery preview. It never invokes PHP.
package main

import (
	"context"
	"database/sql"
	"errors"
	"log/slog"
	"net"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/IZKGMD/GMDmucho-core/go/internal/discovery"
	"github.com/go-sql-driver/mysql"
)

func main() {
	logger := slog.New(slog.NewJSONHandler(os.Stderr, nil))
	if err := run(logger); err != nil {
		// Driver errors may include connection details; do not emit raw DSNs.
		logger.Error("MuchoCore stopped", "reason", err.Error())
		os.Exit(1)
	}
}

func run(logger *slog.Logger) error {
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
	addr := os.Getenv("MUCHO_GO_ADDR")
	if addr == "" {
		addr = "127.0.0.1:8081"
	}
	listener, err := net.Listen("tcp", addr)
	if err != nil {
		return errors.New("cannot bind MUCHO_GO_ADDR")
	}
	server := &http.Server{
		Handler:           discovery.Handler{Store: discovery.SQLStore{DB: db}, Logger: logger},
		ReadHeaderTimeout: 5 * time.Second, ReadTimeout: 10 * time.Second,
		WriteTimeout: 10 * time.Second, IdleTimeout: 60 * time.Second,
		MaxHeaderBytes: 1 << 16,
	}
	serveErr := make(chan error, 1)
	go func() { serveErr <- server.Serve(listener) }()
	logger.Info("MuchoCore Go discovery preview listening", "address", listener.Addr().String())
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
