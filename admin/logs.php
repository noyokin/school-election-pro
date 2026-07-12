<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/functions.php';

require_admin('logs');
$action = trim((string) ($_GET['action'] ?? ''));
$adminId = (int) ($_GET['admin_id'] ?? 0);
$params = [];
$sql = 'SELECT audit_logs.*, admins.username
        FROM audit_logs
        LEFT JOIN admins ON admins.id = audit_logs.admin_id
        WHERE 1 = 1';

if ($action !== '') {
    $sql .= ' AND audit_logs.action LIKE ?';
    $params[] = '%' . $action . '%';
}
if ($adminId > 0) {
    $sql .= ' AND audit_logs.admin_id = ?';
    $params[] = $adminId;
}
$sql .= ' ORDER BY audit_logs.id DESC LIMIT 500';

$statement = db()->prepare($sql);
$statement->execute($params);
$logs = $statement->fetchAll();
$attempts = db()->query('SELECT * FROM login_attempts ORDER BY id DESC LIMIT 120')->fetchAll();
$admins = db()->query('SELECT id, username FROM admins ORDER BY username')->fetchAll();

$pageTitle = 'Журнал';
$isAdminArea = true;
require dirname(__DIR__) . '/partials/header.php';
?>
<section class="section">
    <div class="container">
        <div class="dashboard-heading">
            <div>
                <span class="kicker">Аудит</span>
                <h1>Журнал действий</h1>
                <p class="lead">Изменения кампаний, пользователей, импорт, экспорт, входы и операции с базой.</p>
            </div>
        </div>

        <section class="panel">
            <form method="get" class="filter-form">
                <input name="action" value="<?= e($action) ?>" placeholder="Действие">
                <select name="admin_id">
                    <option value="0">Все администраторы</option>
                    <?php foreach ($admins as $item): ?>
                        <option value="<?= (int) $item['id'] ?>" <?= $adminId === (int) $item['id'] ? 'selected' : '' ?>>
                            <?= e($item['username']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="button secondary">Фильтр</button>
            </form>

            <div class="table-wrap">
                <table>
                    <thead><tr><th>Время</th><th>Администратор</th><th>Действие</th><th>Объект</th><th>Детали</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?= e(format_datetime($log['created_at'])) ?></td>
                            <td><?= e($log['username'] ?? 'Система') ?></td>
                            <td><code><?= e($log['action']) ?></code></td>
                            <td><?= e($log['entity_type']) ?> <?= e($log['entity_id']) ?></td>
                            <td class="details-cell"><?= e($log['details']) ?></td>
                            <td><?= e($log['ip_address']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel table-panel">
            <div class="panel-heading">
                <div><span class="kicker">Безопасность</span><h2>Последние попытки входа</h2></div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Время</th><th>Тип</th><th>Идентификатор</th><th>IP</th><th>Результат</th></tr></thead>
                    <tbody>
                    <?php foreach ($attempts as $attempt): ?>
                        <tr>
                            <td><?= e(format_datetime($attempt['created_at'])) ?></td>
                            <td><?= e($attempt['scope']) ?></td>
                            <td><code><?= e($attempt['identifier']) ?></code></td>
                            <td><?= e($attempt['ip_address']) ?></td>
                            <td><span class="mini-status <?= $attempt['success'] ? 'mini-active' : 'mini-disabled' ?>"><?= $attempt['success'] ? 'Успешно' : 'Ошибка' ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</section>
<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
