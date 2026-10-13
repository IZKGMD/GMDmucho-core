"""Inspect the DLLs Windows will actually load, including game-local overrides."""
import ctypes,os
from pathlib import Path
MIN_VERSION=(14,44,0,0)  # Geode 5.11 Windows proxy requires v14.44 or newer.
CRITICAL=('vcruntime140.dll','vcruntime140_1.dll','msvcp140.dll')
DLLS=CRITICAL+('msvcp140_1.dll','msvcp140_2.dll','msvcp140_atomic_wait.dll','msvcp140_codecvt_ids.dll')
DOWNLOAD='https://aka.ms/vc14/vc_redist.x64.exe'
def version_string(value):return '.'.join(map(str,value)) if value else 'не найден / версия недоступна'
def file_version(path):
 if os.name!='nt' or not Path(path).is_file():return None
 from ctypes import wintypes
 api=ctypes.WinDLL('version',use_last_error=True)
 api.GetFileVersionInfoSizeW.argtypes=[wintypes.LPCWSTR,ctypes.POINTER(wintypes.DWORD)];api.GetFileVersionInfoSizeW.restype=wintypes.DWORD
 dummy=wintypes.DWORD();size=api.GetFileVersionInfoSizeW(str(path),ctypes.byref(dummy))
 if not size or size>1024*1024:return None
 data=ctypes.create_string_buffer(size)
 api.GetFileVersionInfoW.argtypes=[wintypes.LPCWSTR,wintypes.DWORD,wintypes.DWORD,ctypes.c_void_p]
 api.VerQueryValueW.argtypes=[ctypes.c_void_p,wintypes.LPCWSTR,ctypes.POINTER(ctypes.c_void_p),ctypes.POINTER(wintypes.UINT)]
 if not api.GetFileVersionInfoW(str(path),0,size,data):return None
 ptr=ctypes.c_void_p();length=wintypes.UINT()
 if not api.VerQueryValueW(data,'\\',ctypes.byref(ptr),ctypes.byref(length)) or length.value<52:return None
 fixed=ctypes.cast(ptr,ctypes.POINTER(ctypes.c_uint32));ms,ls=fixed[2],fixed[3]
 if fixed[0]!=0xFEEF04BD:return None
 return (ms>>16,ms&65535,ls>>16,ls&65535)
def system_dir():
 from ctypes import wintypes
 api=ctypes.WinDLL('kernel32',use_last_error=True);api.GetSystemDirectoryW.argtypes=[wintypes.LPWSTR,wintypes.UINT];api.GetSystemDirectoryW.restype=wintypes.UINT
 buffer=ctypes.create_unicode_buffer(32768);n=api.GetSystemDirectoryW(buffer,len(buffer))
 if not n or n>=len(buffer):raise OSError('Не удалось определить System32.')
 return Path(buffer.value)
def inspect_runtime(folder,system=None,reader=None):
 if system is None and os.name!='nt':return {'issues':[],'quarantine':[],'versions':[]}
 root=Path(folder);system=Path(system) if system is not None else system_dir();reader=reader or file_version
 issues=[];quarantine=[];versions=[]
 for name in DLLS:
  local=root/name;global_path=system/name;selected=local if local.exists() else global_path
  if name not in CRITICAL and not local.exists():continue
  version=reader(selected);versions.append({'path':str(selected),'version':version_string(version)})
  if version and version[0]==14 and version>=MIN_VERSION:continue
  global_version=reader(global_path)
  if local.exists() and version and global_version and global_version[0]==14 and global_version>=MIN_VERSION:
   quarantine.append(name)
  else:issues.append(f'{selected}: {version_string(version)}. Нужен Microsoft Visual C++ x64 14.44 или новее; нажми «Visual C++ x64», установи пакет и перезапусти Windows.')
 return {'issues':issues,'quarantine':quarantine,'versions':versions}
