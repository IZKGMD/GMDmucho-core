<?php
declare(strict_types=1);

// Regression gate: white/opaque logo backgrounds must never return to headers.
$root = dirname(__DIR__, 2);
$iconPath = $root . '/public/assets/muchocore-icon.png';
if (!is_file($iconPath)) {
    throw new RuntimeException('Missing generated MuchoCore cube icon.');
}
$info = getimagesize($iconPath);
if (!$info || $info[2] !== IMAGETYPE_PNG || $info[0] !== $info[1]) {
    throw new RuntimeException('MuchoCore brand mark must be a square PNG.');
}
if (!function_exists('imagecreatefrompng')) {
    throw new RuntimeException('The GD extension is needed for transparency verification.');
}
$image = imagecreatefrompng($iconPath);
if ($image === false) {
    throw new RuntimeException('Unable to inspect MuchoCore brand mark.');
}
$corner = imagecolorsforindex($image, imagecolorat($image, 0, 0));
imagedestroy($image);
if ((int)($corner['alpha'] ?? 0) < 120) {
    throw new RuntimeException('MuchoCore icon background is not transparent.');
}

// Tabs must use a new dedicated filename, not the old cached brand icon.
$faviconPath = $root . '/public/assets/muchocore-favicon-v4.png';
$faviconInfo = getimagesize($faviconPath);
if (!$faviconInfo || $faviconInfo[2] !== IMAGETYPE_PNG ||
    $faviconInfo[0] !== 64 || $faviconInfo[1] !== 64) {
    throw new RuntimeException('The PNG favicon must be 64x64.');
}
$favicon = imagecreatefrompng($faviconPath);
if ($favicon === false) {
    throw new RuntimeException('Cannot inspect the PNG favicon.');
}
$bg = imagecolorsforindex($favicon, imagecolorat($favicon, 0, 0));
imagedestroy($favicon);
if ((int)($bg['alpha'] ?? 0) < 120) {
    throw new RuntimeException('The tab favicon still has an opaque background.');
}

$ico = file_get_contents($root . '/public/favicon.ico');
if (!is_string($ico) || strlen($ico) < 64 || substr($ico, 0, 4) !== "\x00\x00\x01\x00") {
    throw new RuntimeException('Missing or invalid browser fallback favicon.ico.');
}
$icoCount = unpack('vcount', substr($ico, 4, 2))['count'] ?? 0;
if ($icoCount < 4) {
    throw new RuntimeException('The ICO must include multiple sizes.');
}

$faviconPages = [
    'public/index.html',
    'public/muchogdps-index.html',
    'public/muchocore/index.html',
    'public/muchocore/about/index.html',
    'public/muchocore/compatibility/index.html',
    'public/install/index.html',
    'public/install/vps/index.html',
    'public/install/shared/index.html',
    'public/install/shared/go/index.html',
    'public/deploy/index.html',
    'public/dashboard/index.php',
    'public/dashboard/clans.php',
    'public/clients.php',
    'public/admin/index.php',
    'public/admin/core/plain-shell.php',
    'src/Account/RecoveryController.php',
];
foreach ($faviconPages as $path) {
    $html = (string)file_get_contents($root . '/' . $path);
    if (!str_contains($html, '<link rel="icon" href="/assets/muchocore-favicon-v4.png"')) {
        throw new RuntimeException('Missing current favicon link: ' . $path);
    }
    if (str_contains($html, '<link rel="icon" href="/assets/muchocore-icon.png')) {
        throw new RuntimeException('Stale cached favicon URL in ' . $path);
    }
}

$headerPages = [
    'public/index.html',
    'public/muchogdps-index.html',
    'public/muchocore/index.html',
    'public/install/index.html',
    'public/install/vps/index.html',
    'public/install/shared/index.html',
    'public/install/shared/go/index.html',
    'public/deploy/index.html',
    'public/dashboard/index.php',
    'public/dashboard/clans.php',
    'public/admin/core/AdminChrome.php',
    'src/Account/RecoveryController.php',
];

foreach ($headerPages as $path) {
    $content = file_get_contents($root . '/' . $path);
    if ($content === false) {
        throw new RuntimeException('Unreadable header template: ' . $path);
    }
    if (preg_match('~<img\\b[^>]*\\bsrc=["\\\']/assets/muchocore-logo\\.jpg~i', $content)) {
        throw new RuntimeException('Horizontal JPEG squeezed into a square header: ' . $path);
    }
    if (strpos($content, '/assets/muchocore-icon.png?v=3') === false) {
        throw new RuntimeException('Header must use versioned alpha cube: ' . $path);
    }
}

$css = (string)file_get_contents($root . '/public/assets/product-ui.css');
$adminCss = (string)file_get_contents($root . '/public/admin/admin-polish.css');
if (!str_contains($css, 'img.mc-brand-mark') ||
    !str_contains($css, 'object-position:center') ||
    !str_contains($adminCss, 'img.admin-brand-icon')) {
    throw new RuntimeException('Unified centered brand mark CSS is missing.');
}

echo "brand-mark-contract: OK\n";
