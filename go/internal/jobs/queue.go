// Package jobs implements the shared MuchoCore queue with fenced worker leases.
package jobs

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"regexp"
	"strings"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
)

type Queue struct{ DB *sql.DB }
type Job struct {
	ID           int64
	Type, Worker string
	Attempt      int
	Payload      json.RawMessage
}

var jobType = regexp.MustCompile(`^[a-z][a-z0-9_.-]{0,95}$`)

func truncate(s string, n int) string {
	if len(s) > n {
		return s[:n]
	}
	return s
}
func (q Queue) Enqueue(ctx context.Context, typ string, payload any, delay int) (int64, error) {
	if !jobType.MatchString(typ) {
		return 0, errors.New("invalid job type")
	}
	data, err := json.Marshal(payload)
	if err != nil {
		return 0, err
	}
	r, err := q.DB.ExecContext(ctx, `INSERT INTO mucho_jobs(job_type,payload_json,available_at) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND))`, typ, data, max(0, delay))
	if err != nil {
		return 0, err
	}
	return r.LastInsertId()
}
func (q Queue) Reserve(ctx context.Context, worker string, types []string) (*Job, error) {
	worker = truncate(worker, 96)
	if worker == "" {
		return nil, errors.New("worker ID is required")
	}
	filter := ""
	var args []any
	if len(types) > 0 {
		marks := make([]string, len(types))
		for i, typ := range types {
			if !jobType.MatchString(typ) {
				return nil, errors.New("invalid job type")
			}
			marks[i] = "?"
			args = append(args, typ)
		}
		filter = " AND job_type IN (" + strings.Join(marks, ",") + ")"
	}
	tx, err := q.DB.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()
	r, err := dbutil.One(ctx, tx, `SELECT id,job_type,payload_json,attempts FROM mucho_jobs WHERE status='queued' AND available_at<=UTC_TIMESTAMP()`+filter+` ORDER BY available_at ASC,id ASC LIMIT 1 FOR UPDATE SKIP LOCKED`, args...)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, tx.Commit()
	}
	if err != nil {
		return nil, err
	}
	_, err = tx.ExecContext(ctx, `UPDATE mucho_jobs SET status='running',attempts=attempts+1,locked_at=UTC_TIMESTAMP(),worker_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='queued'`, worker, r.Int("id"))
	if err != nil {
		return nil, err
	}
	if err = tx.Commit(); err != nil {
		return nil, err
	}
	return &Job{ID: r.Int("id"), Type: r["job_type"], Payload: json.RawMessage(r["payload_json"]), Attempt: int(r.Int("attempts")) + 1, Worker: worker}, nil
}
func (q Queue) Complete(ctx context.Context, job Job) (bool, error) {
	return affected(q.DB.ExecContext(ctx, `UPDATE mucho_jobs SET status='done',locked_at=NULL,worker_id=NULL,last_error=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='running' AND worker_id=? AND attempts=?`, job.ID, job.Worker, job.Attempt))
}
func (q Queue) Fail(ctx context.Context, job Job, message string, delay, maxAttempts int) (bool, error) {
	return affected(q.DB.ExecContext(ctx, `UPDATE mucho_jobs SET status=IF(attempts>=?,'failed','queued'),available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),last_error=?,locked_at=NULL,worker_id=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='running' AND worker_id=? AND attempts=?`, max(1, maxAttempts), max(5, min(86400, delay)), truncate(message, 2000), job.ID, job.Worker, job.Attempt))
}
func (q Queue) RetryStale(ctx context.Context, minutes, maxAttempts int) (int64, error) {
	r, err := q.DB.ExecContext(ctx, `UPDATE mucho_jobs SET status=IF(attempts>=?,'failed','queued'),last_error='Worker lease expired.',worker_id=NULL,locked_at=NULL,available_at=UTC_TIMESTAMP(),updated_at=CURRENT_TIMESTAMP WHERE status='running' AND locked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ? MINUTE)`, max(1, maxAttempts), max(1, min(1440, minutes)))
	if err != nil {
		return 0, err
	}
	return r.RowsAffected()
}
func affected(r sql.Result, err error) (bool, error) {
	if err != nil {
		return false, err
	}
	n, err := r.RowsAffected()
	return n == 1, err
}
