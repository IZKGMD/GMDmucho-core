<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function assertAutomation(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$files = [
    'src/Job/AutomationScheduler.php' => 'scheduler',
    'bin/mucho-scheduler.php' => 'cli',
    'bin/mucho-worker.php' => 'worker',
    'database/migrations/20261004_001_automation_center.php' => 'migration',
    'docker-compose.yml' => 'compose',
    'install.sh' => 'installer',
    'bin/mucho' => 'operator cli',
    'public/admin/pages/automation.php' => 'admin page',
    'public/admin/actions/automation.php' => 'admin action',
];

$text = [];
foreach ($files as $relative => $label) {
    $path = $root . '/' . $relative;
    assertAutomation(is_file($path), 'Missing Automation Center ' . $label . ': ' . $relative);
    $text[$relative] = (string)file_get_contents($path);
}

$scheduler = $text['src/Job/AutomationScheduler.php'];
$migration = $text['database/migrations/20261004_001_automation_center.php'];
$worker = $text['bin/mucho-worker.php'];
$compose = $text['docker-compose.yml'];
$installer = $text['install.sh'];
$cli = $text['bin/mucho'];
$page = $text['public/admin/pages/automation.php'];
$action = $text['public/admin/actions/automation.php'];

assertAutomation(str_contains($scheduler, 'muchocore:automation-scheduler'), 'Scheduler advisory lock is missing.');
assertAutomation(str_contains($scheduler, 'beginTransaction()'), 'Scheduler must enqueue/update schedules atomically.');
assertAutomation(str_contains($scheduler, 'mucho_automation_heartbeat'), 'Scheduler heartbeat persistence is missing.');
assertAutomation(str_contains($scheduler, 'maintenance.cleanup'), 'Maintenance cleanup schedule is not registered.');
assertAutomation(str_contains($scheduler, 'security.cleanup'), 'Security cleanup schedule is not registered.');
assertAutomation(str_contains($migration, 'mucho_automation_schedules'), 'Automation schedule table is missing.');
assertAutomation(str_contains($migration, 'mucho_automation_heartbeat'), 'Automation heartbeat table is missing.');
assertAutomation(str_contains($worker, "case 'security.cleanup':"), 'Security cleanup worker job is missing.');
assertAutomation(str_contains($compose, '    scheduler:'), 'Production compose does not run the scheduler.');
assertAutomation(str_contains($installer, 'expected_services=(db app worker scheduler caddy)'), 'Installer does not expect the scheduler service.');
assertAutomation(str_contains($cli, 'automation)'), 'Operator CLI does not expose automation command.');
assertAutomation(str_contains($page, 'Automation Center'), 'Automation Center page is incomplete.');
assertAutomation(str_contains($page, 'Registered safe job types'), 'Automation Center safety explanation is missing.');
assertAutomation(str_contains($action, "requirePermission('automation.manage')"), 'Automation action is not permission protected.');

echo "automation-center-contract: OK\n";
