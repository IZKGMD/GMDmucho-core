<?php declare(strict_types=1);
ob_start();require mucho7_compiled_include($root.'/public/admin/pages/migration.php');$migrationHtml=ob_get_clean();
$migrationHtml=preg_replace('~\s+onclick="return confirm\([^\n]*\)"~','',$migrationHtml);
$migrationHtml=str_replace('formaction="/admin/"','formaction="'.h($script).'?page=migration"',$migrationHtml);
$confirmation='<p><label for="migrationConfirm">Type MIGRATE to import data (not required for a read-only check)</label><br><input id="migrationConfirm" name="migration_confirm" autocomplete="off" maxlength="7"></p>';
echo str_replace('<div class="row" style="margin-top:14px">',$confirmation.'<div class="row" style="margin-top:14px">',$migrationHtml);
