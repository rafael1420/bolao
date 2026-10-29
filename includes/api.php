<?php
// Funções da API JSON usada pelo app React (api/index.php). As regras continuam em app.php e rounds.php;
// aqui só montamos as respostas e conferimos login, CSRF e permissão.

/** Responde em JSON e encerra. A sincronização (sync.php) roda depois, como nas páginas. */
function api_out(array $data, int $code = 200): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Erro com mensagem para a tela; $fields: campo => mensagem (mostrada embaixo do campo). */
function api_fail(string $message, int $code = 400, array $fields = []): void
{
    api_out(['error' => $message, 'fields' => (object) $fields], $code);
}

/** Corpo JSON da requisição (POST). */
function api_body(): array
{
    static $body = null;
    if ($body === null) {
        $raw = (string) file_get_contents('php://input');
        $body = $raw !== '' ? (json_decode($raw, true) ?: []) : [];
        if (!is_array($body)) {
            $body = [];
        }
    }
    return $body;
}

function api_in(string $key, $default = null)
{
    $body = api_body();
    return array_key_exists($key, $body) ? $body[$key] : $default;
}

function api_str(string $key): string
{
    $v = api_in($key, '');
    return is_scalar($v) ? trim((string) $v) : '';
}

/** Senhas não levam trim. */
function api_raw(string $key): string
{
    $v = api_in($key, '');
    return is_scalar($v) ? (string) $v : '';
}

/** Pontos de 0 a 20 (número ou texto só com dígitos); null se inválido. */
function api_points(string $key): ?int
{
    $v = api_in($key);
    $s = is_int($v) ? (string) $v : (is_string($v) ? trim($v) : '');
    return ctype_digit($s) && (int) $s <= 20 ? (int) $s : null;
}

/** Toda ação que muda algo exige o token CSRF no cabeçalho X-CSRF-Token. */
function api_csrf(): void
{
    $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals(csrf_token(), $sent)) {
        api_out(['error' => 'Sua sessão expirou. Recarregue a página e tente de novo.', 'csrf' => csrf_token()], 419);
    }
}

function api_user(): array
{
    $user = current_user();
    if ($user === null) {
        api_fail('Entre na sua conta para continuar.', 401);
    }
    return $user;
}

function api_admin(): array
{
    $user = api_user();
    if (!is_admin($user)) {
        api_fail('Só a administração do site pode fazer isso.', 403);
    }
    return $user;
}

function api_user_out(?array $user): ?array
{
    if ($user === null) {
        return null;
    }
    return ['id' => (int) $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'isAdmin' => is_admin($user)];
}

/** O bolão, se a pessoa participa dele. Senão, 404. */
function api_member(int $groupId, int $userId): array
{
    $stmt = db()->prepare(
        'SELECT g.* FROM bolao_groups g JOIN group_members m ON m.group_id = g.id WHERE g.id = ? AND m.user_id = ?'
    );
    $stmt->execute([$groupId, $userId]);
    $group = $stmt->fetch();
    if (!$group) {
        api_fail('Você não está neste bolão, ou ele não existe mais.', 404);
    }
    return $group;
}

function api_owner(array $group, array $user): void
{
    if ((int) $group['owner_id'] !== (int) $user['id']) {
        api_fail('Só o organizador pode alterar este bolão.', 403);
    }
}

/** Link de convite do app: /convite/CODIGO. */
function invite_url(string $code): string
{
    return site_url() . 'convite/' . $code;
}

/* ---------- Montagem dos dados ---------- */

function api_league(array $l): array
{
    return [
        'id' => (int) $l['id'],
        'name' => $l['name'],
        'short' => ($l['short_name'] ?? '') ?: $l['name'],
        'country' => (string) ($l['country'] ?? 'OUT'),
        'kind' => (string) ($l['kind'] ?? 'liga'),
        'logo' => espn_img($l['logo_url'] ?? null, 48),
    ];
}

function api_team(array $m, string $side): array
{
    $name = (string) ($m[$side . '_team'] ?? '');
    $abbr = (string) ($m[$side . '_abbr'] ?? '');
    return [
        'name' => $name,
        'short' => ($m[$side . '_short'] ?? '') ?: $name,
        'abbr' => $abbr !== '' ? mb_strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N}]/u', '', $abbr), 0, 3)) : team_initials($name),
        'logo' => espn_img($m[$side . '_logo'] ?? null, 96),
        'color' => $m[$side . '_color'] ?? null,
        'color2' => $m[$side . '_color2'] ?? null,
    ];
}

/** Linhas do ranking com posição (empates dividem a posição). */
function api_ranking_rows(array $ranking): array
{
    return array_map(function ($r) {
        return [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'pos' => (int) $r['pos'],
            'total' => (int) $r['total'],
            'exact' => (int) $r['exact'],
            'outcomes' => (int) $r['outcomes'],
            'played' => (int) $r['played'],
        ];
    }, $ranking);
}

/**
 * Um jogo na aba Rodada: situação para palpite (pela regra do bolão), prazo, meu palpite, pontos e,
 * em jogo já fechado, os palpites de todos.
 */
function api_match(array $m, array $ctx, ?array $pred, int $now, ?array $others = null): array
{
    $id = (int) $m['id'];
    $status = predict_status($m, $ctx);
    $open = $status['ok'];
    $locked = in_array($status['reason'], ['locked', 'deadline'], true);
    $finished = $m['home_score'] !== null;
    $kick = strtotime($m['kickoff_at']);
    $close = $kick;
    [$number, $resched] = $ctx['where'][$id] ?? [(int) $m['round'], false];
    if ($open && !$resched && $ctx['deadline'] === 'rodada' && !empty($ctx['rounds'][$number]['start'])) {
        $close = min($kick, (int) $ctx['rounds'][$number]['start']);
    }
    $lockText = null;
    if ($status['reason'] === 'deadline') {
        $lockText = 'Prazo da rodada encerrado';
    } elseif ($status['reason'] === 'locked') {
        $lockText = $resched ? 'Abre na semana do jogo' : round_locked_text($ctx, $number, $now);
    }
    $points = null;
    $reason = null;
    $exact = false;
    if ($pred && $finished) {
        $points = (int) ($pred['points'] ?? 0);
        $reason = points_reason((int) $pred['home_pred'], (int) $pred['away_pred'], (int) $m['home_score'], (int) $m['away_score']);
        $exact = $reason === 'Placar exato';
    }

    return [
        'id' => $id,
        'leagueId' => (int) $m['league_id'],
        'round' => (int) $m['round'],
        'kickoff' => $kick,
        'closeAt' => $close,
        'note' => $m['note'] ?? null,
        'origin' => $m['origin_label'] ?? null,
        'home' => api_team($m, 'home'),
        'away' => api_team($m, 'away'),
        'score' => $finished ? [(int) $m['home_score'], (int) $m['away_score']] : null,
        'live' => !$open && is_live($m),
        'liveScore' => is_live($m) ? [(int) $m['live_home_score'], (int) $m['live_away_score']] : null,
        'statusDetail' => $m['status_detail'] ?? null,
        'open' => $open,
        'locked' => $locked,
        'reason' => $status['reason'],
        'lockText' => $lockText,
        'pred' => $pred ? ['home' => (int) $pred['home_pred'], 'away' => (int) $pred['away_pred']] : null,
        'points' => $points,
        'pointsReason' => $reason,
        'exact' => $exact,
        'others' => $others,
    ];
}

/** Dados do bolão para o cabeçalho e as abas Participantes e Regras. */
function api_group_info(array $group, array $user): array
{
    $gid = (int) $group['id'];
    $uid = (int) $user['id'];
    $leagueIds = group_league_ids($gid);
    $stmt = db()->prepare('SELECT id, name, short_name, country, kind, logo_url FROM leagues WHERE id IN (' . placeholders($leagueIds) . ') ORDER BY sort_order, name');
    $stmt->execute($leagueIds);
    $leagues = array_map('api_league', $stmt->fetchAll());

    $ranking = api_ranking_rows(ranking_with_positions(group_ranking($gid)));
    $me = null;
    $above = null;
    foreach ($ranking as $row) {
        if ($row['id'] === $uid) {
            $me = $row;
        }
    }
    if ($me) {
        foreach ($ranking as $row) {
            if ($row['pos'] < $me['pos'] && $row['total'] > $me['total']) {
                $above = $row;
            }
        }
    }
    $isOwner = (int) $group['owner_id'] === $uid;
    $rm = ROUND_MODES[$group['round_mode'] ?? 'rodada'] ?? ROUND_MODES['rodada'];
    $dm = DEADLINE_MODES[$group['deadline_mode'] ?? 'jogo'] ?? DEADLINE_MODES['jogo'];

    return [
        'id' => $gid,
        'name' => $group['name'],
        'ownerId' => (int) $group['owner_id'],
        'isOwner' => $isOwner,
        'inviteCode' => $group['invite_code'],
        'inviteUrl' => invite_url($group['invite_code']),
        'hasPassword' => $group['password_hash'] !== null,
        'rules' => [
            'exact' => (int) $group['exact_points'],
            'winner' => (int) $group['winner_points'],
            'diff' => (int) $group['goal_diff_points'],
            'roundMode' => (string) ($group['round_mode'] ?? 'rodada'),
            'deadlineMode' => (string) ($group['deadline_mode'] ?? 'jogo'),
            'roundModeText' => $rm,
            'deadlineModeText' => $dm,
        ],
        'leagues' => $leagues,
        'ranking' => $ranking,
        'me' => $me,
        'above' => $above,
        'scored' => group_scored_counts($gid),
    ];
}

/** Aba Rodada (o mesmo que group.php montava): campeonato, rodadas, jogos, remarcados e meu resultado. */
function api_round(array $group, array $user, int $leagueReq, int $roundReq): array
{
    $gid = (int) $group['id'];
    $uid = (int) $user['id'];
    $now = time();
    $leagueIds = group_league_ids($gid);
    $in = placeholders($leagueIds);
    $stmt = db()->prepare("SELECT id, name FROM leagues WHERE id IN ($in) ORDER BY sort_order, name");
    $stmt->execute($leagueIds);
    $leagueNames = array_column($stmt->fetchAll(), 'name', 'id');
    $valid = array_map('intval', array_keys($leagueNames));

    $leagueId = $leagueReq;
    if (!in_array($leagueId, $valid, true)) {
        $stmt = db()->prepare(
            "SELECT league_id FROM matches WHERE league_id IN ($in) AND kickoff_at > ? AND " . not_void_sql('matches') . ' ORDER BY kickoff_at LIMIT 1'
        );
        $stmt->execute(array_merge($leagueIds, [date('Y-m-d H:i:s', $now)]));
        $leagueId = (int) $stmt->fetchColumn() ?: ($valid[0] ?? 0);
    }

    [$rctx, $byId] = round_context($leagueId, $group, $now);

    $stmt = db()->prepare('SELECT match_id, home_pred, away_pred, points FROM predictions WHERE user_id = ? AND group_id = ?');
    $stmt->execute([$uid, $gid]);
    $mine = [];
    foreach ($stmt->fetchAll() as $p) {
        $mine[(int) $p['match_id']] = $p;
    }

    $rounds = [];
    foreach ($rctx['rounds'] as $r => $info) {
        $stat = ['total' => count($info['ids']), 'finished' => 0, 'open' => 0, 'pending' => 0];
        foreach ($info['ids'] as $id) {
            $m = $byId[$id];
            if ($m['home_score'] !== null) {
                $stat['finished']++;
            } elseif (can_predict($m, $rctx)) {
                $stat['open']++;
                if (!isset($mine[$id])) {
                    $stat['pending']++;
                }
            }
        }
        $rounds[] = [
            'number' => (int) $r,
            'label' => $info['label'],
            'chip' => ((int) $r >= 20 && $info['label'] !== 'Rodada ' . $r) ? round_chip_text($info['label']) : (string) $r,
            'state' => round_ui_state($rctx, $r, $byId),
            'stats' => $stat,
        ];
    }
    $numbers = $rctx['numbers'];
    $round = in_array($roundReq, $numbers, true) ? $roundReq : ($rctx['current'] ?? ($numbers ? max($numbers) : 1));

    $matches = [];
    foreach ($rctx['rounds'][$round]['ids'] ?? [] as $id) {
        $matches[] = $byId[$id];
    }
    $rescheduled = [];
    if ($round === $rctx['current'] && !$rctx['over'] && isset($rctx['rounds'][$round])) {
        $cur = $rctx['rounds'][$round];
        $from = (int) $cur['start'] - 86400;
        $until = $rctx['horizon'] ?? ((int) $cur['end'] + ROUND_LATE_MARGIN);
        foreach ($rctx['where'] as $id => [$origin, $isResched]) {
            $t = strtotime($byId[$id]['kickoff_at']);
            if ($isResched && $origin !== $round && $t >= $from && $t <= $until) {
                $rescheduled[] = $byId[$id] + ['origin_label' => $rctx['rounds'][$origin]['label'] ?? ''];
            }
        }
        usort($rescheduled, fn ($a, $b) => strcmp($a['kickoff_at'], $b['kickoff_at']));
    }

    $all = array_merge($matches, $rescheduled);
    $others = closed_match_predictions($gid, $all);
    $out = function (array $list) use ($rctx, $mine, $now, $others) {
        return array_map(function ($m) use ($rctx, $mine, $now, $others) {
            $id = (int) $m['id'];
            return api_match($m, $rctx, $mine[$id] ?? null, $now, $others[$id] ?? null);
        }, $list);
    };

    // Situação e prazo da rodada (cabeçalho)
    $state = 'bloqueada';
    foreach ($rounds as $r) {
        if ($r['number'] === $round) {
            $state = $r['state'];
        }
    }
    $byIdShown = [];
    foreach ($all as $m) {
        $byIdShown[(int) $m['id']] = $m;
    }
    $deadline = isset($rctx['rounds'][$round]) ? round_next_deadline($rctx, $round, $byIdShown) : null;
    foreach ($rescheduled as $m) {
        if (can_predict($m, $rctx)) {
            $t = strtotime($m['kickoff_at']);
            $deadline = $deadline === null ? $t : min($deadline, $t);
            $state = $state === 'encerrada' ? $state : 'aberta';
        }
    }

    $roundMe = null;
    foreach (ranking_with_positions(group_ranking($gid, $round, $leagueId)) as $row) {
        if ((int) $row['id'] === $uid && (int) $row['played'] > 0) {
            $roundMe = ['pos' => (int) $row['pos'], 'total' => (int) $row['total'], 'played' => (int) $row['played']];
        }
    }

    return [
        'leagueId' => $leagueId,
        'leagueName' => (string) ($leagueNames[$leagueId] ?? ''),
        'current' => $rctx['current'],
        'over' => (bool) $rctx['over'],
        'deadlineMode' => $rctx['deadline'],
        'round' => $round,
        'label' => $rctx['rounds'][$round]['label'] ?? ('Rodada ' . $round),
        'state' => $state,
        'deadline' => $deadline,
        'lockedText' => $state === 'bloqueada' ? round_locked_text($rctx, $round, $now) : null,
        'rounds' => $rounds,
        'matches' => $out($matches),
        'rescheduled' => $out($rescheduled),
        'roundMe' => $roundMe,
        'now' => $now,
    ];
}

/** Seletor de ligas por país (criar bolão e Configurar). */
function api_league_picker(array $selected): array
{
    $byCountry = [];
    foreach (league_picker_rows($selected) as $row) {
        $byCountry[$row['country']][] = [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'short' => ($row['short_name'] ?? '') ?: $row['name'],
            'kind' => $row['kind'],
            'season' => $row['season_label'],
            'offSeason' => (bool) $row['off_season'],
            'logo' => espn_img($row['logo_url'] ?? null, 48),
        ];
    }
    $out = [];
    foreach ($byCountry as $code => $leagues) {
        $out[] = [
            'code' => $code,
            'name' => country_name($code),
            'featured' => (bool) (COUNTRIES[$code][2] ?? false),
            'leagues' => $leagues,
        ];
    }
    return ['countries' => $out, 'max' => MAX_GROUP_LEAGUES];
}

/** Ligas escolhidas, só entre as válidas. */
function api_league_selection(array $valid): array
{
    $raw = api_in('leagues', []);
    $raw = is_array($raw) ? $raw : [];
    return array_values(array_unique(array_intersect(array_map('intval', $raw), $valid)));
}
