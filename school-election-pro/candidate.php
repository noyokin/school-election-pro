<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

$candidateId = (int) ($_GET['id'] ?? 0);
$candidate = candidate_by_id($candidateId);
if (!$candidate || !(bool) $candidate['is_active']) {
    http_response_code(404);
    exit('Кандидат не найден.');
}
$election = election_by_id((int) $candidate['election_id']);
$resources = candidate_resources((string) $candidate['resources_text']);
$pageTitle = $candidate['full_name'];
$isAdminArea = false;
require __DIR__ . '/partials/header.php';
?>
<section class="section">
<div class="container candidate-profile">
    <aside class="candidate-profile-side">
        <?php if ($candidate['photo_path']): ?><img class="candidate-profile-photo" src="<?= e($candidate['photo_path']) ?>" alt="<?= e($candidate['full_name']) ?>"><?php else: ?><div class="candidate-avatar profile-avatar" style="--candidate-color: <?= e($candidate['color']) ?>"><?= e(candidate_initials($candidate['full_name'])) ?></div><?php endif; ?>
        <span class="status-pill status-open">Кандидат №<?= (int) $candidate['ballot_number'] ?></span>
    </aside>
    <article class="candidate-profile-main">
        <span class="kicker"><?= e($candidate['class_name']) ?> · <?= e($election['title'] ?? '') ?></span>
        <h1><?= e($candidate['full_name']) ?></h1>
        <p class="candidate-profile-slogan"><?= e($candidate['slogan']) ?></p>

        <?php if ($candidate['bio']): ?><section class="profile-section"><h2>Биография</h2><p><?= nl2br(e($candidate['bio'])) ?></p></section><?php endif; ?>
        <section class="profile-section"><h2>Предвыборная программа</h2><ul class="program-list large-list"><?php foreach (array_filter(preg_split('/\R/u', $candidate['program_text']) ?: []) as $item): ?><li><?= e(trim($item)) ?></li><?php endforeach; ?></ul></section>
        <?php if ($candidate['achievements']): ?><section class="profile-section"><h2>Достижения</h2><ul class="program-list large-list"><?php foreach (array_filter(preg_split('/\R/u', $candidate['achievements']) ?: []) as $item): ?><li><?= e(trim($item)) ?></li><?php endforeach; ?></ul></section><?php endif; ?>

        <?php if ($candidate['video_url'] || $candidate['website_url'] || $resources): ?>
        <section class="profile-section"><h2>Материалы</h2><div class="resource-links">
            <?php if (filter_var($candidate['video_url'], FILTER_VALIDATE_URL)): ?><a class="button secondary" href="<?= e($candidate['video_url']) ?>" target="_blank" rel="noopener">Видео кандидата</a><?php endif; ?>
            <?php if (filter_var($candidate['website_url'], FILTER_VALIDATE_URL)): ?><a class="button secondary" href="<?= e($candidate['website_url']) ?>" target="_blank" rel="noopener">Сайт проекта</a><?php endif; ?>
            <?php foreach ($resources as $resource): ?><a class="button secondary" href="<?= e($resource['url']) ?>" target="_blank" rel="noopener"><?= e($resource['label']) ?></a><?php endforeach; ?>
        </div></section>
        <?php endif; ?>
        <div class="actions"><a class="button primary" href="<?= current_student() ? 'vote.php' : 'login.php' ?>">Перейти к голосованию</a><a class="button secondary" href="index.php#candidates">Все кандидаты</a></div>
    </article>
</div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
