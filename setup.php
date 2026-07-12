<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

if (app_is_installed()) {
    redirect('index.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $schoolName = trim((string) ($_POST['school_name'] ?? 'Школьные выборы'));
    $baseUrl = rtrim(trim((string) ($_POST['base_url'] ?? '')), '/');
    $seedDemo = isset($_POST['seed_demo']);

    if (!preg_match('/^[a-zA-Z0-9_.-]{3,40}$/', $username)) {
        $error = 'Логин: 3–40 символов, латинские буквы, цифры, точка, дефис или подчёркивание.';
    } elseif (strlen($password) < 10) {
        $error = 'Пароль администратора должен содержать не менее 10 символов.';
    } elseif ($baseUrl !== '' && !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
        $error = 'Базовый URL должен начинаться с http:// или https://.';
    } else {
        try {
            $pdo = db();
            $schema = file_get_contents(__DIR__ . '/schema.sql');
            if ($schema === false) {
                throw new RuntimeException('Не удалось прочитать schema.sql.');
            }

            $pdo->beginTransaction();
            $pdo->exec($schema);

            $statement = $pdo->prepare(
                'INSERT INTO admins (username, password_hash, role) VALUES (?, ?, \'superadmin\')'
            );
            $statement->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);

            $settings = [
                'schema_version' => APP_VERSION,
                'school_name' => $schoolName ?: 'Школьные выборы',
                'base_url' => $baseUrl,
                'session_timeout_minutes' => '30',
                'max_login_attempts' => '5',
                'lockout_minutes' => '10',
                'terminal_redirect_seconds' => '8',
                'default_language' => 'ru',
                'allow_public_archive' => '1',
                'qr_secret' => bin2hex(random_bytes(32)),
            ];
            $settingStatement = $pdo->prepare('INSERT INTO election_settings (key, value) VALUES (?, ?)');
            foreach ($settings as $key => $value) {
                $settingStatement->execute([$key, $value]);
            }

            $start = date('Y-m-d') . ' 08:00:00';
            $end = date('Y-m-d') . ' 17:00:00';
            $statement = $pdo->prepare(
                'INSERT INTO elections
                 (title, description, start_at, end_at, status, results_public)
                 VALUES (?, ?, ?, ?, \'draft\', 0)'
            );
            $statement->execute([
                'Выборы президента ученического совета',
                'Выберите кандидата, которому доверяете представлять интересы учеников.',
                $start,
                $end,
            ]);
            $electionId = (int) $pdo->lastInsertId();
            $settingStatement->execute(['active_election_id', (string) $electionId]);

            if ($seedDemo) {
                $candidateStatement = $pdo->prepare(
                    'INSERT INTO candidates
                     (election_id, full_name, class_name, slogan, program_text, bio, achievements, color, ballot_number)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $demoCandidates = [
                    ['Анна Смирнова', '10 «А»', 'Школа, в которой слышат каждого', "Тематические мероприятия\nЗона отдыха для учеников\nЯщик школьных инициатив", 'Организатор школьных мероприятий и волонтёрских проектов.', "Победитель школьной олимпиады\nКоординатор волонтёрского клуба", '#665df5', 1],
                    ['Илья Петров', '10 «Б»', 'Больше спорта и ученических проектов', "Школьная спортивная лига\nПоддержка ученических клубов\nОткрытые встречи с советом", 'Капитан школьной команды и участник ученического совета.', "Призёр городских соревнований\nОрганизатор турниров", '#18a77d', 2],
                    ['Мария Орлова', '9 «В»', 'Творчество, экология и взаимопомощь', "Творческие фестивали\nЭкологические акции\nПрограмма наставничества", 'Участница театральной студии и экологического движения.', "Организатор экологической недели\nАвтор проекта наставничества", '#ed6e62', 3],
                ];
                foreach ($demoCandidates as $candidate) {
                    $candidateStatement->execute([$electionId, ...$candidate]);
                }

                $studentStatement = $pdo->prepare(
                    'INSERT INTO students (student_code, full_name, class_name, password_hash)
                     VALUES (?, ?, ?, ?)'
                );
                foreach ([
                    ['1001', 'Алексей Ковалёв', '8 «А»'],
                    ['1002', 'Екатерина Волкова', '9 «Б»'],
                    ['1003', 'Даниил Соколов', '10 «А»'],
                ] as $student) {
                    $studentStatement->execute([$student[0], $student[1], $student[2], student_password_hash('1234')]);
                }
            }

            $pdo->commit();
            flash('success', 'Система установлена. Войдите в административную панель.');
            redirect('admin/login.php');
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Ошибка установки: ' . $exception->getMessage();
        }
    }
}

$pageTitle = 'Установка';
$isAdminArea = false;
require __DIR__ . '/partials/header.php';
?>
<section class="section auth-section">
    <div class="container narrow">
        <div class="auth-card">
            <span class="eyebrow">Первичная настройка</span>
            <h1>Установка системы выборов</h1>
            <p class="lead">Создайте главного администратора, первую кампанию и базу SQLite.</p>

            <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>

            <form method="post" class="form-grid">
                <?= csrf_field() ?>
                <label>Название школы или проекта
                    <input type="text" name="school_name" value="<?= e($_POST['school_name'] ?? 'Школьные выборы') ?>" required>
                </label>
                <label>Адрес сайта для QR-кодов
                    <input type="url" name="base_url" value="<?= e($_POST['base_url'] ?? '') ?>" placeholder="http://localhost/school-election-pro">
                    <small>Можно заполнить позже в настройках.</small>
                </label>
                <label>Логин главного администратора
                    <input type="text" name="username" autocomplete="username" required>
                </label>
                <label>Пароль администратора
                    <input type="password" name="password" autocomplete="new-password" minlength="10" required>
                </label>
                <label class="check-row">
                    <input type="checkbox" name="seed_demo" checked>
                    <span>Добавить демонстрационные данные<small>Ученики 1001–1003, пароль 1234.</small></span>
                </label>
                <button class="button primary" type="submit">Установить систему</button>
            </form>
        </div>
    </div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
