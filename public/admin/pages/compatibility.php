<?php
declare(strict_types=1);

$root = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 3);
$fixtureRoot = $root . '/tests/client-fixtures';
$versions = [];

if (is_dir($fixtureRoot)) {
    foreach (glob($fixtureRoot . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $version = basename($dir);
        if (!preg_match('/^\\d+(?:\\.\\d+)?$/', $version)) {
            continue;
        }

        $fixtureFile = $dir . '/endpoints.json';
        $entries = [];
        if (is_file($fixtureFile)) {
            $decoded = json_decode(
                (string)file_get_contents($fixtureFile),
                true
            );
            if (is_array($decoded)) {
                $entries = $decoded;
            }
        }

        $paths = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $pathValue = (string)($entry['path'] ?? $entry['endpoint'] ?? '');
            if ($pathValue !== '' && !in_array($pathValue, $paths, true)) {
                $paths[] = $pathValue;
            }
        }

        $versions[] = [
            'version' => $version,
            'fixture' => is_file($fixtureFile),
            'entries' => count($entries),
            'paths' => $paths,
        ];
    }

    usort(
        $versions,
        static fn(array $a, array $b): int => version_compare(
            $b['version'],
            $a['version']
        )
    );
}

$currentProfile = trim(
    (string)MuchoCoreCoreEnvironment::get(
        'MUCHO_GD_VERSIONS',
        'all'
    )
);
?>
<style>
.mc-lab-hero{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:14px;padding:20px;border:1px solid var(--border);border-radius:16px;background:linear-gradient(145deg,#121827,#0d121a)}
.mc-lab-hero h2{margin:0 0 7px;font-size:22px}
.mc-lab-hero p{max-width:780px;margin:0;color:var(--muted);line-height:1.5}
.mc-lab-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:13px}
.mc-lab-profile{min-width:160px;padding:13px 15px;text-align:center;border:1px solid var(--border);border-radius:12px;background:#090e15}
.mc-lab-profile b{display:block;font-size:14px;margin-top:5px}
.mc-lab-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:11px;margin-bottom:14px}
.mc-lab-card{padding:15px}
.mc-lab-value{font-size:25px;font-weight:850;margin-top:5px}
.mc-lab-table{width:100%;border-collapse:collapse}
.mc-lab-table th,.mc-lab-table td{padding:9px 8px;border-bottom:1px solid #202836;text-align:left;font-size:11px;vertical-align:top}
.mc-lab-table th{font-size:10px;color:#7f8ba0;text-transform:uppercase}
.mc-lab-table tr:last-child td{border-bottom:0}
.mc-lab-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
.mc-lab-muted{color:#7e8a9e}
.mc-lab-paths{display:flex;flex-wrap:wrap;gap:5px}
.mc-lab-path{padding:4px 6px;border-radius:6px;background:#151c28;color:#b5c1d3}
@media(max-width:720px){.mc-lab-hero{display:block}.mc-lab-profile{margin-top:12px}}
</style>

<section class="mc-lab-hero">
    <div>
        <span class="hero-eyebrow">MUCHOCORE DEVELOPER TOOLS</span>
        <h2>Compatibility Lab</h2>
        <p>
            Inspect committed real-client fixtures, compare version coverage,
            and jump directly into a server-side endpoint probe. This page is
            read-only until you explicitly submit a probe in API Tester.
        </p>
        <div class="mc-lab-actions">
            <a class="btn" href="/admin/?page=endpoints">Open API Tester</a>
            <a class="btn gray" href="/admin/?page=ops">Open MuchoOps</a>
            <a class="btn gray" href="/admin/?page=monitoring">Monitoring</a>
        </div>
    </div>
    <div class="mc-lab-profile">
        <small>ACTIVE GD PROFILE</small>
        <b><?=h($currentProfile !== '' ? $currentProfile : 'all')?></b>
    </div>
</section>

<div class="mc-lab-grid">
<?php
$fixtureCount = count($versions);
$totalEntries = array_sum(
    array_map(
        static fn(array $item): int => (int)$item['entries'],
        $versions
    )
);
$verifiedFixtures = count(
    array_filter(
        $versions,
        static fn(array $item): bool => $item['fixture']
    )
);
?>
    <div class="card mc-lab-card">
        <small>VERSION DIRECTORIES</small>
        <div class="mc-lab-value"><?=h((string)$fixtureCount)?></div>
        <div class="mc-lab-muted">fixture families discovered</div>
    </div>
    <div class="card mc-lab-card">
        <small>FIXTURE ENTRIES</small>
        <div class="mc-lab-value"><?=h((string)$totalEntries)?></div>
        <div class="mc-lab-muted">captured endpoint records</div>
    </div>
    <div class="card mc-lab-card">
        <small>ENDPOINT FIXTURES</small>
        <div class="mc-lab-value"><?=h((string)$verifiedFixtures)?></div>
        <div class="mc-lab-muted">versions with endpoints.json</div>
    </div>
</div>

<section class="card">
    <div class="row" style="justify-content:space-between;align-items:flex-end">
        <div>
            <h2 style="margin-top:0">Client Compatibility Fixtures</h2>
            <small>
                A fixture is evidence captured from a client, not a synthetic
                “route exists” claim.
            </small>
        </div>
        <a class="btn gray" href="/admin/?page=endpoints">Probe endpoint →</a>
    </div>

    <div class="table">
        <table class="mc-lab-table">
            <thead>
                <tr>
                    <th>Version</th>
                    <th>Fixture</th>
                    <th>Entries</th>
                    <th>Endpoints</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($versions === []): ?>
                <tr>
                    <td colspan="4" class="mc-lab-muted">
                        No committed client fixtures were found.
                    </td>
                </tr>
            <?php endif; ?>

            <?php foreach ($versions as $item): ?>
                <tr>
                    <td><b>GD <?=h($item['version'])?></b></td>
                    <td>
                        <span class="badge <?=$item['fixture'] ? 'green' : ''?>">
                            <?=$item['fixture'] ? 'Captured' : 'Directory only'?>
                        </span>
                    </td>
                    <td><?=h((string)$item['entries'])?></td>
                    <td>
                        <div class="mc-lab-paths">
                        <?php if ($item['paths'] === []): ?>
                            <span class="mc-lab-muted">No endpoint paths recorded.</span>
                        <?php else: ?>
                            <?php foreach ($item['paths'] as $endpoint): ?>
                                <span class="mc-lab-path mc-lab-code"><?=h($endpoint)?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card" style="margin-top:14px">
    <h2 style="margin-top:0">Quick 2.2 probes</h2>
    <p class="muted">
        These use the existing authenticated Admin API Tester and send the
        request through the local Caddy service, exactly like other operator
        probes.
    </p>
    <div class="row">
        <a class="btn gray" href="/admin/?page=endpoints&endpoint=%2Fdatabase%2FgetGJLevels21.php&payload=type%3D0%26page%3D0">
            getGJLevels21
        </a>
        <a class="btn gray" href="/admin/?page=endpoints&endpoint=%2Fdatabase%2FgetGJScores20.php&payload=levelID%3D1">
            getGJScores20
        </a>
    </div>
</section>
