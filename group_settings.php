<?php
require_once __DIR__ . '/includes/app.php';

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
$action = $_POST['action'] ?? 'settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if ($action === 'new_code') {
        // O link antigo deixa de funcionar. Quem já está no bolão continua nele.
        db()->prepare('UPDATE bolao_groups SET invite_code = ? WHERE id = ?')
            ->execute([bin2hex(random_bytes(5)), $groupId]);
        redirect('group_settings.php?id=' . $groupId . '&saved=code');
    }

    if ($action === 'delete') {
        $typed = trim($_POST['confirm_name'] ?? '');
        if ($typed !== $group['name']) {
            $errors[] = 'Para excluir, digite o nome exato do bolão.';
        } else {
            // Apaga membros, ligas e palpites junto (ON DELETE CASCADE).
            db()->prepare('DELETE FROM bolao_groups WHERE id = ?')->execute([$groupId]);
            redirect('index.php');
        }
    }

    if ($action === 'settings') {
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
            $errors[] = 'A nova senha precisa ter pelo menos 4 caracteres.';
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

            redirect('group_settings.php?id=' . $groupId . '&saved=settings');
        }

        // Mantém o que foi digitado quando há erro.
        $group['name'] = $name;
        $group['exact_points'] = $exact;
        $group['winner_points'] = $winner;
        $group['goal_diff_points'] = $diff;
    }
}

$stmt = db()->prepare('SELECT league_id FROM group_leagues WHERE group_id = ?');
$stmt->execute([$groupId]);
$currentLeagues = array_map('intval', array_column($stmt->fetchAll(), 'league_id'));
if (isset($selected)) {
    $currentLeagues = $selected;
}

$scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
$inviteUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . BASE_URL . 'join_group.php?code=' . $group['invite_code'];

$saved = $_GET['saved'] ?? '';
$savedText = [
    'settings' => 'Configurações salvas.',
    'code' => 'Novo código gerado. O link antigo não funciona mais.',
][$saved] ?? null;

page_start('Configurar bolão', $user, 'wrap');
?>
<div class="page-head">
    <div>
        <h1>Configurar bolão</h1>
        <p class="muted-left"><?= e($group['name']) ?></p>
    </div>
    <div class="actions">
        <a class="btn secondary" href="group.php?id=<?= $groupId ?>">Voltar ao bolão</a>
        <a class="btn secondary" href="members.php?id=<?= $groupId ?>">Membros</a>
    </div>
</div>

<?php if ($savedText): ?>
    <?php notice($savedText); ?>
<?php endif; ?>

<?php alert_box($errors); ?>

<section class="panel">
    <h2>Dados e regras</h2>
    <p class="muted-left">Ao mudar pontos ou ligas, os resultados já lançados são recalculados.</p>

    <form method="post" action="group_settings.php?id=<?= $groupId ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="settings">
        <input type="hidden" name="group_id" value="<?= $groupId ?>">

        <label>Nome do bolão
            <input type="text" name="name" value="<?= e($group['name']) ?>" maxlength="100" required>
        </label>

        <fieldset>
            <legend>Senha de entrada</legend>
            <p class="muted-left small-text"><?= $group['password_hash'] !== null ? 'Este bolão tem senha.' : 'Este bolão não tem senha.' ?> Preencha abaixo só para trocar.</p>
            <label>Nova senha
                <input type="password" name="password" autocomplete="new-password">
            </label>
            <?php if ($group['password_hash'] !== null): ?>
                <label class="check">
                    <input type="checkbox" name="remove_password"> Remover a senha
                </label>
            <?php endif; ?>
        </fieldset>

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

        <button type="submit" class="full">Salvar alterações</button>
    </form>
</section>

<section class="panel">
    <h2>Convite</h2>
    <div class="setting-row">
        <div>
            <p class="muted-left small-text">Código atual</p>
            <p class="code"><?= e($group['invite_code']) ?></p>
        </div>
        <form method="post" action="group_settings.php?id=<?= $groupId ?>" class="inline-form"
              onsubmit="return confirm('Gerar um novo código? O link atual deixa de funcionar.');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="new_code">
            <input type="hidden" name="group_id" value="<?= $groupId ?>">
            <button type="submit" class="btn secondary">Gerar novo código</button>
        </form>
    </div>
    <p class="muted-left small-text">Link para os amigos: <?= e($inviteUrl) ?></p>
</section>

<section class="panel danger">
    <h2>Excluir bolão</h2>
    <p>Apaga o bolão, os participantes e todos os palpites. Não dá para desfazer.</p>
    <form method="post" action="group_settings.php?id=<?= $groupId ?>"
          onsubmit="return confirm('Excluir este bolão para sempre?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="group_id" value="<?= $groupId ?>">
        <label>Para confirmar, digite o nome do bolão
            <input type="text" name="confirm_name" autocomplete="off" required>
        </label>
        <button type="submit" class="btn danger-btn">Excluir bolão</button>
    </form>
</section>
<?php
page_end();
