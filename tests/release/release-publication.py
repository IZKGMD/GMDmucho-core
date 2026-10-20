#!/usr/bin/env python3
"""Exercise the workflow's real shell steps against local Git and a GitHub double."""
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import textwrap
import zipfile

ROOT = Path(__file__).resolve().parents[2]
workflow = (ROOT / '.github/workflows/release-stable.yml').read_text()
blocks = re.split(r'(?=^      - name: )', workflow, flags=re.M)[1:]
steps = {}
for block in blocks:
    name = block.splitlines()[0].removeprefix('      - name: ')
    match = re.search(r'^        run: \|\n(.*)', block, flags=re.S | re.M)
    if match:
        steps[name] = textwrap.dedent(match[1])

names = list(steps)
assert names.index('Check release marker') < names.index('Verify release token')
assert names.index('Build shared-hosting package') < names.index('Prepare validated release draft')
assert names.index('Build source archive') < names.index('Prepare validated release draft')
assert names.index('Upload source archive') < names.index('Publish complete validated release')

def run(args, cwd, env, good=True):
    result = subprocess.run(args, cwd=cwd, env=env, text=True, capture_output=True)
    if good and result.returncode:
        raise AssertionError(result.stdout + result.stderr)
    return result

def step(name, cwd, env, good=True):
    return run(['bash', '-euo', 'pipefail', '-c', steps[name]], cwd, env, good)

with tempfile.TemporaryDirectory(prefix='mc-release-publish-') as temporary:
    base = Path(temporary)
    repo = base / 'source'
    repo.mkdir()
    binaries = base / 'bin'
    binaries.mkdir()
    state = base / 'github.json'
    env = dict(os.environ, PATH=str(binaries)+os.pathsep+os.environ['PATH'],
               GH_FIXTURE_STATE=str(state), GITHUB_OUTPUT=str(base/'outputs'),
               GH_TOKEN='fixture', TAG='v1.0.1', VERSION='1.0.1',
               OUTPUT='MuchoCore-v1.0.1-shared-hosting.zip', ARCHIVE_ONLY='false',
               GIT_CONFIG_GLOBAL=os.devnull, GIT_CONFIG_SYSTEM=os.devnull)
    # This double fails uploads on demand, records uploaded files, and only
    # exposes a stable release after the workflow explicitly publishes it.
    (binaries/'gh').write_text('''#!/usr/bin/env python3
import json,os,sys
from pathlib import Path
p=Path(os.environ['GH_FIXTURE_STATE']); a=sys.argv[1:]
if a[:2]==['auth','setup-git']: sys.exit(0)
if a[:2]==['release','view']: sys.exit(0 if p.exists() else 1)
if a[:2]==['release','create']:
 assert '--draft' in a and '--latest' not in a
 p.write_text(json.dumps({'draft':True,'assets':[]})); sys.exit(0)
d=json.loads(p.read_text())
if a[:2]==['release','upload']:
 if os.environ.get('FAIL_UPLOAD') and a[3].endswith(os.environ['FAIL_UPLOAD']): sys.exit(1)
 assert Path(a[3]).is_file()
 if a[3] not in d['assets']: d['assets'].append(a[3])
elif a[:2]==['release','edit']:
 assert '--draft=false' in a and '--latest' in a and len(d['assets'])==2
 d['draft']=False
else: raise RuntimeError(a)
p.write_text(json.dumps(d))
''')
    (binaries/'gh').chmod(0o755)
    (binaries/'composer').write_text('#!/bin/sh\nexit 0\n')
    (binaries/'composer').chmod(0o755)
    for path in ['.htaccess','composer.json','composer.lock','README.md','START_HERE.md',
                 'public/install.php','public/php7.php','public/shared-install.php','public/ftp-install.php',
                 'compat/php7/runtime.php','compat/php7/config.example.php','src/example.php',
                 'database/example.sql','config/example.php','vendor/autoload.php',
                 'docs/SHARED_HOSTING.md','docs/CLIENT_SETUP.md',
                 'docs/PHP7_NATIVE_PHASE1.md','docs/SHARED_INSTALL_RU.txt']:
        file=repo/path
        file.parent.mkdir(parents=True,exist_ok=True)
        file.write_text('fixture\n')
    (repo/'tools/release').mkdir(parents=True)
    shutil.copy(ROOT/'tools/release/build-shared-hosting.sh',repo/'tools/release')
    (repo/'VERSION').write_text('1.0.0\n')
    (repo/'CHANGELOG.md').write_text('## v1.0.1 — fixture\n\nRelease fixture.\n')
    run(['git','init','-q','--initial-branch=main'],repo,env)
    run(['git','config','user.name','Release Test'],repo,env)
    run(['git','config','user.email','release-test@example.invalid'],repo,env)
    run(['git','add','.'],repo,env)
    run(['git','commit','-qm','old release'],repo,env)
    run(['git','tag','v1.0.0'],repo,env)
    (repo/'VERSION').write_text('1.0.1\n')
    run(['git','add','VERSION'],repo,env)
    run(['git','commit','-qm','validated release'],repo,env)
    sha=run(['git','rev-parse','HEAD'],repo,env).stdout.strip()
    env['RELEASE_SHA']=sha
    run(['git','init','-q','--bare',str(base/'remote.git')],repo,env)
    run(['git','remote','add','origin',str(base/'remote.git')],repo,env)
    run(['git','push','-q','origin','main','v1.0.0'],repo,env)
    step('Check release marker',repo,env)
    assert 'ready=false' in (base/'outputs').read_text()
    assert not state.exists()
    print('PASS ordinary main builds require no release token or publication')

    step('Build shared-hosting package',repo,env)
    step('Build source archive',repo,env)
    step('Prepare validated release draft',repo,env)
    assert json.loads(state.read_text())['draft'] is True
    step('Upload shared-hosting package',repo,env)
    failed=step('Upload source archive',repo,dict(env,FAIL_UPLOAD='v1.0.1.zip'),good=False)
    assert failed.returncode != 0 and json.loads(state.read_text())['draft'] is True
    print('PASS failed upload leaves the new release unpublished')
    step('Prepare validated release draft',repo,env)
    step('Upload shared-hosting package',repo,env)
    step('Upload source archive',repo,env)
    step('Publish complete validated release',repo,env)
    assert json.loads(state.read_text())['draft'] is False
    remote_sha=run(['git','ls-remote','origin','refs/tags/v1.0.1'],repo,env).stdout.split()[0]
    assert remote_sha==sha
    print('PASS retry publishes both archives at the validated commit')
    wrong=step('Prepare validated release draft',repo,dict(env,RELEASE_SHA='f'*40),good=False)
    assert wrong.returncode != 0 and 'refusing to move' in wrong.stderr
    print('PASS an existing stable tag cannot be moved')

    archive_env=dict(env,ARCHIVE_ONLY='true',TAG='v1.0.0',VERSION='1.0.0',
                     OUTPUT='MuchoCore-v1.0.0-shared-hosting.zip')
    step('Prepare shared-hosting dependencies',repo,archive_env)
    step('Build shared-hosting package',repo,archive_env)
    step('Build source archive',repo,archive_env)
    with zipfile.ZipFile(repo/'MuchoCore-v1.0.0-shared-hosting.zip') as archive:
        assert archive.read('muchocore/VERSION')==b'1.0.0\n'
    with zipfile.ZipFile(repo/'MuchoCore-v1.0.0.zip') as archive:
        assert archive.read('MuchoCore-v1.0.0/VERSION')==b'1.0.0\n'
    print('PASS archive repair builds both distributions from the requested tag')

print('MUCHOCORE_RELEASE_PUBLICATION_OK')
