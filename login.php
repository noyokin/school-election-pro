<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

if (!app_is_installed()) redirect('setup.php');
if (current_student()) redirect('vote.php');

$error = null;
$prefillCode = '';

if (isset($_GET['student'], $_GET['code'], $_GET['sig'])) {
    $studentId = (int) $_GET['student'];
    $code = strtoupper(trim((string) $_GET['code']));
    if (verify_student_qr_signature($studentId, $code, (string) $_GET['sig'])) {
        $statement = db()->prepare('SELECT student_code FROM students WHERE id = ? AND student_code = ?');
        $statement->execute([$studentId, $code]);
        $prefillCode = (string) ($statement->fetchColumn() ?: '');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $studentCode = strtoupper(trim((string) ($_POST['student_code'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    if (login_is_blocked('student', $studentCode)) {
        $error = 'Слишком много неудачных попыток. Повторите вход позже.';
    } else {
        $statement = db()->prepare('SELECT * FROM students WHERE student_code = ?');
        $statement->execute([$studentCode]);
        $student = $statement->fetch();

        if (!$student || !(bool) $student['is_active'] || !password_verify($password, (string) $student['password_hash'])) {
            record_login_attempt('student', $studentCode, false);
            $error = 'Неверный код ученика или пароль.';
        } else {
            record_login_attempt('student', $studentCode, true);
            create_student_session((int) $student['id']);
            flash('success', 'Вход выполнен. Проверьте имя и класс перед голосованием.');
            redirect('vote.php');
        }
    }
}

$pageTitle = 'Вход ученика';
$isAdminArea = false;
require __DIR__ . '/partials/header.php';
?>
<section class="section auth-section">
    <div class="container narrow">
        <div class="auth-card">
            <span class="eyebrow">Вход ученика</span>
            <h1>Получите электронный бюллетень</h1>
            <p class="lead">Введите код и пароль с персональной карточки. QR-код заполняет код автоматически, но пароль остаётся секретным.</p>
            <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
            <form method="post" class="form-grid">
                <?= csrf_field() ?>
                <label>Код ученика
                    <input type="text" name="student_code" value="<?= e($_POST['student_code'] ?? $prefillCode) ?>" autocomplete="username" required autofocus>
                </label>
                <label>Пароль
                    <input type="password" name="password" autocomplete="current-password" required>
                </label>
                <button class="button primary" type="submit">Войти</button>
            </form>
            <a class="admin-entry" href="admin/login.php">Вход для администрации</a>
        </div>
    </div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
