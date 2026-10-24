<?php declare(strict_types=1); ?>
<section class="card"><h2>Очередь задач</h2>
<p>Обновление поискового индекса и обслуживание выполняются небольшими порциями. Повторяй запуск, пока очередь не опустеет.</p>
<p>Ожидают запуска: <?=(int)$db->query("SELECT COUNT(*) FROM mucho_jobs WHERE status='queued' AND job_type IN ('level.index','maintenance.cleanup') AND available_at<=UTC_TIMESTAMP()")->fetchColumn()?>.</p>
<?php if(isset($_SESSION['shared_worker_result'])):$work=$_SESSION['shared_worker_result'];unset($_SESSION['shared_worker_result']); ?>
<p role="status"><?=!empty($work['busy'])?'Очередь уже выполняется.':'Выполнено: '.(int)$work['done'].'. Отложено после ошибки: '.(int)$work['failed'].'.'?></p>
<?php endif ?>
<form method="post"><?php mucho7_admin_csrf(); ?><input type="hidden" name="action" value="shared-worker-run"><button type="submit">Выполнить порцию задач</button></form>
</section>
