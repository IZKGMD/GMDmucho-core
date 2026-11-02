package core

import (
	"context"
	"encoding/json"
	"testing"
	"time"

	"github.com/IZKGMD/GMDmucho-core/go/internal/jobs"
)

func testNativeWorker(t *testing.T, h *Handler) {
	t.Helper()
	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
	defer cancel()
	// Jobs produced by the HTTP scenario must run through the native services
	// and leave fenced acknowledgements; delivery is disabled in this test.
	for range 100 {
		var pending int
		if err := h.db.QueryRowContext(ctx, `SELECT COUNT(*) FROM mucho_jobs WHERE status='queued' AND available_at<=UTC_TIMESTAMP()`).Scan(&pending); err != nil {
			t.Fatal(err)
		}
		if pending == 0 {
			break
		}
		if err := h.RunWorker(ctx, false, time.Second); err != nil {
			t.Fatal(err)
		}
	}
	var failed int
	if err := h.db.QueryRowContext(ctx, `SELECT COUNT(*) FROM mucho_jobs WHERE status<>'done' OR attempts<>1 OR worker_id IS NOT NULL OR locked_at IS NOT NULL`).Scan(&failed); err != nil || failed != 0 {
		t.Fatalf("unacknowledged HTTP jobs: %d %v", failed, err)
	}
	if _, err := h.db.ExecContext(ctx, `DELETE FROM mucho_level_search_index WHERE level_id=6`); err != nil {
		t.Fatal(err)
	}
	if err := h.ProcessJob(ctx, jobs.Job{Type: "level.index", Payload: json.RawMessage(`{"level_id":6}`)}); err != nil {
		t.Fatal(err)
	}
	var indexed string
	if err := h.db.QueryRowContext(ctx, `SELECT normalized_name FROM mucho_level_search_index WHERE level_id=6`).Scan(&indexed); err != nil || indexed != "rating test" {
		t.Fatalf("index %q: %v", indexed, err)
	}
	if _, err := h.db.ExecContext(ctx, `INSERT INTO mucho_cache(cache_key,cache_value,expires_at) VALUES('worker-expired','x',1),('worker-live','x',2000000000)`); err != nil {
		t.Fatal(err)
	}
	if err := h.ProcessJob(ctx, jobs.Job{Type: "maintenance.cleanup"}); err != nil {
		t.Fatal(err)
	}
	var live int
	if err := h.db.QueryRowContext(ctx, `SELECT COUNT(*) FROM mucho_cache WHERE cache_key IN ('worker-expired','worker-live')`).Scan(&live); err != nil || live != 1 {
		t.Fatalf("cache cleanup %d: %v", live, err)
	}
	q := jobs.Queue{DB: h.db}
	id, err := q.Enqueue(ctx, "unknown.test", map[string]any{}, 0)
	if err != nil {
		t.Fatal(err)
	}
	if err = h.RunWorker(ctx, false, time.Second); err != nil {
		t.Fatal(err)
	}
	var status, lastError string
	var attempts int
	if err = h.db.QueryRowContext(ctx, `SELECT status,attempts,last_error FROM mucho_jobs WHERE id=?`, id).Scan(&status, &attempts, &lastError); err != nil {
		t.Fatal(err)
	}
	if status != "queued" || attempts != 1 || lastError != "unknown job type" {
		t.Fatalf("failed job retry: %s %d %q", status, attempts, lastError)
	}
	if err = h.ProcessJob(ctx, jobs.Job{Type: "level.index", Payload: json.RawMessage(`broken`)}); err == nil {
		t.Fatal("invalid payload accepted")
	}
}
