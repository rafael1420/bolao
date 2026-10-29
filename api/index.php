<?php
// API JSON do app React. Uma rota por ?r=nome (funciona em qualquer hospedagem, sem regra de reescrita).
// GET só lê; POST muda algo e exige o cabeçalho X-CSRF-Token (o token vem em ?r=session).
define('BOLAO_API', true);
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/group_views.php';
require_once __DIR__ . '/../includes/api.php';

$route = (string) ($_GET['r'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    api_csrf();
}
$pdo = db();

switch ($method . ' ' . $route) {

/* ---------- Sessão e conta ---------- */

case 'GET session':
    api_out(['user' => api_user_out(current_user()), 'csrf' => csrf_token(), 'siteName' => SITE_NAME]);

case 'POST auth/login':
    $email = strtolower(api_str('email'));
    $password = api_raw('password');
    $ip = client_ip();
    $wait = login_lock_seconds($email, $ip);
    $locked = 'Muitas tentativas sem acertar a senha. Por segurança, espere ' . wait_text($wait) . ' e tente de novo, ou use "Esqueci minha senha".';
    if ($wait > 0) {
        api_fail($locked, 429);
    }
    $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if ($row && password_verify($password, $row['password_hash'])) {
        $hash = $row['password_hash'];
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $row['id']]);
        }
        login_clear_failures($email, $ip);
        login_user((int) $row['id'], $hash);
        api_out(['user' => api_user_out(current_user()), 'csrf' => csrf_token()]);
    }
    login_record_failure($email, $ip);
    $wait = login_lock_seconds($email, $ip);
    api_fail($wait > 0 ? 'Muitas tentativas sem acertar a senha. Por segurança, espere ' . wait_text($wait) . ' e tente de novo, ou use "Esqueci minha senha".' : 'E-mail ou senha incorretos.', $wait > 0 ? 429 : 400);

case 'POST auth/register':
    $name = api_str('name');
    $email = strtolower(api_str('email'));
    $password = api_raw('password');
    $errors = [];
    if ($name === '' || mb_strlen($name) > 100) {
        $errors['name'] = 'O nome precisa ter entre 1 e 100 letras.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
        $errors['email'] = 'Informe um e-mail válido, como nome@exemplo.com.';
    }
    if (strlen($password) < 8) {
        $errors['password'] = 'A senha precisa ter pelo menos 8 caracteres.';
    } elseif (strlen($password) > 200) {
        $errors['password'] = 'A senha pode ter no máximo 200 caracteres.';
    }
    if (!$errors) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors['email'] = 'Já existe uma conta com este e-mail. Entre com ele ou use outro.';
        }
    }
    if ($errors) {
        api_fail(reset($errors), 422, $errors);
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)')->execute([$name, $email, $hash]);
    login_user((int) $pdo->lastInsertId(), $hash);
    api_out(['user' => api_user_out(current_user()), 'csrf' => csrf_token()]);

case 'POST auth/logout':
    logout_user();
    session_start();
    api_out(['user' => null, 'csrf' => csrf_token()]);

case 'POST auth/forgot':
    $email = strtolower(api_str('email'));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
        api_fail('Informe um e-mail válido, como nome@exemplo.com.', 422, ['email' => 'Informe um e-mail válido.']);
    }
    $ip = client_ip();
    if (!reset_ip_allowed($ip)) {
        api_fail('Muitos pedidos de link a partir desta conexão. Espere uma hora e tente de novo.', 429);
    }
    $stmt = $pdo->prepare('SELECT id, name, email FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $account = $stmt->fetch() ?: null;
    // Resposta igual exista ou não a conta.
    if ($account && reset_user_allowed((int) $account['id'])) {
        $token = create_password_reset((int) $account['id'], 'user');
        if (!send_password_reset_email($account['email'], $account['name'], password_reset_url($token))) {
            error_log('[bolao] Falha ao enviar o e-mail de redefinição para a conta ' . (int) $account['id']);
        }
    }
    api_out(['ok' => true]);

case 'GET auth/reset':
    $reset = find_password_reset(strtolower(trim((string) ($_GET['token'] ?? ''))));
    if (!$reset) {
        api_fail('Este link de redefinição não funciona mais.', 404);
    }
    api_out(['email' => $reset['email']]);

case 'POST auth/reset':
    $token = strtolower(api_str('token'));
    $reset = find_password_reset($token);
    if (!$reset) {
        api_fail('Este link de redefinição não funciona mais. Peça um novo.', 404);
    }
    $password = api_raw('password');
    if (strlen($password) < 8 || strlen($password) > 200) {
        api_fail('A senha precisa ter entre 8 e 200 caracteres.', 422, ['password' => 'Mínimo de 8 caracteres.']);
    }
    if (consume_password_reset($token, $password) === null) {
        api_fail('Este link de redefinição não funciona mais. Peça um novo.', 404);
    }
    $pdo->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$reset['email']]);
    if (current_user()) {
        logout_user();
        session_start();
    }
    api_out(['ok' => true, 'csrf' => csrf_token()]);

case 'POST account/name':
    $user = api_user();
    $name = api_str('name');
    if ($name === '' || mb_strlen($name) > 100) {
        api_fail('O nome precisa ter entre 1 e 100 letras.', 422, ['name' => 'O nome precisa ter entre 1 e 100 letras.']);
    }
    $pdo->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$name, $user['id']]);
    api_out(['user' => api_user_out(['name' => $name] + $user)]);

case 'POST account/password':
    $user = api_user();
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $hash = (string) $stmt->fetchColumn();
    $errors = [];
    if (!password_verify(api_raw('current'), $hash)) {
        $errors['current'] = 'A senha atual está incorreta.';
    }
    $new = api_raw('password');
    if (strlen($new) < 8) {
        $errors['password'] = 'A nova senha precisa ter pelo menos 8 caracteres.';
    } elseif (strlen($new) > 200) {
        $errors['password'] = 'A nova senha pode ter no máximo 200 caracteres.';
    }
    if ($errors) {
        api_fail(reset($errors), 422, $errors);
    }
    $hash = password_hash($new, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $user['id']]);
    // Outros aparelhos saem; este continua.
    session_regenerate_id(true);
    refresh_password_stamp($hash);
    invalidate_password_resets((int) $user['id']);
    api_out(['ok' => true]);

/* ---------- Bolões ---------- */

case 'GET groups':
    $user = api_user();
    $uid = (int) $user['id'];
    $stmt = $pdo->prepare(
        'SELECT g.id, g.name, g.owner_id, g.round_mode, g.deadline_mode,
                (SELECT COUNT(*) FROM group_members WHERE group_id = g.id) AS members
         FROM bolao_groups g JOIN group_members m ON m.group_id = g.id
         WHERE m.user_id = ? ORDER BY g.name'
    );
    $stmt->execute([$uid]);
    $groups = $stmt->fetchAll();
    $ids = array_map('intval', array_column($groups, 'id'));
    $rankings = group_rankings($ids);
    $predictable = group_predictable($groups, $uid, time());
    $out = [];
    foreach ($groups as $g) {
        $gid = (int) $g['id'];
        $mine = null;
        $ranked = ranking_with_positions($rankings[$gid] ?? []);
        foreach ($ranked as $row) {
            if ((int) $row['id'] === $uid) {
                $mine = $row;
            }
        }
        $out[] = [
            'id' => $gid,
            'name' => $g['name'],
            'isOwner' => (int) $g['owner_id'] === $uid,
            'members' => (int) $g['members'],
            'pos' => $mine ? (int) $mine['pos'] : null,
            'points' => $mine ? (int) $mine['total'] : 0,
            'leader' => $ranked ? ['name' => $ranked[0]['name'], 'total' => (int) $ranked[0]['total']] : null,
            'pending' => (int) ($predictable[$gid]['pending'] ?? 0),
            'next' => $predictable[$gid]['next'] ?? null,
        ];
    }
    api_out(['groups' => $out]);

case 'GET leagues':
    api_user();
    $selected = array_map('intval', array_filter(explode(',', (string) ($_GET['selected'] ?? ''))));
    api_out(api_league_picker($selected));

case 'POST groups/create':
    $user = api_user();
    $name = api_str('name');
    $password = api_raw('password');
    $selected = api_league_selection(league_valid_ids());
    $points = ['exact' => api_points('exact'), 'winner' => api_points('winner'), 'diff' => api_points('diff')];
    $errors = [];
    if ($name === '' || mb_strlen($name) > 100) {
        $errors['name'] = 'O nome precisa ter entre 1 e 100 letras.';
    }
    if ($password !== '' && strlen($password) < 4) {
        $errors['password'] = 'A senha do bolão precisa ter pelo menos 4 caracteres, ou fique em branco.';
    }
    if (!$selected) {
        $errors['leagues'] = 'Marque pelo menos uma liga para o bolão.';
    } elseif (count($selected) > MAX_GROUP_LEAGUES) {
        $errors['leagues'] = 'Escolha no máximo ' . MAX_GROUP_LEAGUES . ' ligas.';
    }
    foreach ($points as $v) {
        if ($v === null) {
            $errors['points'] = 'Os pontos devem ficar entre 0 e 20.';
        }
    }
    if ($errors) {
        api_fail(reset($errors), 422, $errors);
    }
    $pdo->beginTransaction();
    $pdo->prepare(
        'INSERT INTO bolao_groups (name, owner_id, invite_code, password_hash, exact_points, winner_points, goal_diff_points) VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$name, $user['id'], bin2hex(random_bytes(5)), $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null,
        (int) $points['exact'], (int) $points['winner'], (int) $points['diff']]);
    $gid = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, ?)')->execute([$gid, $user['id'], 'owner']);
    $ins = $pdo->prepare('INSERT INTO group_leagues (group_id, league_id) VALUES (?, ?)');
    foreach ($selected as $lid) {
        $ins->execute([$gid, $lid]);
    }
    $pdo->commit();
    $fetching = fetch_new_leagues($selected);
    api_out(['id' => $gid, 'fetching' => $fetching]);

case 'GET invite':
    $user = api_user();
    $code = substr(strtolower((string) preg_replace('/\s+/', '', (string) ($_GET['code'] ?? ''))), 0, 20);
    $stmt = $pdo->prepare('SELECT * FROM bolao_groups WHERE invite_code = ?');
    $stmt->execute([$code]);
    $group = $code !== '' ? $stmt->fetch() : false;
    if (!$group) {
        api_fail('Esse convite não existe mais. Peça um novo link para quem organiza.', 404);
    }
    $gid = (int) $group['id'];
    $stmt = $pdo->prepare('SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?');
    $stmt->execute([$gid, $user['id']]);
    $isMember = (bool) $stmt->fetch();
    $stmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
    $stmt->execute([$group['owner_id']]);
    $owner = (string) $stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM group_members WHERE group_id = ?');
    $stmt->execute([$gid]);
    $members = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT l.id, l.name, l.short_name, l.country, l.kind, l.logo_url FROM group_leagues gl JOIN leagues l ON l.id = gl.league_id WHERE gl.group_id = ? ORDER BY l.sort_order, l.name');
    $stmt->execute([$gid]);
    api_out([
        'id' => $gid, 'name' => $group['name'], 'owner' => $owner, 'members' => $members, 'isMember' => $isMember,
        'needsPassword' => $group['password_hash'] !== null, 'leagues' => array_map('api_league', $stmt->fetchAll()),
        'rules' => ['exact' => (int) $group['exact_points'], 'winner' => (int) $group['winner_points'], 'diff' => (int) $group['goal_diff_points']],
    ]);

case 'POST invite/join':
    $user = api_user();
    $code = substr(strtolower((string) preg_replace('/\s+/', '', api_str('code'))), 0, 20);
    $stmt = $pdo->prepare('SELECT * FROM bolao_groups WHERE invite_code = ?');
    $stmt->execute([$code]);
    $group = $code !== '' ? $stmt->fetch() : false;
    if (!$group) {
        api_fail('Esse convite não existe mais. Peça um novo link para quem organiza.', 404);
    }
    if ($group['password_hash'] !== null) {
        $key = 'bolao:' . (int) $group['id'];
        $wait = login_lock_seconds($key, client_ip());
        if ($wait > 0) {
            api_fail('Muitas tentativas erradas. Espere ' . wait_text($wait) . ' e tente de novo.', 429, ['password' => 'Muitas tentativas.']);
        }
        if (!password_verify(api_raw('password'), $group['password_hash'])) {
            login_record_failure($key, client_ip());
            api_fail('Senha incorreta. Confira com quem organiza.', 422, ['password' => 'Senha incorreta.']);
        }
    }
    $pdo->prepare('INSERT IGNORE INTO group_members (group_id, user_id, role) VALUES (?, ?, ?)')->execute([$group['id'], $user['id'], 'member']);
    api_out(['id' => (int) $group['id']]);

case 'GET group':
    $user = api_user();
    $group = api_member((int) ($_GET['id'] ?? 0), (int) $user['id']);
    api_out(api_group_info($group, $user));

case 'GET group/round':
    $user = api_user();
    $group = api_member((int) ($_GET['id'] ?? 0), (int) $user['id']);
    api_out(api_round($group, $user, (int) ($_GET['league'] ?? 0), (int) ($_GET['round'] ?? 0)));

case 'POST group/predict':
    $user = api_user();
    $gid = (int) api_in('id', 0);
    $group = api_member($gid, (int) $user['id']);
    $leagueIds = group_league_ids($gid);
    $preds = api_in('preds', []);
    $preds = is_array($preds) ? $preds : [];
    $ids = array_slice(array_values(array_filter(array_map('intval', array_keys($preds)))), 0, 200);
    $matches = [];
    if ($ids) {
        $stmt = $pdo->prepare('SELECT * FROM matches WHERE id IN (' . placeholders($ids) . ') AND league_id IN (' . placeholders($leagueIds) . ')');
        $stmt->execute(array_merge($ids, $leagueIds));
        foreach ($stmt->fetchAll() as $m) {
            $matches[(int) $m['id']] = $m;
        }
    }
    $now = time();
    $upsert = $pdo->prepare(
        'INSERT INTO predictions (user_id, group_id, match_id, home_pred, away_pred) VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE home_pred = VALUES(home_pred), away_pred = VALUES(away_pred)'
    );
    $saved = [];
    $skipped = [];
    foreach ($preds as $matchId => $score) {
        $matchId = (int) $matchId;
        if (!isset($matches[$matchId]) || !is_array($score)) {
            $skipped[] = ['id' => $matchId, 'reason' => 'jogo não encontrado neste bolão'];
            continue;
        }
        $m = $matches[$matchId];
        $home = trim((string) ($score['home'] ?? ''));
        $away = trim((string) ($score['away'] ?? ''));
        [$ctx] = round_context((int) $m['league_id'], $group, $now);
        $status = predict_status($m, $ctx);
        $reason = null;
        if (!$status['ok']) {
            $reason = predict_reason_text($status, $ctx, $now);
        } elseif ($home === '' || $away === '') {
            $reason = $home === '' ? 'falta o placar do mandante' : 'falta o placar do visitante';
        } elseif (!ctype_digit($home) || !ctype_digit($away) || (int) $home > 30 || (int) $away > 30) {
            $reason = 'use placares de 0 a 30';
        }
        if ($reason !== null) {
            $skipped[] = ['id' => $matchId, 'label' => $m['home_team'] . ' × ' . $m['away_team'], 'reason' => $reason];
            continue;
        }
        $upsert->execute([$user['id'], $gid, $matchId, (int) $home, (int) $away]);
        $saved[] = $matchId;
    }
    api_out(['saved' => $saved, 'skipped' => $skipped, 'time' => kick_clock(time())]);

case 'GET group/games':
    $user = api_user();
    $gid = (int) ($_GET['id'] ?? 0);
    api_member($gid, (int) $user['id']);
    $leagueIds = group_league_ids($gid);
    $filter = (int) ($_GET['league'] ?? 0);
    if ($filter && in_array($filter, $leagueIds, true)) {
        $leagueIds = [$filter];
    }
    $now = time();
    $stmt = $pdo->prepare(
        'SELECT m.*, ' . team_columns_sql() . ' FROM matches m ' . team_joins_sql() . '
         WHERE m.league_id IN (' . placeholders($leagueIds) . ') AND m.kickoff_at BETWEEN ? AND ? AND ' . not_void_sql('m') . '
         ORDER BY m.kickoff_at, m.id'
    );
    $stmt->execute(array_merge($leagueIds, [date('Y-m-d 00:00:00', $now - 3 * 86400), date('Y-m-d 23:59:59', $now + 14 * 86400)]));
    $days = [];
    foreach ($stmt->fetchAll() as $m) {
        $days[substr($m['kickoff_at'], 0, 10)][] = [
            'id' => (int) $m['id'],
            'leagueId' => (int) $m['league_id'],
            'round' => (int) $m['round'],
            'kickoff' => strtotime($m['kickoff_at']),
            'home' => api_team($m, 'home'),
            'away' => api_team($m, 'away'),
            'score' => $m['home_score'] !== null ? [(int) $m['home_score'], (int) $m['away_score']] : null,
            'live' => is_live($m),
            'liveScore' => is_live($m) ? [(int) $m['live_home_score'], (int) $m['live_away_score']] : null,
            'statusDetail' => $m['status_detail'],
        ];
    }
    $out = [];
    foreach ($days as $day => $list) {
        $out[] = ['day' => $day, 'matches' => $list];
    }
    api_out(['days' => $out, 'today' => date('Y-m-d', $now)]);

case 'GET group/ranking':
    $user = api_user();
    $gid = (int) ($_GET['id'] ?? 0);
    api_member($gid, (int) $user['id']);
    $scored = group_scored_rounds($gid);
    $key = (string) ($_GET['rodada'] ?? '');
    $pick = null;
    foreach ($scored as $sr) {
        if ($sr['key'] === $key) {
            $pick = $sr;
        }
    }
    $pick = $pick ?? ($scored[0] ?? null);
    api_out([
        'rounds' => array_map(function ($r) {
            $label = $r['round_name'];
            return $r + ['short' => preg_match('/^Rodada (\d+)$/', $label, $x) ? 'R' . $x[1] : round_chip_text($label)];
        }, $scored),
        'round' => $pick,
        'roundRanking' => $pick ? api_ranking_rows(ranking_with_positions(group_ranking($gid, $pick['round'], $pick['league_id']))) : [],
        'matrix' => (object) group_points_by_round($gid),
    ]);

case 'POST group/settings':
    $user = api_user();
    $gid = (int) api_in('id', 0);
    $group = api_member($gid, (int) $user['id']);
    api_owner($group, $user);
    $action = api_str('action');
    $msg = 'Alterações salvas.';
    if ($action === 'name') {
        $name = api_str('name');
        if ($name === '' || mb_strlen($name) > 100) {
            api_fail('O nome precisa ter entre 1 e 100 letras.', 422, ['name' => 'O nome precisa ter entre 1 e 100 letras.']);
        }
        $pdo->prepare('UPDATE bolao_groups SET name = ? WHERE id = ?')->execute([$name, $gid]);
    } elseif ($action === 'password') {
        $password = api_raw('password');
        if (strlen($password) < 4 || strlen($password) > 200) {
            api_fail('A nova senha precisa ter pelo menos 4 caracteres.', 422, ['password' => 'Mínimo de 4 caracteres.']);
        }
        $pdo->prepare('UPDATE bolao_groups SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $gid]);
        $msg = 'Senha do bolão salva.';
    } elseif ($action === 'remove_password') {
        $pdo->prepare('UPDATE bolao_groups SET password_hash = NULL WHERE id = ?')->execute([$gid]);
        $msg = 'Senha removida. Quem tiver o link entra direto.';
    } elseif ($action === 'leagues') {
        $stmt = $pdo->prepare('SELECT league_id FROM group_leagues WHERE group_id = ?');
        $stmt->execute([$gid]);
        $before = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $selected = api_league_selection(league_valid_ids($before));
        if (!$selected) {
            api_fail('Marque pelo menos uma liga.', 422, ['leagues' => 'Marque pelo menos uma liga.']);
        }
        if (count($selected) > MAX_GROUP_LEAGUES) {
            api_fail('Escolha no máximo ' . MAX_GROUP_LEAGUES . ' ligas.', 422, ['leagues' => 'Escolha no máximo ' . MAX_GROUP_LEAGUES . ' ligas.']);
        }
        $scored = group_scored_counts($gid);
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM group_leagues WHERE group_id = ?')->execute([$gid]);
        $ins = $pdo->prepare('INSERT INTO group_leagues (group_id, league_id) VALUES (?, ?)');
        foreach ($selected as $lid) {
            $ins->execute([$gid, $lid]);
        }
        $pdo->commit();
        recalculate_group($gid);
        if ($scored['preds'] > 0) {
            $msg = 'Alterações salvas. Pontos de ' . plural($scored['preds'], 'palpite', 'palpites') . ' recalculados.';
        }
        if (fetch_new_leagues(array_diff($selected, $before))) {
            $msg .= ' Buscando a tabela completa; pode levar alguns minutos.';
        }
    } elseif ($action === 'rounds') {
        $rm = api_str('roundMode');
        $dm = api_str('deadlineMode');
        if (!in_array($rm, ['rodada', 'duas', 'livre'], true) || !in_array($dm, ['jogo', 'rodada'], true)) {
            api_fail('Escolha uma opção em cada grupo.', 422);
        }
        $pdo->prepare('UPDATE bolao_groups SET round_mode = ?, deadline_mode = ? WHERE id = ?')->execute([$rm, $dm, $gid]);
    } elseif ($action === 'points') {
        $vals = [];
        foreach (['exact', 'winner', 'diff'] as $k) {
            $v = api_points($k);
            if ($v === null) {
                api_fail('Os pontos devem ser um número entre 0 e 20.', 422, [$k => 'De 0 a 20.']);
            }
            $vals[] = $v;
        }
        $pdo->prepare('UPDATE bolao_groups SET exact_points = ?, winner_points = ?, goal_diff_points = ? WHERE id = ?')->execute(array_merge($vals, [$gid]));
        $scored = group_scored_counts($gid);
        recalculate_group($gid);
        if ($scored['preds'] > 0) {
            $msg = 'Alterações salvas. Pontos de ' . plural($scored['preds'], 'palpite', 'palpites') . ' recalculados.';
        }
    } elseif ($action === 'new_code') {
        $pdo->prepare('UPDATE bolao_groups SET invite_code = ? WHERE id = ?')->execute([bin2hex(random_bytes(5)), $gid]);
        $msg = 'Novo código gerado. O link antigo não funciona mais.';
    } elseif ($action === 'transfer') {
        $to = (int) api_in('newOwnerId', 0);
        if ($to <= 0 || $to === (int) $user['id']) {
            api_fail('Escolha quem vai organizar o bolão.', 422, ['newOwnerId' => 'Escolha um participante.']);
        }
        if (api_str('confirmName') !== $group['name']) {
            api_fail('Para transferir, digite o nome exato do bolão.', 422, ['confirmName' => 'Digite o nome exato do bolão.']);
        }
        $stmt = $pdo->prepare('SELECT u.name FROM group_members gm JOIN users u ON u.id = gm.user_id WHERE gm.group_id = ? AND gm.user_id = ?');
        $stmt->execute([$gid, $to]);
        $newName = $stmt->fetchColumn();
        if ($newName === false || !transfer_group_ownership($gid, (int) $user['id'], $to)) {
            api_fail('Essa pessoa não está mais no bolão. Escolha outra.', 422);
        }
        $msg = $newName . ' agora organiza o bolão. Você continua como participante.';
    } elseif ($action === 'delete') {
        if (api_str('confirmName') !== $group['name']) {
            api_fail('Para excluir, digite o nome exato do bolão.', 422, ['confirmName' => 'Digite o nome exato do bolão.']);
        }
        $pdo->prepare('DELETE FROM bolao_groups WHERE id = ?')->execute([$gid]);
        api_out(['ok' => true, 'deleted' => true, 'message' => 'Bolão excluído.']);
    } else {
        api_fail('Ação desconhecida.');
    }
    api_out(['ok' => true, 'message' => $msg]);

case 'POST group/remove-member':
    $user = api_user();
    $gid = (int) api_in('id', 0);
    $group = api_member($gid, (int) $user['id']);
    api_owner($group, $user);
    $rid = (int) api_in('userId', 0);
    if ($rid === (int) $group['owner_id']) {
        api_fail('O organizador não pode ser removido.');
    }
    $pdo->prepare('DELETE FROM group_members WHERE group_id = ? AND user_id = ?')->execute([$gid, $rid]);
    api_out(['ok' => true]);

case 'POST group/leave':
    $user = api_user();
    $gid = (int) api_in('id', 0);
    $group = api_member($gid, (int) $user['id']);
    if ((int) $group['owner_id'] === (int) $user['id']) {
        api_fail('O organizador não pode sair do próprio bolão. Transfira a organização antes.');
    }
    $pdo->prepare('DELETE FROM group_members WHERE group_id = ? AND user_id = ?')->execute([$gid, $user['id']]);
    api_out(['ok' => true]);

/* ---------- Administração ---------- */

case 'GET admin/leagues':
    api_admin();
    $rows = $pdo->query('SELECT id, name, short_name, code, country, kind, is_active, external_id, logo_url FROM leagues ORDER BY sort_order, name')->fetchAll();
    usort($rows, fn ($a, $b) => (COUNTRIES[$a['country']][1] ?? 999) <=> (COUNTRIES[$b['country']][1] ?? 999));
    api_out(['leagues' => array_map(fn ($l) => api_league($l) + ['code' => $l['code'], 'active' => (bool) $l['is_active'], 'auto' => $l['external_id'] !== null], $rows)]);

case 'GET admin/results':
    api_admin();
    $where = [not_void_sql('m')];
    $params = [];
    if ((int) ($_GET['league'] ?? 0)) {
        $where[] = 'm.league_id = ?';
        $params[] = (int) $_GET['league'];
    }
    if ((int) ($_GET['round'] ?? 0)) {
        $where[] = 'm.round = ?';
        $params[] = (int) $_GET['round'];
    }
    $stmt = $pdo->prepare('SELECT m.*, l.name AS league_name, ' . team_columns_sql() . ' FROM matches m JOIN leagues l ON l.id = m.league_id ' . team_joins_sql()
        . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY m.kickoff_at, m.id LIMIT 1000');
    $stmt->execute($params);
    $now = time();
    $out = ['waiting' => [], 'upcoming' => [], 'finished' => []];
    foreach ($stmt->fetchAll() as $m) {
        $row = [
            'id' => (int) $m['id'], 'league' => $m['league_name'], 'round' => (int) $m['round'], 'kickoff' => strtotime($m['kickoff_at']),
            'home' => api_team($m, 'home'), 'away' => api_team($m, 'away'),
            'score' => $m['home_score'] !== null ? [(int) $m['home_score'], (int) $m['away_score']] : null,
            'auto' => $m['external_id'] !== null,
        ];
        $bucket = $m['home_score'] !== null ? 'finished' : (strtotime($m['kickoff_at']) <= $now ? 'waiting' : 'upcoming');
        $out[$bucket][] = $row;
    }
    $out['finished'] = array_reverse($out['finished']);
    $out['upcoming'] = array_slice($out['upcoming'], 0, 60);
    $out['finished'] = array_slice($out['finished'], 0, 100);
    api_out($out);

case 'POST admin/score':
    api_admin();
    $mid = (int) api_in('matchId', 0);
    $h = (string) api_in('home', '');
    $a = (string) api_in('away', '');
    if (!ctype_digit($h) || !ctype_digit($a) || (int) $h > 30 || (int) $a > 30) {
        api_fail('Informe um placar válido, de 0 a 30.', 422);
    }
    $pdo->prepare('UPDATE matches SET home_score = ?, away_score = ? WHERE id = ?')->execute([(int) $h, (int) $a, $mid]);
    recalculate_match($mid);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM predictions WHERE match_id = ?');
    $stmt->execute([$mid]);
    api_out(['message' => 'Salvo. ' . plural((int) $stmt->fetchColumn(), 'palpite recalculado', 'palpites recalculados') . '.']);

case 'POST admin/clear-score':
    api_admin();
    $mid = (int) api_in('matchId', 0);
    $pdo->prepare('UPDATE matches SET home_score = NULL, away_score = NULL WHERE id = ?')->execute([$mid]);
    recalculate_match($mid);
    api_out(['message' => 'Resultado apagado. Os pontos deste jogo foram zerados.']);

case 'GET admin/sync':
    api_admin();
    $state = sync_state();
    $rows = $pdo->query(
        'SELECT l.id, l.name, l.synced_quick_at, l.synced_full_at, l.sync_failures,
                (SELECT COUNT(*) FROM matches m WHERE m.league_id = l.id) AS games
         FROM leagues l WHERE EXISTS (SELECT 1 FROM group_leagues gl WHERE gl.league_id = l.id) ORDER BY l.sort_order, l.name'
    )->fetchAll();
    api_out([
        'lastQuick' => (int) ($state['last_quick'] ?? 0) ?: null,
        'lastFull' => (int) ($state['last_full'] ?? 0) ?: null,
        'lastError' => ($state['last_error'] ?? '') ?: null,
        'detached' => function_exists('litespeed_finish_request') || function_exists('fastcgi_finish_request'),
        'leagues' => array_map(fn ($l) => ['id' => (int) $l['id'], 'name' => $l['name'], 'games' => (int) $l['games'],
            'quick' => (int) $l['synced_quick_at'] ?: null, 'full' => (int) $l['synced_full_at'] ?: null, 'failures' => (int) $l['sync_failures']], $rows),
    ]);

case 'POST admin/sync':
    api_admin();
    @set_time_limit(120);
    $result = sync_run('full');
    if (!empty($result['skipped'])) {
        api_fail('Já existe uma sincronização em andamento. Tente de novo em um minuto.', 409);
    }
    api_out(['message' => sprintf('Sincronizado: %d ligas, %d consultas, %d jogos lidos, %d novos, %d resultados atualizados.%s',
        $result['leagues'], $result['requests'], $result['events'], $result['new'], $result['results'],
        $result['failed_requests'] ? ' ' . $result['failed_requests'] . ' consultas à ESPN falharam.' : '')]);

case 'POST admin/match':
    api_admin();
    $lid = (int) api_in('leagueId', 0);
    $round = (int) api_in('round', 0);
    $home = api_str('home');
    $away = api_str('away');
    $kick = DateTime::createFromFormat('Y-m-d\TH:i', api_str('kickoff'));
    $errors = [];
    $stmt = $pdo->prepare('SELECT 1 FROM leagues WHERE id = ?');
    $stmt->execute([$lid]);
    if (!$stmt->fetch()) {
        $errors['leagueId'] = 'Escolha uma liga.';
    }
    if ($round < 1 || $round > 999) {
        $errors['round'] = 'Informe a rodada, de 1 a 999.';
    }
    if ($home === '' || mb_strlen($home) > 100) {
        $errors['home'] = 'Informe o mandante, com até 100 letras.';
    }
    if ($away === '' || mb_strlen($away) > 100) {
        $errors['away'] = 'Informe o visitante, com até 100 letras.';
    } elseif (mb_strtolower($home) === mb_strtolower($away)) {
        $errors['away'] = 'Mandante e visitante precisam ser times diferentes.';
    }
    if (!$kick) {
        $errors['kickoff'] = 'Informe a data e o horário do jogo.';
    }
    if ($errors) {
        api_fail(reset($errors), 422, $errors);
    }
    $pdo->prepare('INSERT INTO matches (league_id, round, home_team, away_team, kickoff_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$lid, $round, $home, $away, $kick->format('Y-m-d H:i:00')]);
    api_out(['message' => 'Jogo cadastrado.']);

case 'POST admin/import':
    api_admin();
    $lid = (int) api_in('leagueId', 0);
    $text = api_raw('text');
    $stmt = $pdo->prepare('SELECT 1 FROM leagues WHERE id = ?');
    $stmt->execute([$lid]);
    if (!$stmt->fetch()) {
        api_fail('Escolha uma liga.', 422, ['leagueId' => 'Escolha uma liga.']);
    }
    if (trim($text) === '' || strlen($text) > 100000) {
        api_fail('Cole de 1 a 500 jogos.', 422, ['text' => 'Cole pelo menos uma linha com um jogo.']);
    }
    $preview = parse_match_import($text, $lid);
    $c = $preview['count'];
    if (api_in('save') && $c['error'] === 0 && $c['ok'] > 0) {
        $saved = save_match_import($preview, $lid);
        api_out(['saved' => $saved, 'message' => plural($saved, 'jogo importado', 'jogos importados') . '.'
            . ($c['skip'] > 0 ? ' ' . plural($c['skip'], 'linha ignorada', 'linhas ignoradas') . '.' : '')]);
    }
    api_out(['preview' => $preview]);

case 'GET admin/users':
    api_admin();
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $stmt = $pdo->prepare('SELECT id, name, email, created_at FROM users WHERE name LIKE ? OR email LIKE ? ORDER BY name LIMIT 50');
        $stmt->execute([$like, $like]);
    } else {
        $stmt = $pdo->query('SELECT id, name, email, created_at FROM users ORDER BY created_at DESC LIMIT 50');
    }
    api_out(['users' => array_map(fn ($u) => ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'since' => strtotime($u['created_at'])], $stmt->fetchAll())]);

case 'POST admin/reset-link':
    api_admin();
    $stmt = $pdo->prepare('SELECT id, name, email FROM users WHERE id = ?');
    $stmt->execute([(int) api_in('userId', 0)]);
    $target = $stmt->fetch();
    if (!$target) {
        api_fail('Usuário não encontrado.', 404);
    }
    api_out(['name' => $target['name'], 'email' => $target['email'], 'url' => password_reset_url(create_password_reset((int) $target['id'], 'admin'))]);

case 'POST admin/league-toggle':
    api_admin();
    $active = api_in('active') ? 1 : 0;
    $pdo->prepare('UPDATE leagues SET is_active = ? WHERE id = ?')->execute([$active, (int) api_in('leagueId', 0)]);
    api_out(['message' => $active ? 'Liga ativada.' : 'Liga desativada. Ela não aparece mais para novos bolões.']);

case 'POST admin/league':
    api_admin();
    $name = api_str('name');
    $code = strtoupper(api_str('code'));
    $errors = [];
    if ($name === '' || mb_strlen($name) > 100) {
        $errors['name'] = 'Informe o nome da liga, com até 100 letras.';
    }
    if (!preg_match('/^[A-Z0-9_-]{1,20}$/', $code)) {
        $errors['code'] = 'Use letras, números e hífen, até 20 (ex.: BRA-A).';
    } else {
        $stmt = $pdo->prepare('SELECT 1 FROM leagues WHERE code = ?');
        $stmt->execute([$code]);
        if ($stmt->fetch()) {
            $errors['code'] = 'Já existe uma liga com este código.';
        }
    }
    if ($errors) {
        api_fail(reset($errors), 422, $errors);
    }
    $pdo->prepare('INSERT INTO leagues (name, code) VALUES (?, ?)')->execute([$name, $code]);
    api_out(['message' => 'Liga cadastrada.']);

default:
    api_fail('Rota não encontrada.', 404);
}
