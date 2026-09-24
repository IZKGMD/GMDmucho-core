<?php

declare(strict_types=1);

namespace MuchoCore\Security;

use MuchoCore\Http\Request;

final readonly class MuchoProtect
{
    private const GLOBAL_LIMIT = 900;
    private const GLOBAL_WINDOW = 60;
    private const GLOBAL_BURST = 180;
    private const GLOBAL_BURST_WINDOW = 10;

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
        private RateLimiter $limiter = new RateLimiter(),
        private AbusePenaltyStore $penalties = new AbusePenaltyStore()
    ) {
    }

    /** @return array{decision:'allow'|'block', reason:string} */
    public function inspect(Request $request, string $endpoint): array
    {
        $endpoint = $this->normalizeEndpoint($endpoint);

        if (!$this->enabled() || $this->isExempt($endpoint)) {
            return ['decision' => 'allow', 'reason' => 'disabled_or_exempt'];
        }

        $ip = $request->clientIp();
        $identity = $this->identityFingerprint($request);
        $identityKey = $identity !== null
            ? 'identity:' . $identity . ':endpoint:' . $endpoint
            : null;

        $ipPenaltyKey = 'ip:' . $ip . ':endpoint:' . $endpoint;
        $ipPenalty = $this->penalties->status($ipPenaltyKey);

        if ($ipPenalty['active']) {
            $this->audit(
                $request,
                $endpoint,
                'temporary_penalty',
                $ipPenalty['remaining'],
                $ipPenalty['strikes']
            );
            return ['decision' => 'block', 'reason' => 'temporary_penalty'];
        }

        if ($identityKey !== null) {
            $identityPenalty = $this->penalties->status($identityKey);

            if ($identityPenalty['active']) {
                $this->audit(
                    $request,
                    $endpoint,
                    'account_penalty',
                    $identityPenalty['remaining'],
                    $identityPenalty['strikes']
                );
                return ['decision' => 'block', 'reason' => 'account_penalty'];
            }
        }

        $globalKey = 'ip:' . $ip . ':global';

        if (!$this->limiter->allow(
            $globalKey,
            self::GLOBAL_LIMIT,
            self::GLOBAL_WINDOW
        )) {
            $penalty = $this->penalties->penalize($globalKey);
            $this->audit(
                $request,
                $endpoint,
                'global_rate_limit',
                $penalty['seconds'],
                $penalty['strikes']
            );
            return ['decision' => 'block', 'reason' => 'global_rate_limit'];
        }

        if (!$this->limiter->allow(
            $globalKey . ':burst',
            self::GLOBAL_BURST,
            self::GLOBAL_BURST_WINDOW
        )) {
            $penalty = $this->penalties->penalize($globalKey);
            $this->audit(
                $request,
                $endpoint,
                'global_burst_limit',
                $penalty['seconds'],
                $penalty['strikes']
            );
            return ['decision' => 'block', 'reason' => 'global_burst_limit'];
        }

        $policy = self::POLICIES[$endpoint] ?? null;

        if ($policy === null) {
            return ['decision' => 'allow', 'reason' => 'no_policy'];
        }

        if (!$this->limiter->allow(
            $ipPenaltyKey,
            $policy['limit'],
            $policy['window']
        )) {
            $penalty = $this->penalties->penalize($ipPenaltyKey);
            $this->audit(
                $request,
                $endpoint,
                'ip_rate_limit',
                $penalty['seconds'],
                $penalty['strikes']
            );
            return ['decision' => 'block', 'reason' => 'ip_rate_limit'];
        }

        if (!$this->limiter->allow(
            $ipPenaltyKey . ':burst',
            $policy['burst'],
            $policy['burstWindow']
        )) {
            $penalty = $this->penalties->penalize($ipPenaltyKey);
            $this->audit(
                $request,
                $endpoint,
                'burst_limit',
                $penalty['seconds'],
                $penalty['strikes']
            );
            return ['decision' => 'block', 'reason' => 'burst_limit'];
        }

        if (
            $identityKey !== null &&
            !$this->limiter->allow(
                $identityKey,
                $policy['limit'],
                $policy['window']
            )
        ) {
            $penalty = $this->penalties->penalize($identityKey);
            $this->audit(
                $request,
                $endpoint,
                'account_rate_limit',
                $penalty['seconds'],
                $penalty['strikes']
            );
            return ['decision' => 'block', 'reason' => 'account_rate_limit'];
        }

        return ['decision' => 'allow', 'reason' => 'ok'];
    }

    private function enabled(): bool
    {
        $value = $this->env('MUCHO_PROTECT');

        if ($value === null) {
            return true;
        }

        return !in_array(
            strtolower($value),
            ['0', 'false', 'off', 'no'],
            true
        );
    }

    private function isExempt(string $endpoint): bool
    {
        return in_array($endpoint, [
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
        $endpoint = preg_replace(
            '#^/(?:database|accounts|api|a)(?:/|$)#i',
            '/',
            $endpoint
        ) ?? $endpoint;
        $endpoint = preg_replace('#\.php$#i', '', $endpoint) ?? $endpoint;

        if ($endpoint !== '/') {
            $endpoint = rtrim($endpoint, '/');
        }

        $endpoint = strtolower($endpoint === '' ? '/' : $endpoint);

        return substr($endpoint, 0, 256);
    }

    private function identityFingerprint(Request $request): ?string
    {
        $accountId = $this->accountId($request);
        $credential = $request->gdCredential();

        if ($accountId === null || $credential === '') {
            return null;
        }

        return hash('sha256', $accountId . ':' . $credential);
    }

    private function accountId(Request $request): ?int
    {
        foreach (['accountID', 'accountId'] as $key) {
            $value = $request->post[$key] ?? $request->query[$key] ?? null;

            if (is_int($value) && $value > 0) {
                return $value;
            }

            if (
                is_string($value) &&
                preg_match('/^\d+$/', $value) === 1 &&
                (int)$value > 0
            ) {
                return (int)$value;
            }
        }

        return null;
    }

    private function env(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    private function audit(
        Request $request,
        string $endpoint,
        string $reason,
        int $penaltySeconds = 0,
        int $strikes = 0
    ): void {
        $directory = $this->env('MUCHO_PROTECT_AUDIT_DIR')
            ?: '/tmp/muchocore-protect';

        if (
            !is_dir($directory) &&
            !@mkdir($directory, 0700, true) &&
            !is_dir($directory)
        ) {
            return;
        }

        try {
            $entry = json_encode([
                'time' => gmdate('c'),
                'endpoint' => $endpoint,
                'reason' => $reason,
                'ip_hash' => hash('sha256', $request->clientIp()),
                'method' => $request->method,
                'penalty_seconds' => $penaltySeconds,
                'strikes' => $strikes,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

            @file_put_contents(
                $directory . '/events.ndjson',
                $entry,
                FILE_APPEND | LOCK_EX
            );
        } catch (\Throwable) {
            // Security telemetry must never break the game protocol.
        }
    }
}
