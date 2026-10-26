<?php
declare(strict_types=1);
namespace MuchoCore\CreatorEvents;

use PDO;
use MuchoCore\Http\Request;
use RuntimeException;

final class CreatorEventsAdmin
{
    public static function action(PDO $db,array $data): void
    {
        $r=new Request('POST','/',[],$data,[]);$s=new CreatorEventsService($db);
        $operation=$r->postString('operation');
        if($operation==='install'){$s->install();return;}
        if(!$s->installed())throw new RuntimeException('Install Creator Events first.');
        if($operation==='enable'||$operation==='disable'){$s->toggle($operation==='enable');return;}
        $id=$r->postInt('event');
        if($operation==='create')$s->create($r->postString('title'),$r->postString('rules'),$r->postString('deadline'),$r->postInt('minimum',1));
        elseif($operation==='judge')$s->addJudge($id,$r->postInt('judge'));
        elseif($operation==='remove-judge')$s->removeJudge($id,$r->postInt('judge'));
        elseif($operation==='edit')$s->edit($id,$r->postString('title'),$r->postString('rules'),$r->postString('deadline'),$r->postInt('minimum',1));
        elseif($operation==='disqualify')$s->disqualify($id,$r->postInt('entry'),$r->postString('reason'));
        elseif(in_array($operation,['open','judging','published','cancelled'],true))$s->transition($id,$operation);
        else throw new RuntimeException('Unknown Creator Events action.');
    }
    private static function h(mixed $text): string{return htmlspecialchars((string)$text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function form(string $operation,int $id=0): string
    {
        return '<form method="post"><input type="hidden" name="csrf" value="'.self::h(\csrf()).'"><input type="hidden" name="action" value="creator-events"><input type="hidden" name="return" value="plugins"><input type="hidden" name="operation" value="'.$operation.'"><input type="hidden" name="event" value="'.$id.'">';
    }
    public static function render(PDO $db): void
    {
        $s=new CreatorEventsService($db);$installed=$s->installed();
        $owner=(string)(\admin()['role']??'')==='owner';
        echo '<section class="card"><div class="muted">OFFICIAL PLUGIN · 1.0.0</div><h2>Creator Events</h2><p>Run building contests with deadlines, an assigned jury and published rankings. Includes a player submission and judging portal. Permissions: database and extension routes.</p>';
        if(!$installed){if($owner)echo self::form('install').'<button>Install Creator Events</button></form>';else echo '<p>The server owner can install this plugin.</p>';echo '</section>';return;}
        $enabled=$s->enabled();echo '<p><span class="badge">'.($enabled?'Enabled':'Disabled').'</span> · Event data is retained when disabled.</p>';
        if($owner)echo self::form($enabled?'disable':'enable').'<button>'.($enabled?'Disable':'Enable').'</button></form>';
        $mount=function_exists('mucho7_domain_environment')?(string)($GLOBALS['mucho7_domain_mount']??''):'';
        echo '<p><a href="'.self::h($mount.'/extensions/creator-events').'" target="_blank" rel="noopener">Open player portal ↗</a></p>';
        if(!$enabled){echo '</section>';return;}
        echo '<p>One public level per author. Judges cannot participate in their own contests. Scores use Design, Gameplay and Originality (0–10 each); equal totals share a rank. Published results are frozen. No game rating or currency is awarded automatically.</p>';
        if($owner)echo '<details><summary>Create contest</summary>'.self::form('create').'<label>Title<input name="title" maxlength="100" required></label><label>Theme and rules<textarea name="rules" maxlength="10000" required></textarea></label><label>Submission deadline (UTC)<input type="datetime-local" name="deadline" required></label><label>Required reviews per entry<input type="number" name="minimum" min="1" max="20" value="1" required></label><button>Create draft</button></form></details>';
        $events=$s->events(true);echo '<h3>Contests</h3><p>Showing the latest 100 contests. Each contest accepts up to 200 entries.</p><div class="table"><table><tr><th>Contest</th><th>Stage</th><th>Deadline (UTC)</th><th></th></tr>';
        foreach($events as $e)echo '<tr><td>'.self::h($e['title']).'</td><td>'.self::h($e['state']).'</td><td>'.self::h($e['deadline']).'</td><td><a href="?page=plugins&amp;creator_event='.(int)$e['id'].'">Manage</a></td></tr>';
        echo '</table></div>';
        $chosen=$_GET['creator_event']??'';$id=is_string($chosen)&&ctype_digit($chosen)?(int)$chosen:0;
        if($id>0){$e=$s->event($id);echo '<h3>'.self::h($e['title']).'</h3><p>'.nl2br(self::h($e['rules'])).'</p><p>Stage: '.self::h($e['state']).' · Minimum reviews: '.(int)$e['min_judges'].'</p><h4>Jury</h4><ul>';
            foreach($s->judges($id) as $judge)echo '<li>'.self::h($judge['username']).' #'.(int)$judge['account_id'].'</li>';echo '</ul>';
            if($owner&&$e['state']==='draft')echo self::form('judge',$id).'<label>Judge account ID<input name="judge" type="number" min="1" required></label><button>Add judge</button></form>';
            if($owner&&$e['state']==='draft'){
                echo self::form('remove-judge',$id).'<label>Remove judge account ID<input name="judge" type="number" min="1" required></label><button>Remove judge</button></form>';
                echo '<details><summary>Edit draft</summary>'.self::form('edit',$id).'<label>Title<input name="title" value="'.self::h($e['title']).'" maxlength="100" required></label><label>Rules<textarea name="rules" maxlength="10000" required>'.self::h($e['rules']).'</textarea></label><label>Deadline (UTC)<input name="deadline" type="datetime-local" value="'.self::h(str_replace(' ','T',substr($e['deadline'],0,16))).'" required></label><label>Required reviews<input name="minimum" type="number" min="1" max="20" value="'.(int)$e['min_judges'].'" required></label><button>Save draft</button></form></details>';
            }
            if($owner){$next=['draft'=>'open','open'=>'judging','judging'=>'published'];if(isset($next[$e['state']]))echo self::form($next[$e['state']],$id).'<button>'.(['draft'=>'Open submissions','open'=>'Start judging after deadline','judging'=>'Publish results'][$e['state']]).'</button></form>';if(isset($next[$e['state']]))echo self::form('cancelled',$id).'<button>Cancel contest</button></form>';}
            if($owner&&$e['state']==='judging')echo '<details><summary>Disqualify an entry</summary>'.self::form('disqualify',$id).'<label>Entry ID<input type="number" name="entry" min="1" required></label><label>Reason<textarea name="reason" maxlength="1000" required></textarea></label><button>Disqualify</button></form></details>';
            echo '<h4>Entries and review progress</h4><div class="table"><table><tr><th>Entry</th><th>Author</th><th>Level</th><th>Reviews</th><th>Rank</th><th>Score / 30</th></tr>';
            foreach($s->entries($id) as $entry)echo '<tr><td>#'.(int)$entry['id'].'</td><td>'.self::h($entry['username']).'</td><td>'.self::h($entry['level_name']).' #'.(int)$entry['level_id'].'</td><td>'.(int)$entry['reviews'].'</td><td>'.self::h((int)$entry['disqualified']?'Disqualified: '.$entry['disqualification_note']:($entry['result_rank']??'Pending')).'</td><td>'.self::h($entry['result_score']??'Pending').'</td></tr>';echo '</table></div>';
            echo '<details><summary>Jury scorecards</summary><div class="table"><table><tr><th>Entry</th><th>Judge</th><th>Design</th><th>Gameplay</th><th>Originality</th><th>Note</th></tr>';
            foreach($s->reviews($id) as $review)echo '<tr><td>#'.(int)$review['entry_id'].'</td><td>'.self::h($review['username']).'</td><td>'.(int)$review['design'].'</td><td>'.(int)$review['gameplay'].'</td><td>'.(int)$review['originality'].'</td><td>'.self::h($review['note']).'</td></tr>';echo '</table></div></details>';
        }echo '</section>';
    }
}
