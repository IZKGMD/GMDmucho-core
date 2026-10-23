<?php


$result=$_SESSION['endpoint_result'] ?? null;
unset($_SESSION['endpoint_result']);

?>
<div class="card">
<form method="post">

<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="endpoint-test">
<input type="hidden" name="return" value="endpoints">

<div>
<small>Endpoint</small><br>
<input
 style="width:100%;margin-top:5px"
 name="endpoint"
 value="/getGJLevels21.php"
>
</div>

<div style="margin-top:12px">
<small>POST payload</small>
<textarea name="payload" placeholder="type=0&page=0"></textarea>
</div>

<button>Send locally</button>
</form>

<?php if($result!==null): ?>
<h2>Response</h2>
<pre><?=h($result)?></pre>
<?php endif ?>
</div>
<?php

