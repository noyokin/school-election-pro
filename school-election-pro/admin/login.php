<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/functions.php';

if (!app_is_installed()) redirect('../setup.php');
if (current_admin()) redirect('index.php');
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (login_is_blocked('admin', $username)) {
        $error = 'Слишком много неудачных попыток. Повторите вход позже.';
    } else {
        $statement = db()->prepare('SELECT * FROM admins WHERE username = ?');
        $statement->execute([$username]);
        $admin = $statement->fetch();

        if (!$admin || !(bool)$admin['is_active'] || !password_verify($password, (string)$admin['password_hash'])) {
            record_login_attempt('admin', $username, false);
            $error = 'Неверный логин или пароль.';
        } else {
            record_login_attempt('admin', $username, true);
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int)$admin['id'];
            $_SESSION['admin_last_activity'] = time();
            db()->prepare('UPDATE admins SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int)$admin['id']]);
            audit_log('admin_login', 'admin', (int)$admin['id']);
            redirect('index.php');
        }
    }
}
$pageTitle='Вход администратора';$isAdminArea=true;require dirname(__DIR__).'/partials/header.php';
?>
<section class="section auth-section"><div class="container narrow"><div class="auth-card">
<span class="eyebrow">Администрирование</span><h1>Панель организатора</h1><p class="lead">Управление кампаниями, участниками, кандидатами, отчётами и безопасностью.</p>
<?php if($error):?><div class="flash flash-error"><?=e($error)?></div><?php endif;?>
<form method="post" class="form-grid"><?=csrf_field()?><label>Логин<input type="text" name="username" autocomplete="username" required autofocus></label><label>Пароль<input type="password" name="password" autocomplete="current-password" required></label><button class="button primary" type="submit">Войти</button></form><a class="admin-entry" href="../index.php">Вернуться на сайт</a>
</div></div></section>
<?php require dirname(__DIR__).'/partials/footer.php';?>
