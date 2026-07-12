<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

if (setting('allow_public_archive', '1') !== '1' && !current_admin()) {
    http_response_code(403);
    exit('Публичный архив отключён.');
}

$elections = db()->query(
    "SELECT * FROM elections
     WHERE status IN ('closed','archived')
     ORDER BY COALESCE(end_at, created_at) DESC"
)->fetchAll();
$pageTitle = 'Архив выборов';
$isAdminArea = false;
require __DIR__ . '/partials/header.php';
?>
<section class="section"><div class="container">
    <div class="section-heading"><span class="kicker">История</span><h1>Архив выборов</h1><p>Завершённые кампании, итоговые результаты и протоколы.</p></div>
    <div class="archive-grid">
    <?php foreach ($elections as $election): $metrics=election_metrics((int)$election['id']); ?>
        <article class="archive-card"><span class="mini-status mini-active"><?= e(election_status_label($election['status'])) ?></span><h2><?= e($election['title']) ?></h2><p><?= e($election['description']) ?></p><div class="archive-stats"><span><?= $metrics['votes'] ?> голосов</span><span><?= $metrics['turnout'] ?>% явка</span><span><?= e(format_datetime($election['end_at'])) ?></span></div><?php if ((bool)$election['results_public'] || current_admin()): ?><a class="button secondary" href="results.php?id=<?= (int)$election['id'] ?>">Открыть результаты</a><?php endif; ?></article>
    <?php endforeach; ?>
    <?php if (!$elections): ?><div class="empty-state">Завершённых выборов пока нет.</div><?php endif; ?>
    </div>
</div></section>
<?php require __DIR__ . '/partials/footer.php'; ?>
