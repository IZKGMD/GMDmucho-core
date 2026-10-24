<?php declare(strict_types=1);if(!$user||$user['role']!=='owner')return; ?>
<section class="card"><h2>External notifications</h2>
<p>Queue server events for an HTTPS webhook endpoint you control. Delivery runs through the shared task queue; configure provider cron for automatic delivery.</p>
<form method="post"><?php mucho7_admin_csrf(); ?><input type="hidden" name="action" value="webhook-settings">
<p><label><input type="checkbox" name="enable_webhooks" value="1"<?=!empty($settings['enable_webhooks'])?' checked':''?>> Send queued events to this endpoint</label></p>
<p><label for="sharedWebhookUrl">HTTPS webhook URL</label><br><input id="sharedWebhookUrl" name="webhook_url" type="url" maxlength="2048" value="<?=h(isset($settings['webhook_url'])?$settings['webhook_url']:'')?>" placeholder="https://hooks.example.com/events"></p>
<p><label for="sharedWebhookEvents">Events (* or comma-separated names)</label><br><input id="sharedWebhookEvents" name="webhook_events" maxlength="1024" value="<?=h(isset($settings['webhook_events'])?$settings['webhook_events']:'*')?>"></p>
<p><label for="sharedWebhookSecret">Signing secret (leave empty to keep the current secret)</label><br><input id="sharedWebhookSecret" name="webhook_secret" type="password" autocomplete="new-password" maxlength="512"></p>
<p><label><input type="checkbox" name="clear_webhook_secret" value="1"> Clear the stored signing secret</label></p>
<button type="submit">Save notification settings</button></form></section>
