<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
$electionId=(int)($_GET['id']??active_election_id());
$election=election_by_id($electionId);
if(!$election) exit('Выборы не найдены.');
if(!results_are_public($election) && !current_admin()){http_response_code(403);exit('Результаты скрыты.');}
$rows=result_rows($electionId,false);$metrics=election_metrics($electionId);$total=max(1,array_sum(array_map(static fn($r)=>(int)$r['vote_count'],$rows)));
?>
<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="refresh" content="15"><title><?= e($election['title']) ?> — проектор</title><link rel="stylesheet" href="assets/style.css?v=<?= e(APP_VERSION) ?>"></head>
<body class="projector-page"><main class="projector-main"><header><span class="eyebrow live">Обновление каждые 15 секунд</span><h1><?= e($election['title']) ?></h1><div class="projector-metrics"><span><?= $metrics['votes'] ?> голосов</span><span><?= $metrics['turnout'] ?>% явка</span><span><?= e(date('H:i:s')) ?></span></div></header><section class="projector-results"><?php foreach($rows as $i=>$row):$p=round((int)$row['vote_count']/$total*100,1);?><article><div class="projector-rank"><?= $i+1 ?></div><div><h2><?= e($row['full_name']) ?></h2><div class="projector-bar"><span style="width:<?= $p ?>%;background:<?= e($row['color']) ?>"></span></div></div><strong><?= (int)$row['vote_count'] ?><small><?= $p ?>%</small></strong></article><?php endforeach;?></section></main></body></html>
