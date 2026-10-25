<?php
require_once __DIR__ . '/../includes/app.php';

$user = require_login();
$groupId = (int) ($_GET['id'] ?? $_POST['group_id'] ?? 0);
$group = require_member($groupId, $user['id']);

// Só o organizador altera as configurações.
if ((int) $group['owner_id'] !== (int) $user['id']) {
    redirect('group.php?id=' . $groupId);
}

$leagues = db()->query('SELECT id, name FROM leagues ORDER BY name')->fetchAll();
$validLeagueIds = array_map('intval', array_column($leagues, 'id'));
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $name = trim($_POST['name'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $removePassword = isset($_POST['remove_password']);
    $exact = (int) ($_POST['exact_points'] ?? 5);
    $winner = (int) ($_POST['winner_points'] ?? 3);
    $diff = (int) ($_POST['goal_diff_points'] ?? 1);
    $selected = array_values(array_intersect(
        array_map('intval', (array) ($_POST['leagues'] ?? [])),
        $validLeagueIds
    ));

    if ($name === '' || mb_strlen($name) > 100) {
        $errors[] = 'Informe um nome de até 100 caracteres.';
    }
    if (!$selected) {
        $errors[] = 'Escolha pelo menos uma liga.';
    }
    foreach ([$exact, $winner, $diff] as $points) {
        if ($points < 0 || $points > 20) {
            $errors[] = 'Os pontos devem ficar entre 0 e 20.';
            break;
        }
    }
    if ($password !== '' && strlen($password) < 4) {
        $errors[] = 'A nova senha deve ter pelo menos 4 caracteres.';
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();

        // Senha: nova senha substitui; "remover" limpa; em branco mantém a atual.
        $passwordHash = $group['password_hash'];
        if ($removePassword) {
            $passwordHash = null;
        } elseif ($password !== '') {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        }

        $pdo->prepare(
            'UPDATE bolao_groups
             SET name = ?, password_hash = ?, exact_points = ?, winner_points = ?, goal_diff_points = ?
             WHERE id = ?'
        )->execute([$name, $passwordHash, $exact, $winner, $diff, $groupId]);

        $pdo->prepare('DELETE FROM group_leagues WHERE group_id = ?')->execute([$groupId]);
        $insertLeague = $pdo->prepare('INSERT INTO group_leagues (group_id, league_id) VALUES (?, ?)');
        foreach ($selected as $leagueId) {
            $insertLeague->execute([$groupId, $leagueId]);
        }

        $pdo->commit();

        // Pontos podem ter mudado: recalcula os palpites deste grupo.
        recalculate_group($groupId);

        redirect('group.php?id=' . $groupId . '&saved=1');
    }

    $group['name'] = $name;
    $group['exact_points'] = $exact;
    $group['winner_points'] = $winner;
    $group['goal_diff_points'] = $diff;
}

$stmt = db()->prepare('SELECT league_id FROM group_leagues WHERE group_id = ?');
$stmt->execute([$groupId]);
$currentLeagues = array_map('intval', array_column($stmt->fetchAll(), 'league_id'));
if (isset($selected)) {
    $currentLeagues = $selected;
}

page_start('Configurar bolão', $user);
?>
<h1>Configurar bolão</h1>
<p class="muted-left">Somente o organizador vê esta página. Ao alterar pontos ou ligas, os resultados já lançados são recalculados.</p>

<?php alert_box($errors); ?>

<form method="post" action="group_settings.php">
    <?= csrf_field() ?>
    <input type="hidden" name="group_id" value="<?= $groupId ?>">

    <label>Nome do bolão
        <input type="text" name="name" value="<?= e($group['name']) ?>" maxlength="100" required>
    </label>

    <label>Nova senha (deixe em branco para manter a atual)
        <input type="password" name="password">
    </label>
    <?php if ($group['password_hash'] !== null): ?>
        <label class="check">
            <input type="checkbox" name="remove_password"> Remover a senha do bolão
        </label>
    <?php endif; ?>

    <fieldset>
        <legend>Ligas</legend>
        <?php foreach ($leagues as $league): ?>
            <label class="check">
                <input type="checkbox" name="leagues[]" value="<?= (int) $league['id'] ?>"
                    <?= in_array((int) $league['id'], $currentLeagues, true) ? 'checked' : '' ?>>
                <?= e($league['name']) ?>
            </label>
        <?php endforeach; ?>
    </fieldset>

    <fieldset>
        <legend>Pontuação</legend>
        <div class="grid3">
            <label>Placar exato
                <input type="number" name="exact_points" min="0" max="20" value="<?= (int) $group['exact_points'] ?>">
            </label>
            <label>Vencedor ou empate
                <input type="number" name="winner_points" min="0" max="20" value="<?= (int) $group['winner_points'] ?>">
            </label>
            <label>Saldo de gols
                <input type="number" name="goal_diff_points" min="0" max="20" value="<?= (int) $group['goal_diff_points'] ?>">
            </label>
        </div>
    </fieldset>

    <button type="submit" class="btn full">Salvar configurações</button>
</form>

<p><a href="group.php?id=<?= $groupId ?>">&larr; Voltar ao bolão</a></p>
<?php
page_end();
