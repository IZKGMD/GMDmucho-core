import json,sys,os
from pathlib import Path
from PySide6.QtCore import Qt,QObject,QThread,Signal,QTimer,QStandardPaths,QLockFile,QUrl
from PySide6.QtGui import QFont,QDesktopServices
from PySide6.QtWidgets import QApplication,QMainWindow,QWidget,QHBoxLayout,QVBoxLayout,QLabel,QPushButton,QFrame,QFileDialog,QProgressBar,QMessageBox,QScrollArea
from engine import Engine
import build_config
from runtime import DOWNLOAD
BASE=Path(getattr(sys,'_MEIPASS',Path(__file__).parent))
STYLE='''QWidget{background:#090a10;color:#f4f3ff;font-family:"Segoe UI";font-size:14px;}QFrame#sidebar{background:#11131d;border-right:1px solid #2b2e40;}QFrame#card{background:#11131d;border:1px solid #2b2e40;border-radius:18px;}QLabel{background:transparent;}QLabel#muted{color:#aaaec4;}QLabel#eyebrow{color:#b4a0ff;font-size:11px;font-weight:700;}QLabel#heading{font-size:45px;font-weight:750;}QLabel#logo{font-size:24px;font-weight:800;color:#b4a0ff;}QLabel#success{color:#b7ef76;}QPushButton{background:#191c29;border:1px solid #2b2e40;border-radius:10px;padding:12px 18px;text-align:left;font-weight:600;}QPushButton:hover{border-color:#b4a0ff;background:#222638;}QPushButton:disabled{color:#77798e;background:#151723;}QPushButton#primary{background:#7960f2;border-color:#907bff;font-size:19px;padding:17px 23px;}QPushButton#primary:hover{background:#8b74ff;}QProgressBar{background:#191c29;border:0;border-radius:5px;height:10px;}QProgressBar::chunk{background:#b4a0ff;border-radius:5px;}'''
class Worker(QObject):
 progress=Signal(int,str);done=Signal(bool,object)
 def __init__(self,fn):super().__init__();self.fn=fn
 def run(self):
  try:self.done.emit(True,self.fn(self.progress.emit))
  except Exception as e:self.done.emit(False,str(e))
class Window(QMainWindow):
 def __init__(self):
  super().__init__();self.setWindowTitle('MuchoLauncher');self.resize(1080,720);self.setMinimumSize(900,640)
  self.engine=Engine(BASE/'payload.zip',build_config.MANIFEST,build_config.PAYLOAD_SHA);self.thread=None;self.worker=None;self.process=None
  self.config=Path(QStandardPaths.writableLocation(QStandardPaths.AppConfigLocation));self.config.mkdir(parents=True,exist_ok=True)
  self.folder='';saved=self.config/'settings.json'
  if saved.exists():
   try:self.folder=json.loads(saved.read_text('utf8')).get('folder','')
   except Exception:pass
  host=QWidget();self.setCentralWidget(host);outer=QHBoxLayout(host);outer.setContentsMargins(0,0,0,0);outer.setSpacing(0)
  side=QFrame();side.setObjectName('sidebar');side.setFixedWidth(224);sl=QVBoxLayout(side);sl.setContentsMargins(24,30,24,25);sl.setSpacing(15)
  sl.addWidget(self.label('M / MUCHO','logo'));sl.addWidget(self.label('LAUNCHER  /  1.2.1','eyebrow'));sl.addSpacing(35)
  for title,url in [('Главная',None),('Сообщество','https://muchogdps.space/dashboard'),('MuchoCore','https://muchogdps.space/muchocore/'),('Visual C++ x64',DOWNLOAD)]:
   b=QPushButton(title);b.clicked.connect((lambda checked=False,u=url:QDesktopServices.openUrl(QUrl(u))) if url else self.home);sl.addWidget(b)
  sl.addStretch();sl.addWidget(self.label('Твоя игра.\nТвоё сообщество.\nТвоя вселенная.','muted'));outer.addWidget(side)
  main=QWidget();ml=QVBoxLayout(main);ml.setContentsMargins(38,30,38,26);ml.setSpacing(20);main.setMinimumSize(786,700);scroll=QScrollArea();scroll.setWidgetResizable(True);scroll.setFrameShape(QFrame.NoFrame);scroll.setWidget(main);outer.addWidget(scroll,1)
  top=QHBoxLayout();top.addWidget(self.label('ЭКОСИСТЕМА MUCHO / WINDOWS','eyebrow'));top.addStretch();top.addWidget(self.label('●  Проверка перед запуском','success'));ml.addLayout(top)
  hero=QFrame();hero.setObjectName('card');hl=QVBoxLayout(hero);hl.setContentsMargins(27,25,27,25);hl.setSpacing(11)
  hl.addWidget(self.label('Твоя вселенная.','heading'));hl.addWidget(self.label('MuchoGeode + MuchoClient\nВсе функции — внутри твоей игры.','muted'))
  hl.addSpacing(10);actions=QHBoxLayout();self.play=QPushButton('▶  Играть');self.play.setObjectName('primary');self.play.clicked.connect(lambda:self.operation('launch'));actions.addWidget(self.play,2)
  self.choose=QPushButton('Папка игры');self.choose.clicked.connect(self.select);actions.addWidget(self.choose);hl.addLayout(actions)
  self.path=self.label('Выбери папку клиента MuchoGDPS 2.2081','muted');self.path.setWordWrap(True);hl.addWidget(self.path);ml.addWidget(hero)
  columns=QHBoxLayout();self.cards=[]
  for title,detail in [('MuchoGeode','Загрузка модов и расширений'),('MuchoClient','Профили, Social и Creator Hub'),('Защита файлов','SHA-256 / обязательный runtime')]:
   c=QFrame();c.setObjectName('card');cl=QVBoxLayout(c);cl.setContentsMargins(18,19,18,19);cl.addWidget(self.label(title));cl.addWidget(self.label(detail,'muted'));st=self.label('Требуется проверка','eyebrow');cl.addWidget(st);self.cards.append(st);columns.addWidget(c)
  ml.addLayout(columns)
  panel=QFrame();panel.setObjectName('card');pl=QVBoxLayout(panel);pl.setContentsMargins(22,20,22,20)
  self.status=self.label('Готов к установке');pl.addWidget(self.status);self.progress=QProgressBar();self.progress.setRange(0,100);self.progress.setTextVisible(False);pl.addWidget(self.progress)
  self.detail=self.label('Сохранения и сторонние моды остаются на месте.','muted');self.detail.setWordWrap(True);pl.addWidget(self.detail)
  row=QHBoxLayout();self.check=QPushButton('Проверить');self.check.clicked.connect(lambda:self.operation('verify'));self.repair=QPushButton('Установить / восстановить');self.repair.clicked.connect(lambda:self.operation('install'));self.rollback=QPushButton('Вернуть исходный EXE');self.rollback.clicked.connect(self.restore)
  for b in [self.check,self.repair,self.rollback]:row.addWidget(b)
  pl.addLayout(row);ml.addWidget(panel);ml.addStretch();ml.addWidget(self.label('MuchoCore  ·  MuchoGeode основан на Geode  ·  GD 2.2081 x64','muted'))
  self.timer=QTimer(self);self.timer.timeout.connect(self.process_tick);self.timer.start(1000);self.home()
 def label(self,text,kind=''):
  l=QLabel(text);l.setObjectName(kind)
  if kind=='muted':l.setWordWrap(True)
  return l
 def home(self):self.path.setText(self.folder or 'Выбери папку клиента MuchoGDPS 2.2081');self.play.setEnabled(bool(self.folder))
 def select(self):
  folder=QFileDialog.getExistingDirectory(self,'Папка клиента MuchoGDPS',self.folder)
  if folder:self.folder=folder;(self.config/'settings.json').write_text(json.dumps({'folder':folder},ensure_ascii=False),'utf8');self.home();self.operation('verify')
 def active(self,busy):
  for b in [self.play,self.choose,self.check,self.repair,self.rollback]:b.setEnabled(not busy)
  if not busy:self.play.setEnabled(bool(self.folder))
 def operation(self,name):
  if self.thread and self.thread.isRunning():return
  if not self.folder:self.select();return
  if self.process and self.process.poll() is None:return
  self.active(True);self.status.setText('Проверяем обязательные файлы…' if name in ('verify','launch') else 'Восстанавливаем установку…');self.progress.setValue(0)
  self.worker=Worker(lambda report:getattr(self.engine,name)(self.folder,report));self.thread=QThread(self);self.worker.moveToThread(self.thread)
  self.thread.started.connect(self.worker.run);self.worker.progress.connect(self.report);self.worker.done.connect(lambda ok,result:self.finish(name,ok,result));self.worker.done.connect(self.thread.quit);self.thread.finished.connect(self.worker.deleteLater);self.thread.start()
 def report(self,n,text):self.progress.setValue(n);self.detail.setText(text)
 def finish(self,name,ok,result):
  self.active(False)
  if not ok:self.status.setText('Операция остановлена');self.detail.setText(str(result));return
  if name=='launch':self.process=result;self.active(True);self.status.setText('Игра запущена');return
  if name=='verify' and result:
   self.status.setText(f'Требуется восстановление: {len(result)} файлов');self.detail.setText('\n'.join(result[:3]));[c.setText('Нужна проверка / восстановление') for c in self.cards];self.play.setEnabled(False)
  elif name=='restore':self.status.setText('Исходный EXE восстановлен');[c.setText('Запуск через лаунчер требует установки') for c in self.cards]
  else:self.status.setText('Все файлы проверены. Можно играть.');self.detail.setText('MuchoGeode, MuchoClient и встроенная проверка запуска готовы.');[c.setText('●  Проверено') for c in self.cards]
 def restore(self):
  if QMessageBox.question(self,'Вернуть EXE','Вернуть исходный EXE из проверенной резервной копии? Сохранения останутся на месте.')==QMessageBox.Yes:self.operation('restore')
 def process_tick(self):
  if self.process and self.process.poll() is not None:self.process=None;self.active(False);self.status.setText('Игра закрыта')
 def closeEvent(self,event):
  if self.thread and self.thread.isRunning():self.status.setText('Дождись завершения операции');event.ignore()
  else:event.accept()
if __name__=='__main__':
 app=QApplication(sys.argv);app.setApplicationName('MuchoLauncher');app.setOrganizationName('MuchoCore');app.setStyleSheet(STYLE)
 lockdir=Path(QStandardPaths.writableLocation(QStandardPaths.AppConfigLocation));lockdir.mkdir(parents=True,exist_ok=True);lock=QLockFile(str(lockdir/'launcher.lock'))
 if not lock.tryLock(100):QMessageBox.information(None,'MuchoLauncher','Лаунчер уже открыт.');sys.exit(0)
 window=Window();window.show()
 if '--screenshot' in sys.argv:QTimer.singleShot(600,lambda:(window.grab().save('launcher-preview.png'),app.quit()))
 sys.exit(app.exec())
