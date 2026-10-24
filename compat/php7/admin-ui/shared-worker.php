<?php declare(strict_types=1); ?>
<section class="card"><h2>Task queue</h2>
<p>Search indexing and maintenance run in small batches. Repeat until the queue is empty.</p>
<p>Ready to run: <?=(int)$db->query("SELECT COUNT(*) FROM mucho_jobs WHERE status='queued' AND job_type IN ('level.index','maintenance.cleanup') AND available_at<=UTC_TIMESTAMP()")->fetchColumn()?>.</p>
<?php if(isset($_SESSION['shared_worker_result'])):$work=$_SESSION['shared_worker_result'];unset($_SESSION['shared_worker_result']); ?>
<p role="status"><?=!empty($work['busy'])?'The queue is already running.':'Completed: '.(int)$work['done'].'. Scheduled for retry: '.(int)$work['failed'].'.'?></p>
<?php endif ?>
<form method="post"><?php mucho7_admin_csrf(); ?><input type="hidden" name="action" value="shared-worker-run"><button type="submit">Run a batch of tasks</button></form>
<details><summary>Automatic tasks and daily database backups</summary>
<p>Add this command to your hosting control panel cron scheduler and run it every minute. It processes queued local tasks, performs hourly maintenance, and creates a verified database backup at most once every 24 hours.</p>
<pre><?=h('php '.escapeshellarg($root.'/compat/php7/cron.php').' --backup')?></pre>
<p>Use the CLI PHP path provided by your host if it differs from <code>php</code>. No public URL, token, VPS, or running browser is required. Existing backups are preserved; monitor your hosting storage quota.</p>
</details>
</section>
