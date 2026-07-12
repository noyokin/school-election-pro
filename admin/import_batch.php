<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/functions.php';
require_once dirname(__DIR__) . '/student_import.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    require_admin('students');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Разрешён только POST-запрос.');
    }

    verify_csrf();

    $token = (string) ($_POST['token'] ?? '');
    $sessionToken = (string) ($_SESSION['student_import_job_token'] ?? '');

    if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
        throw new RuntimeException('Задание импорта не принадлежит текущей сессии.');
    }

    // Do not hold the PHP session lock while bcrypt and SQLite work are running.
    // Other admin pages remain responsive during a large import.
    session_write_close();

    @set_time_limit(30);
    $progress = student_import_process_job_batch(db(), $token, 12);

    if (!empty($progress['done'])) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $result = $progress['result'];
        try {
            audit_log('student_imported', 'students', null, $result);
        } catch (Throwable $auditException) {
            error_log('[School Election] Не удалось записать журнал импорта: ' . $auditException->getMessage());
        }
        flash(
            'success',
            "Импорт завершён: добавлено {$result['added']}, обновлено {$result['updated']}, пропущено {$result['skipped']}."
        );
        unset($_SESSION['student_import_job_token']);
        student_import_delete_job($token);
        session_write_close();
    }

    echo json_encode(['ok' => true] + $progress, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => database_error_message($exception),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
