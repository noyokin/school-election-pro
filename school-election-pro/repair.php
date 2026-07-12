<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
restore_exception_handler();

/**
 * Standalone emergency recovery for partially migrated SQLite databases.
 * This file intentionally does not include database.php or functions.php.
 */

function r_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function r_valid_identifier(string $name): bool
{
    return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name);
}

function r_table_exists(PDO $pdo, string $table, string $schema = 'main'): bool
{
    if (!r_valid_identifier($table) || !r_valid_identifier($schema)) {
        return false;
    }

    $statement = $pdo->prepare(
        "SELECT 1 FROM {$schema}.sqlite_master WHERE type = 'table' AND name = ? LIMIT 1"
    );
    $statement->execute([$table]);

    return (bool) $statement->fetchColumn();
}

function r_columns(PDO $pdo, string $table, string $schema = 'main'): array
{
    if (!r_table_exists($pdo, $table, $schema)) {
        return [];
    }

    $columns = [];
    foreach ($pdo->query("PRAGMA {$schema}.table_info(\"{$table}\")")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = (string) ($row['name'] ?? '');
        if ($name !== '') {
            $columns[$name] = $row;
        }
    }

    return $columns;
}

function r_tables(PDO $pdo, string $schema = 'main'): array
{
    if (!r_valid_identifier($schema)) {
        return [];
    }

    return array_map(
        'strval',
        $pdo->query(
            "SELECT name FROM {$schema}.sqlite_master
             WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
             ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN)
    );
}

function r_create_admins(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'superadmin' CHECK (role IN ('superadmin','manager','observer')),
            is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0,1)),
            last_login_at TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
}

function r_create_students(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS students (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_code TEXT NOT NULL UNIQUE,
            full_name TEXT NOT NULL,
            class_name TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0,1)),
            session_token TEXT NULL,
            failed_login_count INTEGER NOT NULL DEFAULT 0,
            locked_until TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NULL
        )"
    );
}

function r_rename_broken_table(PDO $pdo, string $table, array $required, array &$log): void
{
    if (!r_table_exists($pdo, $table)) {
        return;
    }

    $columns = r_columns($pdo, $table);
    if (!array_diff($required, array_keys($columns))) {
        return;
    }

    $suffix = date('Ymd_His');
    $newName = $table . '_broken_' . $suffix;
    $counter = 1;
    while (r_table_exists($pdo, $newName)) {
        $newName = $table . '_broken_' . $suffix . '_' . $counter++;
    }

    $pdo->exec('ALTER TABLE "' . $table . '" RENAME TO "' . $newName . '"');
    $log[] = "Некорректная таблица {$table} сохранена как {$newName}.";
}

function r_make_backup(PDO $pdo): string
{
    $directory = dirname(DB_PATH);
    $backup = $directory . '/emergency-before-4.0.5-' . date('Ymd-His') . '.sqlite';

    try {
        $pdo->exec('PRAGMA wal_checkpoint(FULL)');
    } catch (Throwable) {
        // DELETE journal mode or incomplete WAL is acceptable here.
    }

    if (!@copy(DB_PATH, $backup)) {
        throw new RuntimeException('Не удалось создать аварийную копию: ' . $backup);
    }
    @chmod($backup, 0664);

    foreach (['-wal', '-shm'] as $suffix) {
        if (is_file(DB_PATH . $suffix)) {
            @copy(DB_PATH . $suffix, $backup . $suffix);
            @chmod($backup . $suffix, 0664);
        }
    }

    return $backup;
}

function r_backup_files(string $current, string $justCreated): array
{
    $files = [];
    foreach ([dirname($current), BACKUP_ROOT] as $directory) {
        if (!is_dir($directory)) {
            continue;
        }

        foreach (glob($directory . '/*.sqlite') ?: [] as $file) {
            $real = realpath($file) ?: $file;
            if ($real === (realpath($current) ?: $current) || $real === (realpath($justCreated) ?: $justCreated)) {
                continue;
            }
            if (is_file($file) && filesize($file) > 0) {
                $files[$real] = filemtime($file) ?: 0;
            }
        }
    }

    arsort($files, SORT_NUMERIC);
    return array_keys($files);
}

function r_open_readonly_backup(string $path): ?PDO
{
    try {
        return new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable) {
        return null;
    }
}

function r_source_tables(PDO $pdo, array $required, array $exclude = [], string $schema = 'main'): array
{
    $result = [];
    foreach (r_tables($pdo, $schema) as $table) {
        if (in_array($table, $exclude, true) || !r_valid_identifier($table)) {
            continue;
        }
        $columns = r_columns($pdo, $table, $schema);
        if (!array_diff($required, array_keys($columns))) {
            $result[] = $table;
        }
    }

    return $result;
}

function r_unique_username(PDO $pdo, string $username, int $id): string
{
    $username = trim($username) ?: ('recovered_admin_' . $id);
    $username = preg_replace('/[^A-Za-z0-9_.-]/', '_', $username) ?: ('recovered_admin_' . $id);
    $username = substr($username, 0, 40);

    $statement = $pdo->prepare('SELECT id FROM admins WHERE username = ? LIMIT 1');
    $candidate = $username;
    $counter = 1;
    while (true) {
        $statement->execute([$candidate]);
        $owner = $statement->fetchColumn();
        if ($owner === false || (int) $owner === $id) {
            return $candidate;
        }
        $suffix = '_' . $counter++;
        $candidate = substr($username, 0, max(1, 40 - strlen($suffix))) . $suffix;
    }
}

function r_insert_admin(PDO $pdo, array $row): bool
{
    $id = (int) ($row['id'] ?? 0);
    $username = trim((string) ($row['username'] ?? ''));
    $hash = (string) ($row['password_hash'] ?? '');

    if ($username === '' || $hash === '') {
        return false;
    }

    if ($id <= 0) {
        $id = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM admins')->fetchColumn();
    }

    $exists = $pdo->prepare('SELECT 1 FROM admins WHERE id = ?');
    $exists->execute([$id]);
    if ($exists->fetchColumn()) {
        return false;
    }

    $role = (string) ($row['role'] ?? 'superadmin');
    if (!in_array($role, ['superadmin', 'manager', 'observer'], true)) {
        $role = 'superadmin';
    }

    $statement = $pdo->prepare(
        'INSERT INTO admins
         (id, username, password_hash, role, is_active, last_login_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([
        $id,
        r_unique_username($pdo, $username, $id),
        $hash,
        $role,
        ((int) ($row['is_active'] ?? 1)) === 1 ? 1 : 0,
        $row['last_login_at'] ?? null,
        trim((string) ($row['created_at'] ?? '')) ?: date('Y-m-d H:i:s'),
    ]);

    return true;
}

function r_recover_admins(PDO $pdo, array $backupFiles, array &$log): int
{
    $restored = 0;

    foreach (r_source_tables($pdo, ['id', 'username', 'password_hash'], ['admins']) as $source) {
        $count = 0;
        foreach ($pdo->query('SELECT * FROM "' . $source . '" ORDER BY id')->fetchAll() as $row) {
            if (r_insert_admin($pdo, $row)) {
                $count++;
            }
        }
        if ($count > 0) {
            $restored += $count;
            $log[] = "Администраторы восстановлены из {$source}: {$count}.";
        }
    }

    foreach ($backupFiles as $backupPath) {
        $backup = r_open_readonly_backup($backupPath);
        if (!$backup || !r_table_exists($backup, 'admins')) {
            continue;
        }
        $columns = r_columns($backup, 'admins');
        if (array_diff(['id', 'username', 'password_hash'], array_keys($columns))) {
            continue;
        }

        $count = 0;
        foreach ($backup->query('SELECT * FROM admins ORDER BY id')->fetchAll() as $row) {
            if (r_insert_admin($pdo, $row)) {
                $count++;
            }
        }
        if ($count > 0) {
            $restored += $count;
            $log[] = "Администраторы восстановлены из резервной базы {$backupPath}: {$count}.";
            break;
        }
    }

    return $restored;
}

function r_unique_student_code(PDO $pdo, string $preferred, int $id): string
{
    $preferred = trim($preferred) ?: ('RECOVERED-' . $id);
    $statement = $pdo->prepare('SELECT id FROM students WHERE student_code = ? LIMIT 1');
    $candidate = $preferred;
    $counter = 1;

    while (true) {
        $statement->execute([$candidate]);
        $owner = $statement->fetchColumn();
        if ($owner === false || (int) $owner === $id) {
            return $candidate;
        }
        $suffix = '-R' . $id . ($counter > 1 ? '-' . $counter : '');
        $candidate = substr($preferred, 0, max(1, 80 - strlen($suffix))) . $suffix;
        $counter++;
    }
}

function r_insert_student(PDO $pdo, array $row, bool $forceDisabled = false): bool
{
    $id = (int) ($row['id'] ?? $row['student_id'] ?? 0);
    if ($id <= 0) {
        return false;
    }

    $exists = $pdo->prepare('SELECT 1 FROM students WHERE id = ?');
    $exists->execute([$id]);
    if ($exists->fetchColumn()) {
        return false;
    }

    $hash = (string) ($row['password_hash'] ?? '');
    if ($hash === '') {
        $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $forceDisabled = true;
    }

    $statement = $pdo->prepare(
        'INSERT INTO students
         (id, student_code, full_name, class_name, password_hash, is_active,
          session_token, failed_login_count, locked_until, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([
        $id,
        r_unique_student_code($pdo, (string) ($row['student_code'] ?? ''), $id),
        trim((string) ($row['full_name'] ?? '')) ?: ('Восстановленный ученик #' . $id),
        trim((string) ($row['class_name'] ?? '')) ?: 'Не указан',
        $hash,
        $forceDisabled ? 0 : (((int) ($row['is_active'] ?? 1)) === 1 ? 1 : 0),
        $row['session_token'] ?? null,
        max(0, (int) ($row['failed_login_count'] ?? 0)),
        $row['locked_until'] ?? null,
        trim((string) ($row['created_at'] ?? '')) ?: date('Y-m-d H:i:s'),
        $row['updated_at'] ?? null,
    ]);

    return true;
}

function r_recover_students(PDO $pdo, array $backupFiles, array &$log): int
{
    $restored = 0;

    foreach (r_source_tables($pdo, ['id', 'student_code', 'full_name', 'class_name', 'password_hash'], ['students']) as $source) {
        $count = 0;
        foreach ($pdo->query('SELECT * FROM "' . $source . '" ORDER BY id')->fetchAll() as $row) {
            if (r_insert_student($pdo, $row)) {
                $count++;
            }
        }
        if ($count > 0) {
            $restored += $count;
            $log[] = "Ученики восстановлены из {$source}: {$count}.";
        }
    }

    foreach ($backupFiles as $backupPath) {
        $backup = r_open_readonly_backup($backupPath);
        if (!$backup || !r_table_exists($backup, 'students')) {
            continue;
        }
        $columns = r_columns($backup, 'students');
        if (array_diff(['id', 'student_code', 'full_name', 'class_name', 'password_hash'], array_keys($columns))) {
            continue;
        }

        $count = 0;
        foreach ($backup->query('SELECT * FROM students ORDER BY id')->fetchAll() as $row) {
            if (r_insert_student($pdo, $row)) {
                $count++;
            }
        }
        if ($count > 0) {
            $restored += $count;
            $log[] = "Ученики восстановлены из резервной базы {$backupPath}: {$count}.";
            break;
        }
    }

    if (r_table_exists($pdo, 'election_eligibility')) {
        $columns = r_columns($pdo, 'election_eligibility');
        if (!array_diff(['student_id', 'student_code', 'full_name', 'class_name'], array_keys($columns))) {
            $rows = $pdo->query(
                'SELECT student_id AS id, MAX(student_code) AS student_code,
                        MAX(full_name) AS full_name, MAX(class_name) AS class_name
                 FROM election_eligibility
                 WHERE student_id IS NOT NULL
                 GROUP BY student_id ORDER BY student_id'
            )->fetchAll();
            $count = 0;
            foreach ($rows as $row) {
                if (r_insert_student($pdo, $row, true)) {
                    $count++;
                }
            }
            if ($count > 0) {
                $restored += $count;
                $log[] = "Из снимков допуска восстановлено отключённых аккаунтов: {$count}.";
            }
        }
    }

    if (r_table_exists($pdo, 'participation') && isset(r_columns($pdo, 'participation')['student_id'])) {
        $rows = $pdo->query(
            'SELECT DISTINCT participation.student_id AS id
             FROM participation
             LEFT JOIN students ON students.id = participation.student_id
             WHERE participation.student_id IS NOT NULL AND students.id IS NULL
             ORDER BY participation.student_id'
        )->fetchAll();
        $count = 0;
        foreach ($rows as $row) {
            if (r_insert_student($pdo, $row, true)) {
                $count++;
            }
        }
        if ($count > 0) {
            $restored += $count;
            $log[] = "Для истории участия создано отключённых аккаунтов: {$count}.";
        }
    }

    return $restored;
}


function r_repair_orphaned_references(PDO $pdo, array &$log): void
{
    // Preserve historical election IDs by creating closed placeholder campaigns
    // instead of deleting rows that survived a failed migration.
    if (r_table_exists($pdo, 'elections')) {
        $referencedElectionIds = [];
        foreach (['candidates', 'election_eligibility', 'participation', 'votes'] as $table) {
            if (!r_table_exists($pdo, $table) || !isset(r_columns($pdo, $table)['election_id'])) {
                continue;
            }
            foreach ($pdo->query(
                'SELECT DISTINCT election_id FROM "' . $table . '" WHERE election_id IS NOT NULL AND election_id > 0'
            )->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $referencedElectionIds[(int) $id] = true;
            }
        }

        $check = $pdo->prepare('SELECT 1 FROM elections WHERE id = ?');
        $insert = $pdo->prepare(
            "INSERT INTO elections
             (id, title, description, status, results_public, created_at, updated_at)
             VALUES (?, ?, '', 'closed', 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $created = 0;
        foreach (array_keys($referencedElectionIds) as $id) {
            $check->execute([$id]);
            if (!$check->fetchColumn()) {
                $insert->execute([$id, 'Восстановленные выборы #' . $id]);
                $created++;
            }
        }
        if ($created > 0) {
            $log[] = "Создано отсутствующих исторических кампаний: {$created}.";
        }
    }

    // Keep audit history, but detach entries whose administrator record did not survive.
    if (r_table_exists($pdo, 'audit_logs') && isset(r_columns($pdo, 'audit_logs')['admin_id'])) {
        $statement = $pdo->prepare(
            'UPDATE audit_logs
             SET admin_id = NULL
             WHERE admin_id IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM admins WHERE admins.id = audit_logs.admin_id)'
        );
        $statement->execute();
        if ($statement->rowCount() > 0) {
            $log[] = 'Отвязано записей журнала от отсутствующих администраторов: ' . $statement->rowCount() . '.';
        }
    }

    // Create disabled student placeholders for any surviving historical links.
    r_recover_students($pdo, [], $log);

    // Votes are anonymous. If a candidate record disappeared, preserve the vote
    // count under a clearly marked placeholder candidate rather than dropping it.
    if (r_table_exists($pdo, 'votes') && r_table_exists($pdo, 'candidates')) {
        $voteColumns = r_columns($pdo, 'votes');
        $candidateColumns = r_columns($pdo, 'candidates');
        if (isset($voteColumns['candidate_id'], $candidateColumns['election_id'])) {
            $rows = $pdo->query(
                'SELECT votes.candidate_id,
                        COALESCE(MAX(votes.election_id), 0) AS election_id
                 FROM votes
                 LEFT JOIN candidates ON candidates.id = votes.candidate_id
                 WHERE candidates.id IS NULL
                 GROUP BY votes.candidate_id'
            )->fetchAll();

            $activeElectionId = 0;
            if (r_table_exists($pdo, 'election_settings')) {
                $statement = $pdo->query(
                    "SELECT value FROM election_settings WHERE key = 'active_election_id'"
                );
                $activeElectionId = (int) ($statement->fetchColumn() ?: 0);
            }
            if ($activeElectionId <= 0 && r_table_exists($pdo, 'elections')) {
                $activeElectionId = (int) ($pdo->query('SELECT MIN(id) FROM elections')->fetchColumn() ?: 0);
            }

            $insert = $pdo->prepare(
                "INSERT OR IGNORE INTO candidates
                 (id, election_id, full_name, class_name, slogan, program_text, color,
                  ballot_number, is_active, created_at, updated_at)
                 VALUES (?, ?, ?, 'Не указан', 'Восстановленная запись', '', '#7a8499',
                         0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
            );
            $created = 0;
            foreach ($rows as $row) {
                $electionId = (int) ($row['election_id'] ?? 0);
                if ($electionId <= 0) {
                    $electionId = $activeElectionId;
                }
                if ($electionId <= 0) {
                    continue;
                }
                $insert->execute([
                    (int) $row['candidate_id'],
                    $electionId,
                    'Восстановленный кандидат #' . (int) $row['candidate_id'],
                ]);
                $created += $insert->rowCount();
            }
            if ($created > 0) {
                $log[] = "Создано отсутствующих кандидатов для сохранения голосов: {$created}.";
            }
        }
    }
}

function r_upsert_emergency_admin(PDO $pdo, string $username, string $password, array &$log): void
{
    if (!preg_match('/^[A-Za-z0-9_.-]{3,40}$/', $username)) {
        throw new RuntimeException('Аварийный логин: 3–40 символов, только латинские буквы, цифры, точка, дефис и подчёркивание.');
    }
    if (strlen($password) < 10) {
        throw new RuntimeException('Аварийный пароль должен содержать не менее 10 символов.');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $statement = $pdo->prepare('SELECT id FROM admins WHERE username = ?');
    $statement->execute([$username]);
    $id = $statement->fetchColumn();

    if ($id !== false) {
        $update = $pdo->prepare(
            "UPDATE admins
             SET password_hash = ?, role = 'superadmin', is_active = 1
             WHERE id = ?"
        );
        $update->execute([$hash, (int) $id]);
        $log[] = "Пароль аварийного администратора {$username} обновлён.";
    } else {
        $insert = $pdo->prepare(
            "INSERT INTO admins (username, password_hash, role, is_active)
             VALUES (?, ?, 'superadmin', 1)"
        );
        $insert->execute([$username, $hash]);
        $log[] = "Создан аварийный главный администратор {$username}.";
    }
}

function r_repair_database(string $emergencyUsername, string $emergencyPassword): array
{
    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('В PHP XAMPP не подключено расширение pdo_sqlite.');
    }
    if (!is_file(DB_PATH)) {
        throw new RuntimeException('Файл базы не найден: ' . DB_PATH);
    }
    if (!is_writable(DB_PATH) || !is_writable(dirname(DB_PATH))) {
        throw new RuntimeException('PHP не имеет прав на запись в election.sqlite или папку data.');
    }

    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 10000');

    $integrityBefore = (string) $pdo->query('PRAGMA integrity_check')->fetchColumn();
    if ($integrityBefore !== 'ok') {
        throw new RuntimeException('SQLite сообщает о физическом повреждении файла: ' . $integrityBefore);
    }

    $backup = r_make_backup($pdo);
    $backupFiles = r_backup_files(DB_PATH, $backup);
    $log = ['Аварийная копия: ' . $backup];

    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->beginTransaction();

    try {
        r_rename_broken_table($pdo, 'admins', ['id', 'username', 'password_hash'], $log);
        r_rename_broken_table($pdo, 'students', ['id', 'student_code', 'full_name', 'class_name', 'password_hash'], $log);

        r_create_admins($pdo);
        r_create_students($pdo);

        $adminsRestored = r_recover_admins($pdo, $backupFiles, $log);
        $studentsRestored = r_recover_students($pdo, $backupFiles, $log);

        $adminCount = (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
        if ($emergencyUsername !== '' || $emergencyPassword !== '') {
            r_upsert_emergency_admin($pdo, $emergencyUsername, $emergencyPassword, $log);
        } elseif ($adminCount === 0) {
            throw new RuntimeException(
                'Администраторы не найдены ни в базе, ни в резервных копиях. ' .
                'Заполните поля аварийного логина и пароля на этой странице.'
            );
        }

        if ((int) $pdo->query('SELECT COUNT(*) FROM admins WHERE is_active = 1')->fetchColumn() === 0) {
            $pdo->exec("UPDATE admins SET is_active = 1, role = 'superadmin' WHERE id = (SELECT MIN(id) FROM admins)");
            $log[] = 'Активирован первый восстановленный администратор.';
        }

        $pdo->exec('COMMIT');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->exec('ROLLBACK');
        }
        throw $exception;
    }

    require_once __DIR__ . '/migrations.php';
    $pdo->exec('PRAGMA foreign_keys = OFF');
    run_migrations($pdo);

    $pdo->beginTransaction();
    try {
        r_repair_orphaned_references($pdo, $log);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    $pdo->exec('PRAGMA foreign_keys = ON');

    $integrityAfter = (string) $pdo->query('PRAGMA integrity_check')->fetchColumn();
    $violations = $pdo->query('PRAGMA foreign_key_check')->fetchAll();

    if ($integrityAfter !== 'ok') {
        throw new RuntimeException('После восстановления проверка SQLite не пройдена: ' . $integrityAfter);
    }
    if ($violations) {
        throw new RuntimeException(
            'После восстановления остались нарушения внешних ключей: ' .
            json_encode($violations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    $adminCount = (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    $studentCount = (int) $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();
    $disabledStudents = (int) $pdo->query('SELECT COUNT(*) FROM students WHERE is_active = 0')->fetchColumn();

    $report = '[' . date('c') . "] Восстановление 4.0.5 завершено.\n"
        . implode("\n", $log) . "\n"
        . "Администраторов: {$adminCount}; учеников: {$studentCount}; отключено учеников: {$disabledStudents}.\n\n";
    @file_put_contents(dirname(DB_PATH) . '/recovery-report.log', $report, FILE_APPEND | LOCK_EX);

    return [
        'backup' => $backup,
        'admins' => $adminCount,
        'students' => $studentCount,
        'disabled_students' => $disabledStudents,
        'admins_restored' => $adminsRestored,
        'students_restored' => $studentsRestored,
        'integrity' => $integrityAfter,
        'log' => $log,
    ];
}

if (empty($_SESSION['repair_csrf'])) {
    $_SESSION['repair_csrf'] = bin2hex(random_bytes(32));
}

$result = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['repair_csrf'] ?? '');
    $confirmation = trim((string) ($_POST['confirmation'] ?? ''));
    $username = trim((string) ($_POST['admin_username'] ?? ''));
    $password = (string) ($_POST['admin_password'] ?? '');

    if (!hash_equals((string) $_SESSION['repair_csrf'], $token)) {
        $error = 'Срок действия формы истёк. Обновите страницу.';
    } elseif ($confirmation !== 'ВОССТАНОВИТЬ') {
        $error = 'Введите точную фразу ВОССТАНОВИТЬ.';
    } else {
        try {
            $result = r_repair_database($username, $password);
            $_SESSION['repair_csrf'] = bin2hex(random_bytes(32));
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
            @file_put_contents(
                dirname(DB_PATH) . '/recovery-report.log',
                '[' . date('c') . '] Ошибка восстановления 4.0.5: ' . $exception->__toString() . "\n\n",
                FILE_APPEND | LOCK_EX
            );
        }
    }
}

$tables = [];
$adminRows = null;
$studentRows = null;
try {
    if (extension_loaded('pdo_sqlite') && is_file(DB_PATH)) {
        $inspection = new PDO('sqlite:' . DB_PATH, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $tables = r_tables($inspection);
        if (r_table_exists($inspection, 'admins')) {
            $adminRows = (int) $inspection->query('SELECT COUNT(*) FROM admins')->fetchColumn();
        }
        if (r_table_exists($inspection, 'students')) {
            $studentRows = (int) $inspection->query('SELECT COUNT(*) FROM students')->fetchColumn();
        }
    }
} catch (Throwable) {
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Аварийное восстановление 4.0.5</title>
    <style>
        body{font-family:system-ui,-apple-system,sans-serif;background:#f4f7fb;color:#172033;padding:24px;line-height:1.5}
        .box{max-width:900px;margin:4vh auto;background:#fff;border:1px solid #dfe6f0;border-radius:22px;padding:28px;box-shadow:0 20px 55px rgba(31,43,78,.11)}
        label{display:block;font-weight:700;margin-top:14px}input{width:100%;box-sizing:border-box;padding:12px;border:1px solid #dfe6f0;border-radius:12px;margin:7px 0}
        button{border:0;border-radius:13px;padding:12px 18px;background:#5b5ce2;color:#fff;font-weight:800;cursor:pointer;margin-top:14px}
        .ok{padding:16px;border-radius:13px;background:#e8f8f2;color:#087455}.bad{padding:16px;border-radius:13px;background:#fff0f2;color:#a82c3c}
        code,pre{display:block;white-space:pre-wrap;background:#eef2f8;padding:13px;border-radius:12px;overflow:auto}.muted{color:#68738a}a{color:#5b5ce2;font-weight:750}
        .grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}@media(max-width:700px){.grid{grid-template-columns:1fr}}
    </style>
</head>
<body><div class="box">
    <h1>Аварийное восстановление базы 4.0.5</h1>
    <p>Страница работает автономно и сначала восстанавливает <code>admins</code> и <code>students</code>. Основное приложение до этого не запускается.</p>

    <?php if ($error): ?>
        <div class="bad"><strong>Восстановление не завершено:</strong><br><?= r_e($error) ?></div>
    <?php endif; ?>

    <?php if ($result): ?>
        <div class="ok">
            <strong>Восстановление завершено.</strong><br>
            Администраторов: <?= (int) $result['admins'] ?>.<br>
            Учеников: <?= (int) $result['students'] ?>.<br>
            Отключённых учеников: <?= (int) $result['disabled_students'] ?>.<br>
            Целостность SQLite: <?= r_e((string) $result['integrity']) ?>.
        </div>
        <h2>Аварийная копия</h2>
        <code><?= r_e((string) $result['backup']) ?></code>
        <h2>Выполненные действия</h2>
        <pre><?= r_e(implode("\n", $result['log'])) ?></pre>
        <p><a href="admin/login.php">Открыть вход администратора</a> · <a href="index.php">Открыть сайт</a></p>
    <?php else: ?>
        <div class="grid">
            <div><strong>Таблица admins</strong><br><span class="muted"><?= $adminRows === null ? 'отсутствует' : ('строк: ' . $adminRows) ?></span></div>
            <div><strong>Таблица students</strong><br><span class="muted"><?= $studentRows === null ? 'отсутствует' : ('строк: ' . $studentRows) ?></span></div>
        </div>
        <p class="muted">Если старый администратор не сохранился в резервных копиях, заполните аварийный логин и пароль. Эти поля также можно использовать для принудительного сброса пароля известного логина.</p>
        <form method="post">
            <input type="hidden" name="repair_csrf" value="<?= r_e((string) $_SESSION['repair_csrf']) ?>">
            <label>Аварийный логин администратора
                <input type="text" name="admin_username" value="<?= r_e((string) ($_POST['admin_username'] ?? 'admin')) ?>" pattern="[A-Za-z0-9_.-]{3,40}" autocomplete="username">
            </label>
            <label>Новый аварийный пароль — минимум 10 символов
                <input type="password" name="admin_password" minlength="10" autocomplete="new-password">
            </label>
            <label>Фраза подтверждения
                <input type="text" name="confirmation" placeholder="ВОССТАНОВИТЬ" required>
            </label>
            <button type="submit">Создать копию и восстановить базу</button>
        </form>
        <h2>Найденные таблицы</h2>
        <code><?= r_e($tables ? implode(', ', $tables) : 'таблицы не найдены') ?></code>
    <?php endif; ?>
</div></body></html>
