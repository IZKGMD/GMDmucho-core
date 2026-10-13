import sys,tempfile,unittest,zipfile,json,hashlib,struct,os
from unittest.mock import patch as mock_patch
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from engine import Engine,safe_path
from pe_gate import inspect,patch,gated,MENU_RVA,MENU_HEAD,rva_offset
def fixture():
 # Synthetic PE64 with the supported menu RVA, not game code.
 text_size=0x334000;raw=0x400;data=bytearray(raw+text_size+0x200);data[:2]=b'MZ'
 struct.pack_into('<I',data,0x3c,0x80);data[0x80:0x84]=b'PE\0\0'
 struct.pack_into('<HHIIIHH',data,0x84,0x8664,2,0,0,0,240,0x22)
 opt=0x98;struct.pack_into('<H',data,opt,0x20b)
 for offset,value in [(16,0x1000),(32,0x1000),(36,0x200),(56,0x401000),(60,0x400),(108,16),(120,0x400000),(124,20)]:struct.pack_into('<I',data,opt+offset,value)
 for at,name,size,rva,rawsize,ptr in [(opt+240,b'.text',text_size,0x1000,text_size,raw),(opt+280,b'.rdata',0x200,0x400000,0x200,raw+text_size)]:
  struct.pack_into('<8sIIIIIIHHI',data,at,name,size,rva,rawsize,ptr,0,0,0,0,0x60000020)
 data[raw+MENU_RVA-0x1000:raw+MENU_RVA-0x1000+7]=MENU_HEAD
 return bytes(data),hashlib.sha256(data[raw:raw+text_size]).hexdigest()
class Integrity(unittest.TestCase):
 def setUp(self):
  self.temp=tempfile.TemporaryDirectory();self.root=Path(self.temp.name);self.game=self.root/'game';self.game.mkdir()
  self.payload=self.root/'payload.zip';self.data=b'mandatory runtime';self.path='Geode.dll'
  with zipfile.ZipFile(self.payload,'w') as z:z.writestr(self.path,self.data)
  self.manifest={'version':'test','text_sha':'','files':[{'path':self.path,'sha256':hashlib.sha256(self.data).hexdigest(),'size':len(self.data)}]}
  self.engine=Engine(self.payload,self.manifest,hashlib.sha256(self.payload.read_bytes()).hexdigest())
 def tearDown(self):self.temp.cleanup()
 def test_payload_pin(self):
  self.assertEqual(self.engine.payload()[self.path],self.data);self.payload.write_bytes(b'corrupt')
  with self.assertRaises(ValueError):self.engine.payload()
 def test_missing_and_corrupt(self):
  self.assertIn(self.path,self.engine.verify(self.game));(self.game/self.path).write_bytes(b'altered');self.assertIn(self.path,self.engine.verify(self.game))
 def test_paths(self):
  for value in ['../outside','/absolute','C:/test','a\\b']:
   with self.assertRaises(ValueError):safe_path(self.game,value)
 def test_symlink(self):
  try:(self.game/'linked').symlink_to(self.root,target_is_directory=True)
  except OSError:self.skipTest('Symlink privileges unavailable')
  with self.assertRaises(ValueError):safe_path(self.game,'linked/file')
 def test_unknown_exe_untouched(self):
  original=b'unsupported exe';(self.game/'GeometryDash.exe').write_bytes(original)
  with self.assertRaises(ValueError):self.engine.install(self.game)
  self.assertEqual((self.game/'GeometryDash.exe').read_bytes(),original);self.assertFalse((self.game/'Geode.dll').exists())
 def test_launch_is_blocked(self):
  with self.assertRaises(ValueError):self.engine.launch(self.game)
 def test_install_repair_restore(self):
  original,digest=fixture();self.manifest['text_sha']=digest;(self.game/'GeometryDash.exe').write_bytes(original)
  self.engine.install(self.game);self.assertEqual(self.engine.verify(self.game),[])
  modified=(self.game/'GeometryDash.exe').read_bytes();self.assertTrue(gated(modified))
  import pefile
  pe=pefile.PE(data=modified);imports={x.dll.decode():[y.name for y in x.imports] for x in pe.DIRECTORY_ENTRY_IMPORT}
  self.assertEqual(imports['MuchoBootstrap.dll'],[b'MuchoBootstrapStart',b'MuchoClientRequire'])
  self.assertNotEqual(pe.OPTIONAL_HEADER.AddressOfEntryPoint,0x1000)
  with self.assertRaises(ValueError):patch(modified,digest)
  (self.game/'Geode.dll').write_bytes(b'broken');self.assertIn('Geode.dll',self.engine.verify(self.game))
  self.engine.install(self.game);self.assertEqual(self.engine.verify(self.game),[])
  (self.game/'GeometryDash.exe').write_bytes(original);self.assertIn('GeometryDash.exe',self.engine.verify(self.game))
  self.engine.install(self.game);self.engine.restore(self.game)
  self.assertEqual((self.game/'GeometryDash.exe').read_bytes(),original);self.assertFalse(self.engine.state(self.game))
 def test_transaction_rollback(self):
  original,digest=fixture();self.manifest['text_sha']=digest;(self.game/'GeometryDash.exe').write_bytes(original)
  (self.game/'Geode.dll').write_bytes(b'previous runtime');real_replace=os.replace;calls=0
  def replace(source,target):
   nonlocal calls
   calls+=1
   if calls==3:raise OSError('Simulated interrupted write')
   real_replace(source,target)
  with mock_patch('engine.os.replace',side_effect=replace):
   with self.assertRaises(OSError):self.engine.install(self.game)
  self.assertEqual((self.game/'Geode.dll').read_bytes(),b'previous runtime')
  self.assertEqual((self.game/'GeometryDash.exe').read_bytes(),original);self.assertFalse(self.engine.state(self.game))
if __name__=='__main__':unittest.main()
