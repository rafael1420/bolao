<?php
require_once __DIR__ . '/includes/app.php';

$user = require_login();
$leagues = db()->query('SELECT id, name FROM leagues ORDER BY name')->fetchAll();
$validLeagueIds = array_map('intval', array_column($leagues, 'id'));

$errors = [];
$form = [
    'name' => '',
    'leagues' => [],
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
        $errors[] = 'Informe um nome de até 100 caracteres.';
    }
    if (!$selected) {
        $errors[] = 'Escolha pelo menos uma liga.';
    }
    foreach ([$form['exact'], $form['winner'], $form['diff']] as $points) {
        if ($points < 0 || $points > 20) {
            $errors[] = 'Os pontos devem ficar entre 0 e 20.';
            break;
        }
    }
    if ($password !== '' && strlen($password) < 4) {
        $errors[] = 'A senha do grupo deve ter pelo menos 4 caracteres (ou deixe em branco).';
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
        redirect('group.php?id=' . $groupId);
    }
}

page_start('Criar bolão', $user);
?>
<h1>Criar bolão</h1>

<?php alert_box($errors); ?>

<form method="post" action="create_group.php">
    <?= csrf_field() ?>

    <label>Nome do bolão
        <input type="text" name="name" value="<?= e($form['name']) ?>" maxlength="100" required>
    </label>

    <label>Senha para entrar (opcional)
        <input type="password" name="password" placeholder="Deixe em branco para entrar só com o link">
    </label>

    <fieldset>
        <legend>Ligas</legend>
        <?php foreach ($leagues as $league): ?>
            <label class="check">
                <input type="checkbox" name="leagues[]" value="<?= (int) $league['id'] ?>"
                    <?= in_array((int) $league['id'], $form['leagues'], true) ? 'checked' : '' ?>>
                <?= e($league['name']) ?>
            </label>
        <?php endforeach; ?>
    </fieldset>

    <fieldset>
        <legend>Pontuação</legend>
        <div class="grid3">
            <label>Placar exato
                <input type="number" name="exact_points" min="0" max="20" value="<?= (int) $form['exact'] ?>">
            </label>
            <label>Vencedor ou empate
                <input type="number" name="winner_points" min="0" max="20" value="<?= (int) $form['winner'] ?>">
            </label>
            <label>Saldo de gols
                <input type="number" name="goal_diff_points" min="0" max="20" value="<?= (int) $form['diff'] ?>">
            </label>
        </div>
    </fieldset>

    <button type="submit" class="btn full">Criar bolão</button>
</form>
<?php
page_end();
