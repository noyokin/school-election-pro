<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

$electionId = (int) ($_GET['id'] ?? active_election_id());
$election = election_by_id($electionId);
if (!$election) {
    http_response_code(404);
    exit('Выборы не найдены.');
}

$admin = current_admin();
$allowed = results_are_public($election) || ($admin && admin_can('reports', $admin));
$rows = $allowed ? result_rows($electionId, false) : [];
$metrics = $allowed ? election_metrics($electionId) : [];
$classes = $allowed ? turnout_by_class($electionId) : [];
$secondRound = $allowed ? second_round_analysis($election, $rows) : [];
$totalVotes = $allowed ? array_sum(array_map(static fn(array $row): int => (int) $row['vote_count'], $rows)) : 0;

$pageTitle = 'Результаты';
$isAdminArea = false;
require __DIR__ . '/partials/header.php';
?>
<section class="section">
<div class="container">
    <div class="dashboard-heading">
        <div><span class="kicker">Результаты</span><h1><?= e($election['title']) ?></h1><p class="lead">Статус: <?= e(election_status_label($election['status'])) ?> · обновлено <?= e(date('d.m.Y H:i')) ?></p></div>
        <?php if ($allowed): ?><div class="actions"><a class="button secondary" href="projector.php?id=<?= $electionId ?>">Экран для проектора</a><a class="button secondary" href="admin/export.php?type=protocol&id=<?= $electionId ?>" target="_blank">Печатный протокол / PDF</a></div><?php endif; ?>
    </div>

    <?php if (!$allowed): ?>
        <div class="empty-state">Публикация результатов отключена администрацией.</div>
    <?php else: ?>
        <div class="metric-grid">
            <article class="metric-card"><span>Голоса</span><strong><?= $metrics['votes'] ?></strong><small>анонимных записей</small></article>
            <article class="metric-card"><span>Явка</span><strong><?= $metrics['turnout'] ?>%</strong><small><?= $metrics['participants'] ?> из <?= $metrics['eligible'] ?></small></article>
            <article class="metric-card"><span>Осталось</span><strong><?= $metrics['remaining'] ?></strong><small>активных учеников</small></article>
            <article class="metric-card"><span>Кандидаты</span><strong><?= $metrics['candidates'] ?></strong><small>в бюллетене</small></article>
        </div>

        <?php if ($secondRound['required'] ?? false): ?>
            <div class="flash flash-warning"><strong>Требуется второй тур.</strong> Лидер набрал <?= e($secondRound['leader_percent']) ?>%, порог победы — более <?= e($secondRound['threshold']) ?>%. Во второй тур проходят два лидера.</div>
        <?php endif; ?>

        <div class="results-layout">
            <section class="panel"><div class="panel-heading"><div><span class="kicker">Рейтинг</span><h2>Голоса кандидатов</h2></div></div>
                <div class="results-list">
                <?php foreach ($rows as $index => $row): $count=(int)$row['vote_count']; $percent=$totalVotes>0?round($count/$totalVotes*100,1):0; ?>
                    <article class="result-card">
                        <div class="result-rank"><?= $index + 1 ?></div>
                        <div class="result-main"><div class="result-line"><div><strong><?= e($row['full_name']) ?></strong><span><?= e($row['class_name']) ?></span></div><div class="result-value"><?= $count ?> · <?= $percent ?>%</div></div><div class="progress"><span style="width:<?= $percent ?>%;background:<?= e($row['color']) ?>"></span></div></div>
                    </article>
                <?php endforeach; ?>
                <?php if (!$rows): ?><div class="empty-state">Кандидаты не добавлены.</div><?php endif; ?>
                </div>
            </section>

            <section class="panel"><div class="panel-heading"><div><span class="kicker">Участие</span><h2>Явка по классам</h2></div></div>
                <div class="class-turnout-list">
                <?php foreach ($classes as $class): ?>
                    <div class="class-turnout"><div class="result-line"><strong><?= e($class['class_name']) ?></strong><span><?= (int)$class['participated'] ?>/<?= (int)$class['eligible'] ?> · <?= e($class['turnout']) ?>%</span></div><div class="progress"><span style="width:<?= e($class['turnout']) ?>%"></span></div></div>
                <?php endforeach; ?>
                </div>
            </section>
        </div>
    <?php endif; ?>
</div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
