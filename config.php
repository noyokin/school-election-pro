<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Tallinn');

const DB_PATH = __DIR__ . '/data/election.sqlite';
const APP_NAME = 'Школьные выборы PRO';
const APP_VERSION = '4.0.8';
const UPLOAD_ROOT = __DIR__ . '/uploads';
const BACKUP_ROOT = __DIR__ . '/backups';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}


set_exception_handler(static function (Throwable $exception): void {
    error_log('[School Election] ' . $exception->__toString());

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }

    $message = htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Ошибка запуска</title>'
        . '<style>body{font-family:system-ui,-apple-system,sans-serif;background:#f4f7fb;color:#172033;padding:32px}'
        . '.box{max-width:760px;margin:7vh auto;background:#fff;border:1px solid #dfe6f0;border-radius:22px;padding:28px;box-shadow:0 20px 55px rgba(31,43,78,.11)}'
        . 'code{display:block;white-space:pre-wrap;background:#eef2f8;padding:14px;border-radius:12px;margin:16px 0}'
        . 'a{color:#5b5ce2;font-weight:700}</style></head><body><div class="box">'
        . '<h1>Сайт временно не запустился</h1>'
        . '<p>Данные базы не удалены. Система остановила запуск, чтобы не повредить их.</p>'
        . '<code>' . $message . '</code>'
        . '<p><a href="repair.php">Открыть аварийное восстановление</a> · <a href="recovery.php">Диагностика</a></p>'
        . '</div></body></html>';
});
