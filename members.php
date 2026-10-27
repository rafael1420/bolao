<?php
// Remoção de participante (organizador). A lista fica na aba Participantes.
require_once __DIR__ . '/includes/app.php';

$user = require_login();
$groupId = (int) ($_GET['id'] ?? $_POST['group_id'] ?? 0);
$group = require_member($groupId, (int) $user['id']);

if ((int) $group['owner_id'] !== (int) $user['id']) {
    flash_set('alert', 'Só o organizador pode remover participantes.');
    redirect('group.php?id=' . $groupId . '&tab=regras');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (($_POST['action'] ?? '') === 'remove') {
        $removeId = (int) ($_POST['user_id'] ?? 0);
        // O organizador nunca é removido.
        if ($removeId !== (int) $group['owner_id']) {
            $stmt = db()->prepare(
                'SELECT u.name FROM group_members gm JOIN users u ON u.id = gm.user_id
                 WHERE gm.group_id = ? AND gm.user_id = ?'
            );
            $stmt->execute([$groupId, $removeId]);
            $name = $stmt->fetchColumn();

            if ($name !== false) {
                db()->prepare('DELETE FROM group_members WHERE group_id = ? AND user_id = ?')
                    ->execute([$groupId, $removeId]);
                flash_set('notice', $name . ' saiu do bolão.');
            }
        }
    }
}

redirect('group.php?id=' . $groupId . '&tab=participantes');
