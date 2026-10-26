<?php
declare(strict_types=1);

/* CLI regression: single-page admin lists must not render an orphaned "1". */
function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function mucho7_ui_substr($value, $start, $length, $encoding='UTF-8') {
    return function_exists('mb_substr') ? mb_substr($value,$start,$length,$encoding) : substr($value,$start,$length);
}
require dirname(__DIR__,2).'/compat/php7/admin-ui/list-ui.php';

function assertPager(array $state, bool $hasMore, array $mustHave, array $mustNotHave): void {
    ob_start();
    adminListPager('songs', $state, $hasMore);
    $html=(string)ob_get_clean();
    foreach ($mustHave as $needle) {
        if (strpos($html,$needle)===false) throw new RuntimeException('Expected: '.$needle.'; HTML: '.$html);
    }
    foreach ($mustNotHave as $needle) {
        if (strpos($html,$needle)!==false) throw new RuntimeException('Unexpected: '.$needle.'; HTML: '.$html);
    }
}
$base=['q'=>'','filter'=>'','p'=>1];
assertPager($base,false,[],['admin-pagination','>1<']);
assertPager($base,true,['admin-pagination','Page 1','Next','aria-current="page"'],['Previous']);
assertPager(['q'=>'','filter'=>'','p'=>2],false,['Previous','Page 2'],['Next']);
assertPager(['q'=>'','filter'=>'pending','p'=>2],true,['Previous','Page 2','Next','filter=pending'],[]);
echo "admin pager: OK\n";
