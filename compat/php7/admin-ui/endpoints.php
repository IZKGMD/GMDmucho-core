<?php

$result=$_SESSION['endpoint_result'] ?? null;
unset($_SESSION['endpoint_result']);
?>
<p class="mc-page-lead">Send a request through the local endpoint handler and inspect its response.</p>
<div class="mc-two-panel">
<section class="card">
<div class="mc-section-label">Request</div>
<h2>Endpoint tester</h2>
<form method="post">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="endpoint-test">
<input type="hidden" name="return" value="endpoints">
<div class="mc-form-field">
<label for="endpointPath">Endpoint path</label>
<input id="endpointPath" name="endpoint" value="/getGJLevels21.php" required spellcheck="false" autocomplete="off">
<small>Enter an endpoint path, for example /getGJLevels21.php.</small>
</div>
<div class="mc-form-field">
<label for="endpointPayload">POST payload</label>
<textarea id="endpointPayload" class="mc-api-payload" name="payload" placeholder="type=0&amp;page=0" spellcheck="false"></textarea>
<small>Use form data: key=value pairs separated by &amp;.</small>
</div>
<div class="mc-form-actions"><button type="submit">Send locally</button></div>
</form>
</section>
<section class="card mc-api-response" aria-live="polite">
<div class="mc-section-label">Output</div>
<?php if($result!==null): ?>
<h2>Response</h2>
<pre><?=h($result)?></pre>
<?php else: ?>
<h2>Response</h2>
<div class="mc-empty-panel"><strong>Ready for a request</strong><p>The handler response will appear here.</p></div>
<?php endif; ?>
</section>
</div>
<?php
