<?php
declare(strict_types=1);

use MuchoCore\Security\ClientIp;

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
    return ClientIp::detect($_SERVER);
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
    $route=muchoV2Route();

    /*
     * Defaults are intentionally generous.
     * Endpoint-specific limits stay stricter.
     */
    $limit=240;
    $window=60;

    if(str_contains($route,'heartbeat')){
        $limit=120;
    }

    elseif(str_contains($route,'music-upload')){
        $limit=20;
    }

    elseif(str_contains($route,'profile')){
        $limit=180;
    }

    elseif(str_contains($route,'client-config')){
        $limit=180;
    }

    $ip=muchoV2ClientIp();
    $now=time();
    $windowStart=
        intdiv($now,$window)*$window;

    $key=hash(
        'sha256',
        $ip.'|'.$route
    );

    $db=muchoV2Db();

    $q=$db->prepare("
        INSERT INTO mucho_api_rate_limits
        (
            bucket_key,
            window_start,
            hits
        )
        VALUES (?, ?, 1)

        ON DUPLICATE KEY UPDATE

            hits=
                IF(
                    window_start < VALUES(window_start),
                    1,
                    hits+1
                ),

            window_start=
                IF(
                    window_start < VALUES(window_start),
                    VALUES(window_start),
                    window_start
                )
    ");

    $q->execute([
        $key,
        $windowStart
    ]);

    $q=$db->prepare("
        SELECT hits
        FROM mucho_api_rate_limits
        WHERE bucket_key=?
    ");

    $q->execute([$key]);

    $hits=(int)$q->fetchColumn();

    if($hits>$limit){

        muchoV2SecurityEvent(
            'rate_limit',
            [
                'hits'=>$hits,
                'limit'=>$limit,
                'window'=>$window
            ]
        );

        if(!headers_sent()){
            header('Retry-After: 60');
        }

        muchoV2Send([
            'ok'=>false,
            'api'=>'MuchoCore',
            'error'=>'rate_limited',
            'retry_after_seconds'=>60
        ],429);
    }
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
            '[MuchoCore Security] Rate limiter bypass: ' .
            $e->getMessage()
        );

        if (!headers_sent()) {
            header('X-Mucho-RateLimit: bypass');
        }
    }

    register_shutdown_function(
        static function() use ($started): void {
            muchoV2RecordMetric($started);
        }
    );
}
