#!/usr/bin/env python3
"""Preview-release safety contracts; the full stable certification remains mandatory."""
from pathlib import Path

root = Path(__file__).resolve().parents[2]
stable = (root / '.github/workflows/release-stable.yml').read_text()
preview = (root / '.github/workflows/release-preview.yml').read_text()
validate = (root / '.github/workflows/validate.yml').read_text()
version = (root / 'VERSION').read_text().strip()

assert version == '1.1.1'
assert (root / '.release/v1.1.1.preview').exists()
assert not (root / '.release/v1.1.1.ready').exists(), 'Uncertified 1.1.1 cannot be stable'
assert "run: php tests/release/v110-endpoint-gate.php" in stable
assert 'if [[ -f ".release/v${VERSION}.ready" ]]; then' in validate
assert 'run: php tests/release/v111-installability-gate.php' in preview
assert 'workflow_run:' in preview and 'workflows: ["Validate"]' in preview
assert 'github.event.workflow_run.conclusion == \'success\'' in preview
assert 'github.event.workflow_run.head_sha' in preview
assert 'MAIN_SHA' in preview and 'RELEASE_SHA' in preview
assert 'require-php8-ci.py' in preview and '--sha "$RELEASE_SHA"' in preview
assert 'MuchoCore-v1.1.1-shared-hosting.zip' in preview
assert 'composer install --no-dev' in preview
assert 'unzip -t' in preview and 'sha256sum -c checksums.sha256' in preview
assert 'GeometryDash.exe' in preview and ":(exclude)GeometryDash.exe" in preview
assert '--draft --prerelease' in preview and 'gh release edit "$TAG" --draft=false --prerelease' in preview
assert '--latest' not in preview and 'releases/latest' not in preview
assert 'GH_TOKEN: ${{ github.token }}' in preview
assert 'secrets.RELEASE_TOKEN' not in preview
assert 'MuchoCore-v1.1.1-PHP7' not in preview
assert preview.index('Require exact-commit PHP 8 HTTP acceptance') < preview.index('Build installable shared-hosting ZIP')
assert preview.index('Verify SHA-256 and release contents') < preview.index('Publish 1.1.1 as an installable prerelease')
assert preview.index('gh release upload "$TAG" checksums.sha256') < preview.index('gh release edit "$TAG" --draft=false --prerelease')
print('MUCHOCORE_111_PHP8_PREVIEW_SAFETY_OK')
