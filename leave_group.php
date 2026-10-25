<?php
require_once __DIR__ . '/includes/app.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}
verify_csrf();

$groupId = (int) ($_POST['group_id'] ?? 0);
$group = require_member($groupId, $user['id']);

// O organizador não pode sair do próprio bolão.
if ((int) $group['owner_id'] === (int) $user['id']) {
    redirect('group.php?id=' . $groupId . '&error=owner');
}

db()->prepare('DELETE FROM group_members WHERE group_id = ? AND user_id = ?')
    ->execute([$groupId, $user['id']]);

redirect('index.php');
