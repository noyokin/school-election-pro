<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/functions.php';
$admin=require_admin('dashboard');
$election=active_election();
$metrics=$election?election_metrics((int)$election['id']):['eligible'=>0,'participants'=>0,'votes'=>0,'candidates'=>0,'turnout'=>0,'remaining'=>0];
$rows=$election?result_rows((int)$election['id']):[];
$elections=db()->query('SELECT * FROM elections ORDER BY id DESC LIMIT 6')->fetchAll();
$logs=db()->query('SELECT audit_logs.*, admins.username FROM audit_logs LEFT JOIN admins ON admins.id=audit_logs.admin_id ORDER BY audit_logs.id DESC LIMIT 8')->fetchAll();
$pageTitle='Обзор';$isAdminArea=true;require dirname(__DIR__).'/partials/header.php';
?>
<section class="section"><div class="container">
<div class="dashboard-heading"><div><span class="kicker">Панель администратора</span><h1>Обзор системы</h1><p class="lead"><?=e($admin['username'])?> · <?=e($admin['role'])?></p></div><?php if($election):?><span class="status-pill status-<?=e($election['status']==='open'?'open':'closed')?>"><?=e(election_status_label($election['status']))?></span><?php endif;?></div>
<div class="metric-grid"><article class="metric-card"><span>Ученики</span><strong><?=$metrics['eligible']?></strong><small>активных учётных записей</small></article><article class="metric-card"><span>Кандидаты</span><strong><?=$metrics['candidates']?></strong><small>в активной кампании</small></article><article class="metric-card"><span>Голоса</span><strong><?=$metrics['votes']?></strong><small>анонимных записей</small></article><article class="metric-card"><span>Явка</span><strong><?=$metrics['turnout']?>%</strong><small>осталось <?=$metrics['remaining']?></small></article></div>

<div class="quick-actions">
<?php if(admin_can('elections',$admin)):?><a class="button primary" href="elections.php">Управлять выборами</a><?php endif;?>
<?php if(admin_can('students',$admin)):?><a class="button secondary" href="students.php">База учеников</a><a class="button secondary" href="cards.php">Карточки доступа</a><?php endif;?>
<?php if(admin_can('reports',$admin)):?><a class="button secondary" href="reports.php">Отчёты и экспорт</a><?php endif;?>
</div>

<div class="admin-grid">
<section class="panel"><div class="panel-heading"><div><span class="kicker">Активная кампания</span><h2><?=e($election['title']??'Не выбрана')?></h2></div><?php if($election):?><a href="../results.php?id=<?=(int)$election['id']?>">Результаты</a><?php endif;?></div>
<?php if(!$election):?><div class="empty-state">Создайте первые выборы.</div><?php else:?><div class="compact-results"><?php $total=max(1,$metrics['votes']);foreach($rows as $row):$p=round((int)$row['vote_count']/$total*100,1);?><div class="compact-result"><div class="result-line"><strong><?=e($row['full_name'])?></strong><span><?=(int)$row['vote_count']?> · <?=$p?>%</span></div><div class="progress"><span style="width:<?=$p?>%;background:<?=e($row['color'])?>"></span></div></div><?php endforeach;?></div><?php endif;?></section>
<section class="panel"><div class="panel-heading"><div><span class="kicker">Последние действия</span><h2>Журнал</h2></div><a href="logs.php">Все записи</a></div><div class="activity-list"><?php foreach($logs as $log):?><div class="activity-item"><div><strong><?=e($log['action'])?></strong><span><?=e($log['username']??'Система')?> · <?=e($log['entity_type'])?></span></div><time><?=e(format_datetime($log['created_at']))?></time></div><?php endforeach;?><?php if(!$logs):?><div class="empty-state">Журнал пуст.</div><?php endif;?></div></section>
</div>

<section class="panel"><div class="panel-heading"><div><span class="kicker">Кампании</span><h2>Последние выборы</h2></div></div><div class="table-wrap"><table><thead><tr><th>Название</th><th>Статус</th><th>Начало</th><th>Окончание</th><th>Действие</th></tr></thead><tbody><?php foreach($elections as $item):?><tr><td><strong><?=e($item['title'])?></strong></td><td><?=e(election_status_label($item['status']))?></td><td><?=e(format_datetime($item['start_at']))?></td><td><?=e(format_datetime($item['end_at']))?></td><td><a class="link-button" href="elections.php?edit=<?=(int)$item['id']?>">Открыть</a></td></tr><?php endforeach;?></tbody></table></div></section>
</div></section>
<?php require dirname(__DIR__).'/partials/footer.php';?>
