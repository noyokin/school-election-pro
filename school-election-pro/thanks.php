<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
$data = $_SESSION['vote_success'] ?? null;
unset($_SESSION['vote_success']);
if (!$data) redirect('login.php');
$seconds = max(3, (int) setting('terminal_redirect_seconds', '8'));
$pageTitle = 'Голос принят';
$isAdminArea = false;
require __DIR__ . '/partials/header.php';
?>
<section class="section auth-section">
<div class="container narrow"><div class="auth-card vote-thanks" data-auto-redirect="login.php" data-seconds="<?= $seconds ?>">
    <div class="success-icon large">✓</div><span class="eyebrow live">Голос принят</span><h1>Спасибо за участие</h1>
    <p class="lead">Голос сохранён анонимно. Учётная запись автоматически вышла из системы.</p>
    <p>Возврат к форме входа через <strong data-countdown><?= $seconds ?></strong> секунд.</p>
    <a class="button primary" href="login.php">Перейти сейчас</a>
</div></div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
