<?php

declare(strict_types=1);

use MuchoCore\Job\AutomationScheduler;

requirePermission('automation.view');

$scheduler = new AutomationScheduler($db);
$schedules = $scheduler->schedules();
$heartbeat = $scheduler->heartbeat();

$intervalLabel = static function (int $seconds): string {
    if ($seconds % 86400 === 0) {
        return intdiv($seconds, 86400) . 'd';
    }
    if ($seconds % 3600 === 0) {
        return intdiv($seconds, 3600) . 'h';
    }
    return intdiv($seconds, 60) . 'm';
};

$heartbeatAge = null;
if (is_array($heartbeat) && ($heartbeat['ticked_at'] ?? '') !== '') {
    $timestamp = strtotime((string)$heartbeat['ticked_at']);
    if ($timestamp !== false) {
        $heartbeatAge = max(0, time() - $timestamp);
    }
}
?>

<style>
.mc-auto-head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;padding:20px;margin-bottom:14px;border:1px solid var(--border);border-radius:16px;background:linear-gradient(145deg,#121827,#0d121a)}
.mc-auto-head h2{margin:0 0 7px;font-size:22px}.mc-auto-head p{margin:0;max-width:760px;color:var(--muted);line-height:1.5}
.mc-auto-status{min-width:175px;padding:14px;border:1px solid var(--border);border-radius:12px;text-align:center;background:#090e15}.mc-auto-status b{display:block;margin-top:5px}
.mc-auto-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}
.mc-auto-card{padding:16px}
.mc-auto-name{font-size:15px;font-weight:800}.mc-auto-desc{margin-top:4px;color:var(--muted);line-height:1.45;font-size:12px}
.mc-auto-meta{display:flex;flex-wrap:wrap;gap:7px;margin-top:12px}
.mc-auto-pill{padding:4px 8px;border-radius:999px;background:#202938;font-size:10px}
.mc-auto-pill.green{background:#174432;color:#79e9b2}.mc-auto-pill.red{background:#4a2029;color:#ffadb7}.mc-auto-pill.amber{background:#4e3a1b;color:#ffd78b}
.mc-auto-form{margin-top:15px;padding-top:12px;border-top:1px solid #202836}
.mc-auto-form .row{gap:8px}.mc-auto-form input[type=number]{width:110px}
@media(max-width:650px){.mc-auto-head{display:block}.mc-auto-status{margin-top:12px}}
</style>

<section class="mc-auto-head">
    <div>
        <span class="hero-eyebrow">MUCHOCORE AUTOMATION</span>
        <h2>Automation Center</h2>
        <p>
            Run safe recurring maintenance tasks through the MuchoCore worker
            queue. Schedules never execute arbitrary PHP or SQL; only
            registered job types can be emitted.
        </p>
    </div>
    <div class="mc-auto-status">
        <small>SCHEDULER HEARTBEAT</small>
        <b class="<?=($heartbeatAge !== null && $heartbeatAge < 120) ? 'ok' : 'warning'?>">
            <?=($heartbeatAge !== null && $heartbeatAge < 120) ? '● Healthy' : '● Check required'?>
        </b>
        <small>
            <?=h($heartbeatAge === null ? 'No heartbeat yet' : 'Updated ' . $heartbeatAge . 's ago')?>
        </small>
    </div>
</section>

<div class="mc-auto-grid">
<?php foreach ($schedules as $schedule): ?>
    <section class="card mc-auto-card">
        <div class="mc-auto-name"><?=h((string)$schedule['name'])?></div>
        <div class="mc-auto-desc"><?=h((string)$schedule['description'])?></div>

        <div class="mc-auto-meta">
            <span class="mc-auto-pill <?=($schedule['enabled'] ? 'green' : '')?>">
                <?=($schedule['enabled'] ? 'Enabled' : 'Disabled')?>
            </span>
            <span class="mc-auto-pill">
                Every <?=h($intervalLabel((int)$schedule['interval_seconds']))?>
            </span>
            <span class="mc-auto-pill">
                <?=h((string)$schedule['job_type'])?>
            </span>
        </div>

        <div style="margin-top:11px;font-size:11px;color:var(--muted)">
            Next: <?=h((string)$schedule['next_run_at'])?>
            <?php if ($schedule['last_enqueued_at']): ?>
                · Last queued: <?=h((string)$schedule['last_enqueued_at'])?>
            <?php endif; ?>
        </div>

        <?php if (canPermission('automation.manage')): ?>
            <form class="mc-auto-form" method="post">
                <input type="hidden" name="csrf" value="<?=h(csrf())?>">
                <input type="hidden" name="action" value="automation-save">
                <input type="hidden" name="return" value="automation">
                <input type="hidden" name="code" value="<?=h((string)$schedule['code'])?>">
                <div class="row">
                    <label>
                        <input type="checkbox" name="enabled" <?=$schedule['enabled'] ? 'checked' : ''?>>
                        Enabled
                    </label>
                    <label>
                        Every
                        <input
                            type="number"
                            name="interval_minutes"
                            min="1"
                            max="43200"
                            value="<?=h((string)max(1, intdiv((int)$schedule['interval_seconds'], 60)))?>"
                        >
                        min
                    </label>
                    <button class="gray" type="submit">Save</button>
                </div>
            </form>
        <?php endif; ?>
    </section>
<?php endforeach; ?>
</div>

<section class="card" style="margin-top:14px">
    <h2 style="margin-top:0">Operational model</h2>
    <table class="table">
        <thead><tr><th>Stage</th><th>Behavior</th></tr></thead>
        <tbody>
            <tr><td>Schedule due</td><td>Scheduler acquires a database advisory lock and selects due enabled schedules.</td></tr>
            <tr><td>Queue</td><td>A registered safe job type is inserted into <code>mucho_jobs</code>.</td></tr>
            <tr><td>Execution</td><td>The background worker reserves, retries and completes the job.</td></tr>
            <tr><td>Health</td><td>The scheduler writes a heartbeat after every successful tick.</td></tr>
            <tr><td>Failure</td><td>Unknown schedule/job types are skipped; worker failures use normal retry limits.</td></tr>
        </tbody>
    </table>
</section>
