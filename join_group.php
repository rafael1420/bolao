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
    // Senha do bolão: mesmo limite do login (5 erros em 15 min por bolão + IP).
    $throttleKey = 'bolao:' . (int) $group['id'];
    $wait = $group['password_hash'] !== null ? login_lock_seconds($throttleKey, client_ip()) : 0;
    if ($wait > 0) {
        $passwordError = 'Muitas tentativas erradas. Espere ' . wait_text($wait) . ' e tente de novo.';
    } elseif ($group['password_hash'] !== null && !password_verify($password, $group['password_hash'])) {
        login_record_failure($throttleKey, client_ip());
        $passwordError = 'Senha incorreta. Confira com quem organiza.';
    } else {
        // IGNORE: dois cliques seguidos não geram erro de chave duplicada.
        db()->prepare('INSERT IGNORE INTO group_members (group_id, user_id, role) VALUES (?, ?, ?)')
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
    <section class="panel invite-preview">
        <?= avatar($group['name'], 'avatar-group avatar-lg') ?>
        <p class="kicker-text">Você foi convidado para</p>
        <h1 class="h-page"><?= e($group['name']) ?></h1>
        <p class="meta"><?= e(implode(', ', $leagueNames)) ?></p>
        <p class="invite-facts">
            <span class="pill is-muted"><?= icon('users') ?><?= e(plural($members, 'participante', 'participantes')) ?></span>
            <span class="pill is-muted"><?= icon('user') ?>Organizado por <?= e($ownerName) ?></span>
            <?php if ($group['password_hash'] !== null): ?>
                <span class="pill is-pending"><?= icon('lock') ?>Pede senha. Peça ao organizador.</span>
            <?php endif; ?>
        </p>
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
    <div class="join-head">
        <span class="fail-icon"><?= icon('ticket') ?></span>
        <h1 class="h-page">Entrar em bolão</h1>
        <p class="lede">Use o código ou o link que o organizador enviou.</p>
    </div>

    <?php alert_box($error ? [$error] : []); ?>

    <form method="get" action="join_group.php" class="panel auth-form">
        <div class="code-input">
            <?php field('code', 'Código de convite', ['value' => $code, 'maxlength' => 20, 'autocomplete' => 'off', 'required' => true,
                'help' => 'Maiúsculas e espaços não importam.', 'autofocus' => true, 'placeholder' => 'a1b2c3d4e5']); ?>
        </div>
        <button type="submit" class="btn primary wide">Continuar</button>
    </form>
    <p class="muted center auth-switch">Quer organizar? <a href="create_group.php">Crie um bolão</a></p>
    <?php
endif;

page_end();
