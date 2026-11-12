<?php
declare(strict_types=1);

require __DIR__ . '/../../public/admin/pages/dashboard.php';

// Render the real dashboard under restricted roles. No production DB or sessions.
final class DashboardTestPdo extends PDO
{
    public array $queries = [];
    public function __construct() {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->queries[] = $query;
        throw new RuntimeException('Fixture database unavailable');
    }
}
$allowedPages = [];
$allowedPermissions = [];
$countedTables = [];
function canAdminPage(string $page): bool { global $allowedPages; return in_array($page, $allowedPages, true); }
function canPermission(string $permission): bool { global $allowedPermissions; return in_array($permission, $allowedPermissions, true); }
function countTable(PDO $db, string $table): int { global $countedTables; $countedTables[] = $table; throw new RuntimeException('Fixture database unavailable'); }
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function rootOp(string $operation): string { throw new RuntimeException('Server operation must not run for this role'); }
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function renderDashboard(PDO $db): string { ob_start(); renderAdminDashboard($db); return (string)ob_get_clean(); }

$db = new DashboardTestPdo();
$html = renderDashboard($db);
check($db->queries === [] && $countedTables === [], 'viewer does not query unauthorized player or music data');
check(!str_contains($html, 'page=players') && !str_contains($html, 'page=songs'), 'viewer receives no unauthorized management links');
check(!str_contains($html, 'auditLiveState') && !str_contains($html, 'admin-server-card'), 'viewer receives no audit feed or server operation');
check(str_contains($html, 'href="/dashboard"'), 'viewer can open the public player portal');
check(!str_contains($html, 'mc-ops-actions'), 'unprivileged viewers do not see restricted quick tasks');
check(!str_contains($html, 'mc-ops-queue') && !str_contains($html, 'Latest uploads'),
    'unprivileged viewer sees no review queues or uploaded levels');


$allowedPages = ['songs'];
$html = renderDashboard($db);
check($countedTables === ['songs'], 'music manager only counts authorized music data');
check(str_contains($html, 'Latest music') && !str_contains($html, 'New players'), 'music manager sees the correct dashboard feed');
check(!str_contains($html, 'page=players') && str_contains($html, 'page=songs'), 'music manager links respect page permissions');
check(str_contains($html, '—') && str_contains($html, 'Verification unavailable'), 'database failure remains visible instead of becoming a zero count');
check(!str_contains($html, 'LEVEL MODERATION') && str_contains($html, 'MUSIC MODERATION'),
    'music-only reviewer never sees level moderation queue');

$allowedPages = ['levels'];
$html = renderDashboard($db);
check(str_contains($html, 'Latest uploads') && !str_contains($html, 'Latest music'),
    'level manager sees fresh uploads without unauthorized music data');
check(!str_contains($html, 'LEVEL MODERATION'),
    'level viewing alone does not reveal rating permissions');

$allowedPages = ['rating'];
$html = renderDashboard($db);
check(str_contains($html, 'LEVEL MODERATION') && str_contains($html, 'Queue unavailable'),
    'rating staff can open the queue and a database error never looks like zero requests');
check(!str_contains($html, 'MUSIC MODERATION') && !str_contains($html, 'Latest uploads'),
    'rating-only staff do not inherit level browsing or music access');


$allowedPages = ['news', 'rating', 'dbbackups'];
$html = renderDashboard($db);
check(str_contains($html, 'page=news') && str_contains($html, 'page=rating') && str_contains($html, 'page=dbbackups'),
    'quick actions link directly to authorized day-to-day operations');
check(!str_contains($html, 'page=clientpatcher') && !str_contains($html, 'page=settings'),
    'quick actions do not reveal any unauthorized tool links');
check(str_contains($html, 'mc-ops-actions'), 'operator actions have a visible, accessible dashboard section');

$allowedPages = [];
$allowedPermissions = ['audit.view'];
$html = renderDashboard($db);
check(str_contains($html, 'auditLiveState') && str_contains($html, '/admin/product-dashboard.js'), 'audit permission enables the live controller');
check(!str_contains($html, 'admin-server-card'), 'audit access does not grant server operations');
