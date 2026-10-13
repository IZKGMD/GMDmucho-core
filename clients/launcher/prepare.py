import argparse,hashlib,json,zipfile
from pathlib import Path
p=argparse.ArgumentParser();p.add_argument('--loader',required=True);p.add_argument('--client',required=True);p.add_argument('--guard');p.add_argument('--out',default='.');a=p.parse_args();out=Path(a.out);out.mkdir(parents=True,exist_ok=True)
loader=Path(a.loader)
if loader.is_dir():loader=next(loader.rglob('MuchoLoader-v0.1.0-Windows.zip'))
if hashlib.sha256(loader.read_bytes()).hexdigest()!='67ad153145797ff50d1d82ef568fb1a0acf241d0b1a665b133d860ffbfa68e35':raise SystemExit('Loader archive pin mismatch')
files={}
with zipfile.ZipFile(loader) as z:
 for name in z.namelist():
  if '/payload/' in name and not name.endswith('/'):
   rel=name.split('/payload/',1)[1]
   if '/plugins/hello-python/' not in rel:files[rel]=z.read(name)
 for name in ['LICENSE.txt','EULA','README.md']:
  full=next(n for n in z.namelist() if n.endswith('/'+name));files['geode/muchogeode/'+name]=z.read(full)
client=Path(a.client)
if client.is_dir():client=next(client.rglob('*.geode'))
files['geode/mods/izkgmd.muchoclient.geode']=client.read_bytes()
# Refuse packages without the mandatory readiness handshake.
with zipfile.ZipFile(client) as z:
 if b'MuchoClientHealth_v1' not in z.read('izkgmd.muchoclient.dll'):raise SystemExit('Client lacks native health export')
pins={n:hashlib.sha256(files[n]).hexdigest() for n in ['Geode.dll','geode/mods/izkgmd.muchoclient.geode']}
(out/'pins.h').write_text('#define MUCHO_LOADER_SHA "'+pins['Geode.dll']+'"\n#define MUCHO_CLIENT_SHA "'+pins['geode/mods/izkgmd.muchoclient.geode']+'"\n')
if not a.guard:print('Native pins generated');raise SystemExit(0)
files['MuchoBootstrap.dll']=Path(a.guard).read_bytes()
policy=json.loads((Path(__file__).parent/'game-policy.json').read_text())
manifest={'version':'1.0.0','text_sha':policy['text_sha'],'files':[{'path':n,'sha256':hashlib.sha256(data).hexdigest(),'size':len(data)} for n,data in sorted(files.items())]}
with zipfile.ZipFile(out/'payload.zip','w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
 for n,data in sorted(files.items()):z.writestr(n,data)
sha=hashlib.sha256((out/'payload.zip').read_bytes()).hexdigest()
(out/'build_config.py').write_text('MANIFEST = '+repr(manifest)+'\nPAYLOAD_SHA = '+repr(sha)+'\n')
(out/'release-manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
print('Prepared',len(files),'required files')
