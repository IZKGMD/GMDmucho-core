<?php

declare(strict_types=1);

namespace MuchoCore\Security;

use MuchoCore\Http\Request;

final readonly class MuchoProtect
{
    /** @var array<string, array{limit:int, window:int, burst:int, burstWindow:int}> */
    private const POLICIES = [
        '/logingjaccount' => ['limit' => 12, 'window' => 60, 'burst' => 5, 'burstWindow' => 10],
        '/registergjaccount' => ['limit' => 6, 'window' => 300, 'burst' => 2, 'burstWindow' => 30],
        '/backupgjaccount' => ['limit' => 12, 'window' => 60, 'burst' => 4, 'burstWindow' => 10],
        '/backupgjaccount20' => ['limit' => 12, 'window' => 60, 'burst' => 4, 'burstWindow' => 10],
        '/syncgjaccount' => ['limit' => 12, 'window' => 60, 'burst' => 4, 'burstWindow' => 10],
        '/syncgjaccount20' => ['limit' => 12, 'window' => 60, 'burst' => 4, 'burstWindow' => 10],

        '/uploadgjlevel21' => ['limit' => 8, 'window' => 60, 'burst' => 3, 'burstWindow' => 15],
        '/uploadgjlevel22' => ['limit' => 8, 'window' => 60, 'burst' => 3, 'burstWindow' => 15],
        '/deletegjleveluser20' => ['limit' => 20, 'window' => 60, 'burst' => 6, 'burstWindow' => 10],
        '/updategjleveldesc20' => ['limit' => 30, 'window' => 60, 'burst' => 8, 'burstWindow' => 10],
        '/uploadgjlevellist' => ['limit' => 5, 'window' => 60, 'burst' => 2, 'burstWindow' => 15],
        '/deletegjlevellist' => ['limit' => 10, 'window' => 60, 'burst' => 3, 'burstWindow' => 15],

        '/uploadgjcomment20' => ['limit' => 30, 'window' => 60, 'burst' => 8, 'burstWindow' => 10],
        '/uploadgjcomment21' => ['limit' => 30, 'window' => 60, 'burst' => 8, 'burstWindow' => 10],
        '/uploadgjacccomment20' => ['limit' => 20, 'window' => 60, 'burst' => 6, 'burstWindow' => 10],
        '/deletegjcomment20' => ['limit' => 20, 'window' => 60, 'burst' => 6, 'burstWindow' => 10],
        '/deletegjaccountcomment20' => ['limit' => 20, 'window' => 60, 'burst' => 6, 'burstWindow' => 10],

        '/uploadgjmessage20' => ['limit' => 20, 'window' => 60, 'burst' => 6, 'burstWindow' => 10],
        '/deletegjmessages20' => ['limit' => 20, 'window' => 60, 'burst' => 6, 'burstWindow' => 10],

        '/uploadfriendrequest20' => ['limit' => 30, 'window' => 60, 'burst' => 10, 'burstWindow' => 10],
        '/acceptgjfriendrequest20' => ['limit' => 30, 'window' => 60, 'burst' => 10, 'burstWindow' => 10],
        '/blockgjuser20' => ['limit' => 30, 'window' => 60, 'burst' => 10, 'burstWindow' => 10],
        '/unblockgjuser20' => ['limit' => 30, 'window' => 60, 'burst' => 10, 'burstWindow' => 10],
        '/removegjfriend20' => ['limit' => 30, 'window' => 60, 'burst' => 10, 'burstWindow' => 10],
        '/deletegjfriendrequests20' => ['limit' => 30, 'window' => 60, 'burst' => 10, 'burstWindow' => 10],
        '/readgjfriendrequest20' => ['limit' => 60, 'window' => 60, 'burst' => 15, 'burstWindow' => 10],

        '/updategjuserscore' => ['limit' => 60, 'window' => 60, 'burst' => 15, 'burstWindow' => 10],
        '/updategjuserscore22' => ['limit' => 60, 'window' => 60, 'burst' => 15, 'burstWindow' => 10],
        '/updategjaccsettings20' => ['limit' => 10, 'window' => 60, 'burst' => 3, 'burstWindow' => 15],
        '/requestuseraccess' => ['limit' => 6, 'window' => 300, 'burst' => 2, 'burstWindow' => 30],
        '/rategjstars20' => ['limit' => 40, 'window' => 60, 'burst' => 12, 'burstWindow' => 10],
        '/rategjstars211' => ['limit' => 40, 'window' => 60, 'burst' => 12, 'burstWindow' => 10],
        '/rategjdemon21' => ['limit' => 40, 'window' => 60, 'burst' => 12, 'burstWindow' => 10],
        '/suggestgjstars20' => ['limit' => 40, 'window' => 60, 'burst' => 12, 'burstWindow' => 10],
        '/reportgjlevel' => ['limit' => 30, 'window' => 60, 'burst' => 8, 'burstWindow' => 10],

        '/likegjitem21' => ['limit' => 60, 'window' => 60, 'burst' => 15, 'burstWindow' => 10],
        '/likegjitem211' => ['limit' => 60, 'window' => 60, 'burst' => 15, 'burstWindow' => 10],
    ];

    public function __construct(
        private RateLimiter $limiter = new RateLimiter()
    ) {
    }

    /** @return array{decision:'allow'|'block', reason:string} */
    public function inspect(Request $request, string $endpoint): array
    {
        if (!$this->enabled() || $this->isExempt($endpoint)) {
            return ['decision' => 'allow', 'reason' => 'disabled_or_exempt'];
        }

        $endpoint = $this->normalizeEndpoint($endpoint);
        $policy = self::POLICIES[$endpoint] ?? null;

        // Do not throttle unknown/read-only endpoints by default. This keeps
        // compatibility risk low while every sensitive endpoint gets an
        // explicit policy above.
        if ($policy === null) {
            return ['decision' => 'allow', 'reason' => 'no_policy'];
        }

        $ip = $request->clientIp();
        $baseKey = 'ip:' . $ip . ':endpoint:' . $endpoint;

        if (!$this->limiter->allow($baseKey, $policy['limit'], $policy['window'])) {
            $this->audit($request, $endpoint, 'ip_rate_limit');
            return ['decision' => 'block', 'reason' => 'ip_rate_limit'];
        }

        if (!$this->limiter->allow($baseKey . ':burst', $policy['burst'], $policy['burstWindow'])) {
            $this->audit($request, $endpoint, 'burst_limit');
            return ['decision' => 'block', 'reason' => 'burst_limit'];
        }

        $accountId = $this->accountId($request);
        $credential = $request->gdCredential();

        if ($accountId !== null && $credential !== '') {
            $identity = hash(
                'sha256',
                $accountId . ':' . $credential
            );
            $accountKey = 'identity:' . $identity . ':endpoint:' . $endpoint;

            if (!$this->limiter->allow($accountKey, $policy['limit'], $policy['window'])) {
                $this->audit($request, $endpoint, 'account_rate_limit');
                return ['decision' => 'block', 'reason' => 'account_rate_limit'];
            }
        }

        return ['decision' => 'allow', 'reason' => 'ok'];
    }

    private function enabled(): bool
    {
        $value = getenv('MUCHO_PROTECT');

        if ($value === false || $value === '') {
            return true;
        }

        return !in_array(strtolower($value), ['0', 'false', 'off', 'no'], true);
    }

    private function isExempt(string $endpoint): bool
    {
        return in_array($this->normalizeEndpoint($endpoint), [
            '/health',
            '/checkifserveronline',
            '/getaccounturl',
            '/getcustomcontenturl',
        ], true);
    }

    private function normalizeEndpoint(string $endpoint): string
    {
        $path = parse_url($endpoint, PHP_URL_PATH);
        $endpoint = is_string($path) ? $path : '/';
        $endpoint = preg_replace('#/+#', '/', $endpoint) ?? '/';
        $endpoint = preg_replace('#^/(?:database|accounts|api|a)(?:/|$)#i', '/', $endpoint) ?? $endpoint;
        $endpoint = preg_replace('#\.php$#i', '', $endpoint) ?? $endpoint;

        if ($endpoint !== '/') {
            $endpoint = rtrim($endpoint, '/');
        }

        return strtolower($endpoint === '' ? '/' : $endpoint);
    }

    private function accountId(Request $request): ?int
    {
        foreach (['accountID', 'accountId'] as $key) {
            $value = $request->post[$key] ?? $request->query[$key] ?? null;

            if (is_int($value) && $value > 0) {
                return $value;
            }

            if (is_string($value) && preg_match('/^\d+$/', $value) === 1 && (int)$value > 0) {
                return (int)$value;
            }
        }

        return null;
    }

    private function audit(Request $request, string $endpoint, string $reason): void
    {
        $directory = getenv('MUCHO_PROTECT_AUDIT_DIR') ?: '/tmp/muchocore-protect';

        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return;
        }

        $entry = json_encode([
            'time' => gmdate('c'),
            'endpoint' => $endpoint,
            'reason' => $reason,
            'ip_hash' => hash('sha256', $request->clientIp()),
            'method' => $request->method,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

        @file_put_contents($directory . '/events.ndjson', $entry, FILE_APPEND | LOCK_EX);
    }
}
