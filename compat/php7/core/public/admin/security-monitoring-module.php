<?php

declare (strict_types=1);
/*
 * MuchoCore Security & Monitoring Center v3.1
 * Copyright (C) 2026 IZK
 */
function muchoMonitoringTableExists(PDO $db, string $table): bool
{
    $q = $db->prepare("\n        SELECT COUNT(*)\n        FROM information_schema.tables\n        WHERE table_schema=DATABASE()\n          AND table_name=?\n    ");
    $q->execute([$table]);
    return (int) $q->fetchColumn() > 0;
}
function muchoMonitoringColumnExists(PDO $db, string $table, string $column): bool
{
    $q = $db->prepare("\n        SELECT COUNT(*)\n        FROM information_schema.columns\n        WHERE table_schema=DATABASE()\n          AND table_name=?\n          AND column_name=?\n    ");
    $q->execute([$table, $column]);
    return (int) $q->fetchColumn() > 0;
}
function muchoMonitoringEnsure(PDO $db)
{
    $db->exec("\n        CREATE TABLE IF NOT EXISTS mucho_system_alerts (\n            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n\n            severity ENUM(\n                'info',\n                'warning',\n                'critical'\n            ) NOT NULL DEFAULT 'warning',\n\n            source VARCHAR(64) NOT NULL,\n            message VARCHAR(500) NOT NULL,\n\n            resolved TINYINT(1) NOT NULL DEFAULT 0,\n\n            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n            resolved_at TIMESTAMP NULL DEFAULT NULL,\n\n            KEY idx_alert_resolved(resolved),\n            KEY idx_alert_created(created_at)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n    ");
    $db->exec("\n        CREATE TABLE IF NOT EXISTS mucho_security_events (\n            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n\n            event_type VARCHAR(64) NOT NULL,\n\n            ip VARCHAR(45)\n                NOT NULL DEFAULT '',\n\n            route VARCHAR(255)\n                NOT NULL DEFAULT '',\n\n            request_id VARCHAR(64)\n                NOT NULL DEFAULT '',\n\n            metadata TEXT NULL,\n\n            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n\n            KEY idx_security_time(created_at),\n            KEY idx_security_type(event_type),\n            KEY idx_security_ip(ip)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n    ");
    $db->exec("\n        CREATE TABLE IF NOT EXISTS mucho_api_metrics_minute (\n            minute_start DATETIME NOT NULL,\n            route VARCHAR(160) NOT NULL,\n            method VARCHAR(12) NOT NULL,\n            status SMALLINT UNSIGNED NOT NULL,\n\n            requests BIGINT UNSIGNED\n                NOT NULL DEFAULT 0,\n\n            total_ms BIGINT UNSIGNED\n                NOT NULL DEFAULT 0,\n\n            PRIMARY KEY(\n                minute_start,\n                route,\n                method,\n                status\n            )\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n    ");
}
muchoMonitoringEnsure($db);
/* ============================================================
   POST ACTIONS
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $monitorAction = (string) ($_POST['action'] ?? '');
    $monitorActions = ['monitor-alert-resolve', 'monitor-alert-resolve-all', 'monitor-cleanup', 'monitor-release-rollback'];
    if (in_array($monitorAction, $monitorActions, true)) {
        try {
            checkCsrf();
            requireRank(30);
            /* ------------------------------------------------
                  RESOLVE SINGLE ALERT
               ------------------------------------------------ */
            if ($monitorAction === 'monitor-alert-resolve') {
                $id = (int) ($_POST['alert_id'] ?? 0);
                if ($id <= 0) {
                    throw new RuntimeException('Invalid alert ID.');
                }
                $q = $db->prepare("\n                    UPDATE mucho_system_alerts\n                    SET\n                        resolved=1,\n                        resolved_at=NOW()\n                    WHERE id=?\n                ");
                $q->execute([$id]);
                audit($db, 'monitor.alert_resolve', (string) $id, []);
                flash('Alert resolved.');
            } elseif ($monitorAction === 'monitor-alert-resolve-all') {
                $affected = $db->exec("\n                    UPDATE mucho_system_alerts\n                    SET\n                        resolved=1,\n                        resolved_at=NOW()\n                    WHERE resolved=0\n                ");
                audit($db, 'monitor.alert_resolve_all', 'all', ['affected' => (int) $affected]);
                flash('All active alerts resolved.');
            } elseif ($monitorAction === 'monitor-cleanup') {
                $metrics = $db->exec("\n                    DELETE FROM mucho_api_metrics_minute\n                    WHERE minute_start <\n                        DATE_SUB(\n                            NOW(),\n                            INTERVAL 30 DAY\n                        )\n                ");
                $events = $db->exec("\n                    DELETE FROM mucho_security_events\n                    WHERE created_at <\n                        DATE_SUB(\n                            NOW(),\n                            INTERVAL 30 DAY\n                        )\n                ");
                if (muchoMonitoringTableExists($db, 'mucho_api_rate_limits')) {
                    $rate = $db->exec("\n                        DELETE FROM mucho_api_rate_limits\n                        WHERE updated_at <\n                            DATE_SUB(\n                                NOW(),\n                                INTERVAL 1 DAY\n                            )\n                    ");
                } else {
                    $rate = 0;
                }
                $alerts = $db->exec("\n                    DELETE FROM mucho_system_alerts\n                    WHERE resolved=1\n                      AND resolved_at <\n                        DATE_SUB(\n                            NOW(),\n                            INTERVAL 30 DAY\n                        )\n                ");
                audit($db, 'monitor.cleanup', 'system', ['metrics' => (int) $metrics, 'security_events' => (int) $events, 'rate_limits' => (int) $rate, 'alerts' => (int) $alerts]);
                flash('Monitoring data cleaned.');
            } elseif ($monitorAction === 'monitor-release-rollback') {
                $releaseId = (int) ($_POST['release_id'] ?? 0);
                if ($releaseId <= 0) {
                    throw new RuntimeException('Invalid release ID.');
                }
                if (!muchoMonitoringTableExists($db, 'mucho_client_release_files')) {
                    throw new RuntimeException('Release history table does not exist.');
                }
                $q = $db->prepare("\n                    SELECT *\n                    FROM mucho_client_release_files\n                    WHERE id=?\n                      AND platform='android'\n                    LIMIT 1\n                ");
                $q->execute([$releaseId]);
                $release = $q->fetch();
                if (!$release) {
                    throw new RuntimeException('Android release not found.');
                }
                $filePath = '/var/www/mucho-core/releases/android/' . basename((string) $release['file_name']);
                if (!is_file($filePath)) {
                    throw new RuntimeException('APK file is missing from server.');
                }
                $actualSha = hash_file('sha256', $filePath);
                $expectedSha = strtolower((string) $release['sha256']);
                if (!is_string($actualSha) || !hash_equals($expectedSha, strtolower($actualSha))) {
                    throw new RuntimeException('APK SHA-256 verification failed.');
                }
                $db->beginTransaction();
                try {
                    $fields = ['current_version=?', 'download_url=?', 'sha256=?'];
                    $values = [$release['version'], $release['download_url'], $release['sha256']];
                    if (muchoMonitoringColumnExists($db, 'mucho_client_releases', 'signer_sha256')) {
                        $fields[] = 'signer_sha256=?';
                        $values[] = $release['signer_sha256'] ?? null;
                    }
                    $values[] = 'android';
                    $sql = "\n                        UPDATE mucho_client_releases\n                        SET " . implode(',', $fields) . "\n                        WHERE platform=?\n                    ";
                    $q = $db->prepare($sql);
                    $q->execute($values);
                    $db->commit();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    throw $e;
                }
                audit($db, 'client.apk_rollback', (string) $releaseId, ['version' => $release['version'], 'sha256' => $release['sha256'], 'download_url' => $release['download_url']]);
                flash('Android client rolled back to ' . $release['version'] . '.');
            }
        } catch (Throwable $e) {
            flash('Error: ' . $e->getMessage(), 'error');
        }
        \mucho7_admin_header('Location:/admin/?page=securitycenter');
        exit;
    }
}
/* ============================================================
   HELPERS
============================================================ */
function muchoMonitoringFileAge(string $path)
{
    if (!file_exists($path)) {
        return null;
    }
    $mtime = filemtime($path);
    if ($mtime === false) {
        return null;
    }
    return max(0, time() - $mtime);
}
function muchoMonitoringHumanAge($seconds): string
{
    if ($seconds === null) {
        return 'Never';
    }
    if ($seconds < 60) {
        return $seconds . ' sec ago';
    }
    if ($seconds < 3600) {
        return floor($seconds / 60) . ' min ago';
    }
    if ($seconds < 86400) {
        return floor($seconds / 3600) . ' h ago';
    }
    return floor($seconds / 86400) . ' d ago';
}
function renderSecurityMonitoringPage(PDO $db)
{
    $canEdit = admin() && rank((string) admin()['role']) >= 30;
    /* ========================================================
          METRICS
       ========================================================= */
    $summary = ['requests' => 0, 'errors' => 0, 'rate_limited' => 0, 'avg_ms' => 0];
    if (muchoMonitoringTableExists($db, 'mucho_api_metrics_minute')) {
        $row = $db->query("\n            SELECT\n                COALESCE(\n                    SUM(requests),\n                    0\n                ) AS requests,\n\n                COALESCE(\n                    SUM(\n                        CASE\n                            WHEN status>=500\n                            THEN requests\n                            ELSE 0\n                        END\n                    ),\n                    0\n                ) AS errors,\n\n                COALESCE(\n                    SUM(\n                        CASE\n                            WHEN status=429\n                            THEN requests\n                            ELSE 0\n                        END\n                    ),\n                    0\n                ) AS rate_limited,\n\n                COALESCE(\n                    ROUND(\n                        SUM(total_ms) /\n                        NULLIF(\n                            SUM(requests),\n                            0\n                        )\n                    ),\n                    0\n                ) AS avg_ms\n\n            FROM mucho_api_metrics_minute\n\n            WHERE minute_start >=\n                DATE_SUB(\n                    NOW(),\n                    INTERVAL 60 MINUTE\n                )\n        ")->fetch();
        if ($row) {
            $summary = $row;
        }
    }
    /* ========================================================
          ACTIVE ALERTS
       ========================================================= */
    $alerts = $db->query("\n        SELECT\n            id,\n            severity,\n            source,\n            message,\n            created_at\n\n        FROM mucho_system_alerts\n\n        WHERE resolved=0\n\n        ORDER BY\n            FIELD(\n                severity,\n                'critical',\n                'warning',\n                'info'\n            ),\n            created_at DESC\n\n        LIMIT 50\n    ")->fetchAll();
    /* ========================================================
          SECURITY EVENTS
       ========================================================= */
    $events = $db->query("\n        SELECT\n            id,\n            event_type,\n            ip,\n            route,\n            request_id,\n            metadata,\n            created_at\n\n        FROM mucho_security_events\n\n        ORDER BY id DESC\n\n        LIMIT 100\n    ")->fetchAll();
    /* ========================================================
          TOP ROUTES
       ========================================================= */
    $routes = $db->query("\n        SELECT\n            route,\n            SUM(requests) AS requests,\n\n            ROUND(\n                SUM(total_ms) /\n                NULLIF(\n                    SUM(requests),\n                    0\n                )\n            ) AS avg_ms,\n\n            SUM(\n                CASE\n                    WHEN status>=400\n                    THEN requests\n                    ELSE 0\n                END\n            ) AS failed\n\n        FROM mucho_api_metrics_minute\n\n        WHERE minute_start >=\n            DATE_SUB(\n                NOW(),\n                INTERVAL 60 MINUTE\n            )\n\n        GROUP BY route\n\n        ORDER BY requests DESC\n\n        LIMIT 15\n    ")->fetchAll();
    /* ========================================================
          RELEASES
       ========================================================= */
    $releases = [];
    if (muchoMonitoringTableExists($db, 'mucho_client_release_files')) {
        $releases = $db->query("\n            SELECT\n                id,\n                version,\n                file_name,\n                size_bytes,\n                sha256,\n                download_url,\n                created_at\n\n            FROM mucho_client_release_files\n\n            WHERE platform='android'\n\n            ORDER BY id DESC\n\n            LIMIT 15\n        ")->fetchAll();
    }
    $currentRelease = null;
    if (muchoMonitoringTableExists($db, 'mucho_client_releases')) {
        $q = $db->prepare("\n            SELECT *\n            FROM mucho_client_releases\n            WHERE platform='android'\n            LIMIT 1\n        ");
        $q->execute();
        $currentRelease = $q->fetch();
    }
    /* ========================================================
          BACKUP / HEALTH
       ========================================================= */
    $healthLog = '/var/www/mucho-core/logs/health.log';
    $backupLog = '/var/www/mucho-core/logs/automatic-backup.log';
    $healthAge = muchoMonitoringFileAge($healthLog);
    $backupAge = muchoMonitoringFileAge($backupLog);
    ?>

<style>

.mc-grid{
    display:grid;
    grid-template-columns:
        repeat(
            auto-fit,
            minmax(210px,1fr)
        );
    gap:14px;
    margin-bottom:22px;
}

.mc-stat{
    padding:18px;
}

.mc-stat-value{
    font-size:28px;
    font-weight:800;
    margin-top:7px;
}

.mc-ok{
    color:#55d98d;
}

.mc-warning{
    color:#ffbf58;
}

.mc-danger{
    color:#ff6868;
}

.mc-code{
    font-family:
        ui-monospace,
        SFMono-Regular,
        Menlo,
        monospace;

    font-size:12px;
    word-break:break-all;
}

.mc-actions{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}

.mc-section{
    margin-top:28px;
}

.mc-empty{
    padding:22px;
    text-align:center;
    opacity:.65;
}

.mc-badge-critical{
    background:#6b2028 !important;
    color:#ffd6da !important;
}

.mc-badge-warning{
    background:#6a4a16 !important;
    color:#ffe1a3 !important;
}

</style>


<div class="row"
     style="
        justify-content:space-between;
        align-items:flex-start;
        margin-bottom:18px
     ">

    <div>

        <h1 style="margin-bottom:5px">
            Security & Monitoring
        </h1>

        <div class="muted">
            MuchoCore v3.1 · live API health,
            security events and release control
        </div>

    </div>

    <?php 
    if ($canEdit) {
        ?>

        <div class="mc-actions">

            <form method="post">

                <input
                    type="hidden"
                    name="csrf"
                    value="<?php 
        echo h(csrf());
        ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="monitor-cleanup"
                >

                <button
                    type="submit"
                    class="gray"
                >
                    Cleanup old data
                </button>

            </form>

        </div>

    <?php 
    }
    ?>

</div>


<!-- ========================================================
     STATS
========================================================= -->

<div class="mc-grid">

    <div class="card mc-stat">

        <small>
            API REQUESTS · 60 MIN
        </small>

        <div class="mc-stat-value">
            <?php 
    echo h(number_format((int) $summary['requests']));
    ?>
        </div>

    </div>


    <div class="card mc-stat">

        <small>
            SERVER ERRORS · 60 MIN
        </small>

        <div class="mc-stat-value <?php 
    echo (int) $summary['errors'] > 0 ? 'mc-danger' : 'mc-ok';
    ?>">
            <?php 
    echo h(number_format((int) $summary['errors']));
    ?>
        </div>

    </div>


    <div class="card mc-stat">

        <small>
            RATE LIMITED · 60 MIN
        </small>

        <div class="mc-stat-value">
            <?php 
    echo h(number_format((int) $summary['rate_limited']));
    ?>
        </div>

    </div>


    <div class="card mc-stat">

        <small>
            AVG RESPONSE
        </small>

        <div class="mc-stat-value">
            <?php 
    echo h((string) (int) $summary['avg_ms']);
    ?> ms
        </div>

    </div>


    <div class="card mc-stat">

        <small>
            ACTIVE ALERTS
        </small>

        <div class="mc-stat-value <?php 
    echo count($alerts) > 0 ? 'mc-warning' : 'mc-ok';
    ?>">
            <?php 
    echo count($alerts);
    ?>
        </div>

    </div>


    <div class="card mc-stat">

        <small>
            CURRENT ANDROID
        </small>

        <div class="mc-stat-value"
             style="font-size:22px">

            <?php 
    echo h($currentRelease['current_version'] ?? '—');
    ?>

        </div>

    </div>

</div>


<!-- ========================================================
     SERVICES
========================================================= -->

<div class="mc-section">

    <h2>System Services</h2>

    <div class="mc-grid">

        <div class="card">

            <small>
                HEALTHCHECK
            </small>

            <h3>
                <?php 
    if ($healthAge !== null && $healthAge < 600) {
        ?>

                    <span class="mc-ok">
                        ● Running
                    </span>

                <?php 
    } else {
        ?>

                    <span class="mc-warning">
                        ● Check required
                    </span>

                <?php 
    }
    ?>

            </h3>

            <div class="muted">
                Last log update:
                <?php 
    echo h(muchoMonitoringHumanAge($healthAge));
    ?>
            </div>

        </div>


        <div class="card">

            <small>
                AUTOMATIC BACKUP
            </small>

            <h3>
                <?php 
    if ($backupAge !== null && $backupAge < 172800) {
        ?>

                    <span class="mc-ok">
                        ● Active
                    </span>

                <?php 
    } else {
        ?>

                    <span class="mc-warning">
                        ● Not confirmed
                    </span>

                <?php 
    }
    ?>

            </h3>

            <div class="muted">
                Last log update:
                <?php 
    echo h(muchoMonitoringHumanAge($backupAge));
    ?>
            </div>

        </div>


        <div class="card">

            <small>
                REQUEST ID
            </small>

            <h3 class="mc-ok">
                ● Enabled
            </h3>

            <div class="muted">
                Every API v2 response is traceable.
            </div>

        </div>


        <div class="card">

            <small>
                RATE LIMITING
            </small>

            <h3 class="mc-ok">
                ● Enabled
            </h3>

            <div class="muted">
                Central API protection active.
            </div>

        </div>

    </div>

</div>


<!-- ========================================================
     ALERTS
========================================================= -->

<div class="mc-section">

    <div class="row"
         style="justify-content:space-between">

        <h2>
            Active Alerts
        </h2>

        <?php 
    if ($canEdit && count($alerts) > 0) {
        ?>

            <form method="post">

                <input
                    type="hidden"
                    name="csrf"
                    value="<?php 
        echo h(csrf());
        ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="monitor-alert-resolve-all"
                >

                <button
                    type="submit"
                    class="gray"
                >
                    Resolve all
                </button>

            </form>

        <?php 
    }
    ?>

    </div>


    <div class="table">

        <table>

            <thead>
                <tr>
                    <th>Severity</th>
                    <th>Source</th>
                    <th>Message</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>

            <tbody>

            <?php 
    if (!$alerts) {
        ?>

                <tr>
                    <td
                        colspan="5"
                        class="mc-empty"
                    >
                        No active alerts.
                    </td>
                </tr>

            <?php 
    }
    ?>


            <?php 
    foreach ($alerts as $alert) {
        ?>

                <tr>

                    <td>

                        <span class="badge <?php 
        echo $alert['severity'] === 'critical' ? 'mc-badge-critical' : ($alert['severity'] === 'warning' ? 'mc-badge-warning' : '');
        ?>">
                            <?php 
        echo h(strtoupper($alert['severity']));
        ?>
                        </span>

                    </td>

                    <td>
                        <?php 
        echo h($alert['source']);
        ?>
                    </td>

                    <td>
                        <?php 
        echo h($alert['message']);
        ?>
                    </td>

                    <td class="muted">
                        <?php 
        echo h($alert['created_at']);
        ?>
                    </td>

                    <td>

                    <?php 
        if ($canEdit) {
            ?>

                        <form method="post">

                            <input
                                type="hidden"
                                name="csrf"
                                value="<?php 
            echo h(csrf());
            ?>"
                            >

                            <input
                                type="hidden"
                                name="action"
                                value="monitor-alert-resolve"
                            >

                            <input
                                type="hidden"
                                name="alert_id"
                                value="<?php 
            echo h((string) $alert['id']);
            ?>"
                            >

                            <button
                                type="submit"
                                class="green"
                            >
                                Resolve
                            </button>

                        </form>

                    <?php 
        }
        ?>

                    </td>

                </tr>

            <?php 
    }
    ?>

            </tbody>

        </table>

    </div>

</div>


<!-- ========================================================
     TOP ROUTES
========================================================= -->

<div class="mc-section">

    <h2>
        API Routes · Last 60 Minutes
    </h2>

    <div class="table">

        <table>

            <thead>
                <tr>
                    <th>Route</th>
                    <th>Requests</th>
                    <th>Failed</th>
                    <th>Avg</th>
                </tr>
            </thead>

            <tbody>

            <?php 
    if (!$routes) {
        ?>

                <tr>
                    <td
                        colspan="4"
                        class="mc-empty"
                    >
                        Waiting for API metrics…
                    </td>
                </tr>

            <?php 
    }
    ?>


            <?php 
    foreach ($routes as $route) {
        ?>

                <tr>

                    <td class="mc-code">
                        <?php 
        echo h($route['route']);
        ?>
                    </td>

                    <td>
                        <?php 
        echo h(number_format((int) $route['requests']));
        ?>
                    </td>

                    <td>
                        <?php 
        echo h(number_format((int) $route['failed']));
        ?>
                    </td>

                    <td>
                        <?php 
        echo h((string) (int) $route['avg_ms']);
        ?> ms
                    </td>

                </tr>

            <?php 
    }
    ?>

            </tbody>

        </table>

    </div>

</div>


<!-- ========================================================
     SECURITY EVENTS
========================================================= -->

<div class="mc-section">

    <h2>
        Security Events
    </h2>

    <div class="table">

        <table>

            <thead>
                <tr>
                    <th>Type</th>
                    <th>IP</th>
                    <th>Route</th>
                    <th>Request ID</th>
                    <th>Date</th>
                </tr>
            </thead>

            <tbody>

            <?php 
    if (!$events) {
        ?>

                <tr>
                    <td
                        colspan="5"
                        class="mc-empty"
                    >
                        No security events.
                    </td>
                </tr>

            <?php 
    }
    ?>


            <?php 
    foreach ($events as $event) {
        ?>

                <tr>

                    <td>
                        <b>
                            <?php 
        echo h($event['event_type']);
        ?>
                        </b>
                    </td>

                    <td class="mc-code">
                        <?php 
        echo h($event['ip']);
        ?>
                    </td>

                    <td class="mc-code">
                        <?php 
        echo h($event['route']);
        ?>
                    </td>

                    <td class="mc-code">
                        <?php 
        echo h($event['request_id']);
        ?>
                    </td>

                    <td class="muted">
                        <?php 
        echo h($event['created_at']);
        ?>
                    </td>

                </tr>

            <?php 
    }
    ?>

            </tbody>

        </table>

    </div>

</div>


<!-- ========================================================
     RELEASE ROLLBACK
========================================================= -->

<div class="mc-section">

    <h2>
        Android Release Control
    </h2>


    <?php 
    if ($currentRelease) {
        ?>

        <div
            class="card"
            style="margin-bottom:14px"
        >

            <small>
                ACTIVE RELEASE
            </small>

            <h2 style="margin:6px 0">
                Mucho GDPS
                <?php 
        echo h($currentRelease['current_version'] ?? '—');
        ?>
            </h2>

            <?php 
        if (!empty($currentRelease['sha256'])) {
            ?>

                <div class="muted mc-code">
                    SHA-256:
                    <?php 
            echo h($currentRelease['sha256']);
            ?>
                </div>

            <?php 
        }
        ?>

        </div>

    <?php 
    }
    ?>


    <div class="table">

        <table>

            <thead>
                <tr>
                    <th>ID</th>
                    <th>Version</th>
                    <th>Size</th>
                    <th>SHA-256</th>
                    <th>Date</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>

            <?php 
    if (!$releases) {
        ?>

                <tr>
                    <td
                        colspan="6"
                        class="mc-empty"
                    >
                        No Android release history yet.
                    </td>
                </tr>

            <?php 
    }
    ?>


            <?php 
    foreach ($releases as $release) {
        ?>

                <?php 
        $isCurrent = $currentRelease && (string) ($currentRelease['sha256'] ?? '') !== '' && hash_equals(strtolower((string) $currentRelease['sha256']), strtolower((string) $release['sha256']));
        ?>

                <tr>

                    <td>
                        #<?php 
        echo h((string) $release['id']);
        ?>
                    </td>

                    <td>

                        <b>
                            <?php 
        echo h($release['version']);
        ?>
                        </b>

                        <?php 
        if ($isCurrent) {
            ?>

                            <span
                                class="badge green"
                                style="margin-left:6px"
                            >
                                CURRENT
                            </span>

                        <?php 
        }
        ?>

                    </td>

                    <td>
                        <?php 
        echo h(number_format((int) $release['size_bytes'] / 1024 / 1024, 1));
        ?> MB
                    </td>

                    <td
                        class="mc-code"
                        title="<?php 
        echo h($release['sha256']);
        ?>"
                    >
                        <?php 
        echo h(substr($release['sha256'], 0, 16));
        ?>…
                    </td>

                    <td class="muted">
                        <?php 
        echo h($release['created_at']);
        ?>
                    </td>

                    <td>

                        <?php 
        if ($isCurrent) {
            ?>

                            <span class="muted">
                                Active
                            </span>

                        <?php 
        } elseif ($canEdit) {
            ?>

                            <form
                                method="post"
                                onsubmit="
                                    return confirm(
                                        'Rollback Mucho GDPS to version <?php 
            echo h($release['version']);
            ?>?'
                                    );
                                "
                            >

                                <input
                                    type="hidden"
                                    name="csrf"
                                    value="<?php 
            echo h(csrf());
            ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="monitor-release-rollback"
                                >

                                <input
                                    type="hidden"
                                    name="release_id"
                                    value="<?php 
            echo h((string) $release['id']);
            ?>"
                                >

                                <button
                                    type="submit"
                                    class="red"
                                >
                                    Rollback
                                </button>

                            </form>

                        <?php 
        }
        ?>

                    </td>

                </tr>

            <?php 
    }
    ?>

            </tbody>

        </table>

    </div>

</div>

    <?php 
}
