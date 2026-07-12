<?php
declare(strict_types=1);


function migration_table_exists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1"
    );
    $statement->execute([$table]);

    return (bool) $statement->fetchColumn();
}

function migration_table_columns(PDO $pdo, string $table): array
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !migration_table_exists($pdo, $table)) {
        return [];
    }

    $columns = [];
    foreach ($pdo->query('PRAGMA table_info("' . $table . '")')->fetchAll() as $row) {
        $name = (string) ($row['name'] ?? '');
        if ($name !== '') {
            $columns[$name] = $row;
        }
    }

    return $columns;
}

function migration_find_students_source(PDO $pdo): ?string
{
    $required = ['student_code', 'full_name', 'class_name', 'password_hash'];
    $bestTable = null;
    $bestRows = -1;

    $tables = $pdo->query(
        "SELECT name FROM sqlite_master
         WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
         ORDER BY name"
    )->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        $table = (string) $table;
        if ($table === 'students' || !preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            continue;
        }

        $columns = migration_table_columns($pdo, $table);
        if (array_diff($required, array_keys($columns))) {
            continue;
        }

        $rows = (int) $pdo->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn();
        if ($rows > $bestRows) {
            $bestRows = $rows;
            $bestTable = $table;
        }
    }

    return $bestTable;
}

function migration_create_students_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS students (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_code TEXT NOT NULL UNIQUE,
            full_name TEXT NOT NULL,
            class_name TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
            session_token TEXT NULL,
            failed_login_count INTEGER NOT NULL DEFAULT 0,
            locked_until TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NULL
        )"
    );
}

function migration_recover_missing_students(PDO $pdo): void
{
    $created = false;
    $source = null;

    if (!migration_table_exists($pdo, 'students')) {
        $source = migration_find_students_source($pdo);
        migration_create_students_table($pdo);
        $created = true;

        if ($source !== null) {
            $columns = migration_table_columns($pdo, $source);
            $expr = static function (string $column, string $fallback) use ($columns): string {
                return isset($columns[$column]) ? '"' . $column . '"' : $fallback;
            };

            $sql = sprintf(
                'INSERT OR IGNORE INTO students
                 (id, student_code, full_name, class_name, password_hash, is_active,
                  session_token, failed_login_count, locked_until, created_at, updated_at)
                 SELECT %s, student_code, full_name, class_name, password_hash, %s,
                        %s, %s, %s, %s, %s
                 FROM "%s"',
                $expr('id', 'NULL'),
                $expr('is_active', '1'),
                $expr('session_token', 'NULL'),
                $expr('failed_login_count', '0'),
                $expr('locked_until', 'NULL'),
                $expr('created_at', 'CURRENT_TIMESTAMP'),
                $expr('updated_at', 'NULL'),
                $source
            );
            $pdo->exec($sql);

            @file_put_contents(
                dirname(DB_PATH) . '/recovery-report.log',
                '[' . date('c') . "] Таблица students восстановлена из {$source}.\n",
                FILE_APPEND | LOCK_EX
            );
        }
    }

    // Eligibility snapshots intentionally contain no password. They are still
    // sufficient to restore identities and primary keys. Such accounts remain
    // disabled until an administrator assigns new passwords.
    if (migration_table_exists($pdo, 'election_eligibility')) {
        $columns = migration_table_columns($pdo, 'election_eligibility');
        if (!array_diff(['student_id', 'student_code', 'full_name', 'class_name'], array_keys($columns))) {
            $snapshots = $pdo->query(
                "SELECT student_id, MAX(student_code) AS student_code,
                        MAX(full_name) AS full_name, MAX(class_name) AS class_name
                 FROM election_eligibility
                 WHERE student_id IS NOT NULL
                 GROUP BY student_id
                 ORDER BY student_id"
            )->fetchAll();

            if ($snapshots) {
                $insert = $pdo->prepare(
                    'INSERT OR IGNORE INTO students
                     (id, student_code, full_name, class_name, password_hash, is_active, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
                );

                $recovered = 0;
                foreach ($snapshots as $row) {
                    $insert->execute([
                        (int) $row['student_id'],
                        (string) $row['student_code'],
                        (string) $row['full_name'],
                        (string) $row['class_name'],
                        password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                    ]);
                    $recovered += $insert->rowCount();
                }

                if ($recovered > 0) {
                    @file_put_contents(
                        dirname(DB_PATH) . '/recovery-report.log',
                        '[' . date('c') . '] Из election_eligibility восстановлено аккаунтов: '
                        . $recovered . ". Пароли необходимо назначить заново.\n",
                        FILE_APPEND | LOCK_EX
                    );
                }
            }
        }
    }

    // Participation may survive even when the eligibility snapshot does not.
    // Preserve referential integrity with disabled placeholder accounts rather
    // than deleting historical turnout records.
    if (migration_table_exists($pdo, 'participation')) {
        $columns = migration_table_columns($pdo, 'participation');
        if (isset($columns['student_id'])) {
            $missingIds = $pdo->query(
                'SELECT DISTINCT participation.student_id
                 FROM participation
                 LEFT JOIN students ON students.id = participation.student_id
                 WHERE participation.student_id IS NOT NULL AND students.id IS NULL
                 ORDER BY participation.student_id'
            )->fetchAll(PDO::FETCH_COLUMN);

            if ($missingIds) {
                $insert = $pdo->prepare(
                    'INSERT OR IGNORE INTO students
                     (id, student_code, full_name, class_name, password_hash, is_active, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
                );

                foreach ($missingIds as $studentId) {
                    $studentId = (int) $studentId;
                    $insert->execute([
                        $studentId,
                        'RECOVERED-' . $studentId,
                        'Восстановленный ученик #' . $studentId,
                        'Не указан',
                        password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                    ]);
                }

                @file_put_contents(
                    dirname(DB_PATH) . '/recovery-report.log',
                    '[' . date('c') . '] Для сохранения истории участия создано отключённых аккаунтов: '
                    . count($missingIds) . ".\n",
                    FILE_APPEND | LOCK_EX
                );
            }
        }
    }

    if ($created && $source === null) {
        @file_put_contents(
            dirname(DB_PATH) . '/recovery-report.log',
            '[' . date('c') . "] Таблица students отсутствовала и была создана заново.\n",
            FILE_APPEND | LOCK_EX
        );
    }
}


function migration_find_named_source(PDO $pdo, string $namePart, array $requiredColumns): ?string
{
    $bestTable = null;
    $bestRows = -1;
    $tables = $pdo->query(
        "SELECT name FROM sqlite_master
         WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
         ORDER BY name"
    )->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        $table = (string) $table;
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || stripos($table, $namePart) === false) {
            continue;
        }

        $columns = migration_table_columns($pdo, $table);
        if (array_diff($requiredColumns, array_keys($columns))) {
            continue;
        }

        $rows = (int) $pdo->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn();
        if ($rows > $bestRows) {
            $bestRows = $rows;
            $bestTable = $table;
        }
    }

    return $bestTable;
}

function migration_recover_missing_candidates(PDO $pdo): void
{
    if (migration_table_exists($pdo, 'candidates')) {
        return;
    }

    $source = migration_find_named_source($pdo, 'candidate', ['full_name', 'class_name']);
    if ($source !== null && $source !== 'candidates') {
        $pdo->exec('CREATE TABLE candidates AS SELECT * FROM "' . $source . '"');
        @file_put_contents(
            dirname(DB_PATH) . '/recovery-report.log',
            '[' . date('c') . "] Таблица candidates восстановлена из {$source}.\n",
            FILE_APPEND | LOCK_EX
        );
        return;
    }

    $pdo->exec(
        "CREATE TABLE candidates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            election_id INTEGER NULL,
            full_name TEXT NOT NULL,
            class_name TEXT NOT NULL,
            slogan TEXT NOT NULL DEFAULT '',
            program_text TEXT NOT NULL DEFAULT '',
            color TEXT NOT NULL DEFAULT '#665df5',
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );

    @file_put_contents(
        dirname(DB_PATH) . '/recovery-report.log',
        '[' . date('c') . "] Таблица candidates отсутствовала; создана пустая таблица.\n",
        FILE_APPEND | LOCK_EX
    );
}

function migration_recover_missing_votes(PDO $pdo): void
{
    if (migration_table_exists($pdo, 'votes')) {
        return;
    }

    $source = migration_find_named_source($pdo, 'vote', ['candidate_id']);
    if ($source !== null && $source !== 'votes') {
        $pdo->exec('CREATE TABLE votes AS SELECT * FROM "' . $source . '"');
        @file_put_contents(
            dirname(DB_PATH) . '/recovery-report.log',
            '[' . date('c') . "] Таблица votes восстановлена из {$source}.\n",
            FILE_APPEND | LOCK_EX
        );
        return;
    }

    $pdo->exec(
        "CREATE TABLE votes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            election_id INTEGER NULL,
            candidate_id INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );

    @file_put_contents(
        dirname(DB_PATH) . '/recovery-report.log',
        '[' . date('c') . "] Таблица votes отсутствовала; создана пустая таблица.\n",
        FILE_APPEND | LOCK_EX
    );
}



function migration_create_admins_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'superadmin',
            is_active INTEGER NOT NULL DEFAULT 1,
            last_login_at TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
}

function migration_recover_missing_admins(PDO $pdo): void
{
    if (migration_table_exists($pdo, 'admins')) {
        return;
    }

    $source = migration_find_named_source($pdo, 'admin', ['username', 'password_hash']);
    migration_create_admins_table($pdo);

    if ($source === null || $source === 'admins') {
        @file_put_contents(
            dirname(DB_PATH) . '/recovery-report.log',
            '[' . date('c') . "] Таблица admins отсутствовала и была создана заново. При отсутствии учётной записи используйте repair.php.\n",
            FILE_APPEND | LOCK_EX
        );
        return;
    }

    $columns = migration_table_columns($pdo, $source);
    $expr = static function (string $column, string $fallback) use ($columns): string {
        return isset($columns[$column]) ? '"' . $column . '"' : $fallback;
    };

    $sql = sprintf(
        'INSERT OR IGNORE INTO admins
         (id, username, password_hash, role, is_active, last_login_at, created_at)
         SELECT %s, username, password_hash, %s, %s, %s, %s FROM "%s"',
        $expr('id', 'NULL'),
        $expr('role', "'superadmin'"),
        $expr('is_active', '1'),
        $expr('last_login_at', 'NULL'),
        $expr('created_at', 'CURRENT_TIMESTAMP'),
        $source
    );
    $pdo->exec($sql);

    @file_put_contents(
        dirname(DB_PATH) . '/recovery-report.log',
        '[' . date('c') . "] Таблица admins восстановлена из {$source}.\n",
        FILE_APPEND | LOCK_EX
    );
}

function migration_has_column(PDO $pdo, string $table, string $column): bool
{
    $rows = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();

    foreach ($rows as $row) {
        if (($row['name'] ?? '') === $column) {
            return true;
        }
    }

    return false;
}

function migration_add_column(PDO $pdo, string $table, string $definition): void
{
    $column = preg_split('/\s+/', trim($definition))[0] ?? '';

    if ($column !== '' && !migration_has_column($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $definition");
    }
}

function migration_votes_needs_rebuild(PDO $pdo): bool
{
    $columns = $pdo->query('PRAGMA table_info(votes)')->fetchAll();
    $columnMap = [];
    foreach ($columns as $column) {
        $columnMap[(string) ($column['name'] ?? '')] = $column;
    }

    if (isset($columnMap['student_id'])) {
        return true;
    }

    if (!isset($columnMap['election_id']) || (int) ($columnMap['election_id']['notnull'] ?? 0) !== 1) {
        return true;
    }

    $foreignKeys = $pdo->query('PRAGMA foreign_key_list(votes)')->fetchAll();
    $hasElectionForeignKey = false;
    $hasCandidateForeignKey = false;
    foreach ($foreignKeys as $foreignKey) {
        $from = (string) ($foreignKey['from'] ?? '');
        $table = (string) ($foreignKey['table'] ?? '');
        if ($from === 'election_id' && $table === 'elections') {
            $hasElectionForeignKey = true;
        }
        if ($from === 'candidate_id' && $table === 'candidates') {
            $hasCandidateForeignKey = true;
        }
    }

    return !$hasElectionForeignKey || !$hasCandidateForeignKey;
}

function migration_rebuild_votes(PDO $pdo, int $fallbackElectionId): void
{
    $hasStudentId = migration_has_column($pdo, 'votes', 'student_id');

    if ($hasStudentId) {
        // Preserve the fact of participation before removing the legacy direct
        // relationship between a student and a selected candidate.
        $pdo->exec(
            "INSERT OR IGNORE INTO participation (election_id, student_id, voted_at)
             SELECT candidates.election_id, votes.student_id, COALESCE(votes.created_at, CURRENT_TIMESTAMP)
             FROM votes
             JOIN candidates ON candidates.id = votes.candidate_id
             JOIN students ON students.id = votes.student_id
             JOIN elections ON elections.id = candidates.election_id
             WHERE votes.student_id IS NOT NULL"
        );
    }

    $pdo->exec('DROP TABLE IF EXISTS votes_v401');
    $pdo->exec(
        "CREATE TABLE votes_v401 (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            election_id INTEGER NOT NULL,
            candidate_id INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE RESTRICT,
            FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE RESTRICT
        )"
    );

    $copy = $pdo->prepare(
        "INSERT INTO votes_v401 (id, election_id, candidate_id, created_at)
         SELECT votes.id,
                COALESCE(candidates.election_id, NULLIF(votes.election_id, 0), ?),
                votes.candidate_id,
                COALESCE(votes.created_at, CURRENT_TIMESTAMP)
         FROM votes
         JOIN candidates ON candidates.id = votes.candidate_id
         JOIN elections ON elections.id = COALESCE(candidates.election_id, NULLIF(votes.election_id, 0), ?)"
    );
    $copy->execute([$fallbackElectionId, $fallbackElectionId]);

    $pdo->exec('DROP TABLE votes');
    $pdo->exec('ALTER TABLE votes_v401 RENAME TO votes');
}


function migration_student_history_needs_rebuild(PDO $pdo, string $table): bool
{
    if (!migration_table_exists($pdo, $table)) {
        return false;
    }

    $columns = migration_table_columns($pdo, $table);
    if (!isset($columns['student_id']) || (int) ($columns['student_id']['notnull'] ?? 0) === 1) {
        return true;
    }

    if ($table === 'participation') {
        foreach (['student_code', 'full_name', 'class_name'] as $column) {
            if (!isset($columns[$column])) {
                return true;
            }
        }
    }

    foreach ($pdo->query('PRAGMA foreign_key_list("' . $table . '")')->fetchAll() as $foreignKey) {
        if ((string) ($foreignKey['from'] ?? '') === 'student_id') {
            return strtoupper((string) ($foreignKey['on_delete'] ?? '')) !== 'SET NULL';
        }
    }

    return true;
}

function migration_rebuild_student_history(PDO $pdo): void
{
    if (migration_student_history_needs_rebuild($pdo, 'election_eligibility')) {
        $pdo->exec('DROP TABLE IF EXISTS election_eligibility_v408');
        $pdo->exec(
            "CREATE TABLE election_eligibility_v408 (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                election_id INTEGER NOT NULL,
                student_id INTEGER NULL,
                student_code TEXT NOT NULL,
                full_name TEXT NOT NULL,
                class_name TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (election_id, student_id),
                FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE RESTRICT,
                FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL
            )"
        );
        $pdo->exec(
            "INSERT INTO election_eligibility_v408
             (id, election_id, student_id, student_code, full_name, class_name, created_at)
             SELECT id, election_id, student_id, student_code, full_name, class_name,
                    COALESCE(created_at, CURRENT_TIMESTAMP)
             FROM election_eligibility"
        );
        $pdo->exec('DROP TABLE election_eligibility');
        $pdo->exec('ALTER TABLE election_eligibility_v408 RENAME TO election_eligibility');
    }

    if (migration_student_history_needs_rebuild($pdo, 'participation')) {
        $columns = migration_table_columns($pdo, 'participation');
        $codeExpr = isset($columns['student_code']) ? "NULLIF(p.student_code, '')" : 'NULL';
        $nameExpr = isset($columns['full_name']) ? "NULLIF(p.full_name, '')" : 'NULL';
        $classExpr = isset($columns['class_name']) ? "NULLIF(p.class_name, '')" : 'NULL';

        $pdo->exec('DROP TABLE IF EXISTS participation_v408');
        $pdo->exec(
            "CREATE TABLE participation_v408 (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                election_id INTEGER NOT NULL,
                student_id INTEGER NULL,
                student_code TEXT NOT NULL DEFAULT '',
                full_name TEXT NOT NULL DEFAULT '',
                class_name TEXT NOT NULL DEFAULT '',
                voted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (election_id, student_id),
                FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE RESTRICT,
                FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL
            )"
        );
        $pdo->exec(
            "INSERT INTO participation_v408
             (id, election_id, student_id, student_code, full_name, class_name, voted_at)
             SELECT p.id,
                    p.election_id,
                    p.student_id,
                    COALESCE($codeExpr, ee.student_code, s.student_code, 'DELETED-' || p.id),
                    COALESCE($nameExpr, ee.full_name, s.full_name, 'Удалённый ученик'),
                    COALESCE($classExpr, ee.class_name, s.class_name, 'Не указан'),
                    COALESCE(p.voted_at, CURRENT_TIMESTAMP)
             FROM participation p
             LEFT JOIN election_eligibility ee
               ON ee.election_id = p.election_id AND ee.student_id = p.student_id
             LEFT JOIN students s ON s.id = p.student_id"
        );
        $pdo->exec('DROP TABLE participation');
        $pdo->exec('ALTER TABLE participation_v408 RENAME TO participation');
    }
}

function run_migrations(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $pdo->beginTransaction();

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS election_settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)");

        // Core identity tables must exist before dependent foreign keys are created.
        migration_recover_missing_admins($pdo);

        // students must exist before SQLite creates or validates tables whose
        // foreign keys reference it. This also repairs partially migrated DBs.
        migration_recover_missing_students($pdo);

        $pdo->exec("CREATE TABLE IF NOT EXISTS elections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            start_at TEXT NULL,
            end_at TEXT NULL,
            status TEXT NOT NULL DEFAULT 'draft',
            results_public INTEGER NOT NULL DEFAULT 0,
            candidates_randomized INTEGER NOT NULL DEFAULT 1,
            terminal_mode INTEGER NOT NULL DEFAULT 1,
            second_round_enabled INTEGER NOT NULL DEFAULT 1,
            second_round_threshold REAL NOT NULL DEFAULT 50.0,
            locked_at TEXT NULL,
            closed_at TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS election_eligibility (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            election_id INTEGER NOT NULL,
            student_id INTEGER NULL,
            student_code TEXT NOT NULL,
            full_name TEXT NOT NULL,
            class_name TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (election_id, student_id),
            FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE RESTRICT,
            FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS participation (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            election_id INTEGER NOT NULL,
            student_id INTEGER NULL,
            student_code TEXT NOT NULL DEFAULT '',
            full_name TEXT NOT NULL DEFAULT '',
            class_name TEXT NOT NULL DEFAULT '',
            voted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (election_id, student_id),
            FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE RESTRICT,
            FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            admin_id INTEGER NULL,
            action TEXT NOT NULL,
            entity_type TEXT NOT NULL DEFAULT '',
            entity_id INTEGER NULL,
            details TEXT NOT NULL DEFAULT '',
            ip_address TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scope TEXT NOT NULL,
            identifier TEXT NOT NULL DEFAULT '',
            ip_address TEXT NOT NULL DEFAULT '',
            success INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");

        // Convert historical student links to nullable snapshot references.
        // This permits account deletion without losing turnout or anonymous votes.
        migration_rebuild_student_history($pdo);

        // Run recovery once more now that eligibility/participation tables are
        // available; this can restore snapshot-only and placeholder accounts.
        migration_recover_missing_students($pdo);
        migration_recover_missing_candidates($pdo);
        migration_recover_missing_votes($pdo);

        migration_add_column($pdo, 'admins', "role TEXT NOT NULL DEFAULT 'superadmin'");
        migration_add_column($pdo, 'admins', 'is_active INTEGER NOT NULL DEFAULT 1');
        migration_add_column($pdo, 'admins', 'last_login_at TEXT NULL');

        migration_add_column($pdo, 'students', 'session_token TEXT NULL');
        migration_add_column($pdo, 'students', 'failed_login_count INTEGER NOT NULL DEFAULT 0');
        migration_add_column($pdo, 'students', 'locked_until TEXT NULL');
        migration_add_column($pdo, 'students', 'updated_at TEXT NULL');
        $pdo->exec("UPDATE students SET updated_at = COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)");

        migration_add_column($pdo, 'candidates', 'election_id INTEGER NULL');
        migration_add_column($pdo, 'candidates', "bio TEXT NOT NULL DEFAULT ''");
        migration_add_column($pdo, 'candidates', "achievements TEXT NOT NULL DEFAULT ''");
        migration_add_column($pdo, 'candidates', "resources_text TEXT NOT NULL DEFAULT ''");
        migration_add_column($pdo, 'candidates', "video_url TEXT NOT NULL DEFAULT ''");
        migration_add_column($pdo, 'candidates', "website_url TEXT NOT NULL DEFAULT ''");
        migration_add_column($pdo, 'candidates', 'photo_path TEXT NULL');
        migration_add_column($pdo, 'candidates', 'ballot_number INTEGER NOT NULL DEFAULT 0');
        migration_add_column($pdo, 'candidates', 'updated_at TEXT NULL');
        $pdo->exec("UPDATE candidates SET updated_at = COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)");

        migration_add_column($pdo, 'votes', 'election_id INTEGER NULL');

        $activeId = (int) $pdo->query(
            "SELECT value FROM election_settings WHERE key = 'active_election_id'"
        )->fetchColumn();

        if ($activeId <= 0 || !(bool) $pdo->query("SELECT 1 FROM elections WHERE id = $activeId")->fetchColumn()) {
            $legacyTitle = (string) ($pdo->query(
                "SELECT value FROM election_settings WHERE key = 'election_title'"
            )->fetchColumn() ?: 'Выборы президента ученического совета');
            $legacyDate = (string) ($pdo->query(
                "SELECT value FROM election_settings WHERE key = 'election_date'"
            )->fetchColumn() ?: date('Y-m-d'));
            $legacyOpen = (string) ($pdo->query(
                "SELECT value FROM election_settings WHERE key = 'election_open'"
            )->fetchColumn() ?: '0');
            $legacyPublic = (string) ($pdo->query(
                "SELECT value FROM election_settings WHERE key = 'results_public'"
            )->fetchColumn() ?: '1');

            $statement = $pdo->prepare(
                'INSERT INTO elections
                 (title, start_at, status, results_public, locked_at)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $status = $legacyOpen === '1' ? 'open' : 'draft';
            $statement->execute([
                $legacyTitle,
                $legacyDate . ' 08:00:00',
                $status,
                $legacyPublic === '1' ? 1 : 0,
                $status === 'open' ? date('Y-m-d H:i:s') : null,
            ]);
            $activeId = (int) $pdo->lastInsertId();

            $statement = $pdo->prepare(
                "INSERT INTO election_settings (key, value) VALUES ('active_election_id', ?)
                 ON CONFLICT(key) DO UPDATE SET value = excluded.value"
            );
            $statement->execute([(string) $activeId]);
        }

        $statement = $pdo->prepare('UPDATE candidates SET election_id = ? WHERE election_id IS NULL OR election_id = 0');
        $statement->execute([$activeId]);
        $statement = $pdo->prepare('UPDATE votes SET election_id = ? WHERE election_id IS NULL OR election_id = 0');
        $statement->execute([$activeId]);

        if (migration_votes_needs_rebuild($pdo)) {
            migration_rebuild_votes($pdo, $activeId);
        }

        if (migration_has_column($pdo, 'students', 'has_voted')) {
            $votedAt = migration_has_column($pdo, 'students', 'voted_at') ? 'COALESCE(voted_at, CURRENT_TIMESTAMP)' : 'CURRENT_TIMESTAMP';
            $pdo->exec(
                "INSERT OR IGNORE INTO participation
                 (election_id, student_id, student_code, full_name, class_name, voted_at)
                 SELECT $activeId, id, student_code, full_name, class_name, $votedAt
                 FROM students WHERE has_voted = 1"
            );
        }

        // A legacy election that already has votes is completed rather than a
        // deletable draft, even when the old `election_open` switch was off.
        $closeLegacy = $pdo->prepare(
            "UPDATE elections
             SET status = 'closed', closed_at = COALESCE(closed_at, CURRENT_TIMESTAMP), updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND status = 'draft'
               AND (EXISTS (SELECT 1 FROM votes WHERE votes.election_id = elections.id)
                    OR EXISTS (SELECT 1 FROM participation WHERE participation.election_id = elections.id))"
        );
        $closeLegacy->execute([$activeId]);

        $activeStatusStatement = $pdo->prepare('SELECT status FROM elections WHERE id = ?');
        $activeStatusStatement->execute([$activeId]);
        $activeStatus = (string) ($activeStatusStatement->fetchColumn() ?: 'draft');

        // A draft campaign must not keep a historical eligibility snapshot. The
        // snapshot is created when the campaign opens. Legacy open/closed
        // campaigns retain all students so turnout calculations stay stable.
        if (in_array($activeStatus, ['open', 'closed', 'archived'], true)) {
            $eligibility = $pdo->prepare(
                'INSERT OR IGNORE INTO election_eligibility
                 (election_id, student_id, student_code, full_name, class_name)
                 SELECT ?, id, student_code, full_name, class_name FROM students'
            );
            $eligibility->execute([$activeId]);
        }

        $defaults = [
            'schema_version' => APP_VERSION,
            'school_name' => 'Школьные выборы',
            'base_url' => '',
            'session_timeout_minutes' => '30',
            'max_login_attempts' => '5',
            'lockout_minutes' => '10',
            'terminal_redirect_seconds' => '8',
            'default_language' => 'ru',
            'allow_public_archive' => '1',
        ];
        $setting = $pdo->prepare(
            'INSERT INTO election_settings (key, value) VALUES (?, ?)
             ON CONFLICT(key) DO NOTHING'
        );
        foreach ($defaults as $key => $value) {
            $setting->execute([$key, $value]);
        }

        $versionStatement = $pdo->prepare(
            "INSERT INTO election_settings (key, value) VALUES ('schema_version', ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value"
        );
        $versionStatement->execute([APP_VERSION]);

        // Remove only stale draft snapshots left by version 4.0.0. Historical
        // open/closed elections and all participation records are preserved.
        $pdo->exec(
            "DELETE FROM election_eligibility
             WHERE election_id IN (
                 SELECT elections.id FROM elections
                 WHERE elections.status = 'draft'
                   AND NOT EXISTS (SELECT 1 FROM participation WHERE participation.election_id = elections.id)
                   AND NOT EXISTS (SELECT 1 FROM votes WHERE votes.election_id = elections.id)
             )"
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_candidates_election ON candidates(election_id, is_active)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_votes_election_candidate ON votes(election_id, candidate_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_eligibility_election ON election_eligibility(election_id, student_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_participation_election ON participation(election_id, student_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_students_list_sort ON students(class_name, full_name, id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_students_active ON students(is_active, class_name, full_name)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_participation_student_election ON participation(student_id, election_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_eligibility_student_election ON election_eligibility(student_id, election_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_participation_snapshot ON participation(election_id, student_code)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs(created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_lookup ON login_attempts(scope, identifier, ip_address, created_at)');

        $pdo->commit();
        $done = true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
