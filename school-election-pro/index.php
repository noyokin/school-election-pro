<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

if (!app_is_installed()) {
    redirect('setup.php');
}

$election = active_election();
$metrics = $election ? election_metrics((int) $election['id']) : ['eligible'=>0,'participants'=>0,'votes'=>0,'candidates'=>0,'turnout'=>0,'remaining'=>0];
$candidates = $election ? active_candidates((int) $election['id']) : [];

$pageTitle = 'Главная';
$isAdminArea = false;
require __DIR__ . '/partials/header.php';
?>
<?php if (!$election): ?>
<section class="section"><div class="container"><div class="empty-state">Активные выборы ещё не созданы.</div></div></section>
<?php else: ?>
<section class="hero">
    <div class="container hero-grid">
        <div>
            <span class="eyebrow <?= election_is_open($election) ? 'live' : '' ?>">
                <?= e(election_is_open($election) ? t('voting_open') : t('voting_closed')) ?>
            </span>
            <h1><?= e($election['title']) ?></h1>
            <p class="lead"><?= e($election['description']) ?></p>
            <?php $countdownTarget = election_is_open($election) ? $election['end_at'] : (($election['status'] === 'scheduled') ? $election['start_at'] : null); ?>
            <?php if ($countdownTarget): ?><div class="election-countdown" data-election-countdown="<?= e(date('c', strtotime($countdownTarget))) ?>" data-countdown-label="<?= election_is_open($election) ? 'До завершения' : 'До открытия' ?>"><span><?= election_is_open($election) ? 'До завершения' : 'До открытия' ?></span><strong>—</strong></div><?php endif; ?>
            <div class="actions">
                <?php if (election_is_open($election)): ?>
                    <a class="button primary" href="<?= current_student() ? 'vote.php' : 'login.php' ?>"><?= e(t('vote')) ?></a>
                <?php endif; ?>
                <a class="button secondary" href="results.php?id=<?= (int) $election['id'] ?>"><?= e(t('view_results')) ?></a>
                <a class="button secondary" href="#candidates"><?= e(t('study_programs')) ?></a>
            </div>
        </div>
        <aside class="hero-panel">
            <span class="muted-light">Период голосования</span>
            <strong class="hero-date"><?= e(format_datetime($election['start_at'])) ?></strong>
            <span class="muted-light">до <?= e(format_datetime($election['end_at'])) ?></span>
            <div class="stat-grid">
                <div class="stat-card"><strong><?= $metrics['candidates'] ?></strong><span><?= e(t('candidates')) ?></span></div>
                <div class="stat-card"><strong><?= $metrics['eligible'] ?></strong><span><?= e(t('students')) ?></span></div>
                <div class="stat-card"><strong><?= $metrics['votes'] ?></strong><span><?= e(t('votes')) ?></span></div>
                <div class="stat-card"><strong><?= $metrics['turnout'] ?>%</strong><span><?= e(t('turnout')) ?></span></div>
            </div>
        </aside>
    </div>
</section>

<section class="section" id="candidates">
    <div class="container">
        <div class="section-heading">
            <span class="kicker"><?= e(t('candidates')) ?></span>
            <h2>Участники кампании</h2>
            <p>Откройте карточку кандидата, чтобы прочитать биографию, программу, достижения и дополнительные материалы.</p>
        </div>
        <div class="candidate-grid">
            <?php foreach ($candidates as $candidate): ?>
                <article class="candidate-card">
                    <?php if ($candidate['photo_path']): ?>
                        <img class="candidate-photo" src="<?= e($candidate['photo_path']) ?>" alt="<?= e($candidate['full_name']) ?>">
                    <?php else: ?>
                        <div class="candidate-avatar" style="--candidate-color: <?= e($candidate['color']) ?>"><?= e(candidate_initials($candidate['full_name'])) ?></div>
                    <?php endif; ?>
                    <span class="candidate-class">№<?= (int) $candidate['ballot_number'] ?> · <?= e($candidate['class_name']) ?></span>
                    <h3><?= e($candidate['full_name']) ?></h3>
                    <p class="candidate-slogan"><?= e($candidate['slogan']) ?></p>
                    <a class="button secondary small" href="candidate.php?id=<?= (int) $candidate['id'] ?>">Подробнее</a>
                </article>
            <?php endforeach; ?>
            <?php if (!$candidates): ?><div class="empty-state">Кандидаты пока не опубликованы.</div><?php endif; ?>
        </div>
    </div>
</section>

<section class="section soft-section">
    <div class="container feature-grid">
        <article class="feature-card"><span class="feature-number">01</span><h3>Персональный доступ</h3><p>Вход выполняется по индивидуальному коду и паролю или карточке с QR-кодом.</p></article>
        <article class="feature-card"><span class="feature-number">02</span><h3>Тайна выбора</h3><p>В базе отдельно хранится факт участия и отдельно — анонимный голос за кандидата.</p></article>
        <article class="feature-card"><span class="feature-number">03</span><h3>Автоматический выход</h3><p>После голосования кабинет закрывается и терминал готов к следующему ученику.</p></article>
    </div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
