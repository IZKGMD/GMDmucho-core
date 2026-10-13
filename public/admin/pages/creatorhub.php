<?php
declare(strict_types=1);
use MuchoCore\Creator\CreatorHubService;
function creatorHubAdminActor(CreatorHubService $hub):array {
 requirePermission('moderation.manage');$a=admin();return $hub->adminActor((int)($a['id']??0),(string)($a['username']??'Administrator'));
}
function handleCreatorHubAdminAction(PDO $db,string $action):void {
 $hub=new CreatorHubService($db);$a=creatorHubAdminActor($hub);$id=(int)($_POST['request_id']??0);$revision=(int)($_POST['revision']??0);
 try {
  match($action){
   'creatorhub-claim'=>$hub->claim($a,$id,$revision),
   'creatorhub-release'=>$hub->release($a,$id,$revision),
   'creatorhub-decide'=>$hub->decide($a,$id,$revision,(string)($_POST['state']??''),(string)($_POST['note']??'')),
   default=>throw new RuntimeException('Unknown Creator Hub action.')};
  audit($db,'creatorhub.review',(string)$id,['request_id'=>$id,'action'=>$action]);flash('Решение сохранено. Автор увидит его в MuchoClient.','ok');
 }catch(Throwable $e){flash($e instanceof \PDOException?'Хранилище Creator Hub недоступно. Выполни миграции.':$e->getMessage(),'err');}
 header('Location: /admin/?page=creatorhub&id='.$id);exit;
}
function creatorHubState(string $state):string {return match($state){'submitted'=>'В очереди','in_review'=>'На рассмотрении','changes_requested'=>'Нужны исправления','approved'=>'Рекомендован к рейтингу','rejected'=>'Отклонён','withdrawn'=>'Отозван',default=>$state};}
function renderCreatorHubPage(PDO $db):void {
 $hub=new CreatorHubService($db);$a=creatorHubAdminActor($hub);$id=(int)($_GET['id']??0);$page=max(1,(int)($_GET['p']??1));$view=($_GET['view']??'queue')==='archive'?'archive':'queue';
 echo '<section class="card"><div class="muted">MUCHOCLIENT / CREATOR HUB</div><h2>От уровня до решения</h2><p class="muted">Очередь авторских заявок с историей рассмотрения и обратной связью внутри игры.</p><div class="row"><a class="btn" href="/admin/?page=creatorhub">Очередь</a><a class="btn gray" href="/admin/?page=creatorhub&view=archive">Архив</a></div></section>';
 try {
  if($id){
   $r=$hub->detail($a,$id);
   echo '<section class="card"><div class="row"><div><h2>'.h($r['level_name']).'</h2><p class="muted">'.h($r['creator_name']).' · уровень #'.(int)$r['level_id'].' · версия '.(int)$r['level_version'].' · цикл '.(int)$r['cycle'].'</p></div><span class="badge">'.h(creatorHubState($r['state'])).'</span></div>';
   echo '<p style="white-space:pre-wrap">'.h($r['author_note']).'</p>';
   if($r['level_changed'])echo '<p class="red">Уровень изменился после заявки. Автору нужно отправить новую версию.</p>';
   if($r['stars']>0)echo '<p class="green">Уровень уже получил '.(int)$r['stars'].' звёзд.</p>';
   if(canPermission('levels.rate'))echo '<a class="btn gray" href="/admin/?page=rating&id='.(int)$r['level_id'].'">Открыть Rating Studio</a>';
   $formStart='<form method="post" action="/admin/?page=creatorhub"><input type="hidden" name="csrf" value="'.h(csrf()).'"><input type="hidden" name="request_id" value="'.$id.'"><input type="hidden" name="revision" value="'.(int)$r['revision'].'">';
   if($r['state']==='submitted')echo $formStart.'<button name="action" value="creatorhub-claim">Взять на рассмотрение</button></form>';
   if($r['state']==='in_review'){
    echo '<p class="muted">Рассматривает: '.h($r['reviewer_name']??'').'</p>';
    if($r['assigned_to_me']&&!$r['level_changed'])echo $formStart.'<label>Ответ автору<textarea name="note" maxlength="2000" rows="4" placeholder="Что получилось хорошо и что стоит исправить?"></textarea></label><input type="hidden" name="action" value="creatorhub-decide"><div class="row"><button name="state" value="changes_requested">Нужны исправления</button><button name="state" value="approved">Рекомендовать к рейтингу</button><button class="danger" name="state" value="rejected">Отклонить</button></div></form>';
    echo $formStart.'<button class="gray" name="action" value="creatorhub-release">Вернуть в очередь</button></form>';
   }
   echo '</section><section class="card"><h3>История рассмотрения</h3>';
   foreach($r['history'] as $event)echo '<article style="padding:14px 0;border-bottom:1px solid var(--border)"><b>'.h(creatorHubState($event['action'])).'</b> · '.h($event['actor_name']).'<small class="muted"> · '.h($event['created_at']).' · цикл '.(int)$event['cycle'].'</small><p style="white-space:pre-wrap">'.h($event['note']).'</p></article>';
   echo '</section>';
  }else{
   $data=$hub->listing($a,$view,$page);echo '<section class="card"><div class="table"><table><tr><th>Уровень</th><th>Автор</th><th>Статус</th><th>Рассматривает</th><th></th></tr>';
   foreach($data['items'] as $r)echo '<tr><td><b>'.h($r['level_name']).'</b><br><small>#'.(int)$r['level_id'].' · v'.(int)$r['level_version'].($r['level_changed']?' · изменён':'').'</small></td><td>'.h($r['creator_name']).'</td><td>'.h(creatorHubState($r['state'])).'</td><td>'.h($r['reviewer_name']??'—').'</td><td><a class="btn" href="/admin/?page=creatorhub&id='.(int)$r['id'].'">Рассмотреть</a></td></tr>';
   echo '</table></div>';if(!$data['items'])echo '<p class="muted">Заявок пока нет. Авторы могут отправлять опубликованные уровни из Creator Hub в MuchoClient.</p>';
   echo '<div class="row">';if($page>1)echo '<a class="btn gray" href="/admin/?page=creatorhub&view='.$view.'&p='.($page-1).'">Назад</a>';if($data['has_next'])echo '<a class="btn" href="/admin/?page=creatorhub&view='.$view.'&p='.($page+1).'">Далее</a>';echo '</div></section>';
  }
 }catch(Throwable $e){echo '<section class="card"><p class="red">'.h($e instanceof \PDOException?'Хранилище Creator Hub недоступно. Обнови ядро и выполни миграции.':$e->getMessage()).'</p></section>';}
}
