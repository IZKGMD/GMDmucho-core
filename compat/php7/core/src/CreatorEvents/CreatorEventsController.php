<?php

declare (strict_types=1);
namespace MuchoCore\CreatorEvents;

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Core\Environment;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use MuchoCore\Security\RateLimiter;
use PDO;
use Throwable;
final class CreatorEventsController
{
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }
    public function handle(Request $r): Response
    {
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        if (!in_array($r->method, ['GET', 'POST'], true)) {
            return Response::text('Method not allowed.', 405);
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_secure', ($r->server['HTTPS'] ?? '') === 'on' ? '1' : '0');
            ini_set('session.cookie_samesite', 'Lax');
            session_name('MUCHO_CREATOR');
            session_start();
        }
        if (empty($_SESSION['creator_csrf'])) {
            $_SESSION['creator_csrf'] = bin2hex(random_bytes(32));
        }
        $service = new CreatorEventsService($this->db);
        $message = '';
        $status = 200;
        if (!$service->enabled()) {
            return Response::text('Creator Events is unavailable.', 404);
        }
        if ($r->method === 'POST') {
            try {
                if (!hash_equals($_SESSION['creator_csrf'], $r->postString('csrf'))) {
                    throw new \RuntimeException('Refresh this page before submitting.');
                }
                if (Environment::get('MUCHO_CREATOR_WRITES', '1') !== '1') {
                    throw new \RuntimeException('Submissions are temporarily read-only.');
                }
                $limit = new RateLimiter(Environment::get('MUCHO_ACCOUNT_RATE_DIR'));
                if (!$limit->allowStrict('creator-ip|' . $r->clientIp(), 20, 900)) {
                    throw new \RuntimeException('Too many attempts. Try again later.');
                }
                $s = $this->db->prepare('SELECT account_id FROM accounts WHERE username=?');
                $s->execute([$r->postString('username')]);
                $id = (int) $s->fetchColumn();
                try {
                    (new AccountAuthenticator($this->db))->authenticate($id, $r->postString('password'));
                } catch (Throwable $__mcCaught) {
                    throw new \RuntimeException('Sign-in details are invalid.');
                }
                if (!$limit->allowStrict('creator-account|' . $id, 20, 900)) {
                    throw new \RuntimeException('Too many attempts. Try again later.');
                }
                $event = $r->postInt('event');
                $action = $r->postString('operation');
                if ($action === 'submit') {
                    $service->submit($event, $id, $r->postInt('level'));
                } elseif ($action === 'withdraw') {
                    $service->withdraw($event, $id);
                } elseif ($action === 'score') {
                    $service->score($event, $r->postInt('entry'), $id, $r->postInt('design', -1), $r->postInt('gameplay', -1), $r->postInt('originality', -1), $r->postString('note'));
                } else {
                    throw new \RuntimeException('Unknown action.');
                }
                $_SESSION['creator_csrf'] = bin2hex(random_bytes(32));
                $message = 'Saved successfully.';
            } catch (\RuntimeException $e) {
                $message = $e instanceof \PDOException ? 'The request could not be saved. Please try again.' : $e->getMessage();
                $status = 400;
            } catch (Throwable $__mcCaught) {
                $message = 'The request could not be saved. Please try again.';
                $status = 503;
            }
        }
        $csrf = self::h($_SESSION['creator_csrf']);
        session_write_close();
        $base = self::h((string) Environment::get('MUCHO_PUBLIC_ASSET_BASE', ''));
        $body = '<h1>Creator Events</h1><p>Build, submit and compete. Deadlines are shown in UTC. Each author may submit one public level per contest; resubmitting replaces it before the deadline.</p>';
        if ($message !== '') {
            $body .= '<p role="status">' . self::h($message) . '</p>';
        }
        foreach ($service->events() as $event) {
            $id = (int) $event['id'];
            $body .= '<section class="card"><h2>' . self::h($event['title']) . '</h2><p>' . nl2br(self::h($event['rules'])) . '</p><p>' . self::h($event['state']) . ' · Deadline: ' . self::h($event['deadline']) . ' UTC · Required reviews: ' . (int) $event['min_judges'] . '</p><table><tr><th>Entry</th><th>Author</th><th>Level</th><th>Result</th></tr>';
            foreach ($service->entries($id) as $entry) {
                $body .= '<tr><td>#' . (int) $entry['id'] . '</td><td>' . self::h($entry['username']) . '</td><td>' . self::h($entry['level_name']) . ' #' . (int) $entry['level_id'] . '</td><td>' . ((int) $entry['disqualified'] ? 'Disqualified: ' . self::h($entry['disqualification_note']) : ($event['state'] === 'published' ? '#' . (int) $entry['result_rank'] . ' · ' . self::h($entry['result_score']) . '/30' : 'Pending')) . '</td></tr>';
            }
            $body .= '</table>';
            if (in_array($event['state'], ['open', 'judging'], true)) {
                $body .= '<details><summary>' . ($event['state'] === 'open' ? 'Submit or withdraw your entry' : 'Judge an entry') . '</summary><form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="event" value="' . $id . '"><label>Username<input name="username" autocomplete="username" maxlength="20" required></label><label>Password<input type="password" name="password" autocomplete="current-password" maxlength="256" required></label>';
                if ($event['state'] === 'open') {
                    $body .= '<label>Level ID<input type="number" name="level" min="1"></label><button name="operation" value="submit">Submit / replace</button><button name="operation" value="withdraw">Withdraw</button>';
                } else {
                    $body .= '<label>Entry ID<input type="number" name="entry" min="1" required></label>';
                    foreach (['design', 'gameplay', 'originality'] as $field) {
                        $body .= '<label>' . ucfirst($field) . ' (0–10)<input type="number" name="' . $field . '" min="0" max="10" required></label>';
                    }
                    $body .= '<label>Review note<textarea name="note" maxlength="1000"></textarea></label><button name="operation" value="score">Save review</button>';
                }
                $body .= '</form></details>';
            }
            $body .= '</section>';
        }
        return new Response('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Creator Events · MuchoCore</title><link rel="stylesheet" href="' . $base . '/recovery/recovery.css"><link rel="stylesheet" href="' . $base . '/muchocore-theme.css"><link rel="stylesheet" href="' . $base . '/assets/creator-events.css?v=1"></head><body><main class="shell">' . $body . '</main></body></html>', $status, 'text/html; charset=utf-8');
    }
    private static function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    private $db;
}
