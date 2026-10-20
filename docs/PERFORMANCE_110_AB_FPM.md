# MuchoCore — controlled PHP-FPM A/B results

Date: October 20, 2026. GitHub Actions: https://github.com/IZKGMD/GMDmucho-core/actions/runs/39358835581
Artifact: php-fpm-comparison (JSON + source SHAs).
Baseline main: 247cd0ad584977649ce85817b11ea41d05dd3457.
Candidate: 25fd8f404f54e9c7efc656fa7d61638392f9a911.

## Setup

- GitHub-hosted Ubuntu 24.04 runner, separate PHP 8.3 FPM pools and localhost Nginx ports.
- Shared, ephemeral MariaDB 11.4 fixture with one synthetic level and creator. This matches the live VPS's one-level cardinality, but **not** its real data or resource limits.
- Same Composer dependencies, response-cache backend, MuchoProtect file-backed mode and PHP runtime.
- Two scenarios; for each, 18 samples x two rounds x two versions, 144 measured HTTP requests overall.
- Two warmups before each measured series; 600ms request spacing; order reversed in second round.
- Fresh local HTTP connections include Nginx/FPM/PHP/SQL, but not public Internet or a native GD client.

## Results: mean of two separate p95 values

| Action | main p95 | performance p95 | Reduction |
|---|---:|---:|---:|
| GD 2.209 type=0 list | 22.847 ms | 9.779 ms | 57.2% |
| GD 2.209 search ID=1 | 19.206 ms | 9.476 ms | 50.7% |

| Action | Version | Round 1 p50/p95 | Round 2 p50/p95 |
|---|---|---:|---:|
| type=0 | main | 17.651 / 25.906 ms | 17.812 / 19.788 ms |
| type=0 | candidate | 7.553 / 9.195 ms | 7.605 / 10.363 ms |
| ID=1 | main | 17.806 / 19.871 ms | 17.772 / 18.540 ms |
| ID=1 | candidate | 7.660 / 8.518 ms | 7.655 / 10.433 ms |

144/144 measured HTTP calls succeeded. The GD 2.209 response SHA256 was identical across both versions and repeated rounds. The benchmark fails on non-2xx/empty/-1/-2/non-deterministic wire replies.

## Limitations

- **This measures the whole performance branch, not a single optimization.**
- **This is a GitHub runner benchmark, not the user's 1GiB VPS.** FPM OPcache configuration, CPU/RAM, concurrency and real player data may change p95 substantially.
- Pace-limited benchmark output does NOT measure peak requests/sec.
- Native Geometry Dash acceptance and stable release certification are separate gates.
- Existing VPS baseline, obtained independently on production with 50/50 accepted real list responses, was 40.106ms and 50.418ms p95 on two runs; do not directly compare that against hosted runner numbers.

No production database, configuration, protection settings, Docker containers or indexes were modified by this isolated CI benchmark.