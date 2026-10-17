<?php
declare(strict_types=1);

use MuchoCore\Release\ReleaseService;

function renderReleaseUpdatePage(string $rootDir, string $controlDir): void
{
    $status = ReleaseService::check($rootDir, $controlDir);

    $current = (string)$status['current_version'];
    $latest = (string)($status['latest_version'] ?? '');
    $available = (bool)$status['update_available'];
    $ahead = (bool)$status['ahead_of_stable'];
    $known = $latest !== '';
    $title = !$known ? 'Release check unavailable' : ($available ? 'Update available' : ($ahead ? 'Ahead of stable release' : 'You are up to date'));
    $badge = !$known ? 'UNKNOWN' : ($available ? 'UPDATE READY' : ($ahead ? 'DEVELOPMENT' : 'CURRENT'));
    $releaseUrl = (string)($status['release_url'] ?? '');
    $releaseName = (string)($status['release_name'] ?? '');
    $body = (string)($status['release_body'] ?? '');

    $published = (string)($status['published_at'] ?? '');
    $publishedText = $published !== ''
        ? h($published)
        : 'Unknown';

    echo '<section class="card" style="border-color:' .
        ($available ? '#5e4fd8' : '#243044') . ';overflow:hidden">';
    echo '<div style="display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap">';
    echo '<div>';
    echo '<div style="font-size:11px;font-weight:800;letter-spacing:.08em;color:#8c7dff">MUCHOCORE RELEASES</div>';
    echo '<h2 style="margin:7px 0 4px">' .
        h($title) .
        '</h2>';
    echo '<p class="muted" style="margin:0;line-height:1.5">';
    echo 'Installed <b>v' . h($current) . '</b>';
    if ($latest !== '') {
        echo ' · Latest stable <b>v' . h($latest) . '</b>';
    }
    echo '</p>';
    echo '</div>';
    echo '<span class="badge ' . ($known && !$available && !$ahead ? 'green' : '') . '">' .
        h($badge) .
        '</span>';
    echo '</div>';

    if ($ahead) {
        echo '<p class="muted">This installation is newer than the published stable release. A lower version is not an upgrade; source version numbers do not by themselves confirm stable release status.</p>';
    }

    if ($status['error'] !== null) {
        echo '<div class="warning" style="margin-top:14px">' .
            h((string)$status['error']) .
            '</div>';
    }

    if ($available) {
        echo '<div style="display:grid;grid-template-columns:1fr auto;gap:12px;align-items:start;margin-top:18px">';
        echo '<div>';
        echo '<b>' . h($releaseName !== '' ? $releaseName : 'New MuchoCore release') . '</b>';
        echo '<div class="muted" style="font-size:11px;margin-top:4px">Published ' .
            $publishedText . '</div>';
        echo '</div>';

        if ($releaseUrl !== '') {
            echo '<a class="btn" href="' . h($releaseUrl) . '" target="_blank" rel="noopener noreferrer">View release ↗</a>';
        }

        echo '</div>';

        echo '<div class="card" style="margin-top:14px;background:#0b1018">';
        echo '<h3 style="margin-top:0">Automatic update</h3>';
        echo '<p class="muted" style="line-height:1.55">This release is the stable deployment target. The VPS release updater applies it automatically. The same SSH command remains available as a manual fallback.</p>';
        echo '<pre style="margin:12px 0 0;padding:13px;border:1px solid #273448;border-radius:10px;background:#070b11;overflow:auto">sudo /opt/mucho-core/update.sh</pre>';
        echo '</div>';

        if ($body !== '') {
            echo '<details style="margin-top:14px">';
            echo '<summary style="cursor:pointer;font-weight:750">Release notes</summary>';
            echo '<pre style="white-space:pre-wrap;word-break:break-word;color:#aeb9ca;font:12px/1.55 ui-monospace,SFMono-Regular,Menlo,monospace;margin-top:10px">' .
                h($body) .
                '</pre>';
            echo '</details>';
        }
    }

    echo '<div class="muted" style="font-size:10px;margin-top:15px">Release checks are read-only and cached for 5 minutes.</div>';
    echo '</section>';
}
