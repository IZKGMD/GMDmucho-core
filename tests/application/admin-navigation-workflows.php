<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$pages = require $root . '/public/admin/config/pages.php';
$plain = false;
$allowed = array_keys($pages);
$page = 'migration';

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function adminIsPlain(): bool
{
    global $plain;
    return $plain;
}
function canAdminPage(string $key): bool
{
    global $allowed;
    return in_array($key, $allowed, true);
}
function mustNav(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function inspectNav(): DOMXPath
{
    global $root, $pages, $page;
    ob_start();
    require $root . '/public/admin/core/navigation.php';
    $markup = (string)ob_get_clean();

    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try {
        $dom->loadHTML('<!doctype html><html><body>'.$markup.'</body></html>');
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    return new DOMXPath($dom);
}

require $root . '/public/admin/core/AdminChrome.php';
foreach ([false, true] as $plain) {
    $xpath = inspectNav();
    foreach (array_keys($pages) as $key) {
        mustNav(
            $xpath->query('//nav[@aria-label="Main"]//a[contains(@href,"page='.$key.'")]')->length === 1,
            'Missing or duplicated admin page '.$key
        );
    }
    mustNav($xpath->query('//details[contains(@class,"admin-nav-extra") and @open]')->length === 1,
        'Active secondary tools must reveal the outer list');
    mustNav($xpath->query('//details[contains(@class,"admin-nav-group") and @open]//a[@aria-current="page"]')->length === 1,
        'Active tool must be visible inside its expanded group');
    $allowed = ['dashboard', 'migration', 'audit'];
    $xpath = inspectNav();
    mustNav($xpath->query('//nav//a[contains(@href,"page=players")]')->length === 0,
        'Unauthorized primary page leaks into sidebar');
    mustNav($xpath->query('//details[contains(@class,"admin-nav-group")]/ul//a[contains(@href,"page=migration")]')->length === 1,
        'Allowed secondary page is not reachable');
    mustNav($xpath->query('//details[contains(@class,"admin-nav-group")]')->length === 2,
        'Empty groups must be removed for restricted roles');
    $allowed = array_keys($pages);
}

echo "admin-navigation-workflows: OK\n";
