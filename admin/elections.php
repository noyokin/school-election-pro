<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/functions.php';
$admin=require_admin('elections');

function local_datetime_to_sql(string $value): ?string {
    $value=trim($value); if($value==='') return null; $timestamp=strtotime($value); return $timestamp===false?null:date('Y-m-d H:i:s',$timestamp);
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();$action=(string)($_POST['action']??'');
    try{
        if($action==='save'){
            $id=(int)($_POST['id']??0);$title=trim((string)($_POST['title']??''));$description=trim((string)($_POST['description']??''));$start=local_datetime_to_sql((string)($_POST['start_at']??''));$end=local_datetime_to_sql((string)($_POST['end_at']??''));
            if($title==='') throw new RuntimeException('Введите название выборов.');
            if($start&&$end&&strtotime($end)<=strtotime($start)) throw new RuntimeException('Окончание должно быть позже начала.');
            $values=[$title,$description,$start,$end,isset($_POST['results_public'])?1:0,isset($_POST['candidates_randomized'])?1:0,isset($_POST['terminal_mode'])?1:0,isset($_POST['second_round_enabled'])?1:0,max(0,min(100,(float)($_POST['second_round_threshold']??50)))];
            if($id>0){
                $election=election_by_id($id);if(!$election) throw new RuntimeException('Выборы не найдены.');
                if(!election_is_editable($election)&&$admin['role']!=='superadmin') throw new RuntimeException('После открытия редактирование доступно только главному администратору.');
                $statement=db()->prepare('UPDATE elections SET title=?,description=?,start_at=?,end_at=?,results_public=?,candidates_randomized=?,terminal_mode=?,second_round_enabled=?,second_round_threshold=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');$statement->execute([...$values,$id]);audit_log('election_updated','election',$id,['title'=>$title]);flash('success','Выборы обновлены.');
            }else{
                $status=$start&&strtotime($start)>time()?'scheduled':'draft';
                $statement=db()->prepare('INSERT INTO elections(title,description,start_at,end_at,status,results_public,candidates_randomized,terminal_mode,second_round_enabled,second_round_threshold) VALUES(?,?,?,?,?,?,?,?,?,?)');$statement->execute([$title,$description,$start,$end,$status,$values[4],$values[5],$values[6],$values[7],$values[8]]);$id=(int)db()->lastInsertId();audit_log('election_created','election',$id,['title'=>$title]);flash('success','Новые выборы созданы.');
            }
        }elseif($action==='set_active'){
            $id=(int)($_POST['id']??0);if(!election_by_id($id)) throw new RuntimeException('Выборы не найдены.');save_setting('active_election_id',(string)$id);audit_log('election_set_active','election',$id);flash('success','Активная кампания изменена.');
        }elseif($action==='open'){
            $id=(int)($_POST['id']??0);$election=election_by_id($id);if(!$election)throw new RuntimeException('Выборы не найдены.');
            $st=db()->prepare('SELECT COUNT(*) FROM candidates WHERE election_id=? AND is_active=1');$st->execute([$id]);if((int)$st->fetchColumn()<2)throw new RuntimeException('Для открытия нужно минимум два активных кандидата.');
            $eligibleCount=snapshot_election_eligibility($id,true);if($eligibleCount<1)throw new RuntimeException('Нет активных учеников для допуска к выборам.');backup_database('before-open-election-'.$id);db()->prepare("UPDATE elections SET status='open',locked_at=COALESCE(locked_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$id]);save_setting('active_election_id',(string)$id);audit_log('election_opened','election',$id);flash('success','Голосование открыто. Зафиксирован список допущенных: '.$eligibleCount.'. Создана резервная копия.');
        }elseif($action==='close'){
            $id=(int)($_POST['id']??0);db()->prepare("UPDATE elections SET status='closed',closed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$id]);audit_log('election_closed','election',$id);flash('success','Голосование закрыто.');
        }elseif($action==='archive'){
            $id=(int)($_POST['id']??0);db()->prepare("UPDATE elections SET status='archived',updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$id]);audit_log('election_archived','election',$id);flash('success','Выборы перемещены в архив.');
        }elseif($action==='duplicate'){
            $sourceId=(int)($_POST['id']??0);$source=election_by_id($sourceId);if(!$source)throw new RuntimeException('Исходные выборы не найдены.');$pdo=db();$pdo->beginTransaction();
            $st=$pdo->prepare("INSERT INTO elections(title,description,status,results_public,candidates_randomized,terminal_mode,second_round_enabled,second_round_threshold) VALUES(?,?,'draft',0,?,?,?,?)");$st->execute([$source['title'].' — копия',$source['description'],$source['candidates_randomized'],$source['terminal_mode'],$source['second_round_enabled'],$source['second_round_threshold']]);$newId=(int)$pdo->lastInsertId();
            $st=$pdo->prepare('INSERT INTO candidates(election_id,full_name,class_name,slogan,program_text,bio,achievements,resources_text,video_url,website_url,photo_path,color,ballot_number,is_active) SELECT ?,full_name,class_name,slogan,program_text,bio,achievements,resources_text,video_url,website_url,photo_path,color,ballot_number,is_active FROM candidates WHERE election_id=?');$st->execute([$newId,$sourceId]);$pdo->commit();audit_log('election_duplicated','election',$newId,['source'=>$sourceId]);flash('success','Создана копия кампании без голосов.');
        }elseif($action==='second_round'){
            $sourceId=(int)($_POST['id']??0);$source=election_by_id($sourceId);if(!$source)throw new RuntimeException('Выборы не найдены.');$rows=result_rows($sourceId);$analysis=second_round_analysis($source,$rows);if(count($analysis['finalists'])<2)throw new RuntimeException('Недостаточно кандидатов для второго тура.');$pdo=db();$pdo->beginTransaction();
            $st=$pdo->prepare("INSERT INTO elections(title,description,status,results_public,candidates_randomized,terminal_mode,second_round_enabled,second_round_threshold) VALUES(?,?,'draft',0,1,1,0,50)");$st->execute(['Второй тур: '.$source['title'],'Во второй тур прошли два кандидата с наибольшим количеством голосов.']);$newId=(int)$pdo->lastInsertId();
            $insert=$pdo->prepare('INSERT INTO candidates(election_id,full_name,class_name,slogan,program_text,bio,achievements,resources_text,video_url,website_url,photo_path,color,ballot_number,is_active) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,1)');$num=1;foreach($analysis['finalists'] as $c){$insert->execute([$newId,$c['full_name'],$c['class_name'],$c['slogan'],$c['program_text'],$c['bio'],$c['achievements'],$c['resources_text'],$c['video_url'],$c['website_url'],$c['photo_path'],$c['color'],$num++]);}$pdo->commit();save_setting('active_election_id',(string)$newId);audit_log('second_round_created','election',$newId,['source'=>$sourceId]);flash('success','Кампания второго тура создана и назначена активной.');
        }elseif($action==='delete'){
            $id=(int)($_POST['id']??0);$e=election_by_id($id);if(!$e||$e['status']!=='draft')throw new RuntimeException('Удалить можно только черновик.');
            $pdo=db();$st=$pdo->prepare('SELECT COUNT(*) FROM votes WHERE election_id=?');$st->execute([$id]);if((int)$st->fetchColumn()>0)throw new RuntimeException('Нельзя удалить выборы с голосами.');
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM participation WHERE election_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM election_eligibility WHERE election_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM votes WHERE election_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM candidates WHERE election_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM elections WHERE id=?')->execute([$id]);
            $replacement=(int)($pdo->query("SELECT id FROM elections ORDER BY id DESC LIMIT 1")->fetchColumn()?:0);
            $setting=$pdo->prepare("INSERT INTO election_settings(key,value) VALUES('active_election_id',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value");
            $setting->execute([(string)$replacement]);
            $pdo->commit();audit_log('election_deleted','election',$id);flash('success','Черновик и зависимые черновые данные удалены.');
        }
    }catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();flash('error',database_error_message($e));}
    redirect('elections.php');
}

$editId=(int)($_GET['edit']??0);$edit=$editId?election_by_id($editId):null;$elections=db()->query('SELECT * FROM elections ORDER BY id DESC')->fetchAll();$activeId=active_election_id();
$pageTitle='Выборы';$isAdminArea=true;require dirname(__DIR__).'/partials/header.php';
?>
<section class="section"><div class="container">
<div class="dashboard-heading"><div><span class="kicker">Кампании и архив</span><h1>Управление выборами</h1><p class="lead">Создавайте независимые кампании, назначайте расписание, открывайте, закрывайте и архивируйте результаты.</p></div></div>
<section class="panel"><div class="panel-heading"><div><span class="kicker"><?=$edit?'Редактирование':'Новая кампания'?></span><h2><?=$edit?e($edit['title']):'Создать выборы'?></h2></div><?php if($edit):?><a href="elections.php">Отменить</a><?php endif;?></div>
<form method="post" class="form-grid form-two-columns"><?=csrf_field()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=(int)($edit['id']??0)?>">
<label class="full-column">Название<input type="text" name="title" value="<?=e($edit['title']??'')?>" required></label><label class="full-column">Описание<textarea name="description" rows="3"><?=e($edit['description']??'')?></textarea></label>
<label>Начало<input type="datetime-local" name="start_at" value="<?=!empty($edit['start_at'])?e(date('Y-m-d\TH:i',strtotime($edit['start_at']))):''?>"></label><label>Окончание<input type="datetime-local" name="end_at" value="<?=!empty($edit['end_at'])?e(date('Y-m-d\TH:i',strtotime($edit['end_at']))):''?>"></label>
<label class="check-row"><input type="checkbox" name="results_public" <?=!empty($edit['results_public'])?'checked':''?>><span>Публичные результаты<small>Можно изменить после завершения.</small></span></label><label class="check-row"><input type="checkbox" name="candidates_randomized" <?=!isset($edit['candidates_randomized'])||$edit['candidates_randomized']?'checked':''?>><span>Перемешивать кандидатов<small>Снижает преимущество позиции в списке.</small></span></label><label class="check-row"><input type="checkbox" name="terminal_mode" <?=!isset($edit['terminal_mode'])||$edit['terminal_mode']?'checked':''?>><span>Режим терминала<small>Автоматический выход после голоса.</small></span></label><label class="check-row"><input type="checkbox" name="second_round_enabled" <?=!isset($edit['second_round_enabled'])||$edit['second_round_enabled']?'checked':''?>><span>Контроль второго тура</span></label>
<label>Порог победы, %<input type="number" name="second_round_threshold" min="0" max="100" step="0.1" value="<?=e($edit['second_round_threshold']??'50')?>"></label><div class="form-end"><button class="button primary" type="submit">Сохранить</button></div></form></section>

<div class="election-list"><?php foreach($elections as $item):$metrics=election_metrics((int)$item['id']);$analysis=second_round_analysis($item,result_rows((int)$item['id']));?>
<article class="panel election-card <?=$activeId===(int)$item['id']?'active-election':''?>"><div class="election-card-top"><div><span class="mini-status <?=in_array($item['status'],['open','closed'],true)?'mini-active':'mini-disabled'?>"><?=e(election_status_label($item['status']))?></span><?php if($activeId===(int)$item['id']):?><span class="mini-status mini-primary">Активная</span><?php endif;?><h2><?=e($item['title'])?></h2><p><?=e($item['description'])?></p></div><div class="election-metrics"><strong><?=$metrics['votes']?></strong><span>голосов</span><strong><?=$metrics['turnout']?>%</strong><span>явка</span></div></div><div class="election-dates"><span>Начало: <?=e(format_datetime($item['start_at']))?></span><span>Окончание: <?=e(format_datetime($item['end_at']))?></span></div><div class="card-actions">
<a class="button secondary small" href="?edit=<?=(int)$item['id']?>">Настройки</a><a class="button secondary small" href="candidates.php?election_id=<?=(int)$item['id']?>">Кандидаты</a><a class="button secondary small" href="../results.php?id=<?=(int)$item['id']?>">Результаты</a>
<?php if($activeId!==(int)$item['id']):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="set_active"><input type="hidden" name="id" value="<?=(int)$item['id']?>"><button class="button secondary small">Сделать активной</button></form><?php endif;?>
<?php if(in_array($item['status'],['draft','scheduled'],true)):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="open"><input type="hidden" name="id" value="<?=(int)$item['id']?>"><button class="button primary small" data-confirm="Открыть выборы? Кандидаты и бюллетень будут заблокированы.">Открыть</button></form><?php endif;?>
<?php if($item['status']==='open'):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="close"><input type="hidden" name="id" value="<?=(int)$item['id']?>"><button class="button danger-button small" data-confirm="Закрыть голосование?">Закрыть</button></form><?php endif;?>
<?php if($item['status']==='closed'):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="archive"><input type="hidden" name="id" value="<?=(int)$item['id']?>"><button class="button secondary small">В архив</button></form><?php if($analysis['required']):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="second_round"><input type="hidden" name="id" value="<?=(int)$item['id']?>"><button class="button primary small">Создать второй тур</button></form><?php endif;?><?php endif;?>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="duplicate"><input type="hidden" name="id" value="<?=(int)$item['id']?>"><button class="button secondary small">Копировать</button></form>
<?php if($item['status']==='draft'):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$item['id']?>"><button class="button danger-button small" data-confirm="Удалить черновик и его кандидатов?">Удалить</button></form><?php endif;?>
</div></article><?php endforeach;?></div>
</div></section>
<?php require dirname(__DIR__).'/partials/footer.php';?>
