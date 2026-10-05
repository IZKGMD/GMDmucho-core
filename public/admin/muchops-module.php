<?php
declare(strict_types=1);

use MuchoCore\Monitoring\ControlPlaneSnapshot;

$rootDir = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 2);
$controlDir = defined('CONTROL_DIR') ? CONTROL_DIR : $rootDir . '/storage/control';
$opsSnapshot = (new ControlPlaneSnapshot($db, $rootDir, $controlDir))->snapshot();

$opsNum = static fn(int $value): string => number_format($value);

$opsAge = static function (?int $seconds): string {
    if ($seconds === null) return 'No data';
    if ($seconds < 60) return $seconds . 's ago';
    if ($seconds < 3600) return intdiv($seconds, 60) . 'm ago';
    return intdiv($seconds, 3600) . 'h ago';
};

$opsApi = $opsSnapshot['api'] ?? [];
$opsJobs = $opsSnapshot['jobs'] ?? [];
$opsAlerts = $opsSnapshot['alerts'] ?? [];
$opsSecurity = $opsSnapshot['security'] ?? [];
$opsClients = $opsSnapshot['clients'] ?? [];
$opsSearch = $opsSnapshot['search'] ?? [];
$opsBackups = $opsSnapshot['backups'] ?? [];
$opsLogs = $opsSnapshot['logs'] ?? [];
$opsAutomation = $opsSnapshot['automation'] ?? [];
$opsServices = $opsSnapshot['services'] ?? [];
$opsMigrations = $opsSnapshot['migrations'] ?? [];
$opsDatabase = $opsSnapshot['database'] ?? [];

$opsMaintenance = (bool)($opsSnapshot['maintenance'] ?? false);
$opsRegistrationsDisabled = (bool)($opsSnapshot['registrations_disabled'] ?? false);
$opsCriticalAlerts = 0;

foreach (($opsAlerts['items'] ?? []) as $alert) {
    if (($alert['severity'] ?? '') === 'critical') {
        $opsCriticalAlerts++;
    }
}

$opsCriticalServices = 0;
$opsWarningServices = 0;

foreach ($opsServices as $service) {
    if (($service['status'] ?? '') === 'critical') {
        $opsCriticalServices++;
    } elseif (($service['status'] ?? '') === 'warning') {
        $opsWarningServices++;
    }
}

$opsState = 'healthy';
$opsStateLabel = 'Operational';

if ($opsCriticalAlerts > 0 || $opsCriticalServices > 0 || !($opsDatabase['ok'] ?? false)) {
    $opsState = 'critical';
    $opsStateLabel = 'Attention required';
} elseif ($opsMaintenance) {
    $opsState = 'maintenance';
    $opsStateLabel = 'Maintenance mode';
} elseif ($opsWarningServices > 0 || ($opsAlerts['active'] ?? 0) > 0) {
    $opsState = 'warning';
    $opsStateLabel = 'Warnings present';
}
?>
<style>
.ops-shell{display:grid;gap:14px}
.ops-hero{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;padding:20px;border:1px solid var(--border);border-radius:16px;background:radial-gradient(circle at 82% 18%,rgba(119,100,255,.18),transparent 38%),linear-gradient(145deg,#121826,#0e131c)}
.ops-hero h2{margin:0 0 7px;font-size:22px}.ops-hero p{margin:0;color:var(--muted);max-width:780px;line-height:1.55}
.ops-state{min-width:170px;text-align:center;padding:15px 17px;border:1px solid var(--border);border-radius:13px;background:#0a0f17}.ops-state b{display:block;font-size:17px;margin-top:5px}
.ops-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:13px}
.ops-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:10px}
.ops-stat{padding:15px;border:1px solid var(--border);border-radius:13px;background:var(--card,#111620)}
.ops-stat small,.ops-section-label{display:block;text-transform:uppercase;letter-spacing:.07em;font-size:10px;color:#8995aa}
.ops-stat-value{font-size:25px;font-weight:850;margin-top:6px;letter-spacing:-.02em}.ops-stat-value.good{color:var(--green)}.ops-stat-value.bad{color:var(--red)}.ops-stat-value.warn{color:#ffd78b}
.ops-stat-meta{font-size:11px;color:var(--muted);margin-top:4px}
.ops-columns{display:grid;grid-template-columns:minmax(0,1.18fr) minmax(320px,.82fr);gap:14px;align-items:start}.ops-stack{display:grid;gap:14px}
.ops-title{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:10px}.ops-title h2{margin:0;font-size:16px}
.ops-live{font-size:10px;color:#8f9bb0;text-transform:uppercase;letter-spacing:.08em}
.ops-pill{display:inline-flex;align-items:center;gap:4px;padding:4px 8px;border-radius:999px;background:#202938;font-size:10px;border:1px solid transparent}.ops-pill.green{background:#174432;color:#79e9b2}.ops-pill.amber{background:#4e3a1b;color:#ffd78b}.ops-pill.red{background:#4a2029;color:#ffadb7}
.ops-kv{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.ops-kv>div{border:1px solid #202938;border-radius:10px;padding:11px;background:#0b1018}.ops-kv b{display:block;margin-top:4px;font-size:13px;word-break:break-word}
.ops-table{width:100%;border-collapse:collapse}.ops-table th,.ops-table td{text-align:left;padding:8px 7px;border-bottom:1px solid #202836;font-size:11px;vertical-align:top}.ops-table th{color:#7e8ba1;font-size:10px;text-transform:uppercase}.ops-table tr:last-child td{border-bottom:0}
.ops-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}.ops-muted{color:#7f8ba0}.ops-empty{padding:18px;text-align:center;color:#788498}
.ops-alert{border:1px solid #342a24;background:#12131a;border-radius:10px;padding:10px;margin-top:8px}.ops-alert-critical{border-color:#5b2932;background:#181016}.ops-alert-warning{border-color:#534321;background:#17140d}.ops-alert-info{border-color:#29394c}
.ops-event{padding:9px 0;border-bottom:1px solid #202836}.ops-event:last-child{border-bottom:0}
.ops-service-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.ops-service{display:flex;gap:9px;align-items:flex-start;padding:11px;border:1px solid #202938;border-radius:10px;background:#0b1018}.ops-dot{width:9px;height:9px;border-radius:50%;margin-top:4px;flex:none;background:#788498}.ops-dot.green{background:#4dd393}.ops-dot.amber{background:#f0b94e}.ops-dot.red{background:#e46676}.ops-service b{display:block;font-size:12px}.ops-service span{display:block;margin-top:3px;font-size:10px;color:var(--muted)}
.ops-action-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px}.ops-action{border:1px solid #202938;border-radius:11px;padding:12px;background:#0b1018}.ops-action h3{margin:0 0 5px;font-size:12px}.ops-action p{margin:0 0 10px;color:var(--muted);font-size:10px;line-height:1.4}.ops-action form{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.ops-action input[type=number]{width:74px}.ops-warning{margin-top:9px;padding:9px 10px;border:1px solid #534321;border-radius:9px;background:#17140d;color:#d5c38d;font-size:10px;line-height:1.45}
@media(max-width:980px){.ops-columns{grid-template-columns:1fr}.ops-action-grid{grid-template-columns:1fr}}@media(max-width:650px){.ops-hero{display:block}.ops-state{margin-top:14px}.ops-kv,.ops-service-grid{grid-template-columns:1fr}.ops-stat-value{font-size:23px}}
</style>

<div class="ops-shell">
<section class="ops-hero">
    <div>
        <span class="hero-eyebrow">MUCHOCORE OPERATIONS</span>
        <h2>MuchoOps Control Center</h2>
        <p>
            A production control plane for server health, runtime services,
            API traffic, background work, security, backups, search,
            migrations, automation and client releases.
        </p>
        <div class="ops-actions">
            <a class="btn" href="/admin/?page=monitoring">Monitoring</a>
            <a class="btn gray" href="/admin/?page=securitycenter">Security</a>
            <a class="btn gray" href="/admin/?page=dbbackups">Backups</a>
            <a class="btn gray" href="/admin/?page=clients">Clients</a>
            <a class="btn gray" href="/admin/?page=updates">Core Updates</a>
        </div>
    </div>
    <div class="ops-state">
        <span class="ops-pill <?=match($opsState){'healthy'=>'green','warning','maintenance'=>'amber',default=>'red'}?>">
            ● <?=h(strtoupper($opsState))?>
        </span>
        <b><?=h($opsStateLabel)?></b>
        <small>Snapshot <?=h((string)($opsSnapshot['snapshot_ms'] ?? 0))?> ms</small>
    </div>
</section>

<div class="ops-grid">
    <div class="ops-stat"><small>Accounts</small><div class="ops-stat-value"><?=$opsNum((int)($opsSnapshot['counts']['accounts'] ?? 0))?></div><div class="ops-stat-meta">registered accounts</div></div>
    <div class="ops-stat"><small>Levels</small><div class="ops-stat-value"><?=$opsNum((int)($opsSnapshot['counts']['levels'] ?? 0))?></div><div class="ops-stat-meta">stored level records</div></div>
    <div class="ops-stat"><small>API · 60 min</small><div class="ops-stat-value"><?=$opsNum((int)($opsApi['requests'] ?? 0))?></div><div class="ops-stat-meta"><?=h((string)($opsApi['avg_ms'] ?? 0))?> ms average</div></div>
    <div class="ops-stat"><small>API errors</small><div class="ops-stat-value <?=((int)($opsApi['errors'] ?? 0)>0)?'bad':'good'?>"><?=$opsNum((int)($opsApi['errors'] ?? 0))?></div><div class="ops-stat-meta"><?=$opsNum((int)($opsApi['rate_limited'] ?? 0))?> rate-limited requests</div></div>
    <div class="ops-stat"><small>Jobs</small><div class="ops-stat-value <?=((int)($opsJobs['failed_24h'] ?? 0)>0)?'warn':'good'?>"><?=$opsNum((int)($opsJobs['running'] ?? 0))?></div><div class="ops-stat-meta"><?=$opsNum((int)($opsJobs['queued'] ?? 0))?> queued · <?=$opsNum((int)($opsJobs['failed_24h'] ?? 0))?> failed/24h</div></div>
    <div class="ops-stat"><small>Alerts</small><div class="ops-stat-value <?=((int)($opsAlerts['active'] ?? 0)>0)?'bad':'good'?>"><?=$opsNum((int)($opsAlerts['active'] ?? 0))?></div><div class="ops-stat-meta"><?=$opsNum($opsCriticalAlerts)?> critical</div></div>
    <div class="ops-stat"><small>Security · 24h</small><div class="ops-stat-value"><?=$opsNum((int)($opsSecurity['last_24h'] ?? 0))?></div><div class="ops-stat-meta">recorded security events</div></div>
    <div class="ops-stat"><small>Search coverage</small><div class="ops-stat-value <?=((int)($opsSearch['coverage_percent'] ?? 0)>=95)?'good':'warn'?>"><?=$opsNum((int)($opsSearch['coverage_percent'] ?? 0))?>%</div><div class="ops-stat-meta"><?=$opsNum((int)($opsSearch['indexed'] ?? 0))?> / <?=$opsNum((int)($opsSearch['levels'] ?? 0))?> indexed</div></div>
</div>

<?php if (canPermission('system.manage')): ?>
<section class="card">
    <div class="ops-title"><h2>Control Actions</h2><span class="ops-live">privileged operations</span></div>
    <div class="ops-action-grid">
        <div class="ops-action">
            <h3>Maintenance mode</h3>
            <p>Block game API traffic immediately while keeping the admin control plane available.</p>
            <form method="post">
                <input type="hidden" name="csrf" value="<?=h(csrf())?>">
                <input type="hidden" name="action" value="ops-maintenance">
                <input type="hidden" name="enabled" value="<?=$opsMaintenance ? '0' : '1'?>">
                <button class="<?=$opsMaintenance ? 'green' : 'red'?>" type="submit">
                    <?=$opsMaintenance ? 'Disable maintenance' : 'Enable maintenance'?>
                </button>
            </form>
        </div>
        <div class="ops-action">
            <h3>Registrations</h3>
            <p>Pause only new account registration without interrupting existing players.</p>
            <form method="post">
                <input type="hidden" name="csrf" value="<?=h(csrf())?>">
                <input type="hidden" name="action" value="ops-registrations">
                <input type="hidden" name="enabled" value="<?=$opsRegistrationsDisabled ? '0' : '1'?>">
                <button class="<?=$opsRegistrationsDisabled ? 'green' : 'red'?>" type="submit">
                    <?=$opsRegistrationsDisabled ? 'Enable registrations' : 'Disable registrations'?>
                </button>
            </form>
        </div>
        <div class="ops-action">
            <h3>Recover stale jobs</h3>
            <p>Requeue jobs that have been stuck in the running state longer than the selected window.</p>
            <form method="post">
                <input type="hidden" name="csrf" value="<?=h(csrf())?>">
                <input type="hidden" name="action" value="ops-retry-stale-jobs">
                <label class="ops-code" for="ops-stale-minutes">Minutes</label>
                <input id="ops-stale-minutes" type="number" name="minutes" min="1" max="1440" value="10">
                <button class="gray" type="submit">Recover</button>
            </form>
        </div>
    </div>
    <?php if ($opsMaintenance): ?>
        <div class="ops-warning"><b>Maintenance is active.</b> The public game API returns the legacy unavailable response. Admin pages remain accessible.</div>
    <?php endif; ?>
</section>
<?php endif; ?>

<div class="ops-columns">
<div class="ops-stack">
    <section class="card">
        <div class="ops-title"><h2>Service Health</h2><span class="ops-live"><?=count($opsServices)?> checks</span></div>
        <div class="ops-service-grid">
            <?php foreach ($opsServices as $service): ?>
                <?php $serviceStatus=(string)($service['status']??'warning'); ?>
                <div class="ops-service">
                    <span class="ops-dot <?=match($serviceStatus){'healthy'=>'green','warning'=>'amber','critical'=>'red',default=>''}?>"></span>
                    <div>
                        <b><?=h((string)($service['label']??'Service'))?></b>
                        <span><?=h((string)($service['detail']??'No status detail'))?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card">
        <div class="ops-title"><h2>Runtime & System</h2><span class="ops-live">local instance</span></div>
        <?php $opsRuntime=$opsSnapshot['runtime']??[]; ?>
        <div class="ops-kv">
            <div><small>Core</small><b>v<?=h((string)($opsSnapshot['version']??'unknown'))?></b></div>
            <div><small>Transport</small><b><?=h((string)($opsSnapshot['transport']??'direct'))?></b></div>
            <div><small>Domain</small><b><?=h((string)($opsSnapshot['domain']?:'not configured'))?></b></div>
            <div><small>PHP</small><b><?=h((string)($opsRuntime['php_version']??'unknown'))?></b></div>
            <div><small>Database server</small><b><?=h((string)($opsRuntime['database_server']??'unknown'))?></b></div>
            <div><small>Disk free</small><b><?=h((string)($opsRuntime['disk_free_percent']??'—'))?><?=($opsRuntime['disk_free_percent']??null)!==null?'%':''?></b></div>
            <div><small>Maintenance</small><b class="<?=($opsMaintenance?'warn':'good')?>"><?=($opsMaintenance?'Enabled':'Disabled')?></b></div>
            <div><small>Registrations</small><b class="<?=($opsRegistrationsDisabled?'warn':'good')?>"><?=($opsRegistrationsDisabled?'Disabled':'Open')?></b></div>
        </div>
    </section>

    <section class="card">
        <div class="ops-title"><h2>Background Jobs</h2><span class="ops-live">recent activity</span></div>
        <div class="ops-kv">
            <div><small>Queued</small><b><?=$opsNum((int)($opsJobs['queued']??0))?></b></div>
            <div><small>Running</small><b><?=$opsNum((int)($opsJobs['running']??0))?></b></div>
            <div><small>Completed · 24h</small><b><?=$opsNum((int)($opsJobs['done_24h']??0))?></b></div>
            <div><small>Failed · 24h</small><b class="<?=((int)($opsJobs['failed_24h']??0)>0)?'bad':'good'?>"><?=$opsNum((int)($opsJobs['failed_24h']??0))?></b></div>
        </div>
        <?php if(!empty($opsJobs['recent'])): ?>
            <div class="table"><table class="ops-table"><thead><tr><th>Job</th><th>Status</th><th>Attempts</th><th>Worker</th><th>Updated</th></tr></thead><tbody>
            <?php foreach($opsJobs['recent'] as $job): ?>
                <tr>
                    <td><b class="ops-code"><?=h((string)$job['job_type'])?></b><div class="ops-muted">#<?=h((string)$job['id'])?></div></td>
                    <td><span class="ops-pill <?=match((string)$job['status']){'done'=>'green','failed'=>'red','running'=>'amber',default=>''}?>"><?=h((string)$job['status'])?></span></td>
                    <td><?=h((string)$job['attempts'])?></td>
                    <td><?=h((string)($job['worker_id']??'—'))?></td>
                    <td><?=h((string)$job['updated_at'])?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php else: ?><div class="ops-empty">No background jobs recorded.</div><?php endif; ?>
    </section>

    <section class="card">
        <div class="ops-title"><h2>API Performance</h2><span class="ops-live">top routes · 60 minutes</span></div>
        <?php if(!empty($opsApi['routes'])): ?>
            <div class="table"><table class="ops-table"><thead><tr><th>Route</th><th>Requests</th><th>Failed</th><th>Avg</th></tr></thead><tbody>
            <?php foreach($opsApi['routes'] as $route): ?>
                <tr><td class="ops-code"><?=h((string)$route['route'])?></td><td><?=$opsNum((int)$route['requests'])?></td><td class="<?=((int)$route['failed']>0)?'bad':'good'?>"><?=$opsNum((int)$route['failed'])?></td><td><?=h((string)$route['avg_ms'])?> ms</td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php else: ?><div class="ops-empty">No API metrics are available yet.</div><?php endif; ?>
    </section>
</div>

<div class="ops-stack">
    <section class="card">
        <div class="ops-title"><h2>Active Alerts</h2><span class="ops-pill <?=((int)($opsAlerts['active']??0)>0)?'red':'green'?>"><?=((int)($opsAlerts['active']??0)>0)?$opsNum((int)$opsAlerts['active']).' active':'clear'?></span></div>
        <?php if(!empty($opsAlerts['items'])): foreach($opsAlerts['items'] as $alert): ?>
            <div class="ops-alert ops-alert-<?=h((string)$alert['severity'])?>">
                <div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start">
                    <div><b><?=h(strtoupper((string)$alert['severity']))?> · <?=h((string)$alert['source'])?></b><div style="margin-top:4px"><?=h((string)$alert['message'])?></div><small><?=h((string)$alert['created_at'])?></small></div>
                    <?php if (canPermission('system.manage')): ?>
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?=h(csrf())?>">
                            <input type="hidden" name="action" value="ops-resolve-alert">
                            <input type="hidden" name="id" value="<?=h((string)$alert['id'])?>">
                            <button class="gray" type="submit">Resolve</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; else: ?><div class="ops-empty">No unresolved alerts.</div><?php endif; ?>
    </section>

    <section class="card">
        <div class="ops-title"><h2>Security Activity</h2><a href="/admin/?page=securitycenter">Open →</a></div>
        <?php if(!empty($opsSecurity['types'])): foreach($opsSecurity['types'] as $type): ?>
            <div class="ops-event"><b><?=h((string)$type['event_type'])?></b><span class="ops-muted"> · <?=$opsNum((int)$type['total'])?></span></div>
        <?php endforeach; else: ?><div class="ops-empty">No security events recorded.</div><?php endif; ?>
    </section>

    <section class="card">
        <div class="ops-title"><h2>Client Releases</h2><a href="/admin/?page=clients">Open →</a></div>
        <?php if(!empty($opsClients['releases'])): foreach($opsClients['releases'] as $release): ?>
            <div class="ops-event">
                <b><?=h(strtoupper((string)$release['platform']))?> · v<?=h((string)$release['current_version'])?></b>
                <div><small>Minimum v<?=h((string)$release['minimum_version'])?> · <?=((int)$release['maintenance']===1)?'maintenance':'live'?></small></div>
            </div>
        <?php endforeach; else: ?><div class="ops-empty">No client release metadata.</div><?php endif; ?>
    </section>

    <section class="card">
        <div class="ops-title"><h2>Migration Readiness</h2><a href="/admin/?page=migration">Migration Center →</a></div>
        <div class="ops-kv">
            <div><small>Migration files</small><b><?=$opsNum((int)($opsMigrations['total']??0))?></b></div>
            <div><small>Applied</small><b><?= $opsNum((int)($opsMigrations['applied']??0))?></b></div>
            <div><small>Pending</small><b class="<?=((int)($opsMigrations['pending']??0)>0)?'bad':'good'?>"><?=$opsNum((int)($opsMigrations['pending']??0))?></b></div>
            <div><small>Latest schema</small><b><?=h((string)($opsMigrations['latest']??'No migrations'))?></b></div>
        </div>
        <div style="margin-top:10px" class="ops-muted">Latest applied: <?=h((string)($opsMigrations['latest_applied']??'None recorded'))?></div>
    </section>

    <section class="card">
        <div class="ops-title"><h2>Automation</h2><a href="/admin/?page=automation">Automation Center →</a></div>
        <div class="ops-kv">
            <div><small>Schedules</small><b><?=$opsNum((int)($opsAutomation['enabled']??0))?> / <?=$opsNum((int)($opsAutomation['total']??0))?></b></div>
            <div><small>Heartbeat</small><b class="<?=((int)($opsAutomation['heartbeat_age_seconds']??PHP_INT_MAX)<120)?'good':'bad'?>"><?=h($opsAge($opsAutomation['heartbeat_age_seconds']??null))?></b></div>
        </div>
        <div style="margin-top:9px" class="ops-muted"><?=h((string)($opsAutomation['heartbeat']['scheduler_id']??'Scheduler has not reported a heartbeat yet.'))?></div>
    </section>

    <section class="card">
        <div class="ops-title"><h2>Search & Backups</h2><a href="/admin/?page=dbbackups">Backup Center →</a></div>
        <div class="ops-kv">
            <div><small>Search coverage</small><b><?=$opsNum((int)($opsSearch['coverage_percent']??0))?>%</b></div>
            <div><small>Revisions · 24h</small><b><?=$opsNum((int)($opsSearch['revisions_24h']??0))?></b></div>
            <div><small>Health log</small><b><?=h($opsAge($opsLogs['health_age_seconds']??null))?></b></div>
            <div><small>Backup log</small><b><?=h($opsAge($opsLogs['backup_age_seconds']??null))?></b></div>
        </div>
        <?php if(!empty($opsBackups['last'])): ?>
            <div style="margin-top:10px">
                <span class="ops-pill <?=((int)$opsBackups['last']['gzip_valid']===1 && (int)$opsBackups['last']['sql_valid']===1)?'green':'red'?>">
                    <?=((int)$opsBackups['last']['gzip_valid']===1 && (int)$opsBackups['last']['sql_valid']===1)?'VERIFIED':'CHECK REQUIRED'?>
                </span>
                <span class="ops-muted" style="margin-left:7px"><?=h((string)$opsBackups['last']['file_name'])?></span>
            </div>
        <?php endif; ?>
    </section>
</div>
</div>

<div class="ops-live-refresh" style="text-align:right;color:#69778d;font-size:10px">
    Last snapshot: <?=h((string)($opsSnapshot['generated_at']??'unknown'))?> · auto-refresh every 15 seconds.
</div>
</div>

<script>
(() => {
    let refreshing = false;

    const refresh = async () => {
        if (refreshing || document.visibilityState === 'hidden') return;
        refreshing = true;

        try {
            const response = await fetch(
                '/admin/?page=ops&ops_feed=1&t=' + Date.now(),
                {
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {'Accept': 'application/json'}
                }
            );

            if (response.ok) {
                const data = await response.json();
                if (data.ok) {
                    window.location.reload();
                    return;
                }
            }
        } catch {}

        refreshing = false;
        window.setTimeout(refresh, 15000);
    };

    window.setTimeout(refresh, 15000);
})();
</script>
