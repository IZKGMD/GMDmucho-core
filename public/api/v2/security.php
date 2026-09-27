<?php
declare(strict_types=1);

/*
 * MuchoCore API Security
 * Copyright (C) 2026 IZK
 */

function muchoV2RequestId(): string
{
    static $id=null;

    if($id!==null){
        return $id;
    }

    $incoming=trim(
        (string)(
            $_SERVER['HTTP_X_REQUEST_ID']
            ?? ''
        )
    );

    if(
        preg_match(
            '/^[A-Za-z0-9._\-]{8,64}$/',
            $incoming
        )
    ){
        $id=$incoming;
    }else{
        $id=bin2hex(random_bytes(12));
    }

    return $id;
}


function muchoV2ClientIp(): string
{
    return \MuchoCore\Http\ClientIp::resolve($_SERVER);
}


function muchoV2Route(): string
{
    $path=parse_url(
        (string)(
            $_SERVER['REQUEST_URI']
            ?? '/'
        ),
        PHP_URL_PATH
    );

    return substr(
        is_string($path) ? $path : '/',
        0,
        160
    );
}


function muchoV2SecurityEvent(
    string $type,
    mixed $metadata=null
): void {
    try {

        $db=muchoV2Db();

        $q=$db->prepare("
            INSERT INTO mucho_security_events
            (
                event_type,
                ip,
                route,
                request_id,
                metadata
            )
            VALUES (?,?,?,?,?)
        ");

        $q->execute([
            $type,
            muchoV2ClientIp(),
            muchoV2Route(),
            muchoV2RequestId(),

            $metadata===null
                ? null
                : json_encode(
                    $metadata,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
                )
        ]);

    } catch(Throwable) {
    }
}


function muchoV2ApplyRateLimit(): void
{
    $request = \\MuchoCore\\Http\\Request::fromGlobals();
    $endpoint = $request->path;
    $protection = (new \\MuchoCore\\Security\\MuchoProtect())->inspect(
        $request,
        $endpoint
    );

    if ($protection['decision'] !== 'block') {
        return;
    }

    $retryAfter = match ($protection['reason']) {
        'global_rate_limit' => 60,
        'global_burst_limit' => 10,
        'network_rate_limit' => 60,
        'network_burst_limit' => 10,
        'ip_rate_limit' => 60,
        'burst_limit' => 10,
        'temporary_penalty', 'network_penalty', 'account_penalty',
        'username_penalty', 'email_penalty', 'device_penalty' => 15,
        default => 60,
    };

    muchoV2SecurityEvent(
        'rate_limit',
        [
            'reason' => $protection['reason'],
            'retry_after' => $retryAfter,
        ]
    );

    if (!headers_sent()) {
        header('Retry-After: ' . $retryAfter);
    }

    muchoV2Send([
        'ok' => false,
        'api' => 'MuchoCore',
        'version' => MUCHO_V2_VERSION,
        'error' => 'rate_limited',
        'retry_after_seconds' => $retryAfter,
    ], 429);
}


function muchoV2RecordMetric(
    float $started
): void {
    try {

        $db=muchoV2Db();

        $status=http_response_code();

        if($status<=0){
            $status=200;
        }

        $ms=max(
            0,
            (int)round(
                (microtime(true)-$started)*1000
            )
        );

        $route=muchoV2Route();

        $method=substr(
            strtoupper(
                (string)(
                    $_SERVER['REQUEST_METHOD']
                    ?? 'GET'
                )
            ),
            0,
            12
        );

        $q=$db->prepare("
            INSERT INTO mucho_api_metrics_minute
            (
                minute_start,
                route,
                method,
                status,
                requests,
                total_ms
            )
            VALUES (
                DATE_FORMAT(
                    NOW(),
                    '%Y-%m-%d %H:%i:00'
                ),
                ?,
                ?,
                ?,
                1,
                ?
            )

            ON DUPLICATE KEY UPDATE
                requests=requests+1,
                total_ms=total_ms+VALUES(total_ms)
        ");

        $q->execute([
            $route,
            $method,
            $status,
            $ms
        ]);

    } catch(Throwable) {
    }
}


if(
    PHP_SAPI!=='cli' &&
    !defined('MUCHO_API_SECURITY_ACTIVE')
){
    define(
        'MUCHO_API_SECURITY_ACTIVE',
        true
    );

    $started=microtime(true);

    header(
        'X-Request-ID: '.
        muchoV2RequestId()
    );

    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header(
        'Permissions-Policy: camera=(), microphone=(), geolocation=()'
    );

    try {
        muchoV2ApplyRateLimit();
    } catch (Throwable $e) {
        error_log(
            '[MuchoCore Security] Rate limiter unavailable: ' .
            $e->getMessage()
        );

        /*
         * The v2 API is not a legacy game transport. Do not silently turn
         * off abuse protection when its backing store is unavailable.
         */
        muchoV2Send([
            'ok' => false,
            'api' => 'MuchoCore',
            'version' => MUCHO_V2_VERSION,
            'error' => 'security_unavailable'
        ], 503);
    }

    register_shutdown_function(
        static function() use ($started): void {
            muchoV2RecordMetric($started);
        }
    );
}
