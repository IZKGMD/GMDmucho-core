package core

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"time"

	"github.com/IZKGMD/GMDmucho-core/go/internal/jobs"
)

// ProcessJob uses the same application services as the HTTP runtime.
func (h *Handler) ProcessJob(ctx context.Context, job jobs.Job) error {
	var payload struct {
		LevelID   int64          `json:"level_id"`
		Event     string         `json:"event"`
		RequestID string         `json:"request_id"`
		Data      map[string]any `json:"data"`
	}
	if len(job.Payload) > 0 {
		if err := json.Unmarshal(job.Payload, &payload); err != nil {
			return errors.New("invalid job payload")
		}
	}
	switch job.Type {
	case "level.index":
		if payload.LevelID <= 0 {
			return errors.New("missing level ID")
		}
		if err := h.indexLevel(request{ctx: ctx}, payload.LevelID); err != nil {
			return errors.New("level index job failed")
		}
		return nil
	case "webhook.dispatch":
		if payload.Event == "" {
			return errors.New("webhook event is missing")
		}
		return h.options.Webhooks.Dispatch(ctx, payload.Event, payload.Data, payload.RequestID)
	case "maintenance.cleanup":
		for _, query := range []string{`DELETE FROM mucho_jobs WHERE status IN ('done','failed') AND updated_at<DATE_SUB(NOW(),INTERVAL 30 DAY) LIMIT 1000`, `DELETE FROM mucho_cache WHERE expires_at>0 AND expires_at<=UNIX_TIMESTAMP() LIMIT 1000`} {
			for {
				result, err := h.db.ExecContext(ctx, query)
				if err != nil {
					return errors.New("maintenance cleanup failed")
				}
				n, err := result.RowsAffected()
				if err != nil {
					return errors.New("maintenance cleanup failed")
				}
				if n < 1000 {
					break
				}
			}
		}
		return nil
	default:
		return errors.New("unknown job type")
	}
}
func (h *Handler) RunWorker(ctx context.Context, loop bool, interval time.Duration) error {
	if interval < time.Second {
		interval = time.Second
	}
	if interval > 60*time.Second {
		interval = 60 * time.Second
	}
	name, _ := os.Hostname()
	workerID := fmt.Sprintf("%s:%d", name, os.Getpid())
	q := jobs.Queue{DB: h.db}
	nextPrune := time.Time{}
	for {
		if ctx.Err() != nil {
			return nil
		}
		if loop && time.Now().After(nextPrune) {
			nextPrune = time.Now().Add(time.Minute)
			_, _ = h.db.ExecContext(ctx, `DELETE FROM mucho_level_downloads WHERE created_at<CURRENT_TIMESTAMP-INTERVAL ? SECOND ORDER BY created_at ASC LIMIT 512`, h.options.DownloadWindow)
		}
		if _, err := q.RetryStale(ctx, 10, 5); err != nil {
			if ctx.Err() != nil {
				return nil
			}
			return errors.New("cannot recover worker leases")
		}
		job, err := q.Reserve(ctx, workerID, nil)
		if err != nil {
			if ctx.Err() != nil {
				return nil
			}
			return errors.New("cannot reserve a background job")
		}
		if job == nil {
			if !loop {
				return nil
			}
			timer := time.NewTimer(interval)
			select {
			case <-ctx.Done():
				timer.Stop()
				return nil
			case <-timer.C:
				continue
			}
		}
		jobCtx, cancel := context.WithTimeout(ctx, 30*time.Second)
		err = h.ProcessJob(jobCtx, *job)
		cancel()
		ackCtx, ackCancel := context.WithTimeout(context.Background(), 3*time.Second)
		var acknowledged bool
		var ackErr error
		if err == nil {
			acknowledged, ackErr = q.Complete(ackCtx, *job)
		} else {
			acknowledged, ackErr = q.Fail(ackCtx, *job, err.Error(), 30, 5)
		}
		ackCancel()
		if ackErr != nil {
			return errors.New("cannot persist a worker acknowledgement")
		}
		if h.options.Logger != nil {
			if !acknowledged {
				h.options.Logger.Warn("background job lease lost", "job_id", job.ID)
			} else if err != nil {
				h.options.Logger.Error("background job failed", "job_id", job.ID, "type", job.Type)
			} else {
				h.options.Logger.Info("background job completed", "job_id", job.ID, "type", job.Type)
			}
		}
		if !loop {
			return nil
		}
	}
}
