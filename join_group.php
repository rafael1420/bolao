<?php
require_once __DIR__ . '/includes/app.php';

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$rawCode = $isPost ? ($_POST['code'] ?? '') : ($_GET['code'] ?? '');
// Códigos são hexadecimais: ignora maiúsculas e espaços colados pela pessoa.
$code = substr(strtolower((string) preg_replace('/\s+/', '', (string) $rawCode)), 0, 20);

$user = current_user();
if ($user === null) {
    // Sem login, guarda o convite e volta para ele depois de entrar ou criar conta.
    redirect($code !== '' ? 'login.php?next=' . urlencode('join_group.php?code=' . $code) : 'login.php');
}

$error = null;
$passwordError = null;
$group = null;

if ($isPost) {
    verify_csrf();
}

if ($code === '') {
    if ($isPost) {
        $error = 'Informe o código do convite.';
    }
} else {
    $stmt = db()->prepare('SELECT * FROM bolao_groups WHERE invite_code = ?');
    $stmt->execute([$code]);
    $group = $stmt->fetch() ?: null;

    if (!$group) {
        $error = 'Esse convite não existe mais. Peça um novo link para quem organiza.';
    } else {
        $check = db()->prepare('SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?');
        $check->execute([$group['id'], $user['id']]);
        if ($check->fetch()) {
            // Já é membro: entra direto, sem tela intermediária.
            redirect('group.php?id=' . (int) $group['id']);
        }
    }
}

if ($isPost && $group) {
    $password = (string) ($_POST['password'] ?? '');
    if ($group['password_hash'] !== null && !password_verify($password, $group['password_hash'])) {
        $passwordError = 'Senha incorreta. Confira com quem organiza.';
    } else {
        db()->prepare('INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, ?)')
            ->execute([$group['id'], $user['id'], 'member']);
        flash_set('notice', 'Você entrou. Dê seu primeiro palpite.');
        redirect('group.php?id=' . (int) $group['id']);
    }
}

page_start('Entrar em bolão', $user, 'card', 'inicio');

if ($group):
    // Prévia do bolão antes de entrar.
    $stmt = db()->prepare('SELECT name FROM users WHERE id = ?');
    $stmt->execute([$group['owner_id']]);
    $ownerName = (string) $stmt->fetchColumn();

    $stmt = db()->prepare('SELECT COUNT(*) FROM group_members WHERE group_id = ?');
    $stmt->execute([$group['id']]);
    $members = (int) $stmt->fetchColumn();

    $stmt = db()->prepare('SELECT l.name FROM group_leagues gl JOIN leagues l ON l.id = gl.league_id WHERE gl.group_id = ? ORDER BY l.name');
    $stmt->execute([$group['id']]);
    $leagueNames = array_column($stmt->fetchAll(), 'name');
    ?>
    <section class="panel stack invite-preview">
        <p class="kicker-text">Você foi convidado para</p>
        <h1 class="h-page"><?= e($group['name']) ?></h1>
        <p class="meta"><?= e(implode(', ', $leagueNames)) ?></p>
        <p class="meta"><?= e(plural($members, 'participante', 'participantes')) ?>, organizado por <?= e($ownerName) ?></p>
        <?php if ($group['password_hash'] !== null): ?>
            <p class="meta">Este bolão pede senha. Peça ao organizador.</p>
        <?php endif; ?>
    </section>

    <?php alert_box($error ? [$error] : []); ?>

    <form method="post" action="join_group.php" class="panel auth-form">
        <?= csrf_field() ?>
        <input type="hidden" name="code" value="<?= e($code) ?>">
        <?php if ($group['password_hash'] !== null): ?>
            <?php field('password', 'Senha do bolão', ['type' => 'password', 'autocomplete' => 'off', 'required' => true,
                'error' => $passwordError, 'autofocus' => true]); ?>
        <?php endif; ?>
        <button type="submit" class="btn primary wide" data-busy="Entrando…">Entrar no bolão</button>
    </form>
    <?php
else:
    ?>
    <h1 class="h-page">Entrar em bolão</h1>
    <p class="lede">Use o código ou o link que o organizador enviou.</p>

    <?php alert_box($error ? [$error] : []); ?>

    <form method="get" action="join_group.php" class="panel auth-form">
        <?php field('code', 'Código de convite', ['value' => $code, 'maxlength' => 20, 'autocomplete' => 'off', 'required' => true,
            'help' => 'Maiúsculas e espaços não importam.', 'autofocus' => true]); ?>
        <button type="submit" class="btn primary wide">Continuar</button>
    </form>
    <?php
endif;

page_end();
