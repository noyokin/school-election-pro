<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function prepare_writable_directory(string $directory, string $label): void
{
    if (file_exists($directory) && !is_dir($directory)) {
        throw new RuntimeException("$label существует, но не является папкой: $directory");
    }

    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException("PHP не смог создать папку $label: $directory");
    }

    if (!is_writable($directory)) {
        @chmod($directory, 0775);
        clearstatcache(true, $directory);
    }

    if (!is_writable($directory)) {
        throw new RuntimeException(
            "$label недоступна PHP для записи: $directory. " .
            'Для XAMPP macOS выполните: sudo chown -R daemon:daemon ' . escapeshellarg($directory) .
            ' && sudo chmod -R 775 ' . escapeshellarg($directory)
        );
    }
}


function create_automatic_pre_migration_backup(): void
{
    if (!file_exists(DB_PATH) || filesize(DB_PATH) === 0) {
        return;
    }

    $backup = dirname(DB_PATH) . '/automatic-before-' . APP_VERSION . '.sqlite';
    if (file_exists($backup)) {
        return;
    }

    // At this point no migration transaction has started. A direct copy gives
    // the operator a last-resort snapshot even when the legacy schema is only
    // partially intact.
    if (!@copy(DB_PATH, $backup)) {
        @file_put_contents(
            dirname(DB_PATH) . '/recovery-report.log',
            '[' . date('c') . '] Не удалось создать автоматическую резервную копию: ' . $backup . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
        return;
    }

    @chmod($backup, 0664);
}

function prepare_database_directory(): void
{
    prepare_writable_directory(dirname(DB_PATH), 'Папка базы данных');

    if (file_exists(DB_PATH) && !is_writable(DB_PATH)) {
        @chmod(DB_PATH, 0664);
        clearstatcache(true, DB_PATH);

        if (!is_writable(DB_PATH)) {
            throw new RuntimeException('Файл базы данных недоступен PHP для записи: ' . DB_PATH);
        }
    }
}

function database_has_table(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1"
    );
    $statement->execute([$table]);

    return (bool) $statement->fetchColumn();
}

function database_has_application_data(PDO $pdo): bool
{
    $names = [
        'admins', 'students', 'elections', 'candidates', 'votes',
        'election_settings', 'election_eligibility', 'participation', 'audit_logs',
    ];
    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $statement = $pdo->prepare(
        "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ($placeholders)"
    );
    $statement->execute($names);

    return (int) $statement->fetchColumn() > 0;
}

function database_needs_migration(PDO $pdo): bool
{
    $critical = [
        'admins', 'students', 'elections', 'candidates', 'votes',
        'election_settings', 'election_eligibility', 'participation',
    ];
    foreach ($critical as $table) {
        if (!database_has_table($pdo, $table)) {
            return true;
        }
    }

    try {
        $statement = $pdo->prepare(
            "SELECT value FROM election_settings WHERE key = 'schema_version' LIMIT 1"
        );
        $statement->execute();
        return (string) ($statement->fetchColumn() ?: '') !== APP_VERSION;
    } catch (Throwable) {
        return true;
    }
}

function write_migration_error(Throwable $exception): void
{
    $directory = dirname(DB_PATH);
    if (!is_dir($directory) || !is_writable($directory)) {
        return;
    }

    $message = '[' . date('c') . '] ' . $exception->__toString() . PHP_EOL . PHP_EOL;
    @file_put_contents($directory . '/migration-error.log', $message, FILE_APPEND | LOCK_EX);
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('Расширение PHP pdo_sqlite не установлено или отключено.');
    }

    prepare_database_directory();
    create_automatic_pre_migration_backup();

    try {
        $connection = new PDO('sqlite:' . DB_PATH, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $exception) {
        throw new RuntimeException(
            'Не удалось открыть SQLite-базу: ' . DB_PATH . '. ' . $exception->getMessage(),
            0,
            $exception
        );
    }

    $connection->exec('PRAGMA busy_timeout = 5000');
    $connection->exec('PRAGMA temp_store = MEMORY');
    $connection->exec('PRAGMA cache_size = -20000');
    $connection->exec('PRAGMA synchronous = NORMAL');

    $hasApplicationData = database_has_application_data($connection);
    if ($hasApplicationData && database_needs_migration($connection)) {
        require_once __DIR__ . '/migrations.php';

        // Migration runs only when the stored schema version differs from the
        // application version. Normal page loads no longer repeat all schema
        // checks and cleanup queries.
        $connection->exec('PRAGMA foreign_keys = OFF');
        try {
            run_migrations($connection);
        } catch (Throwable $exception) {
            write_migration_error($exception);
            throw new RuntimeException(
                'Автоматическое обновление базы не завершилось: ' . $exception->getMessage() .
                '. Откройте recovery.php; исходная база сохранена.',
                0,
                $exception
            );
        } finally {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            $connection->exec('PRAGMA foreign_keys = ON');
        }

        $violations = $connection->query('PRAGMA foreign_key_check')->fetchAll();
        if ($violations) {
            $details = json_encode($violations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $exception = new RuntimeException('После миграции обнаружены нарушения внешних ключей: ' . $details);
            write_migration_error($exception);
            throw $exception;
        }
    } else {
        $connection->exec('PRAGMA foreign_keys = ON');
    }

    try {
        $connection->exec('PRAGMA journal_mode = WAL');
    } catch (Throwable) {
        // Some XAMPP/filesystem combinations cannot create WAL/SHM files.
        // DELETE mode remains fully functional for a school-local installation.
        $connection->exec('PRAGMA journal_mode = DELETE');
    }

    $pdo = $connection;
    return $pdo;
}

function app_is_installed(): bool
{
    if (!file_exists(DB_PATH) || !extension_loaded('pdo_sqlite')) {
        return false;
    }

    try {
        // Installation detection must not run migrations. Otherwise a migration
        // error is mistaken for a fresh installation and redirects to setup.php.
        $connection = new PDO('sqlite:' . DB_PATH, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return database_has_application_data($connection);
    } catch (Throwable) {
        return false;
    }
}
