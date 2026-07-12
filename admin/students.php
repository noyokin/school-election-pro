<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/functions.php';
require_once dirname(__DIR__) . '/student_import.php';

$admin = require_admin('students');
$activeElectionId = active_election_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'add' || $action === 'update') {
            $id = (int) ($_POST['id'] ?? 0);
            $code = strtoupper(trim((string) ($_POST['student_code'] ?? '')));
            $name = trim((string) ($_POST['full_name'] ?? ''));
            $className = trim((string) ($_POST['class_name'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');

            if ($code === '' || $name === '' || $className === '') {
                throw new RuntimeException('Заполните код, ФИО и класс.');
            }

            if ($action === 'add') {
                if (mb_strlen($password) < 4) {
                    throw new RuntimeException('Пароль должен содержать минимум 4 символа.');
                }
                $statement = db()->prepare(
                    'INSERT INTO students (student_code, full_name, class_name, password_hash) VALUES (?, ?, ?, ?)'
                );
                $statement->execute([$code, $name, $className, student_password_hash($password)]);
                $id = (int) db()->lastInsertId();
                audit_log('student_created', 'student', $id, ['code' => $code]);
                flash('success', 'Ученик добавлен.');
            } else {
                $sql = 'UPDATE students SET student_code = ?, full_name = ?, class_name = ?, updated_at = CURRENT_TIMESTAMP';
                $parameters = [$code, $name, $className];
                if ($password !== '') {
                    if (mb_strlen($password) < 4) {
                        throw new RuntimeException('Новый пароль слишком короткий.');
                    }
                    $sql .= ', password_hash = ?';
                    $parameters[] = student_password_hash($password);
                }
                $sql .= ' WHERE id = ?';
                $parameters[] = $id;
                db()->prepare($sql)->execute($parameters);
                audit_log('student_updated', 'student', $id, ['code' => $code]);
                flash('success', 'Данные ученика обновлены.');
            }
        } elseif ($action === 'generate') {
            $code = next_student_code(trim((string) ($_POST['prefix'] ?? 'S')) ?: 'S');
            $password = random_password(8);
            $name = trim((string) ($_POST['full_name'] ?? ''));
            $className = trim((string) ($_POST['class_name'] ?? ''));

            if ($name === '' || $className === '') {
                throw new RuntimeException('Укажите ФИО и класс.');
            }

            $statement = db()->prepare(
                'INSERT INTO students (student_code, full_name, class_name, password_hash) VALUES (?, ?, ?, ?)'
            );
            $statement->execute([$code, $name, $className, student_password_hash($password)]);
            $_SESSION['generated_credential'] = ['code' => $code, 'password' => $password, 'name' => $name];
            audit_log('student_generated', 'student', (int) db()->lastInsertId());
            flash('success', 'Учётная запись и случайный пароль созданы.');
        } elseif ($action === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);
            db()->prepare(
                'UPDATE students SET is_active = CASE is_active WHEN 1 THEN 0 ELSE 1 END,
                 session_token = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
            )->execute([$id]);
            audit_log('student_toggled', 'student', $id);
            flash('success', 'Статус изменён.');
        } elseif ($action === 'reset_password') {
            $id = (int) ($_POST['id'] ?? 0);
            $password = (string) ($_POST['new_password'] ?? '');
            if ($password === 'auto') {
                $password = random_password(8);
            }
            if (mb_strlen($password) < 4) {
                throw new RuntimeException('Пароль должен содержать минимум 4 символа.');
            }
            db()->prepare(
                'UPDATE students SET password_hash = ?, session_token = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
            )->execute([student_password_hash($password), $id]);
            $_SESSION['generated_credential'] = ['code' => '', 'password' => $password, 'name' => 'Новый пароль'];
            audit_log('student_password_reset', 'student', $id);
            flash('success', 'Пароль изменён.');
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $pdo = db();
            $pdo->beginTransaction();

            $snapshot = $pdo->prepare(
                "UPDATE participation
                 SET student_code = COALESCE(NULLIF(student_code, ''), (SELECT student_code FROM students WHERE id = ?), 'DELETED-' || id),
                     full_name = COALESCE(NULLIF(full_name, ''), (SELECT full_name FROM students WHERE id = ?), 'Удалённый ученик'),
                     class_name = COALESCE(NULLIF(class_name, ''), (SELECT class_name FROM students WHERE id = ?), 'Не указан')
                 WHERE student_id = ?"
            );
            $snapshot->execute([$id, $id, $id, $id]);
            $pdo->prepare('UPDATE participation SET student_id = NULL WHERE student_id = ?')->execute([$id]);
            $pdo->prepare('UPDATE election_eligibility SET student_id = NULL WHERE student_id = ?')->execute([$id]);
            $delete = $pdo->prepare('DELETE FROM students WHERE id = ?');
            $delete->execute([$id]);

            if ($delete->rowCount() !== 1) {
                throw new RuntimeException('Ученик не найден или уже удалён.');
            }

            $pdo->commit();
            audit_log('student_deleted_with_history', 'student', $id);
            flash('success', 'Ученик удалён. История явки и анонимные голоса сохранены.');
        } elseif ($action === 'delete_all_safe') {
            if (($admin['role'] ?? '') !== 'superadmin') {
                throw new RuntimeException('Массовое удаление доступно только главному администратору.');
            }

            $pdo = db();
            $total = (int) $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();
            if ($total === 0) {
                throw new RuntimeException('База учеников уже пуста.');
            }

            $backupPath = backup_database('before-delete-all-students');
            $pdo->beginTransaction();
            $pdo->exec(
                "UPDATE participation
                 SET student_code = COALESCE(NULLIF(student_code, ''), (SELECT student_code FROM students WHERE students.id = participation.student_id), 'DELETED-' || id),
                     full_name = COALESCE(NULLIF(full_name, ''), (SELECT full_name FROM students WHERE students.id = participation.student_id), 'Удалённый ученик'),
                     class_name = COALESCE(NULLIF(class_name, ''), (SELECT class_name FROM students WHERE students.id = participation.student_id), 'Не указан')
                 WHERE student_id IS NOT NULL"
            );
            $pdo->exec('UPDATE participation SET student_id = NULL WHERE student_id IS NOT NULL');
            $pdo->exec('UPDATE election_eligibility SET student_id = NULL WHERE student_id IS NOT NULL');
            $pdo->exec('DELETE FROM students');
            $pdo->commit();

            audit_log('students_bulk_deleted_with_history', 'students', null, [
                'deleted' => $total,
                'backup' => basename($backupPath),
            ]);
            flash('success', "Удалены все ученики: $total. Историческая явка и анонимные голоса сохранены. Резервная копия создана автоматически.");
        } elseif ($action === 'upload_preview') {
            $oldToken = (string) ($_SESSION['student_import_job_token'] ?? '');
            if ($oldToken !== '') {
                student_import_delete_job($oldToken);
            }

            $records = parse_student_import_file($_FILES['students_file'] ?? []);
            $job = student_import_create_job(db(), $records);
            $_SESSION['student_import_job_token'] = $job['token'];
            audit_log('student_import_preview', 'students', null, [
                'rows' => (int) ($job['total'] ?? count($job['records'] ?? [])),
                'valid' => $job['valid'],
                'invalid' => $job['invalid'],
            ]);
            flash('success', 'Таблица проверена. Импорт будет выполняться небольшими пакетами и не заблокирует страницу.');
        } elseif ($action === 'start_import') {
            $token = (string) ($_SESSION['student_import_job_token'] ?? '');
            if ($token === '') {
                throw new RuntimeException('Задание импорта не найдено. Загрузите таблицу повторно.');
            }
            student_import_start_job($token, isset($_POST['update_existing']));
            redirect('students.php?import=running');
        } elseif ($action === 'cancel_import') {
            $token = (string) ($_SESSION['student_import_job_token'] ?? '');
            if ($token !== '') {
                student_import_delete_job($token);
            }
            unset($_SESSION['student_import_job_token']);
            flash('success', 'Импорт отменён.');
        }
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', database_error_message($exception));
    }

    redirect('students.php');
}

$q = trim((string) ($_GET['q'] ?? ''));
$classFilter = trim((string) ($_GET['class'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$voted = (string) ($_GET['voted'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = (int) ($_GET['per_page'] ?? 25);
if (!in_array($perPage, [25, 50], true)) {
    $perPage = 25;
}

$where = ' WHERE 1 = 1';
$filterParameters = [];
if ($q !== '') {
    $where .= ' AND (students.full_name LIKE ? OR students.student_code LIKE ?)';
    $filterParameters[] = '%' . $q . '%';
    $filterParameters[] = '%' . $q . '%';
}
if ($classFilter !== '') {
    $where .= ' AND students.class_name = ?';
    $filterParameters[] = $classFilter;
}
if ($status === 'active') {
    $where .= ' AND students.is_active = 1';
} elseif ($status === 'disabled') {
    $where .= ' AND students.is_active = 0';
}
if ($voted === 'yes') {
    $where .= ' AND EXISTS (SELECT 1 FROM participation pv WHERE pv.student_id = students.id AND pv.election_id = ?)';
    $filterParameters[] = $activeElectionId;
} elseif ($voted === 'no') {
    $where .= ' AND NOT EXISTS (SELECT 1 FROM participation pv WHERE pv.student_id = students.id AND pv.election_id = ?)';
    $filterParameters[] = $activeElectionId;
}

$offset = ($page - 1) * $perPage;
$sql = 'SELECT students.*,
        EXISTS(SELECT 1 FROM participation p WHERE p.student_id = students.id AND p.election_id = ?) AS participated,
        (SELECT p2.voted_at FROM participation p2 WHERE p2.student_id = students.id AND p2.election_id = ? LIMIT 1) AS voted_at,
        EXISTS(SELECT 1 FROM election_eligibility ee WHERE ee.student_id = students.id AND ee.election_id = ?) AS eligible_for_election
        FROM students'
    . $where
    . ' ORDER BY students.class_name, students.full_name, students.id'
    . ' LIMIT ' . ($perPage + 1) . ' OFFSET ' . $offset;
$statement = db()->prepare($sql);
$statement->execute(array_merge([$activeElectionId, $activeElectionId, $activeElectionId], $filterParameters));
$students = $statement->fetchAll();
$hasNextPage = count($students) > $perPage;
if ($hasNextPage) {
    array_pop($students);
}
$shownCount = count($students);
$shownFrom = $shownCount > 0 ? $offset + 1 : 0;
$shownTo = $offset + $shownCount;

// The class list is a small indexed query and is intentionally independent
// from the heavy participation filters.
$classes = db()->query('SELECT DISTINCT class_name FROM students ORDER BY class_name')->fetchAll(PDO::FETCH_COLUMN);

$editId = (int) ($_GET['edit'] ?? 0);
$edit = null;
if ($editId > 0) {
    $statement = db()->prepare('SELECT * FROM students WHERE id = ?');
    $statement->execute([$editId]);
    $edit = $statement->fetch() ?: null;
}

$credential = $_SESSION['generated_credential'] ?? null;
unset($_SESSION['generated_credential']);

$importToken = (string) ($_SESSION['student_import_job_token'] ?? '');
$importJob = null;
if ($importToken !== '') {
    try {
        $importJob = student_import_load_job($importToken);
    } catch (Throwable) {
        unset($_SESSION['student_import_job_token']);
        $importToken = '';
    }
}

$pageUrl = static function (int $targetPage) use ($q, $classFilter, $status, $voted, $perPage): string {
    return 'students.php?' . http_build_query([
        'q' => $q,
        'class' => $classFilter,
        'status' => $status,
        'voted' => $voted,
        'per_page' => $perPage,
        'page' => $targetPage,
    ]);
};

$pageTitle = 'Ученики';
$isAdminArea = true;
require dirname(__DIR__) . '/partials/header.php';
?>
<section class="section">
    <div class="container">
        <div class="dashboard-heading">
            <div>
                <span class="kicker">Участники</span>
                <h1>База учеников</h1>
                <p class="lead">Пакетный импорт, редактирование, фильтры и контроль участия без раскрытия выбора.</p>
            </div>
            <div class="actions">
                <a class="button secondary" href="cards.php">Печать карточек и QR</a>
                <a class="button secondary" href="export.php?type=students_csv">Экспорт CSV</a>
                <a class="button secondary" href="export.php?type=students_xlsx">Экспорт Excel</a>
            </div>
        </div>

        <?php if ($credential): ?>
            <div class="flash flash-warning">
                <strong><?= e($credential['name']) ?></strong> · код: <code><?= e($credential['code']) ?></code>
                · пароль: <code><?= e($credential['password']) ?></code>. Сохраните пароль сейчас — позже он не отображается.
            </div>
        <?php endif; ?>

        <div class="admin-grid admin-grid-equal">
            <section class="panel">
                <div class="panel-heading">
                    <div>
                        <span class="kicker"><?= $edit ? 'Редактирование' : 'Новая запись' ?></span>
                        <h2><?= $edit ? e($edit['full_name']) : 'Добавить ученика' ?></h2>
                    </div>
                    <?php if ($edit): ?><a href="students.php">Отмена</a><?php endif; ?>
                </div>

                <form method="post" class="form-grid">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?= $edit ? 'update' : 'add' ?>">
                    <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
                    <label>Код<input name="student_code" value="<?= e($edit['student_code'] ?? '') ?>" required></label>
                    <label>ФИО<input name="full_name" value="<?= e($edit['full_name'] ?? '') ?>" required></label>
                    <label>Класс<input name="class_name" value="<?= e($edit['class_name'] ?? '') ?>" required></label>
                    <label><?= $edit ? 'Новый пароль (необязательно)' : 'Пароль' ?><input name="password" type="text" <?= $edit ? '' : 'required' ?>></label>
                    <button class="button primary">Сохранить</button>
                </form>

                <hr class="separator">

                <form method="post" class="form-grid">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="generate">
                    <span class="kicker">Автогенерация</span>
                    <label>ФИО<input name="full_name" required></label>
                    <label>Класс<input name="class_name" required></label>
                    <label>Префикс кода<input name="prefix" value="S"></label>
                    <button class="button secondary">Создать код и случайный пароль</button>
                </form>
            </section>

            <section class="panel">
                <div class="panel-heading">
                    <div><span class="kicker">Excel / CSV</span><h2>Загрузить таблицу</h2></div>
                    <a href="../templates/students-template.xlsx">Скачать шаблон</a>
                </div>
                <form method="post" enctype="multipart/form-data" class="form-grid">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="upload_preview">
                    <label>Файл XLSX или CSV<input type="file" name="students_file" accept=".xlsx,.csv" required></label>
                    <button class="button primary">Проверить таблицу</button>
                </form>
                <p class="muted">После проверки данные импортируются пакетами по 12 строк. Страница показывает прогресс и не ждёт обработки всего файла одним запросом.</p>
            </section>
        </div>

        <?php if ($importJob && ($importJob['status'] ?? '') === 'preview'): ?>
            <section class="panel import-preview">
                <div class="panel-heading">
                    <div>
                        <span class="kicker">Предпросмотр</span>
                        <h2>Проверка импорта</h2>
                        <p>Всего строк: <?= (int) ($importJob['total'] ?? count($importJob['records'] ?? [])) ?> · корректных: <?= (int) $importJob['valid'] ?> · с ошибками: <?= (int) $importJob['invalid'] ?></p>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Строка</th><th>Код</th><th>ФИО</th><th>Класс</th><th>Состояние</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($importJob['preview_records'] ?? $importJob['records'] ?? [], 0, 100) as $record): ?>
                            <tr class="<?= empty($record['errors']) ? '' : 'row-error' ?>">
                                <td><?= (int) $record['source_row'] ?></td>
                                <td><?= e($record['student_code']) ?></td>
                                <td><?= e($record['full_name']) ?></td>
                                <td><?= e($record['class_name']) ?></td>
                                <td>
                                    <?php if (!empty($record['errors'])): ?>
                                        <?= e(implode(', ', $record['errors'])) ?>
                                    <?php elseif (!empty($record['exists'])): ?>
                                        Код уже существует
                                    <?php else: ?>
                                        Готово
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ((int) ($importJob['total'] ?? count($importJob['records'] ?? [])) > 100): ?>
                    <p class="muted">Показаны первые 100 строк. Остальные строки также будут обработаны.</p>
                <?php endif; ?>
                <div class="actions">
                    <a class="button secondary" href="export.php?type=import_errors">Скачать ошибки CSV</a>
                    <form method="post" class="inline-import-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="start_import">
                        <label class="check-row"><input type="checkbox" name="update_existing"><span>Обновлять существующие коды</span></label>
                        <button class="button primary">Начать пакетный импорт</button>
                    </form>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="cancel_import">
                        <button class="button secondary">Отменить</button>
                    </form>
                </div>
            </section>
        <?php elseif ($importJob && ($importJob['status'] ?? '') === 'running'): ?>
            <?php $cursor = (int) ($importJob['cursor'] ?? 0); $jobTotal = (int) ($importJob['total'] ?? count($importJob['records'] ?? [])); $initialPercent = $jobTotal ? round($cursor / $jobTotal * 100, 1) : 100; ?>
            <section class="panel import-runner" data-import-runner data-token="<?= e($importToken) ?>" data-csrf="<?= e(csrf_token()) ?>">
                <div class="panel-heading">
                    <div><span class="kicker">Пакетный импорт</span><h2>Добавление учеников</h2></div>
                    <strong data-import-percent><?= $initialPercent ?>%</strong>
                </div>
                <div class="progress import-progress"><span data-import-progress style="width: <?= $initialPercent ?>%"></span></div>
                <p data-import-status>Обработано <?= $cursor ?> из <?= $jobTotal ?> строк. Не закрывайте вкладку до завершения.</p>
                <div class="import-counters">
                    <span>Добавлено: <strong data-import-added><?= (int) ($importJob['result']['added'] ?? 0) ?></strong></span>
                    <span>Обновлено: <strong data-import-updated><?= (int) ($importJob['result']['updated'] ?? 0) ?></strong></span>
                    <span>Пропущено: <strong data-import-skipped><?= (int) ($importJob['result']['skipped'] ?? 0) ?></strong></span>
                </div>
            </section>
        <?php endif; ?>

        <section class="panel table-panel">
            <div class="panel-heading">
                <div>
                    <span class="kicker">Список</span>
                    <h2>Ученики</h2>
                    <p>Показано <?= $shownFrom ?>–<?= $shownTo ?> · страница <?= $page ?>. Общий подсчёт отключён для ускорения.</p>
                </div>
                <form method="get" class="filter-form">
                    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Имя или код">
                    <select name="class"><option value="">Все классы</option><?php foreach ($classes as $class): ?><option value="<?= e($class) ?>" <?= $classFilter === $class ? 'selected' : '' ?>><?= e($class) ?></option><?php endforeach; ?></select>
                    <select name="status"><option value="">Любой статус</option><option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Активные</option><option value="disabled" <?= $status === 'disabled' ? 'selected' : '' ?>>Отключённые</option></select>
                    <select name="voted"><option value="">Любое участие</option><option value="yes" <?= $voted === 'yes' ? 'selected' : '' ?>>Проголосовали</option><option value="no" <?= $voted === 'no' ? 'selected' : '' ?>>Не голосовали</option></select>
                    <select name="per_page"><option value="25" <?= $perPage === 25 ? 'selected' : '' ?>>25</option><option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50</option></select>
                    <button class="button secondary small">Фильтр</button>
                </form>
            </div>

            <div class="table-wrap">
                <table>
                    <thead><tr><th>Код</th><th>Ученик</th><th>Класс</th><th>Доступ</th><th>Допуск / активные выборы</th><th>Действия</th></tr></thead>
                    <tbody>
                    <?php foreach ($students as $student): ?>
                        <tr>
                            <td><code><?= e($student['student_code']) ?></code></td>
                            <td><?= e($student['full_name']) ?></td>
                            <td><?= e($student['class_name']) ?></td>
                            <td><span class="mini-status <?= $student['is_active'] ? 'mini-active' : 'mini-disabled' ?>"><?= $student['is_active'] ? 'Активен' : 'Отключён' ?></span></td>
                            <td>
                                <?php if ($student['participated']): ?>
                                    <strong>Участвовал</strong><small class="block"><?= e(format_datetime($student['voted_at'])) ?></small>
                                <?php elseif ($student['eligible_for_election']): ?>
                                    <span class="mini-status mini-active">Допущен</span><small class="block">Не голосовал</small>
                                <?php else: ?>
                                    <span class="mini-status mini-disabled">Не включён</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="row-actions">
                                    <a class="link-button" href="?edit=<?= (int) $student['id'] ?>">Изменить</a>
                                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $student['id'] ?>"><button class="link-button"><?= $student['is_active'] ? 'Отключить' : 'Включить' ?></button></form>
                                    <details class="inline-details"><summary>Пароль</summary><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="<?= (int) $student['id'] ?>"><input name="new_password" placeholder="Новый или auto" required><button class="link-button">Сохранить</button></form></details>
                                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $student['id'] ?>"><button class="link-button danger" data-confirm="Удалить ученика?">Удалить</button></form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$students): ?><tr><td colspan="6"><div class="empty-state">Ничего не найдено.</div></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($page > 1 || $hasNextPage): ?>
                <nav class="pagination" aria-label="Страницы учеников">
                    <?php if ($page > 1): ?><a href="<?= e($pageUrl($page - 1)) ?>">← Назад</a><?php endif; ?>
                    <span class="active"><?= $page ?></span>
                    <?php if ($hasNextPage): ?><a href="<?= e($pageUrl($page + 1)) ?>">Вперёд →</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>

        <?php if (($admin['role'] ?? '') === 'superadmin'): ?>
            <section class="panel danger-panel">
                <div class="panel-heading">
                    <div>
                        <span class="kicker">Массовое удаление</span>
                        <h2>Удалить учеников одной кнопкой</h2>
                        <p>Удаляются все учётные записи, включая участников закрытых выборов. Анонимные голоса, историческая явка и снимки списков сохраняются. Перед операцией создаётся резервная копия.</p>
                    </div>
                </div>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_all_safe">
                    <button class="button danger-button" data-confirm="Удалить абсолютно всех учеников? Вход станет невозможен, но история выборов и анонимные голоса сохранятся. Перед операцией будет создана резервная копия.">Удалить всех учеников</button>
                </form>
            </section>
        <?php endif; ?>
    </div>
</section>
<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
