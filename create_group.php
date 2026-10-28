<?php
require_once __DIR__ . '/includes/app.php';

$user = require_login();
$leagues = db()->query('SELECT id, name FROM leagues ORDER BY name')->fetchAll();
$validLeagueIds = array_map('intval', array_column($leagues, 'id'));

$errors = [];   // campo => mensagem
$summary = [];
$form = [
    'name' => '',
    'leagues' => count($leagues) === 1 ? [(int) $leagues[0]['id']] : [],
    'exact' => 5,
    'winner' => 3,
    'diff' => 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $name = trim($_POST['name'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $selected = array_values(array_intersect(
        array_map('intval', (array) ($_POST['leagues'] ?? [])),
        $validLeagueIds
    ));
    $form = [
        'name' => $name,
        'leagues' => $selected,
        'exact' => (int) ($_POST['exact_points'] ?? 5),
        'winner' => (int) ($_POST['winner_points'] ?? 3),
        'diff' => (int) ($_POST['goal_diff_points'] ?? 1),
    ];

    if ($name === '' || mb_strlen($name) > 100) {
        $errors['name'] = 'O nome precisa ter entre 1 e 100 letras.';
    }
    if (!$selected) {
        $errors['leagues'] = 'Marque pelo menos uma liga para o bolão.';
    }
    foreach (['exact_points' => $form['exact'], 'winner_points' => $form['winner'], 'goal_diff_points' => $form['diff']] as $field => $points) {
        if ($points < 0 || $points > 20) {
            $errors['points'] = 'Os pontos devem ficar entre 0 e 20.';
            break;
        }
    }
    if ($password !== '' && strlen($password) < 4) {
        $errors['password'] = 'A senha do bolão precisa ter pelo menos 4 caracteres, ou fique em branco.';
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'INSERT INTO bolao_groups
                (name, owner_id, invite_code, password_hash, exact_points, winner_points, goal_diff_points)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $name,
            $user['id'],
            bin2hex(random_bytes(5)),
            $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null,
            $form['exact'],
            $form['winner'],
            $form['diff'],
        ]);
        $groupId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, ?)')
            ->execute([$groupId, $user['id'], 'owner']);

        $insertLeague = $pdo->prepare('INSERT INTO group_leagues (group_id, league_id) VALUES (?, ?)');
        foreach ($selected as $leagueId) {
            $insertLeague->execute([$groupId, $leagueId]);
        }

        $pdo->commit();
        flash_set('welcome', true);
        redirect('group.php?id=' . $groupId);
    }
    $summary = array_values($errors);
}

$exampleText = points_example([
    'exact_points' => $form['exact'],
    'winner_points' => $form['winner'],
    'goal_diff_points' => $form['diff'],
]);

page_start('Novo bolão', $user, 'wrap', 'inicio');
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Meus bolões</p>
        <h1 class="h-page">Novo bolão</h1>
        <p class="lede">Você vira o organizador e pode mudar as regras depois.</p>
    </div>
</div>

<?php alert_box($summary); ?>

<form method="post" action="create_group.php" class="panel stack-lg create-form">
    <?= csrf_field() ?>
    <h2 class="form-step"><span class="step-num" aria-hidden="true">1</span>Nome e entrada</h2>

    <?php field('name', 'Nome do bolão', ['value' => $form['name'], 'maxlength' => 100, 'required' => true,
        'error' => $errors['name'] ?? null, 'autofocus' => isset($errors['name'])]); ?>

    <?php field('password', 'Senha para entrar (opcional)', ['type' => 'password', 'autocomplete' => 'new-password',
        'help' => 'Sem senha, quem tiver o link entra direto.', 'error' => $errors['password'] ?? null,
        'autofocus' => isset($errors['password'])]); ?>

    <fieldset class="fieldset<?= isset($errors['leagues']) ? ' is-invalid' : '' ?>">
        <legend class="form-step"><span class="step-num" aria-hidden="true">2</span>Ligas</legend>
        <?php if (isset($errors['leagues'])): ?>
            <p class="field-error"><?= e($errors['leagues']) ?></p>
        <?php endif; ?>
        <?php foreach ($leagues as $league): ?>
            <?php check_option('leagues[]', (string) $league['id'], $league['name'],
                in_array((int) $league['id'], $form['leagues'], true), 'liga-' . $league['id']); ?>
        <?php endforeach; ?>
    </fieldset>

    <fieldset class="fieldset<?= isset($errors['points']) ? ' is-invalid' : '' ?>">
        <legend class="form-step"><span class="step-num" aria-hidden="true">3</span>Pontuação</legend>
        <div class="grid3">
            <?php field('exact_points', 'Placar exato', ['type' => 'number', 'min' => 0, 'max' => 20, 'inputmode' => 'numeric',
                'value' => $form['exact'], 'id' => 'p-exact', 'error' => null]); ?>
            <?php field('winner_points', 'Vencedor ou empate', ['type' => 'number', 'min' => 0, 'max' => 20, 'inputmode' => 'numeric',
                'value' => $form['winner'], 'id' => 'p-winner', 'error' => null]); ?>
            <?php field('goal_diff_points', 'Saldo de gols', ['type' => 'number', 'min' => 0, 'max' => 20, 'inputmode' => 'numeric',
                'value' => $form['diff'], 'id' => 'p-diff', 'error' => null]); ?>
        </div>
        <?php if (isset($errors['points'])): ?>
            <p class="field-error"><?= e($errors['points']) ?></p>
        <?php endif; ?>
        <p class="help example" data-points-example data-fmt="create" aria-live="polite"><?= e($exampleText) ?></p>
    </fieldset>

    <button type="submit" class="btn primary wide" data-busy="Criando…">Criar bolão</button>
</form>
<?php
page_end();
