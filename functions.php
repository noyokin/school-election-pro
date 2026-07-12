<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $location): never
{
    header('Location: ' . $location);
    exit;
}

function student_password_hash(string $password): string
{
    // Student credentials are short-lived local accounts. Cost 9 keeps bulk
    // import responsive on XAMPP while remaining compatible with password_verify().
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 9]);
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Срок действия формы истёк. Обновите страницу и повторите действие.');
    }
}

function database_error_message(Throwable $exception, string $fallback = 'Не удалось выполнить операцию с базой данных.'): string
{
    $message = $exception->getMessage();

    if ($exception instanceof PDOException) {
        if (str_contains($message, 'FOREIGN KEY constraint failed')) {
            return 'Операция заблокирована: запись используется в истории выборов или связана с другими данными. Используйте отключение записи либо удалите зависимые данные безопасным способом.';
        }
        if (str_contains($message, 'UNIQUE constraint failed')) {
            return 'Такая запись уже существует. Проверьте уникальный код или логин.';
        }
        if (str_contains($message, 'database is locked')) {
            return 'База данных временно занята другой операцией. Повторите действие через несколько секунд.';
        }
    }

    return $message !== '' ? $message : $fallback;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return is_array($messages) ? $messages : [];
}

function setting(string $key, string $default = ''): string
{
    try {
        $statement = db()->prepare('SELECT value FROM election_settings WHERE key = ?');
        $statement->execute([$key]);
        $value = $statement->fetchColumn();

        return $value === false ? $default : (string) $value;
    } catch (Throwable) {
        return $default;
    }
}

function save_setting(string $key, string $value): void
{
    $statement = db()->prepare(
        'INSERT INTO election_settings (key, value) VALUES (?, ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value'
    );
    $statement->execute([$key, $value]);
}

function current_language(): string
{
    $requested = (string) ($_GET['lang'] ?? '');
    if (in_array($requested, ['ru', 'en'], true)) {
        setcookie('school_election_lang', $requested, [
            'expires' => time() + 31536000,
            'path' => '/',
            'samesite' => 'Lax',
        ]);
        $_COOKIE['school_election_lang'] = $requested;
    }

    $language = (string) ($_COOKIE['school_election_lang'] ?? setting('default_language', 'ru'));

    return in_array($language, ['ru', 'en'], true) ? $language : 'ru';
}

function t(string $key, array $replace = []): string
{
    static $dictionary = [
        'ru' => [
            'home' => 'Главная', 'results' => 'Результаты', 'archive' => 'Архив',
            'ballot' => 'Бюллетень', 'student_login' => 'Вход ученика', 'logout' => 'Выйти',
            'voting_open' => 'Голосование открыто', 'voting_closed' => 'Голосование закрыто',
            'vote' => 'Проголосовать', 'candidates' => 'Кандидаты', 'turnout' => 'Явка',
            'votes' => 'Голосов', 'students' => 'Учеников', 'view_results' => 'Посмотреть результаты',
            'study_programs' => 'Изучить программы', 'accessibility' => 'Доступность',
        ],
        'en' => [
            'home' => 'Home', 'results' => 'Results', 'archive' => 'Archive',
            'ballot' => 'Ballot', 'student_login' => 'Student login', 'logout' => 'Log out',
            'voting_open' => 'Voting is open', 'voting_closed' => 'Voting is closed',
            'vote' => 'Vote', 'candidates' => 'Candidates', 'turnout' => 'Turnout',
            'votes' => 'Votes', 'students' => 'Students', 'view_results' => 'View results',
            'study_programs' => 'View platforms', 'accessibility' => 'Accessibility',
        ],
    ];

    $text = $dictionary[current_language()][$key] ?? $dictionary['ru'][$key] ?? $key;

    foreach ($replace as $name => $value) {
        $text = str_replace(':' . $name, (string) $value, $text);
    }

    return $text;
}

function app_base_url(): string
{
    $configured = rtrim(setting('base_url', ''), '/');
    if ($configured !== '') {
        return $configured;
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/'));
    $directory = rtrim(dirname($script), '/.');
    if (str_ends_with($directory, '/admin')) {
        $directory = substr($directory, 0, -6);
    }

    return $scheme . '://' . $host . ($directory === '' ? '' : $directory);
}

function table_has_column(string $table, string $column): bool
{
    $rows = db()->query('PRAGMA table_info(' . $table . ')')->fetchAll();
    foreach ($rows as $row) {
        if (($row['name'] ?? '') === $column) {
            return true;
        }
    }

    return false;
}

function active_election_id(): int
{
    $pdo = db();
    $id = (int) setting('active_election_id', '0');

    if ($id > 0) {
        $statement = $pdo->prepare('SELECT 1 FROM elections WHERE id = ?');
        $statement->execute([$id]);
        if ($statement->fetchColumn()) {
            return $id;
        }
    }

    $fallback = (int) ($pdo->query(
        "SELECT id FROM elections ORDER BY CASE status WHEN 'open' THEN 0 WHEN 'scheduled' THEN 1 WHEN 'draft' THEN 2 WHEN 'closed' THEN 3 ELSE 4 END, id DESC LIMIT 1"
    )->fetchColumn() ?: 0);

    if ($fallback > 0) {
        $statement = $pdo->prepare(
            "INSERT INTO election_settings (key, value) VALUES ('active_election_id', ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value"
        );
        $statement->execute([(string) $fallback]);
    }

    return $fallback;
}

function election_by_id(int $electionId): ?array
{
    if ($electionId <= 0) {
        return null;
    }

    $statement = db()->prepare('SELECT * FROM elections WHERE id = ?');
    $statement->execute([$electionId]);
    $election = $statement->fetch();

    if (!$election) {
        return null;
    }

    sync_election_status($election);
    $statement->execute([$electionId]);

    return $statement->fetch() ?: null;
}

function active_election(): ?array
{
    return election_by_id(active_election_id());
}

function snapshot_election_eligibility(int $electionId, bool $replace = false): int
{
    $pdo = db();
    if ($replace) {
        $participation = $pdo->prepare('SELECT COUNT(*) FROM participation WHERE election_id = ?');
        $participation->execute([$electionId]);
        if ((int) $participation->fetchColumn() > 0) {
            throw new RuntimeException('Нельзя обновить список допущенных после начала голосования.');
        }
        $pdo->prepare('DELETE FROM election_eligibility WHERE election_id = ?')->execute([$electionId]);
    }

    $statement = $pdo->prepare(
        'INSERT OR IGNORE INTO election_eligibility
         (election_id, student_id, student_code, full_name, class_name)
         SELECT ?, id, student_code, full_name, class_name
         FROM students WHERE is_active = 1'
    );
    $statement->execute([$electionId]);

    $count = $pdo->prepare('SELECT COUNT(*) FROM election_eligibility WHERE election_id = ?');
    $count->execute([$electionId]);

    return (int) $count->fetchColumn();
}

function student_is_eligible(int $studentId, int $electionId): bool
{
    $statement = db()->prepare(
        'SELECT 1 FROM election_eligibility WHERE election_id = ? AND student_id = ?'
    );
    $statement->execute([$electionId, $studentId]);

    return (bool) $statement->fetchColumn();
}

function sync_election_status(array $election): void
{
    $id = (int) ($election['id'] ?? 0);
    $status = (string) ($election['status'] ?? 'draft');

    if ($id <= 0 || $status === 'archived') {
        return;
    }

    $now = time();
    $start = !empty($election['start_at']) ? strtotime((string) $election['start_at']) : false;
    $end = !empty($election['end_at']) ? strtotime((string) $election['end_at']) : false;
    $newStatus = $status;

    if ($end !== false && $end <= $now && in_array($status, ['scheduled', 'open'], true)) {
        $newStatus = 'closed';
    } elseif ($start !== false && $start <= $now && ($end === false || $end > $now)
        && in_array($status, ['draft', 'scheduled'], true)) {
        $newStatus = 'open';
    } elseif ($start !== false && $start > $now && $status === 'draft') {
        $newStatus = 'scheduled';
    }

    if ($newStatus !== $status) {
        if ($newStatus === 'open') {
            snapshot_election_eligibility($id);
        }
        $statement = db()->prepare(
            'UPDATE elections
             SET status = ?, locked_at = CASE WHEN ? = \'open\' THEN COALESCE(locked_at, CURRENT_TIMESTAMP) ELSE locked_at END,
                 closed_at = CASE WHEN ? = \'closed\' THEN COALESCE(closed_at, CURRENT_TIMESTAMP) ELSE closed_at END,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $statement->execute([$newStatus, $newStatus, $newStatus, $id]);
    }
}

function election_is_open(?array $election = null): bool
{
    $election ??= active_election();

    return $election !== null && ($election['status'] ?? '') === 'open';
}

function election_is_editable(array $election): bool
{
    return in_array((string) ($election['status'] ?? ''), ['draft', 'scheduled'], true);
}

function results_are_public(?array $election = null): bool
{
    $election ??= active_election();

    return $election !== null && (bool) ($election['results_public'] ?? false);
}

function election_status_label(string $status): string
{
    return [
        'draft' => 'Черновик', 'scheduled' => 'Запланированы', 'open' => 'Открыты',
        'closed' => 'Завершены', 'archived' => 'Архив',
    ][$status] ?? $status;
}

function format_datetime(?string $value, string $fallback = '—'): string
{
    if (!$value) {
        return $fallback;
    }

    $timestamp = strtotime($value);

    return $timestamp === false ? $fallback : date('d.m.Y H:i', $timestamp);
}

function current_student(): ?array
{
    $studentId = $_SESSION['student_id'] ?? null;
    $sessionToken = (string) ($_SESSION['student_session_token'] ?? '');

    if ((!is_int($studentId) && !ctype_digit((string) $studentId)) || $sessionToken === '') {
        return null;
    }

    $statement = db()->prepare(
        'SELECT id, student_code, full_name, class_name, is_active, session_token
         FROM students WHERE id = ?'
    );
    $statement->execute([(int) $studentId]);
    $student = $statement->fetch();

    if (!$student || !(bool) $student['is_active'] || !hash_equals((string) ($student['session_token'] ?? ''), $sessionToken)) {
        unset($_SESSION['student_id'], $_SESSION['student_session_token']);
        return null;
    }

    return $student;
}

function current_admin(): ?array
{
    $adminId = $_SESSION['admin_id'] ?? null;
    if (!is_int($adminId) && !ctype_digit((string) $adminId)) {
        return null;
    }

    $timeout = max(5, (int) setting('session_timeout_minutes', '30')) * 60;
    $lastActivity = (int) ($_SESSION['admin_last_activity'] ?? 0);
    if ($lastActivity > 0 && time() - $lastActivity > $timeout) {
        unset($_SESSION['admin_id'], $_SESSION['admin_last_activity']);
        flash('error', 'Сессия администратора завершена из-за бездействия.');
        return null;
    }

    $statement = db()->prepare('SELECT id, username, role, is_active FROM admins WHERE id = ?');
    $statement->execute([(int) $adminId]);
    $admin = $statement->fetch();

    if (!$admin || !(bool) $admin['is_active']) {
        unset($_SESSION['admin_id'], $_SESSION['admin_last_activity']);
        return null;
    }

    $_SESSION['admin_last_activity'] = time();

    return $admin;
}

function admin_permissions(): array
{
    return [
        'observer' => ['dashboard', 'reports', 'logs'],
        'manager' => ['dashboard', 'reports', 'logs', 'students', 'candidates', 'elections', 'exports'],
        'superadmin' => ['*'],
    ];
}

function admin_can(string $permission, ?array $admin = null): bool
{
    $admin ??= current_admin();
    if (!$admin) {
        return false;
    }

    $permissions = admin_permissions()[(string) $admin['role']] ?? [];

    return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
}

function require_student(): array
{
    $student = current_student();
    if (!$student) {
        flash('error', 'Сначала войдите как ученик.');
        redirect('login.php');
    }

    return $student;
}

function require_admin(?string $permission = null): array
{
    $admin = current_admin();
    if (!$admin) {
        flash('error', 'Войдите в административную панель.');
        redirect('login.php');
    }

    if ($permission !== null && !admin_can($permission, $admin)) {
        http_response_code(403);
        exit('Недостаточно прав для этого раздела.');
    }

    return $admin;
}

function logout_student_session(?int $studentId = null): void
{
    $studentId ??= isset($_SESSION['student_id']) ? (int) $_SESSION['student_id'] : 0;
    $token = (string) ($_SESSION['student_session_token'] ?? '');

    if ($studentId > 0 && $token !== '') {
        $statement = db()->prepare('UPDATE students SET session_token = NULL WHERE id = ? AND session_token = ?');
        $statement->execute([$studentId, $token]);
    }

    unset($_SESSION['student_id'], $_SESSION['student_session_token']);
    session_regenerate_id(true);
}

function student_participation(int $studentId, int $electionId): ?array
{
    $statement = db()->prepare(
        'SELECT id, voted_at FROM participation WHERE student_id = ? AND election_id = ?'
    );
    $statement->execute([$studentId, $electionId]);

    return $statement->fetch() ?: null;
}

function active_candidates(?int $electionId = null, bool $randomize = false): array
{
    $electionId ??= active_election_id();
    $statement = db()->prepare(
        'SELECT * FROM candidates
         WHERE election_id = ? AND is_active = 1
         ORDER BY CASE WHEN ballot_number > 0 THEN ballot_number ELSE 999999 END, id'
    );
    $statement->execute([$electionId]);
    $rows = $statement->fetchAll();

    if ($randomize && count($rows) > 1) {
        shuffle($rows);
    }

    return $rows;
}

function candidate_by_id(int $candidateId, ?int $electionId = null): ?array
{
    $sql = 'SELECT * FROM candidates WHERE id = ?';
    $params = [$candidateId];
    if ($electionId !== null) {
        $sql .= ' AND election_id = ?';
        $params[] = $electionId;
    }

    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetch() ?: null;
}

function candidate_initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }

    return $initials ?: 'К';
}

function candidate_resources(string $resourcesText): array
{
    $items = [];
    foreach (preg_split('/\R/u', $resourcesText) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        [$label, $url] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
        if ($url === '') {
            $url = $label;
            $label = 'Материал';
        }
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            $items[] = ['label' => $label ?: 'Материал', 'url' => $url];
        }
    }

    return $items;
}

function result_rows(?int $electionId = null, bool $onlyActive = false): array
{
    $electionId ??= active_election_id();
    $active = $onlyActive ? ' AND candidates.is_active = 1' : '';
    $statement = db()->prepare(
        "SELECT candidates.*, COUNT(votes.id) AS vote_count
         FROM candidates
         LEFT JOIN votes ON votes.candidate_id = candidates.id AND votes.election_id = candidates.election_id
         WHERE candidates.election_id = ? $active
         GROUP BY candidates.id
         ORDER BY vote_count DESC,
                  CASE WHEN candidates.ballot_number > 0 THEN candidates.ballot_number ELSE 999999 END,
                  candidates.full_name"
    );
    $statement->execute([$electionId]);

    return $statement->fetchAll();
}

function election_metrics(?int $electionId = null): array
{
    $electionId ??= active_election_id();
    $election = election_by_id($electionId);

    $statement = db()->prepare('SELECT COUNT(*) FROM election_eligibility WHERE election_id = ?');
    $statement->execute([$electionId]);
    $eligible = (int) $statement->fetchColumn();

    if ($eligible === 0 && $election && in_array($election['status'], ['draft', 'scheduled'], true)) {
        $eligible = (int) db()->query('SELECT COUNT(*) FROM students WHERE is_active = 1')->fetchColumn();
    }

    $statement = db()->prepare('SELECT COUNT(*) FROM participation WHERE election_id = ?');
    $statement->execute([$electionId]);
    $participants = (int) $statement->fetchColumn();

    $statement = db()->prepare('SELECT COUNT(*) FROM votes WHERE election_id = ?');
    $statement->execute([$electionId]);
    $votes = (int) $statement->fetchColumn();

    $statement = db()->prepare('SELECT COUNT(*) FROM candidates WHERE election_id = ? AND is_active = 1');
    $statement->execute([$electionId]);
    $candidates = (int) $statement->fetchColumn();

    return [
        'eligible' => $eligible,
        'participants' => $participants,
        'votes' => $votes,
        'candidates' => $candidates,
        'turnout' => $eligible > 0 ? round($participants / $eligible * 100, 1) : 0.0,
        'remaining' => max(0, $eligible - $participants),
    ];
}

function turnout_by_class(?int $electionId = null): array
{
    $electionId ??= active_election_id();
    $snapshot = db()->prepare('SELECT COUNT(*) FROM election_eligibility WHERE election_id = ?');
    $snapshot->execute([$electionId]);

    if ((int) $snapshot->fetchColumn() > 0) {
        $statement = db()->prepare(
            'SELECT election_eligibility.class_name,
                    COUNT(election_eligibility.id) AS eligible,
                    SUM(CASE WHEN participation.id IS NOT NULL THEN 1 ELSE 0 END) AS participated
             FROM election_eligibility
             LEFT JOIN participation
               ON participation.election_id = election_eligibility.election_id
              AND participation.student_code = election_eligibility.student_code
             WHERE election_eligibility.election_id = ?
             GROUP BY election_eligibility.class_name
             ORDER BY election_eligibility.class_name'
        );
        $statement->execute([$electionId]);
    } else {
        $statement = db()->prepare(
            'SELECT students.class_name,
                    COUNT(students.id) AS eligible,
                    SUM(CASE WHEN participation.id IS NOT NULL THEN 1 ELSE 0 END) AS participated
             FROM students
             LEFT JOIN participation
               ON participation.student_id = students.id AND participation.election_id = ?
             WHERE students.is_active = 1
             GROUP BY students.class_name
             ORDER BY students.class_name'
        );
        $statement->execute([$electionId]);
    }

    $rows = $statement->fetchAll();
    foreach ($rows as &$row) {
        $eligible = (int) $row['eligible'];
        $participated = (int) $row['participated'];
        $row['turnout'] = $eligible > 0 ? round($participated / $eligible * 100, 1) : 0;
    }

    return $rows;
}

function second_round_analysis(array $election, array $rows): array
{
    $total = array_sum(array_map(static fn(array $row): int => (int) $row['vote_count'], $rows));
    $threshold = (float) ($election['second_round_threshold'] ?? 50.0);
    $leaderPercent = ($total > 0 && isset($rows[0])) ? ((int) $rows[0]['vote_count'] / $total * 100) : 0;

    return [
        'enabled' => (bool) ($election['second_round_enabled'] ?? false),
        'required' => (bool) ($election['second_round_enabled'] ?? false)
            && ($election['status'] ?? '') === 'closed'
            && count($rows) > 1
            && $leaderPercent <= $threshold,
        'leader_percent' => round($leaderPercent, 1),
        'threshold' => $threshold,
        'finalists' => array_slice($rows, 0, 2),
    ];
}

function audit_log(string $action, string $entityType = '', ?int $entityId = null, array|string $details = []): void
{
    $admin = current_admin();
    $detailsText = is_array($details)
        ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : $details;

    $statement = db()->prepare(
        'INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, details, ip_address)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([
        $admin ? (int) $admin['id'] : null,
        $action,
        $entityType,
        $entityId,
        (string) $detailsText,
        client_ip(),
    ]);
}

function record_login_attempt(string $scope, string $identifier, bool $success): void
{
    $normalized = mb_strtolower($identifier);
    if ($success) {
        $cleanup = db()->prepare('DELETE FROM login_attempts WHERE scope = ? AND success = 0 AND (identifier = ? OR ip_address = ?)');
        $cleanup->execute([$scope, $normalized, client_ip()]);
    }

    $statement = db()->prepare(
        'INSERT INTO login_attempts (scope, identifier, ip_address, success) VALUES (?, ?, ?, ?)'
    );
    $statement->execute([$scope, $normalized, client_ip(), $success ? 1 : 0]);

    if (random_int(1, 20) === 1) {
        db()->exec("DELETE FROM login_attempts WHERE created_at < datetime('now', '-30 days')");
    }
}

function login_is_blocked(string $scope, string $identifier): bool
{
    $max = max(3, (int) setting('max_login_attempts', '5'));
    $minutes = max(1, (int) setting('lockout_minutes', '10'));
    $since = '-' . $minutes . ' minutes';

    $identifierStatement = db()->prepare(
        "SELECT COUNT(*) FROM login_attempts
         WHERE scope = ? AND success = 0 AND identifier = ?
           AND created_at >= datetime('now', ?)"
    );
    $identifierStatement->execute([$scope, mb_strtolower($identifier), $since]);

    $ipStatement = db()->prepare(
        "SELECT COUNT(*) FROM login_attempts
         WHERE scope = ? AND success = 0 AND ip_address = ?
           AND created_at >= datetime('now', ?)"
    );
    $ipStatement->execute([$scope, client_ip(), $since]);

    return (int) $identifierStatement->fetchColumn() >= $max
        || (int) $ipStatement->fetchColumn() >= ($max * 5);
}

function create_student_session(int $studentId): void
{
    $token = bin2hex(random_bytes(32));
    $statement = db()->prepare(
        'UPDATE students SET session_token = ?, failed_login_count = 0, locked_until = NULL WHERE id = ?'
    );
    $statement->execute([$token, $studentId]);

    session_regenerate_id(true);
    $_SESSION['student_id'] = $studentId;
    $_SESSION['student_session_token'] = $token;
}

function random_password(int $length = 8): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return $password;
}

function next_student_code(string $prefix = 'S'): string
{
    $number = (int) (db()->query('SELECT COALESCE(MAX(id), 0) + 1 FROM students')->fetchColumn() ?: 1);

    do {
        $code = strtoupper($prefix) . str_pad((string) $number, 4, '0', STR_PAD_LEFT);
        $statement = db()->prepare('SELECT 1 FROM students WHERE student_code = ?');
        $statement->execute([$code]);
        $number++;
    } while ($statement->fetchColumn());

    return $code;
}

function student_qr_signature(int $studentId, string $studentCode): string
{
    $secret = setting('qr_secret', '');
    if ($secret === '') {
        $secret = bin2hex(random_bytes(32));
        save_setting('qr_secret', $secret);
    }

    return hash_hmac('sha256', $studentId . '|' . $studentCode, $secret);
}

function verify_student_qr_signature(int $studentId, string $studentCode, string $signature): bool
{
    return $signature !== '' && hash_equals(student_qr_signature($studentId, $studentCode), $signature);
}

function safe_candidate_photo_upload(array $file, ?string $oldPath = null): ?string
{
    prepare_writable_directory(UPLOAD_ROOT, 'Папка загрузок');
    prepare_writable_directory(UPLOAD_ROOT . '/candidates', 'Папка фотографий кандидатов');
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return $oldPath;
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Не удалось загрузить фотографию кандидата.');
    }
    if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('Фотография не должна превышать 5 МБ.');
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    $info = @getimagesize($tmp);
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($allowed[$info[2]])) {
        throw new RuntimeException('Разрешены изображения JPG, PNG и WEBP.');
    }

    $name = bin2hex(random_bytes(16)) . '.' . $allowed[$info[2]];
    $target = UPLOAD_ROOT . '/candidates/' . $name;
    if (!move_uploaded_file($tmp, $target)) {
        throw new RuntimeException('PHP не смог сохранить фотографию.');
    }

    // Старое изображение намеренно не удаляется автоматически: одна фотография
    // может использоваться копиями кампаний. Очистку неиспользуемых файлов
    // безопаснее выполнять отдельно после архивации.
    return 'uploads/candidates/' . $name;
}


function safe_brand_logo_upload(array $file, ?string $oldPath = null): ?string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return $oldPath;
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Не удалось загрузить логотип.');
    }
    if ((int) ($file['size'] ?? 0) > 3 * 1024 * 1024) {
        throw new RuntimeException('Логотип не должен превышать 3 МБ.');
    }

    prepare_writable_directory(UPLOAD_ROOT, 'Папка загрузок');
    prepare_writable_directory(UPLOAD_ROOT . '/branding', 'Папка фирменного оформления');
    $tmp = (string) ($file['tmp_name'] ?? '');
    $info = @getimagesize($tmp);
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($allowed[$info[2]])) {
        throw new RuntimeException('Логотип должен быть JPG, PNG или WEBP.');
    }

    $name = 'logo-' . bin2hex(random_bytes(10)) . '.' . $allowed[$info[2]];
    $target = UPLOAD_ROOT . '/branding/' . $name;
    if (!move_uploaded_file($tmp, $target)) {
        throw new RuntimeException('PHP не смог сохранить логотип.');
    }

    if ($oldPath) {
        $oldFile = __DIR__ . '/' . ltrim($oldPath, '/');
        if (is_file($oldFile)) @unlink($oldFile);
    }

    return 'uploads/branding/' . $name;
}

function backup_database(string $prefix = 'manual'): string
{
    prepare_writable_directory(BACKUP_ROOT, 'Папка резервных копий');
    if (!file_exists(DB_PATH)) {
        throw new RuntimeException('Файл базы данных ещё не создан.');
    }

    $filename = $prefix . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sqlite';
    $target = BACKUP_ROOT . '/' . $filename;

    if (!copy(DB_PATH, $target)) {
        throw new RuntimeException('Не удалось создать резервную копию.');
    }
    @chmod($target, 0660);

    return $target;
}

function human_file_size(int $bytes): string
{
    $units = ['Б', 'КБ', 'МБ', 'ГБ'];
    $index = 0;
    $value = $bytes;
    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }

    return number_format($value, $index === 0 ? 0 : 1, ',', ' ') . ' ' . $units[$index];
}
