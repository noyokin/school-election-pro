<?php
declare(strict_types=1);

$root = __DIR__;
$dbPath = $root . '/data/election.sqlite';
$checks = [];
$checks[] = ['PHP', PHP_VERSION, version_compare(PHP_VERSION, '8.1.0', '>=')];
$checks[] = ['pdo_sqlite', extension_loaded('pdo_sqlite') ? 'подключено' : 'отсутствует', extension_loaded('pdo_sqlite')];
$checks[] = ['Папка data', is_dir(dirname($dbPath)) ? dirname($dbPath) : 'не существует', is_dir(dirname($dbPath))];
$checks[] = ['Запись в data', is_writable(dirname($dbPath)) ? 'доступна' : 'нет прав', is_writable(dirname($dbPath))];
$checks[] = ['Файл базы', file_exists($dbPath) ? $dbPath : 'не найден', file_exists($dbPath)];
if (file_exists($dbPath)) {
    $checks[] = ['Запись в базу', is_writable($dbPath) ? 'доступна' : 'нет прав', is_writable($dbPath)];
}

$dbError = '';
$integrity = '';
$foreignKeys = [];
$tables = [];
if (extension_loaded('pdo_sqlite') && file_exists($dbPath)) {
    try {
        $pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $integrity = (string) $pdo->query('PRAGMA integrity_check')->fetchColumn();
        $foreignKeys = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $exception) {
        $dbError = $exception->getMessage();
    }
}

$logPath = dirname($dbPath) . '/migration-error.log';
$log = file_exists($logPath) ? (string) file_get_contents($logPath) : '';
$reportPath = dirname($dbPath) . '/recovery-report.log';
$report = file_exists($reportPath) ? (string) file_get_contents($reportPath) : '';
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Восстановление сайта</title><style>body{font-family:system-ui,-apple-system,sans-serif;background:#f4f7fb;color:#172033;padding:24px}.box{max-width:900px;margin:auto;background:white;border:1px solid #dfe6f0;border-radius:22px;padding:26px}.ok{color:#087455}.bad{color:#b02f41}table{width:100%;border-collapse:collapse}td{padding:10px;border-bottom:1px solid #e3e8f0}pre{overflow:auto;background:#182038;color:white;padding:16px;border-radius:12px;white-space:pre-wrap}a{color:#5b5ce2;font-weight:700}</style></head><body><div class="box">
<h1>Диагностика восстановления</h1><p>Эта страница не запускает миграцию и не изменяет базу.</p><table>
<?php foreach ($checks as [$name,$value,$ok]): ?><tr><td><?= htmlspecialchars($name) ?></td><td class="<?= $ok?'ok':'bad' ?>"><?= htmlspecialchars((string)$value) ?></td></tr><?php endforeach; ?>
<tr><td>Целостность SQLite</td><td class="<?= $integrity==='ok'?'ok':'bad' ?>"><?= htmlspecialchars($integrity ?: $dbError ?: 'не проверено') ?></td></tr>
<tr><td>Нарушения внешних ключей</td><td class="<?= !$foreignKeys?'ok':'bad' ?>"><?= $foreignKeys ? htmlspecialchars(json_encode($foreignKeys, JSON_UNESCAPED_UNICODE)) : 'не найдены' ?></td></tr>
<tr><td>Таблицы</td><td><?= htmlspecialchars(implode(', ', $tables)) ?></td></tr></table>
<?php if ($report): ?><h2>Отчёт автоматического восстановления</h2><pre><?= htmlspecialchars(substr($report, -12000)) ?></pre><?php endif; ?>
<?php if ($log): ?><h2>Последняя ошибка миграции</h2><pre><?= htmlspecialchars(substr($log, -12000)) ?></pre><?php endif; ?>
<p><a href="repair.php">Запустить безопасное восстановление</a> · <a href="index.php">Повторить запуск сайта</a></p></div></body></html>
