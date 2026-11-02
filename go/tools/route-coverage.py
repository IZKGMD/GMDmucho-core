#!/usr/bin/env python3
"""Inventory the PHP Application router against registered native handlers."""
import argparse
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
source = (ROOT / 'src/Core/Application.php').read_text()
if (ROOT / 'VERSION').read_text().strip() != (ROOT / 'go/internal/buildinfo/VERSION').read_text().strip():
    raise SystemExit('Refresh go/internal/buildinfo/VERSION from the root VERSION')
php_router = (ROOT / 'src/Routing/Router.php').read_text()
native = (ROOT / 'go/internal/core/handler.go').read_text()
aliases = dict(re.findall(r"'(/[^']+)' => '(/[^']+)'", php_router))


def normalize(path):
    path = re.sub(r'/+', '/', path.split('?', 1)[0])
    while re.match(r'^/(database|accounts|api|a)(/|$)', path, re.I):
        path = re.sub(r'^/(database|accounts|api|a)(/|$)', '/', path, count=1, flags=re.I)
    path = re.sub(r'\.php$', '', path.rstrip('/'), flags=re.I).lower() or '/'
    return aliases.get(path, path)


implemented = set(re.findall(r'"(/[^\"]+)"\s*:', native))
implemented.update({
    '/health', '/checkifserveronline', '/getgjsonginfo', '/getgjgauntlets',
    '/getgjgauntlets21', '/getgjmappacks', '/getgjmappacks21', '/getgjdailylevel',
})
routes = {}
for match in re.finditer(r"\$route\(\s*'([^']+)'", source):
    routes.setdefault(normalize(match[1]), []).append(match[1])
count = sum(path in implemented for path in routes)
lines = [
    '# Native route inventory', '',
    f'Native handlers registered: **{count} / {len(routes)}** canonical routes from `src/Core/Application.php`.', '',
    'This counts registered handlers, not completed features or production readiness. '
    'Aliases do not count as additional implementations. PHP differential tests cover selected scenarios; '
    'real-client acceptance remains pending.', '',
    'API v2 standalone scripts, browser admin, recovery/passkeys, plugins, deployment/update/backup '
    'tooling and client tooling require separate acceptance and are not included in this denominator.', '',
    '| Canonical path | Native handler | PHP entry points |',
    '| --- | --- | --- |',
]
for path, names in sorted(routes.items()):
    state = 'Present' if path in implemented else '**Pending**'
    lines.append(f'| `{path}` | {state} | ' + ', '.join(f'`{name}`' for name in names) + ' |')
v2 = sorted(path for path in (ROOT / 'public/api/v2').glob('*.php')
            if path.name not in {'bootstrap.php', 'security.php'})
v2_count = sum(normalize('/api/v2/' + path.name) in implemented for path in v2)
lines += ['', '## Standalone services', '',
          f'API v2: **{v2_count} / {len(v2)}** endpoint scripts have native handlers. '
          'This excludes its bootstrap/security include files, whose protection and metrics run as middleware.', '',
          '| PHP endpoint | Native handler |', '| --- | --- |']
for path in v2:
    state = 'Present' if normalize('/api/v2/' + path.name) in implemented else '**Pending**'
    lines.append(f'| `/api/v2/{path.name}` | {state} |')
lines += ['', 'Account recovery (`/api/api/accounts/lostusername.php`) has a native browser/service implementation. '
          'Browser administration, administrator passkeys, plugins and deployment/update/backup tools require separate migration and acceptance.']
content = '\n'.join(lines) + '\n'
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--check', action='store_true')
args = parser.parse_args()
destination = ROOT / 'go/ROUTES.md'
if args.check:
    if not destination.exists() or destination.read_text() != content:
        raise SystemExit('Run python3 go/tools/route-coverage.py to refresh the route inventory')
else:
    destination.write_text(content)
print(f'Native Application handlers: {count}/{len(routes)}')
