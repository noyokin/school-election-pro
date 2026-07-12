<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/functions.php';

$pageTitle = $pageTitle ?? APP_NAME;
$isAdminArea = $isAdminArea ?? false;
$prefix = $isAdminArea ? '../' : '';
$student = $isAdminArea ? null : current_student();
$admin = $isAdminArea ? current_admin() : null;
$flashes = take_flashes();
$language = current_language();
?>
<!DOCTYPE html>
<html lang="<?= e($language) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Система проведения школьных выборов">
    <meta name="color-scheme" content="light dark">
    <title><?= e($pageTitle) ?> — <?= e(setting('school_name', APP_NAME)) ?></title>
    <link rel="stylesheet" href="<?= $prefix ?>assets/style.css?v=<?= e(APP_VERSION) ?>">
    <?php $accent = setting('accent_color', '#5b5ce2'); if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) $accent = '#5b5ce2'; ?>
    <style>:root{--primary:<?= e($accent) ?>;--primary-dark:color-mix(in srgb, <?= e($accent) ?> 78%, black)}</style>
</head>
<body data-base-url="<?= e(app_base_url()) ?>">
<header class="site-header">
    <div class="container nav">
        <a class="brand" href="<?= $isAdminArea ? 'index.php' : $prefix . 'index.php' ?>">
            <?php $logoPath = setting('logo_path', ''); ?>
            <?php if ($logoPath): ?><img class="brand-logo" src="<?= $prefix . e($logoPath) ?>" alt="Логотип"><?php else: ?><span class="brand-mark">ШВ</span><?php endif; ?>
            <span><strong><?= e(setting('school_name', APP_NAME)) ?></strong><small><?= $isAdminArea ? 'Административная панель' : 'Электронное голосование' ?></small></span>
        </a>

        <nav class="nav-links" aria-label="Основная навигация">
            <?php if ($isAdminArea): ?>
                <?php if ($admin): ?>
                    <a href="index.php">Обзор</a>
                    <?php if (admin_can('elections', $admin)): ?><a href="elections.php">Выборы</a><?php endif; ?>
                    <?php if (admin_can('students', $admin)): ?><a href="students.php">Ученики</a><?php endif; ?>
                    <?php if (admin_can('candidates', $admin)): ?><a href="candidates.php">Кандидаты</a><?php endif; ?>
                    <?php if (admin_can('reports', $admin)): ?><a href="reports.php">Отчёты</a><?php endif; ?>
                    <?php if (admin_can('logs', $admin)): ?><a href="logs.php">Журнал</a><?php endif; ?>
                    <?php if (admin_can('settings', $admin) || ($admin['role'] ?? '') === 'superadmin'): ?><a href="settings.php">Настройки</a><?php endif; ?>
                    <a class="nav-quiet" href="logout.php">Выйти</a>
                <?php endif; ?>
            <?php else: ?>
                <a href="<?= $prefix ?>index.php"><?= e(t('home')) ?></a>
                <a href="<?= $prefix ?>results.php"><?= e(t('results')) ?></a>
                <a href="<?= $prefix ?>archive.php"><?= e(t('archive')) ?></a>
                <?php if ($student): ?>
                    <a href="<?= $prefix ?>vote.php"><?= e(t('ballot')) ?></a>
                    <a class="nav-quiet" href="<?= $prefix ?>logout.php"><?= e(t('logout')) ?></a>
                <?php else: ?>
                    <a class="nav-button" href="<?= $prefix ?>login.php"><?= e(t('student_login')) ?></a>
                <?php endif; ?>
            <?php endif; ?>
        </nav>
    </div>
</header>

<div class="accessibility-bar" aria-label="Настройки отображения">
    <button type="button" data-ui-action="theme" title="Тёмная тема">◐</button>
    <button type="button" data-ui-action="font" title="Крупный шрифт">A+</button>
    <button type="button" data-ui-action="contrast" title="Высокая контрастность">◧</button>
    <?php if (!$isAdminArea): ?>
        <a href="?<?= e(http_build_query(array_merge($_GET, ['lang' => $language === 'ru' ? 'en' : 'ru']))) ?>" title="Сменить язык"><?= $language === 'ru' ? 'EN' : 'RU' ?></a>
    <?php endif; ?>
</div>

<?php if ($flashes): ?>
<div class="container flash-stack">
    <?php foreach ($flashes as $flash): ?>
        <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<main>
