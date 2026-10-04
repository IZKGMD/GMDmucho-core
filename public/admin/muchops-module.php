<?php
declare(strict_types=1);

use MuchoCore\Monitoring\ControlPlaneSnapshot;

$rootDir = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 2);
$controlDir = defined('CONTROL_DIR') ? CONTROL_DIR : $rootDir . '/storage/control';
$opsSnapshot = (new ControlPlaneSnapshot($db, $rootDir, $controlDir))->snapshot();
$opsNum = static fn(int $v): string => number_format($v);
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
$opsDbOk = (bool)($opsSnapshot['database']['ok'] ?? false);
$opsAlertCount = (int)($opsAlerts['active'] ?? 0);
$opsHealthy = $opsDbOk && $opsAlertCount === 0 && !(bool)($opsSnapshot['maintenance'] ?? false);

if (!empty($_GET['ops_feed'])) {
    if (!canPermission('monitoring.view')) {
        http_response_code(403);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(['ok' => true, 'snapshot' => $opsSnapshot], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}
?>
<style>
.ops-hero{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;padding:20px;margin-bottom:14px;border:1px solid var(--border);border-radius:16px;background:radial-gradient(circle at 80% 20%,rgba(119,100,255,.16),transparent 38%),linear-gradient(145deg,#121826,#0e131c)}
.ops-hero h2{margin:0 0 7px;font-size:22px}
.ops-hero p{margin:0;color:var(--muted);max-width:780px;line-height:1.5}
.ops-actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:13px}
.ops-state{min-width:155px;text-align:center;padding:15px 17px;border:1px solid var(--border);border-radius:13px;background:#0a0f17}
.ops-state b{display:block;font-size:17px;margin-top:5px}
.ops-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:14px}
.ops-stat{padding:15px;border:1px solid var(--border);border-radius:13px;background:var(--card,#111620)}
.ops-stat small{display:block;text-transform:uppercase;letter-spacing:.07em;font-size:10px}
.ops-stat-value{font-size:25px;font-weight:850;margin-top:6px}
.ops-stat-meta{font-size:11px;color:var(--muted);margin-top:4px}
.ops-columns{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(320px,.8fr);gap:14px;align-items:start}
.ops-stack{display:grid;gap:14px}
.ops-title{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:10px}
.ops-title h2{margin:0;font-size:16px}
.ops-live{font-size:10px;color:#8f9bb0;text-transform:uppercase;letter-spacing:.08em}
.ops-pill{display:inline-flex;padding:4px 8px;border-radius:999px;background:#202938;font-size:10px}
.ops-pill.green{background:#174432;color:#79e9b2}.ops-pill.amber{background:#4e3a1b;color:#ffd78b}.ops-pill.red{background:#4a2029;color:#ffadb7}
.ops-kv{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}
.ops-kv>div{border:1px solid #202938;border-radius:10px;padding:11px;background:#0b1018}
.ops-kv b{display:block;margin-top:4px;font-size:13px;word-break:break-word}
.ops-table{width:100%;border-collapse:collapse}.ops-table th,.ops-table td{text-align:left;padding:8px 7px;border-bottom:1px solid #202836;font-size:11px;vertical-align:top}.ops-table th{color:#7e8ba1;font-size:10px;text-transform:uppercase}.ops-table tr:last-child td{border-bottom:0}
.ops-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}.ops-muted{color:#7f8ba0}.ops-empty{padding:18px;text-align:center;color:#788498}
.ops-alert{border:1px solid #342a24;background:#12131a;border-radius:10px;padding:10px;margin-top:8px}.ops-alert-critical{border-color:#5b2932;background:#181016}.ops-alert-warning{border-color:#534321;background:#17140d}
.ops-event{padding:9px 0;border-bottom:1px solid #202836}.ops-event:last-child{border-bottom:0}
@media(max-width:980px){.ops-columns{grid-template-columns:1fr}}@media(max-width:600px){.ops-hero{display:block}.ops-state{margin-top:14px}.ops-kv{grid-template-columns:1fr}}
</style>
<section class="ops-hero">
  <div>
    <span class="hero-eyebrow">MUCHOCORE OPERATIONS</span>
    <h2>MuchoOps Control Plane</h2>
    <p>One operational view for production health, API traffic, background jobs, security events, backups, search coverage and client releases.</p>
    <div class="ops-actions">
      <a class="btn" href="/admin/?page=monitoring">Monitoring</a>
      <a class="btn gray" href="/admin/?page=securitycenter">Security</a>
      <a class="btn gray" href="/admin/?page=dbbackups">Backups</a>
      <a class="btn gray" href="/admin/?page=clients">Clients</a>
      <a class="btn gray" href="/admin/?page=updates">Core Updates</a>
    </div>
  </div>
  <div class="ops-state">
    <span class="ops-pill <?= $opsHealthy ? 'green' : 'red' ?>">● <?= $opsHealthy ? 'HEALTHY' : 'ATTENTION' ?></span>
    <b><?= $opsHealthy ? 'Operational' : 'Attention required' ?></b>
    <small>Snapshot <?=h((string)($opsSnapshot['snapshot_ms'] ?? 0))?> ms</small>
  </div>
</section>

<div class="ops-grid">
  <div class="ops-stat"><small>Players</small><div class="ops-stat-value"><?=$opsNum((int)$opsSnapshot['counts']['accounts'])?></div><div class="ops-stat-meta">registered accounts</div></div>
  <div class="ops-stat"><small>Levels</small><div class="ops-stat-value"><?=$opsNum((int)$opsSnapshot['counts']['levels'])?></div><div class="ops-stat-meta">published records</div></div>
  <div class="ops-stat"><small>API · 60 min</small><div class="ops-stat-value"><?=$opsNum((int)($opsApi['requests'] ?? 0))?></div><div class="ops-stat-meta"><?=h((string)($opsApi['avg_ms'] ?? 0))?> ms average</div></div>
  <div class="ops-stat"><small>Errors</small><div class="ops-stat-value <?=((int)($opsApi['errors'] ?? 0)>0)?'bad':'ok'?>"><?=$opsNum((int)($opsApi['errors'] ?? 0))?></div><div class="ops-stat-meta"><?=$opsNum((int)($opsApi['rate_limited'] ?? 0))?> rate limited</div></div>
  <div class="ops-stat"><small>Jobs</small><div class="ops-stat-value"><?=$opsNum((int)($opsJobs['running'] ?? 0))?></div><div class="ops-stat-meta"><?=$opsNum((int)($opsJobs['queued'] ?? 0))?> queued</div></div>
  <div class="ops-stat"><small>Alerts</small><div class="ops-stat-value <?=($opsAlertCount>0)?'bad':'ok'?>"><?=$opsNum($opsAlertCount)?></div><div class="ops-stat-meta">unresolved</div></div>
  <div class="ops-stat"><small>Security · 24h</small><div class="ops-stat-value"><?=$opsNum((int)($opsSecurity['last_24h'] ?? 0))?></div><div class="ops-stat-meta">recorded events</div></div>
  <div class="ops-stat"><small>Search coverage</small><div class="ops-stat-value"><?=$opsNum((int)($opsSearch['coverage_percent'] ?? 0))?>%</div><div class="ops-stat-meta"><?=$opsNum((int)($opsSearch['indexed'] ?? 0))?> / <?=$opsNum((int)($opsSearch['levels'] ?? 0))?></div></div>
</div>

<div class="ops-columns">
  <div class="ops-stack">
    <section class="card"><div class="ops-title"><h2>System State</h2><span class="ops-live"><?=h((string)$opsSnapshot['generated_at'])?></span></div><div class="ops-kv">
      <div><small>Core</small><b>v<?=h((string)$opsSnapshot['version'])?></b></div>
      <div><small>Transport</small><b><?=h((string)$opsSnapshot['transport'])?></b></div>
      <div><small>Domain</small><b><?=h((string)($opsSnapshot['domain'] ?: 'not configured'))?></b></div>
      <div><small>Database</small><b class="<?= $opsDbOk ? 'ok' : 'bad' ?>"><?= $opsDbOk ? 'Connected' : 'Unavailable' ?><?php if($opsDbOk && $opsSnapshot['database']['latency_ms']!==null): ?> · <?=h((string)$opsSnapshot['database']['latency_ms'])?> ms<?php endif; ?></b></div>
      <div><small>Maintenance</small><b class="<?=($opsSnapshot['maintenance']??false)?'bad':'ok'?>"><?=($opsSnapshot['maintenance']??false)?'Enabled':'Disabled'?></b></div>
      <div><small>Registrations</small><b class="<?=($opsSnapshot['registrations_disabled']??false)?'bad':'ok'?>"><?=($opsSnapshot['registrations_disabled']??false)?'Disabled':'Open'?></b></div>
    </div></section>

    <section class="card"><div class="ops-title"><h2>Background Jobs</h2><span class="ops-live">last 24 hours</span></div>
      <div class="ops-kv"><div><small>Queued</small><b><?=$opsNum((int)($opsJobs['queued']??0))?></b></div><div><small>Running</small><b><?=$opsNum((int)($opsJobs['running']??0))?></b></div><div><small>Completed</small><b><?=$opsNum((int)($opsJobs['done_24h']??0))?></b></div><div><small>Failed</small><b class="<?=((int)($opsJobs['failed_24h']??0)>0)?'bad':'ok'?>"><?=$opsNum((int)($opsJobs['failed_24h']??0))?></b></div></div>
      <?php if(!empty($opsJobs['recent'])): ?><div class="table"><table class="ops-table"><thead><tr><th>Job</th><th>Status</th><th>Attempts</th><th>Updated</th></tr></thead><tbody>
      <?php foreach($opsJobs['recent'] as $job): ?><tr><td><b class="ops-code"><?=h((string)$job['job_type'])?></b><div class="ops-muted">#<?=h((string)$job['id'])?></div></td><td><span class="ops-pill <?=match((string)$job['status']){'failed'=>'red','running'=>'amber','done'=>'green',default=>''}?>"><?=h((string)$job['status'])?></span></td><td><?=h((string)$job['attempts'])?></td><td><?=h((string)$job['updated_at'])?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php else: ?><div class="ops-empty">No background jobs recorded.</div><?php endif; ?>
    </section>

    <section class="card"><div class="ops-title"><h2>API Performance</h2><span class="ops-live">top routes · 60 minutes</span></div>
      <?php if(!empty($opsApi['routes'])): ?><div class="table"><table class="ops-table"><thead><tr><th>Route</th><th>Requests</th><th>Failed</th><th>Avg</th></tr></thead><tbody>
      <?php foreach($opsApi['routes'] as $route): ?><tr><td class="ops-code"><?=h((string)$route['route'])?></td><td><?=$opsNum((int)$route['requests'])?></td><td class="<?=((int)$route['failed']>0)?'bad':'ok'?>"><?=$opsNum((int)$route['failed'])?></td><td><?=h((string)$route['avg_ms'])?> ms</td></tr><?php endforeach; ?>
      </tbody></table></div><?php else: ?><div class="ops-empty">No API metrics are available yet.</div><?php endif; ?>
    </section>
  </div>

  <div class="ops-stack">
    <section class="card"><div class="ops-title"><h2>Active Alerts</h2><span class="ops-pill <?=($opsAlertCount>0)?'red':'green'?>"><?=$opsAlertCount>0?$opsNum($opsAlertCount).' active':'clear'?></span></div>
      <?php if(!empty($opsAlerts['items'])): foreach($opsAlerts['items'] as $alert): ?><div class="ops-alert ops-alert-<?=h((string)$alert['severity'])?>"><b><?=h(strtoupper((string)$alert['severity']))?> · <?=h((string)$alert['source'])?></b><div style="margin-top:4px"><?=h((string)$alert['message'])?></div><small><?=h((string)$alert['created_at'])?></small></div><?php endforeach; else: ?><div class="ops-empty">No unresolved alerts.</div><?php endif; ?>
    </section>

    <section class="card"><div class="ops-title"><h2>Security Activity</h2><span class="ops-live">top event types · 24 hours</span></div>
      <?php if(!empty($opsSecurity['types'])): foreach($opsSecurity['types'] as $type): ?><div class="ops-event"><b><?=h((string)$type['event_type'])?></b><span class="ops-muted"> · <?=$opsNum((int)$type['total'])?></span></div><?php endforeach; else: ?><div class="ops-empty">No security events recorded.</div><?php endif; ?>
    </section>

    <section class="card"><div class="ops-title"><h2>Clients</h2><a href="/admin/?page=clients">Open →</a></div>
      <?php if(!empty($opsClients['releases'])): foreach($opsClients['releases'] as $release): ?><div class="ops-event"><b><?=h(strtoupper((string)$release['platform']))?></b><span class="ops-muted"> · v<?=h((string)$release['current_version'])?></span><div><small>Minimum v<?=h((string)$release['minimum_version'])?> · <?=((int)$release['maintenance']===1)?'maintenance':'live'?></small></div></div><?php endforeach; else: ?><div class="ops-empty">No client releases published yet.</div><?php endif; ?>
    </section>

    <section class="card"><div class="ops-title"><h2>Backups & Derived Data</h2><a href="/admin/?page=dbbackups">Backup Center →</a></div>
      <div class="ops-kv"><div><small>Health log</small><b><?=h($opsAge($opsLogs['health_age_seconds']??null))?></b></div><div><small>Backup log</small><b><?=h($opsAge($opsLogs['backup_age_seconds']??null))?></b></div><div><small>Search coverage</small><b><?=h((string)($opsSearch['coverage_percent']??0))?>%</b></div><div><small>Cache rows</small><b><?=$opsNum((int)($opsSearch['cache_rows']??0))?></b></div><div><small>Revisions · 24h</small><b><?=$opsNum((int)($opsSearch['revisions_24h']??0))?></b></div><div><small>Last verified</small><b><?=h((string)($opsBackups['last']['verified_at']??'No verification'))?></b></div></div>
      <?php if(!empty($opsBackups['last'])): ?><div style="margin-top:10px"><span class="ops-pill <?=((int)$opsBackups['last']['gzip_valid']===1 && (int)$opsBackups['last']['sql_valid']===1)?'green':'red'?>"><?=((int)$opsBackups['last']['gzip_valid']===1 && (int)$opsBackups['last']['sql_valid']===1)?'VERIFIED':'CHECK REQUIRED'?></span><span class="ops-muted" style="margin-left:7px"><?=h((string)$opsBackups['last']['file_name'])?></span></div><?php endif; ?>
    </section>
  </div>
</div>

<script>
(() => {
  const refresh = async () => {
    try {
      const r = await fetch('/admin/?page=ops&ops_feed=1&t=' + Date.now(), {credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
      if(!r.ok) return;
      const data = await r.json();
      if(!data.ok) return;
      document.documentElement.dataset.opsLastRefresh = data.snapshot?.generated_at || '';
      window.location.reload();
    } catch {}
  };
  window.setTimeout(refresh, 15000);
})();
</script>