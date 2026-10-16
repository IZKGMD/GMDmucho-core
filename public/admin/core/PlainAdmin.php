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
    // Native HTML spacing replaces layout classes; no CSS is introduced.
    foreach ($xpath->query('//table') as $table) {
        $table->setAttribute('border', '1');
        $table->setAttribute('cellpadding', '6');
    }
    foreach ($xpath->query('//th[not(@scope)]') as $cell) $cell->setAttribute('scope', 'col');
    foreach ($xpath->query('//form//label') as $label) {
        $parent=$label->parentNode;
        if ($parent instanceof DOMElement && in_array($parent->tagName, ['form','div','fieldset'], true)) {
            $paragraph=$doc->createElement('p');
            $parent->insertBefore($paragraph, $label);
            $paragraph->appendChild($label);
        }
        $control=$xpath->query('.//input[not(@type="checkbox" or @type="radio" or @type="hidden")] | .//select | .//textarea', $label)->item(0);
        if ($control && $control->previousSibling?->nodeName!=='br') {
            $control->parentNode->insertBefore($doc->createElement('br'), $control);
        }
    }
    foreach ($xpath->query('//*[contains(concat(" ",@class," ")," record-name ")]/small') as $small) {
        $small->parentNode->insertBefore($doc->createElement('br'), $small);
    }
    foreach ($xpath->query('//img[contains(concat(" ",@class," ")," rating-face ") and not(@width)]') as $img) {
        $img->setAttribute('width', '48');
        $img->setAttribute('height', '48');
    }
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
