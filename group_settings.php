<?php
// Ações do organizador na aba Configurar. Cada bloco tem o seu formulário.
require_once __DIR__ . '/includes/app.php';

$user = require_login();
$groupId = (int) ($_GET['id'] ?? $_POST['group_id'] ?? 0);
$group = require_member($groupId, (int) $user['id']);

if ((int) $group['owner_id'] !== (int) $user['id']) {
    flash_set('alert', 'Só o organizador pode alterar as configurações deste bolão.');
    redirect('group.php?id=' . $groupId . '&tab=regras');
}

$back = 'group.php?id=' . $groupId . '&tab=configurar';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($back);
}
verify_csrf();

/** Volta para o bloco com os erros e o que foi digitado. */
function config_fail(string $block, array $fieldErrors, array $old, string $back): void
{
    flash_set('config', [
        'block' => $block,
        'errors' => array_values($fieldErrors),
        'field_errors' => $fieldErrors,
        'old' => $old,
        'ok' => null,
    ]);
    redirect($back . '#bloco-' . $block);
}

function config_ok(string $block, string $text, string $back): void
{
    flash_set('config', ['block' => $block, 'ok' => $text, 'errors' => [], 'field_errors' => [], 'old' => []]);
    redirect($back . '#bloco-' . $block);
}

$pdo = db();
$action = $_POST['action'] ?? '';

if ($action === 'name') {
    $name = trim($_POST['name'] ?? '');
    if ($name === '' || mb_strlen($name) > 100) {
        config_fail('nome', ['name' => 'O nome precisa ter entre 1 e 100 letras.'], ['name' => $name], $back);
    }
    $pdo->prepare('UPDATE bolao_groups SET name = ? WHERE id = ?')->execute([$name, $groupId]);
    config_ok('nome', 'Alterações salvas.', $back);
}

if ($action === 'password') {
    $password = (string) ($_POST['password'] ?? '');
    if (strlen($password) < 4 || strlen($password) > 200) {
        config_fail('entrada', ['password' => 'A nova senha precisa ter pelo menos 4 caracteres.'], [], $back);
    }
    $pdo->prepare('UPDATE bolao_groups SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), $groupId]);
    config_ok('entrada', 'Alterações salvas.', $back);
}

if ($action === 'remove_password') {
    $pdo->prepare('UPDATE bolao_groups SET password_hash = NULL WHERE id = ?')->execute([$groupId]);
    config_ok('entrada', 'Senha removida. Quem tiver o link entra direto.', $back);
}

if ($action === 'leagues') {
    $leagueIds = array_map('intval', array_column($pdo->query('SELECT id FROM leagues')->fetchAll(), 'id'));
    $selected = array_values(array_intersect(array_map('intval', (array) ($_POST['leagues'] ?? [])), $leagueIds));
    if (!$selected) {
        config_fail('ligas', ['leagues' => 'Marque pelo menos uma liga.'], ['leagues' => []], $back);
    }

    $scored = group_scored_counts($groupId);
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM group_leagues WHERE group_id = ?')->execute([$groupId]);
    $insert = $pdo->prepare('INSERT INTO group_leagues (group_id, league_id) VALUES (?, ?)');
    foreach ($selected as $leagueId) {
        $insert->execute([$groupId, $leagueId]);
    }
    $pdo->commit();
    recalculate_group($groupId);

    $text = $scored['preds'] > 0
        ? 'Alterações salvas. Pontos de ' . plural($scored['preds'], 'palpite', 'palpites') . ' recalculados.'
        : 'Alterações salvas.';
    config_ok('ligas', $text, $back);
}

if ($action === 'points') {
    $values = [
        'exact' => trim((string) ($_POST['exact_points'] ?? '')),
        'winner' => trim((string) ($_POST['winner_points'] ?? '')),
        'diff' => trim((string) ($_POST['goal_diff_points'] ?? '')),
    ];
    $errors = [];
    foreach ($values as $key => $raw) {
        if (!ctype_digit($raw) || (int) $raw > 20) {
            $errors[$key] = 'Os pontos devem ser um número entre 0 e 20.';
        }
    }
    if ($errors) {
        config_fail('pontos', $errors, $values, $back);
    }

    $pdo->prepare(
        'UPDATE bolao_groups SET exact_points = ?, winner_points = ?, goal_diff_points = ? WHERE id = ?'
    )->execute([(int) $values['exact'], (int) $values['winner'], (int) $values['diff'], $groupId]);

    $scored = group_scored_counts($groupId);
    recalculate_group($groupId);
    $text = $scored['preds'] > 0
        ? 'Alterações salvas. Pontos de ' . plural($scored['preds'], 'palpite', 'palpites') . ' recalculados.'
        : 'Alterações salvas.';
    config_ok('pontos', $text, $back);
}

if ($action === 'new_code') {
    // O link antigo deixa de funcionar. Quem já está no bolão continua nele.
    $pdo->prepare('UPDATE bolao_groups SET invite_code = ? WHERE id = ?')
        ->execute([bin2hex(random_bytes(5)), $groupId]);
    config_ok('convite', 'Novo código gerado. O link antigo não funciona mais.', $back);
}

if ($action === 'transfer') {
    $toId = (int) ($_POST['new_owner_id'] ?? 0);
    $typed = trim((string) ($_POST['confirm_name'] ?? ''));
    $old = ['new_owner_id' => (string) $toId];
    if ($toId <= 0 || $toId === (int) $user['id']) {
        config_fail('transferir', ['new_owner_id' => 'Escolha quem vai organizar o bolão.'], $old, $back);
    }
    if ($typed !== $group['name']) {
        config_fail('transferir', ['confirm_name' => 'Para transferir, digite o nome exato do bolão.'], $old, $back);
    }
    $stmt = $pdo->prepare('SELECT u.name FROM group_members gm JOIN users u ON u.id = gm.user_id WHERE gm.group_id = ? AND gm.user_id = ?');
    $stmt->execute([$groupId, $toId]);
    $newName = $stmt->fetchColumn();
    if ($newName === false || !transfer_group_ownership($groupId, (int) $user['id'], $toId)) {
        config_fail('transferir', ['new_owner_id' => 'Essa pessoa não está mais no bolão. Escolha outra.'], $old, $back);
    }
    flash_set('notice', $newName . ' agora organiza o bolão. Você continua como participante.');
    redirect('group.php?id=' . $groupId . '&tab=participantes');
}

if ($action === 'delete') {
    $typed = trim($_POST['confirm_name'] ?? '');
    if ($typed !== $group['name']) {
        config_fail('perigo', ['confirm_name' => 'Para excluir, digite o nome exato do bolão.'], [], $back);
    }
    // Apaga membros, ligas e palpites junto (ON DELETE CASCADE).
    $pdo->prepare('DELETE FROM bolao_groups WHERE id = ?')->execute([$groupId]);
    flash_set('notice', 'Bolão excluído.');
    redirect('index.php');
}

redirect($back);
