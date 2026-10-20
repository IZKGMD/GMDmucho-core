<?php
/**
 * Read-only VPS diagnostics for symptoms in GD 2.209:
 * Weekly/Event spinner, quest timers, chest unlocks, native News.
 * Never prints passwords, tokens, account data, user names or SQL errors.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (in_array('--help', $argv ?? [], true)) {
    echo "Usage: php tools/diagnostics/gd2209-feature-check.php\n";
    echo "Read-only checks: daily/weekly/event, quests, rewards and native news.\n";
    exit(0);
}
require $root . '/vendor/autoload.php';

use MuchoCore\Core\Environment;
use MuchoCore\Database\Database;

$report = ['description'=>'GD 2.209 read-only GDPS feature audit', 'checks'=>[]];
$add = static function (string $name, bool $ok, string $message) use (&$report): void {
    $report['checks'][$name] = ['ok'=>$ok,'message'=>$message];
};

try {
    $pdo = (new Database())->connection();
    $add('database', true, 'Connected to configured GDPS database.');
} catch (Throwable $error) {
    $add('database', false, 'Database unavailable; check service/database configuration.');
    echo json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
    exit(1);
}

foreach (['daily','weekly','event'] as $kind) {
    try {
        $query = $pdo->prepare(
            'SELECT COUNT(*) FROM mucho_daily_rotation
             WHERE kind=? AND enabled=1
               AND starts_at<=UTC_TIMESTAMP() AND ends_at>UTC_TIMESTAMP()'
        );
        $query->execute([$kind]);
        $active = (int)$query->fetchColumn();
        $add($kind.'_rotation',$active>0,
            $active>0 ? "Active ".$kind." rotation found." :
              "No active ".$kind." rotation: getGJDailyLevel type ".
              ($kind==='daily'?'0':($kind==='weekly'?'1':'2')).
              " returns -1; configure a schedule and eligible level.");
    } catch (Throwable $error) {
        $add($kind.'_rotation',false,'Rotation table is unavailable; run schema migrations.');
    }
}

try {
    $active = (int)$pdo->query(
        'SELECT COUNT(*) FROM mucho_challenge_pool WHERE active=1'
    )->fetchColumn();
    $add('quests',$active>=3,
        $active>=3 ? "Quest pool has ".$active." active challenges." :
        "Only ".$active." active challenges; getGJChallenges requires at least 3.");
} catch (Throwable $error) {
    $add('quests',false,'Quest pool table missing or inaccessible.');
}

try {
    $pdo->query('SELECT account_id FROM mucho_reward_state LIMIT 1')->fetchColumn();
    $add('rewards',true,'Rewards state table exists; verify auth and chk with native client.');
} catch (Throwable $error) {
    $add('rewards',false,'Rewards state table unavailable.');
}

$enabled = (string)(Environment::get('MUCHO_NATIVE_NEWS','0')??'0')==='1';
$add('native_news_flag',$enabled,
    $enabled ? 'MUCHO_NATIVE_NEWS=1 is enabled in PHP runtime.' :
    'MUCHO_NATIVE_NEWS=0; game still uses default CDN, not MuchoCore News Board.');
try {
    $version = $pdo->query(
        'SELECT version FROM mucho_news_state WHERE id=1'
    )->fetchColumn();
    $add('native_news_version',$version!==false,
        $version!==false ? 'Native news revision state exists.' :
        'Native news revision missing; run migrations.');
} catch (Throwable $error) {
    $add('native_news_version',false,'Native news revision table missing.');
}
try {
    $published = (int)$pdo->query(
        'SELECT COUNT(*) FROM mucho_news_posts
         WHERE is_published=1 AND published_at IS NOT NULL'
    )->fetchColumn();
    $add('news_posts',$published>0,
        $published>0 ? "Published news posts: ".$published."." :
        'No published news; publish a post in the owner News Board.');
} catch (Throwable $error) {
    $add('news_posts',false,'News posts table missing.');
}
$failed = array_filter($report['checks'], static function (array $item): bool {
    return !$item['ok'];
});
$report['status'] = $failed === [] ? 'checks_passed_not_client_certified' :
    'configuration_gaps_found';
echo json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
exit($failed === [] ? 0 : 2);
