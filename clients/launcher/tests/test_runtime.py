import sys,tempfile,unittest
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from runtime import inspect_runtime,CRITICAL
class RuntimeTests(unittest.TestCase):
 def setUp(self):
  self.temp=tempfile.TemporaryDirectory();self.root=Path(self.temp.name);self.game=self.root/'game';self.system=self.root/'system';self.game.mkdir();self.system.mkdir();self.versions={}
  for name in CRITICAL:(self.system/name).touch();self.versions[str(self.system/name)]=(14,44,35211,0)
 def tearDown(self):self.temp.cleanup()
 def inspect(self):return inspect_runtime(self.game,self.system,lambda path:self.versions.get(str(path)))
 def test_new_system(self):self.assertEqual(self.inspect()['issues'],[]);self.assertEqual(self.inspect()['quarantine'],[])
 def test_local_old_dll_shadows_updated_redist(self):
  p=self.game/'vcruntime140.dll';p.touch();self.versions[str(p)]=(14,29,30139,0)
  result=self.inspect();self.assertEqual(result['issues'],[]);self.assertEqual(result['quarantine'],['vcruntime140.dll'])
 def test_system_old(self):
  self.versions[str(self.system/'vcruntime140.dll')]=(14,42,1,0);self.assertTrue(self.inspect()['issues']);self.assertEqual(self.inspect()['quarantine'],[])
 def test_unknown_local_not_removed(self):
  (self.game/'vcruntime140.dll').touch();self.assertTrue(self.inspect()['issues']);self.assertEqual(self.inspect()['quarantine'],[])
 def test_modern_local_preserved(self):
  p=self.game/'vcruntime140.dll';p.touch();self.versions[str(p)]=(14,50,1,0);self.assertEqual(self.inspect()['quarantine'],[])
if __name__=='__main__':unittest.main()
