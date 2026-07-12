<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$student = require_student();
$election = active_election();
if (!$election) {
    flash('error', 'Активные выборы не найдены.');
    redirect('index.php');
}

$electionId = (int) $election['id'];
$participation = student_participation((int) $student['id'], $electionId);
$candidates = active_candidates($electionId, (bool) $election['candidates_randomized']);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $candidateId = filter_input(INPUT_POST, 'candidate_id', FILTER_VALIDATE_INT);

    if ($participation) {
        $error = 'Вы уже участвовали в этих выборах.';
    } elseif (!election_is_open($election)) {
        $error = 'Голосование сейчас закрыто.';
    } elseif (!student_is_eligible((int) $student['id'], $electionId)) {
        $error = 'Ваша учётная запись не включена в список допущенных к этим выборам. Обратитесь к организатору.';
    } elseif (!$candidateId) {
        $error = 'Выберите кандидата.';
    } else {
        try {
            $pdo = db();
            $pdo->beginTransaction();

            $statement = $pdo->prepare('SELECT id FROM candidates WHERE id = ? AND election_id = ? AND is_active = 1');
            $statement->execute([$candidateId, $electionId]);
            if (!$statement->fetchColumn()) throw new RuntimeException('Выбранный кандидат недоступен.');

            $current = election_by_id($electionId);
            if (!$current || !election_is_open($current)) throw new RuntimeException('Голосование уже закрыто.');
            if (!student_is_eligible((int) $student['id'], $electionId)) throw new RuntimeException('Ученик не допущен к этой кампании.');

            $statement = $pdo->prepare(
                'INSERT INTO participation
                 (election_id, student_id, student_code, full_name, class_name)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $electionId,
                (int) $student['id'],
                (string) $student['student_code'],
                (string) $student['full_name'],
                (string) $student['class_name'],
            ]);

            $statement = $pdo->prepare('INSERT INTO votes (election_id, candidate_id) VALUES (?, ?)');
            $statement->execute([$electionId, $candidateId]);

            $pdo->commit();
            logout_student_session((int) $student['id']);
            $_SESSION['vote_success'] = ['election_title' => $election['title'], 'at' => date('d.m.Y H:i')];
            redirect('thanks.php');
        } catch (PDOException $exception) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            $error = str_contains($exception->getMessage(), 'UNIQUE') ? 'Вы уже участвовали в этих выборах.' : 'Не удалось сохранить голос.';
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            $error = $exception->getMessage();
        }
    }
}

$pageTitle = 'Бюллетень';
$isAdminArea = false;
require __DIR__ . '/partials/header.php';
?>
<section class="section">
<div class="container">
    <div class="dashboard-heading">
        <div><span class="kicker">Электронный бюллетень</span><h1><?= e($student['full_name']) ?></h1><p class="lead">Класс: <?= e($student['class_name']) ?> · Код: <?= e($student['student_code']) ?></p></div>
        <span class="status-pill <?= election_is_open($election) ? 'status-open' : 'status-closed' ?>"><?= e(election_status_label($election['status'])) ?></span>
    </div>
    <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($participation): ?>
        <div class="success-panel"><div class="success-icon">✓</div><div><h2>Вы уже проголосовали</h2><p>Факт участия зарегистрирован <?= e(format_datetime($participation['voted_at'])) ?>. Ваш выбор анонимен.</p></div></div>
    <?php elseif (!election_is_open($election)): ?>
        <div class="empty-state">Голосование закрыто. Период: <?= e(format_datetime($election['start_at'])) ?> — <?= e(format_datetime($election['end_at'])) ?>.</div>
    <?php else: ?>
        <form method="post" id="vote-form">
            <?= csrf_field() ?>
            <div class="ballot-grid">
                <?php foreach ($candidates as $candidate): ?>
                <label class="ballot-card">
                    <input type="radio" name="candidate_id" value="<?= (int) $candidate['id'] ?>" required>
                    <span class="ballot-select">Выбрать</span>
                    <?php if ($candidate['photo_path']): ?><img class="candidate-photo" src="<?= e($candidate['photo_path']) ?>" alt=""><?php else: ?><span class="candidate-avatar" style="--candidate-color: <?= e($candidate['color']) ?>"><?= e(candidate_initials($candidate['full_name'])) ?></span><?php endif; ?>
                    <span class="candidate-class">№<?= (int) $candidate['ballot_number'] ?> · <?= e($candidate['class_name']) ?></span>
                    <strong class="ballot-name"><?= e($candidate['full_name']) ?></strong>
                    <span class="candidate-slogan"><?= e($candidate['slogan']) ?></span>
                    <ul class="program-list"><?php foreach (array_filter(preg_split('/\R/u', $candidate['program_text']) ?: []) as $item): ?><li><?= e(trim($item)) ?></li><?php endforeach; ?></ul>
                    <a class="candidate-more" href="candidate.php?id=<?= (int) $candidate['id'] ?>" target="_blank">Полная программа</a>
                </label>
                <?php endforeach; ?>
            </div>
            <div class="vote-submit-panel"><div><strong>Проверьте выбор</strong><p>После подтверждения изменить голос нельзя. Кабинет будет автоматически закрыт.</p></div><button class="button primary" type="submit" data-confirm="Подтвердить выбранного кандидата? Изменить голос будет невозможно.">Подтвердить голос</button></div>
        </form>
    <?php endif; ?>
</div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
