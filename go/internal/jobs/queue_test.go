package jobs

import (
	"context"
	"database/sql"
	"fmt"
	"os"
	"sync"
	"testing"

	_ "github.com/go-sql-driver/mysql"
)

func TestConcurrentLeasesAndFencing(t *testing.T) {
	dsn := os.Getenv("MUCHO_GO_CORE_DSN")
	if dsn == "" {
		t.Skip("set MUCHO_GO_CORE_DSN")
	}
	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatal(err)
	}
	defer db.Close()
	q := Queue{DB: db}
	ctx := context.Background()
	for i := 0; i < 20; i++ {
		if _, err = q.Enqueue(ctx, "test.native", map[string]int{"i": i}, 0); err != nil {
			t.Fatal(err)
		}
	}
	var wg sync.WaitGroup
	reserved := make(chan Job, 20)
	for i := 0; i < 4; i++ {
		wg.Add(1)
		go func(worker int) {
			defer wg.Done()
			for {
				job, err := q.Reserve(ctx, fmt.Sprintf("test-%d", worker), []string{"test.native"})
				if err != nil {
					t.Error(err)
					return
				}
				if job == nil {
					return
				}
				reserved <- *job
			}
		}(i)
	}
	wg.Wait()
	close(reserved)
	seen := map[int64]bool{}
	for job := range reserved {
		if seen[job.ID] {
			t.Fatal("job reserved twice")
		}
		seen[job.ID] = true
		wrong := job
		wrong.Attempt++
		if yes, err := q.Complete(ctx, wrong); err != nil || yes {
			t.Fatal("incorrect attempt completed a lease")
		}
		wrong = job
		wrong.Worker = "other-worker"
		if yes, err := q.Complete(ctx, wrong); err != nil || yes {
			t.Fatal("incorrect worker completed a lease")
		}
		if yes, err := q.Complete(ctx, job); err != nil || !yes {
			t.Fatal("owner could not complete lease")
		}
	}
	if len(seen) != 20 {
		t.Fatalf("reserved %d of 20", len(seen))
	}
	_, err = q.Enqueue(ctx, "test.expired", nil, 0)
	if err != nil {
		t.Fatal(err)
	}
	job, err := q.Reserve(ctx, "worker", []string{"test.expired"})
	if err != nil || job == nil {
		t.Fatal("missing lease")
	}
	if _, err = db.Exec(`UPDATE mucho_jobs SET locked_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 20 MINUTE) WHERE id=?`, job.ID); err != nil {
		t.Fatal(err)
	}
	if n, err := q.RetryStale(ctx, 10, 5); err != nil || n != 1 {
		t.Fatal("stale lease did not recover")
	}
	newJob, err := q.Reserve(ctx, "worker", []string{"test.expired"})
	if err != nil || newJob == nil || newJob.Attempt != 2 {
		t.Fatal("retry attempt missing")
	}
	if yes, err := q.Complete(ctx, *job); err != nil || yes {
		t.Fatal("stale handle completed a new lease")
	}
	if yes, err := q.Fail(ctx, *newJob, "test failure", 30, 2); err != nil || !yes {
		t.Fatal("failure did not persist")
	}
	if next, err := q.Reserve(ctx, "worker", []string{"test.expired"}); err != nil || next != nil {
		t.Fatal("terminal failure was retried")
	}
}
