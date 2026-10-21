# Fast Android APK Upload (Admin Patcher)

The authenticated Android APK patcher in `/admin/?page=clientpatcher` negotiates a
**parallel-v1** transport. It is meant to reduce the time spent waiting for
individual HTTP request acknowledgements on high-latency connections.

## Transport

- The browser uploads up to **3 independent HTTPS parts** simultaneously.
- Parts are at most 8 MiB; the server selects 4, 2, 1 or 0.5 MiB when the
  PHP upload limits are tighter to preserve headroom for multipart headers.
- `android-patcher-start` creates one private staging file, returns the
  part size, upload mode and maximum concurrency. Old callers that omit
  `supports_parallel=1` continue on the original sequential protocol.
- `android-patcher-part` validates the admin session/CSRF and upload ID,
  index, multipart size and PHP temporary upload. It then releases the PHP
  session lock so other parts can proceed.
- Each part writes to its own byte range and commits a separate marker
  **only after** the range is fully written and flushed. A lock per index
  ensures retries never race.
- `android-patcher-status` exposes only completed part indices for the
  authenticated upload. A lost acknowledgement can be recovered without
  retransmitting a committed chunk.
- `android-patcher-finish` refuses incomplete uploads. Patch, zipalign,
  signing, verification, audit and private downloads remain unchanged.

The system still has to transfer every byte of the APK over the user's
network. Concurrent transfers may improve utilization but cannot overcome
an upstream bandwidth cap. No claims are made about specific speedups until
measured on the deployment being used.

## Admin deployment

Update the complete MuchoCore **PHP 8.2+** VPS or shared-hosting installation,
including `src/Client/ParallelApkUpload.php` and
`public/admin/android-client-patcher-module.php`. Old frontend caches can
be refreshed with a full reload.

This is **not** part of the experimental PHP 7.0 5v.pl admin runtime.

Check `upload_max_filesize`, `post_max_size`, upstream proxy body limits,
PHP-FPM worker count (3 or more available workers improves concurrency), and
free disk space if uploads are still slow. The staging file uses sparse
allocation but still needs enough free space for the entire APK and patched
output. During final signing, CPU/disk may dominate even after transfer is
complete.

## Tests

```sh
php tests/client/parallel-apk-upload.php
node --test tests/application/apk-parallel-upload.test.cjs
node --test tests/application/client-upload-speed.test.cjs
```

No proprietary APKs or credentials are used in these tests.
