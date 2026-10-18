<?php
declare(strict_types=1);

// Monitoring is read-only and permission-gated by the admin controller.
// Host-level log access remains restricted to system administrators.
$canReadHostLogs=canPermission('system.manage');
?>
<section class="admin-monitor-guide card" aria-labelledby="monitorIntro">
    <h2 id="monitorIntro">Server diagnostics</h2>
    <p class="muted">Health checks report what PHP can actually observe. A responding Geometry Dash endpoint is not proof that login or gameplay succeeds. Unknown means not measured — not offline.</p>
    <div class="section-actions">
        <button type="button" id="v5RefreshHealth">Refresh checks</button>
        <span id="v5LastChecked" class="muted" role="status" aria-live="polite">No check completed yet.</span>
    </div>
</section>
<div class="admin-monitor-grid">
    <section class="card" aria-labelledby="monitorServicesLabel">
        <h2 id="monitorServicesLabel">Services</h2>
        <div id="v5Services" role="status">Checking connectivity…</div>
    </section>
    <section class="card" aria-labelledby="monitorEndpointsLabel">
        <h2 id="monitorEndpointsLabel">API responses</h2>
        <div id="v5Endpoints" role="status">Waiting for probe results…</div>
    </section>
</div>
<section class="card" aria-labelledby="monitorAbuseLabel">
    <h2 id="monitorAbuseLabel">Possible stat anomalies</h2>
    <p class="muted">These are thresholds for manual review, not evidence of cheating or automatic bans.</p>
    <button type="button" class="gray" id="v5RefreshAbuse">Review stats</button>
    <div id="v5Abuse" role="status">Waiting for results…</div>
</section>
<section class="card" aria-labelledby="monitorLogsLabel">
    <h2 id="monitorLogsLabel">Host logs</h2>
    <?php if ($canReadHostLogs): ?>
    <p class="muted">Available only when the VPS has the legacy log helper. On Docker installs, use <code>sudo mucho logs</code> over SSH.</p>
    <div class="admin-monitor-toolbar">
        <label for="v5LogType">Log source</label>
        <select id="v5LogType">
            <option value="php">PHP-FPM (legacy)</option>
            <option value="nginx">Nginx error (legacy)</option>
            <option value="access">Nginx access (legacy)</option>
            <option value="cloudflare">Cloudflare (legacy)</option>
        </select>
        <button type="button" id="v5RefreshLogs">Refresh logs</button>
        <label><input type="checkbox" id="v5AutoLogs"> Auto-refresh logs</label>
    </div>
    <pre id="v5Logs" tabindex="0" role="status">Select Refresh logs to load output.</pre>
    <?php else: ?>
    <p class="muted" role="status">Host logs are restricted to system administrators. Health diagnostics remain available.</p>
    <?php endif; ?>
</section>
<noscript><p class="card">Automatic checks require JavaScript. On your VPS, use <code>sudo mucho doctor</code> or <code>sudo mucho logs</code>.</p></noscript>
<script src="/admin/admin-monitoring.js?v=1" defer></script>
