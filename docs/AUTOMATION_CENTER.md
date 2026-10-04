# Automation Center

MuchoCore's Automation Center provides safe recurring background maintenance without requiring host-level cron entries.

## Architecture

```text
Automation Scheduler
        |
        | due schedule
        v
  mucho_jobs queue
        |
        v
   MuchoCore Worker
        |
        v
 registered job implementation
```

The scheduler and worker are separate services. Both use the same MariaDB database.

## Safety model

Automation schedules do not contain PHP or SQL source code.

Each schedule references a registered job type:

| Schedule | Job | Default interval | Purpose |
| --- | --- | ---: | --- |
| Maintenance cleanup | `maintenance.cleanup` | 6h | Remove old completed/failed jobs and expired cache records |
| Security event cleanup | `security.cleanup` | 24h | Remove old security events, stale limiter buckets and expired penalties |

Only job types declared by `AutomationScheduler::DEFINITIONS` can be emitted by the scheduler.

## Concurrency

The scheduler acquires the MariaDB advisory lock:

`muchocore:automation-scheduler`

Before enqueueing due tasks it starts a database transaction. Enqueued jobs and schedule advancement are therefore committed together.

A crash before commit leaves the schedule due and prevents a permanently advanced schedule without a job.

## Heartbeat

Every successful scheduler tick updates:

`mucho_automation_heartbeat`

The record contains:

| Field | Meaning |
| --- | --- |
| `scheduler_id` | Scheduler hostname/process identity |
| `ticked_at` | Last successful tick |
| `enqueued_count` | Jobs emitted by the last tick |

MuchoOps displays this heartbeat and marks the scheduler as requiring attention when it has not reported recently.

## Operator controls

Open:

`Admin → Automation Center`

Each registered schedule can be:

- enabled or disabled;
- assigned a new interval;
- monitored through its next execution time;
- compared with the scheduler heartbeat.

The same state is visible in:

`Admin → MuchoOps`

## CLI

Run a single scheduler tick:

```bash
sudo mucho automation
```

Run it continuously:

```bash
sudo mucho automation --loop --sleep=15
```

Inspect the worker:

```bash
sudo mucho worker --loop
```

Check the production stack:

```bash
sudo mucho status
sudo mucho doctor
```

## Adding a new automation task

A new task should follow this order:

1. Add a fixed schedule definition to `AutomationScheduler::DEFINITIONS`.
2. Seed it in the migration.
3. Add a dedicated worker job implementation.
4. Add a contract test.
5. Expose it in the Admin Automation Center.
6. Document retention and failure behavior.

Do not add arbitrary executable expressions, SQL strings, shell commands, or PHP file paths to schedule payloads.
