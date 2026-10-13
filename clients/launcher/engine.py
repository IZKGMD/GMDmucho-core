import hashlib,json,os,shutil,subprocess,tempfile,zipfile
from pathlib import Path,PurePosixPath
from pe_gate import patch,gated

def sha(data):return hashlib.sha256(data).hexdigest()
def file_sha(path):
 h=hashlib.sha256()
 with path.open('rb') as f:
  for block in iter(lambda:f.read(1024*1024),b''):h.update(block)
 return h.hexdigest()

def safe_path(root,name):
 p=PurePosixPath(name)
 if not name or p.is_absolute() or '\\' in name or ':' in name or any(x in ('..','.','') for x in p.parts):raise ValueError('Небезопасный путь пакета.')
 target=root.joinpath(*p.parts)
 for node in [target,*target.parents]:
  if node==root.parent:break
  if node.is_symlink() or (hasattr(node,'is_junction') and node.is_junction()):raise ValueError('Ссылки и junction в папке игры не поддерживаются.')
 if target.exists() and target.is_file() and target.stat().st_nlink>1:raise ValueError('Файл имеет несколько жёстких ссылок.')
 return target

def writable_exe(root):
 if os.name!='nt':return
 # Windows refuses an exclusive writable handle for a running image.
 import ctypes
 from ctypes import wintypes
 kernel=ctypes.WinDLL('kernel32',use_last_error=True)
 kernel.CreateFileW.argtypes=[wintypes.LPCWSTR,wintypes.DWORD,wintypes.DWORD,ctypes.c_void_p,wintypes.DWORD,wintypes.DWORD,wintypes.HANDLE]
 kernel.CreateFileW.restype=wintypes.HANDLE
 kernel.CloseHandle.argtypes=[wintypes.HANDLE]
 handle=kernel.CreateFileW(str(safe_path(root,'GeometryDash.exe')),0xC0000000,0,None,3,0x80,None)
 if handle==ctypes.c_void_p(-1).value:raise ValueError('EXE занят или недоступен для записи. Закрой игру и проверь права на папку.')
 kernel.CloseHandle(handle)

class Engine:
 def __init__(self,bundle,manifest,payload_hash):
  self.bundle=Path(bundle);self.manifest=manifest;self.payload_hash=payload_hash
 def payload(self):
  if file_sha(self.bundle)!=self.payload_hash:raise ValueError('Пакет лаунчера повреждён. Скачай архив лаунчера заново.')
  result={}
  with zipfile.ZipFile(self.bundle) as z:
   if len(z.namelist())!=len(set(z.namelist())):raise ValueError('Повторяющиеся файлы в пакете.')
   for item in self.manifest['files']:
    data=z.read(item['path'])
    if sha(data)!=item['sha256'] or len(data)!=item['size']:raise ValueError('Контрольная сумма пакета не совпадает.')
    result[item['path']]=data
  return result
 def state(self,root):
  path=safe_path(root,'.mucho-launcher/install.json')
  return json.loads(path.read_text('utf8')) if path.exists() else None
 def source(self,root):
  state=self.state(root)
  if state:
   original=safe_path(root,state['backup']);data=original.read_bytes()
   if sha(data)!=state['source_sha']:raise ValueError('Резервная копия исходного EXE повреждена.')
   return data,state['backup']
  exe=safe_path(root,'GeometryDash.exe');data=exe.read_bytes()
  if gated(data):raise ValueError('Уже изменённый EXE без данных восстановления. Выбери исходную папку MuchoGDPS.')
  backup='.mucho-launcher/backups/'+sha(data)+'.exe';return data,backup
 def verify(self,folder,progress=lambda p,t:None):
  root=Path(folder).resolve();issues=[]
  for i,item in enumerate(self.manifest['files']):
   path=safe_path(root,item['path'])
   if not path.is_file() or path.stat().st_size!=item['size'] or file_sha(path)!=item['sha256']:issues.append(item['path'])
   progress((i+1)*90//len(self.manifest['files']),item['path'])
  if not self.state(root):issues.append('GeometryDash.exe / mandatory gate not installed')
  else:
   original,_=self.source(root);expected=sha(patch(original,self.manifest['text_sha']))
   exe=safe_path(root,'GeometryDash.exe')
   if not exe.exists() or file_sha(exe)!=expected:issues.append('GeometryDash.exe')
  progress(100,'Проверка завершена');return issues
 def install(self,folder,progress=lambda p,t:None):
  root=Path(folder).resolve()
  if not root.is_dir():raise ValueError('Выбери папку установленного клиента MuchoGDPS.')
  writable_exe(root)
  original,backup=self.source(root);patched=patch(original,self.manifest['text_sha']);files=self.payload()
  source_path=safe_path(root,backup)
  if source_path.exists():
   if file_sha(source_path)!=sha(original):raise ValueError('Резервная копия не совпадает.')
  else:files[backup]=original
  files['GeometryDash.exe']=patched
  files['.mucho-launcher/install.json']=(json.dumps({'backup':backup,'source_sha':sha(original),'version':self.manifest['version']},ensure_ascii=False)+'\n').encode()
  stage=Path(tempfile.mkdtemp(prefix='mucho-install-'));changed=[]
  try:
   # Validate every destination before any mutation.
   destinations={name:safe_path(root,name) for name in files}
   for i,(name,data) in enumerate(files.items()):
    path=destinations[name];path.parent.mkdir(parents=True,exist_ok=True)
    old=None
    if path.exists():old=stage/str(i);shutil.copyfile(path,old)
    fd,tmp=tempfile.mkstemp(prefix='.mucho-',dir=path.parent)
    try:
     with os.fdopen(fd,'wb') as f:f.write(data);f.flush();os.fsync(f.fileno())
     if file_sha(Path(tmp))!=sha(data):raise IOError('Ошибка проверки временного файла.')
     os.replace(tmp,path);changed.append((path,old))
    finally:
     if os.path.exists(tmp):os.unlink(tmp)
    progress((i+1)*95//len(files),name)
   issues=self.verify(root,lambda p,t:None)
   if issues:raise IOError('Проверка после установки не прошла: '+', '.join(issues[:3]))
  except Exception:
   for path,old in reversed(changed):
    if old:os.replace(old,path)
    else:path.unlink(missing_ok=True)
   raise
  finally:shutil.rmtree(stage,ignore_errors=True)
  progress(100,'Готово. Все обязательные компоненты проверены.');return []
 def restore(self,folder,progress=lambda p,t:None):
  root=Path(folder).resolve();original,_=self.source(root);writable_exe(root)
  target=safe_path(root,'GeometryDash.exe');fd,tmp=tempfile.mkstemp(prefix='.mucho-restore-',dir=root)
  try:
   with os.fdopen(fd,'wb') as f:f.write(original);f.flush();os.fsync(f.fileno())
   os.replace(tmp,target);safe_path(root,'.mucho-launcher/install.json').unlink(missing_ok=True)
  finally:
   if os.path.exists(tmp):os.unlink(tmp)
  progress(100,'Исходный EXE восстановлен.');return []
 def launch(self,folder,progress=lambda p,t:None):
  root=Path(folder).resolve();issues=self.verify(root,progress)
  if issues:raise ValueError('Запуск заблокирован: нажми «Восстановить» ('+', '.join(issues[:3])+').')
  return subprocess.Popen([str(root/'GeometryDash.exe')],cwd=root)
