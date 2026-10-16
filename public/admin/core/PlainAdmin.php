<?php
declare(strict_types=1);

function adminIsPlain(): bool
{
    return defined('MUCHO_ADMIN_PLAIN') && MUCHO_ADMIN_PLAIN === true;
}

/** Only panel routes change; assets, external URLs and the styled switch stay intact. */
function adminPlainUrl(string $url): string
{
    if (preg_match('~^/admin/(?:index\.php)?(?=[?#]|$)~', $url)) {
        return (string)preg_replace('~^/admin/(?:index\.php)?~', '/admin/plain/', $url, 1);
    }
    return $url;
}

/** Shared page modules may contain styles: never deliver those in the plain UI. */
function adminPlainHtml(string $html): string
{
    if (!preg_match('/^\s*<!doctype html/i', $html)) return $html;
    $doc = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    try {
        $doc->loadHTML($html, LIBXML_NONET);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    $xpath = new DOMXPath($doc);
    foreach ($xpath->query('//style | //link[contains(concat(" ",translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")," ")," stylesheet ")] | //link[@as="style"]') as $node) {
        $node->parentNode?->removeChild($node);
    }
    foreach ($xpath->query('//*[@style]') as $node) $node->removeAttribute('style');
    // These scripts create styled controls or inject CSS. Functional page scripts stay.
    foreach ($xpath->query('//script[@src]') as $node) {
        $path = parse_url($node->getAttribute('src'), PHP_URL_PATH);
        if (in_array($path, ['/muchocore-theme.js','/assets/product-ui.js','/admin/admin-i18n.js'], true)) {
            $node->parentNode?->removeChild($node);
        }
    }
    foreach ($xpath->query('//*[@href or @action]') as $node) {
        if ($node->hasAttribute('data-styled-admin')) continue;
        foreach (['href', 'action'] as $attr) {
            if ($node->hasAttribute($attr)) $node->setAttribute($attr, adminPlainUrl($node->getAttribute($attr)));
        }
    }
    return $doc->saveHTML();
}

function adminPlainStartOutput(): void
{
    ob_start(static function (string $output): string {
        foreach (headers_list() as $header) {
            // Do not parse backups, patcher downloads or JSON as HTML.
            if (stripos($header, 'Content-Disposition: attachment') === 0) return $output;
            if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'text/html') === false) return $output;
        }
        return adminPlainHtml($output);
    });
}
