<?php
require_once __DIR__ . '/includes/app.php';

$user = require_admin();

$tabs = [
    'lancar' => 'Lançar resultados',
    'jogos' => 'Jogos',
    'importar' => 'Importar jogos',
    'ligas' => 'Ligas',
    'usuarios' => 'Usuários',
];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? (string) $_GET['tab'] : 'lancar';

$fieldErrors = [];
$old = [];
$importPreview = null;  // conferência da importação em lote

/** Endereço desta página mantendo os filtros e a aba. */
function admin_url(array $params, string $anchor = ''): string
{
    $params = array_filter($params, function ($v) {
        return $v !== null && $v !== '' && $v !== 0;
    });
    return 'admin_matches.php' . ($params ? '?' . http_build_query($params) : '') . $anchor;
}

$filters = [
    'liga' => (int) ($_REQUEST['liga'] ?? 0),
    'rodada' => (int) ($_REQUEST['rodada'] ?? 0),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'sync') {
        // Busca agora jogos e placares na ESPN (normalmente roda sozinho, ver includes/sync.php).
        @set_time_limit(120);
        try {
            $result = sync_run('full');
            if (!empty($result['skipped'])) {
                flash_set('alert', 'Já existe uma sincronização em andamento. Tente de novo em um minuto.');
            } else {
                flash_set('notice', sprintf(
                    'Sincronizado: %d ligas, %d consultas, %d jogos lidos, %d novos, %d resultados atualizados.%s',
                    $result['leagues'],
                    $result['requests'],
                    $result['events'],
                    $result['new'],
                    $result['results'],
                    $result['failed_requests'] ? ' ' . $result['failed_requests'] . ' consultas à ESPN falharam.' : ''
                ));
            }
        } catch (Throwable $ex) {
            error_log('[bolao] sync: ' . $ex);
            flash_set('alert', 'A sincronização falhou: ' . mb_substr($ex->getMessage(), 0, 200));
        }
        redirect(admin_url(['tab' => 'jogos']));
    }

    if ($action === 'toggle_league') {
        // Liga desativada some do seletor de ligas; bolões que já a têm continuam com ela.
        $leagueId = (int) ($_POST['league_id'] ?? 0);
        $active = (int) ($_POST['active'] ?? 0) === 1 ? 1 : 0;
        db()->prepare('UPDATE leagues SET is_active = ? WHERE id = ?')->execute([$active, $leagueId]);
        flash_set('notice', $active ? 'Liga ativada.' : 'Liga desativada. Ela não aparece mais para novos bolões.');
        redirect(admin_url(['tab' => 'ligas'], '#liga-' . $leagueId));
    }

    if ($action === 'add_league') {
        $name = trim($_POST['league_name'] ?? '');
        $code = strtoupper(trim($_POST['league_code'] ?? ''));
        $old = ['league_name' => $name, 'league_code' => $code];

        if ($name === '' || mb_strlen($name) > 100) {
            $fieldErrors['league_name'] = 'Informe o nome da liga, com até 100 letras.';
        }
        if (!preg_match('/^[A-Z0-9_-]{1,20}$/', $code)) {
            $fieldErrors['league_code'] = 'Informe o código da liga, como BRA-A (letras, números e hífen, até 20).';
        }
        if (!$fieldErrors) {
            $stmt = db()->prepare('SELECT id FROM leagues WHERE code = ?');
            $stmt->execute([$code]);
            if ($stmt->fetch()) {
                $fieldErrors['league_code'] = 'Já existe uma liga com este código.';
            }
        }
        if (!$fieldErrors) {
            db()->prepare('INSERT INTO leagues (name, code) VALUES (?, ?)')->execute([$name, $code]);
            flash_set('notice', 'Liga cadastrada.');
            redirect(admin_url(['tab' => 'ligas']));
        }
        $tab = 'ligas';
    }

    if ($action === 'add_match') {
        $leagueId = (int) ($_POST['league_id'] ?? 0);
        $round = (int) ($_POST['round'] ?? 0);
        $home = trim($_POST['home_team'] ?? '');
        $away = trim($_POST['away_team'] ?? '');
        $kickoffRaw = (string) ($_POST['kickoff'] ?? '');
        $kickoff = \DateTime::createFromFormat('Y-m-d\TH:i', $kickoffRaw);
        $old = ['league_id' => $leagueId, 'round' => $round ?: '', 'home_team' => $home, 'away_team' => $away, 'kickoff' => $kickoffRaw];

        $leagueIds = array_map('intval', array_column(db()->query('SELECT id FROM leagues')->fetchAll(), 'id'));
        if (!in_array($leagueId, $leagueIds, true)) {
            $fieldErrors['league_id'] = 'Escolha uma liga.';
        }
        if ($round < 1 || $round > 999) {
            $fieldErrors['round'] = 'Informe a rodada, de 1 a 999.';
        }
        if ($home === '' || mb_strlen($home) > 100) {
            $fieldErrors['home_team'] = 'Informe o mandante, com até 100 letras.';
        }
        if ($away === '' || mb_strlen($away) > 100) {
            $fieldErrors['away_team'] = 'Informe o visitante, com até 100 letras.';
        } elseif (mb_strtolower($home) === mb_strtolower($away)) {
            $fieldErrors['away_team'] = 'Mandante e visitante precisam ser times diferentes.';
        }
        if (!$kickoff) {
            $fieldErrors['kickoff'] = 'Informe a data e o horário do jogo.';
        }

        if (!$fieldErrors) {
            db()->prepare(
                'INSERT INTO matches (league_id, round, home_team, away_team, kickoff_at) VALUES (?, ?, ?, ?, ?)'
            )->execute([$leagueId, $round, $home, $away, $kickoff->format('Y-m-d H:i:00')]);
            flash_set('notice', 'Jogo cadastrado.');
            redirect(admin_url(['tab' => 'jogos']));
        }
        $tab = 'jogos';
    }

    if ($action === 'score') {
        $matchId = (int) ($_POST['match_id'] ?? 0);
        $home = trim((string) ($_POST['home_score'] ?? ''));
        $away = trim((string) ($_POST['away_score'] ?? ''));
        $anchor = '#jogo-' . $matchId;
        $back = admin_url(['tab' => 'lancar', 'liga' => $filters['liga'], 'rodada' => $filters['rodada']], $anchor);

        if (!ctype_digit($home) || !ctype_digit($away) || (int) $home > 30 || (int) $away > 30) {
            flash_set('alert', 'Informe um placar válido, de 0 a 30.');
            redirect($back);
        }
        $stmt = db()->prepare('SELECT 1 FROM matches WHERE id = ?');
        $stmt->execute([$matchId]);
        if (!$stmt->fetch()) {
            flash_set('alert', 'Jogo não encontrado.');
            redirect($back);
        }

        db()->prepare('UPDATE matches SET home_score = ?, away_score = ? WHERE id = ?')
            ->execute([(int) $home, (int) $away, $matchId]);
        recalculate_match($matchId);

        $stmt = db()->prepare('SELECT COUNT(*) FROM predictions WHERE match_id = ?');
        $stmt->execute([$matchId]);
        flash_set('notice', 'Salvo. ' . plural((int) $stmt->fetchColumn(), 'palpite recalculado', 'palpites recalculados') . '.');
        redirect($back);
    }

    if ($action === 'import_preview' || $action === 'import_save') {
        // Importação em lote: sempre mostra a conferência; só grava se não houver erro.
        $tab = 'importar';
        $importLeague = (int) ($_POST['league_id'] ?? 0);
        $importText = (string) ($_POST['import_text'] ?? '');
        $old = ['league_id' => $importLeague, 'import_text' => $importText];

        $stmt = db()->prepare('SELECT id FROM leagues WHERE id = ?');
        $stmt->execute([$importLeague]);
        if (!$stmt->fetch()) {
            $fieldErrors['league_id'] = 'Escolha uma liga.';
        }
        if (trim($importText) === '') {
            $fieldErrors['import_text'] = 'Cole pelo menos uma linha com um jogo.';
        } elseif (strlen($importText) > 100000) {
            $fieldErrors['import_text'] = 'Texto grande demais. Divida em partes de até 500 jogos.';
        }

        if (!$fieldErrors) {
            $importPreview = parse_match_import($importText, $importLeague);
            $c = $importPreview['count'];
            if ($action === 'import_save' && $c['error'] === 0 && $c['ok'] > 0) {
                $saved = save_match_import($importPreview, $importLeague);
                flash_set('notice', plural($saved, 'jogo importado', 'jogos importados') . '.'
                    . ($c['skip'] > 0 ? ' ' . plural($c['skip'], 'linha ignorada', 'linhas ignoradas') . ' (já cadastradas ou repetidas).' : ''));
                redirect(admin_url(['tab' => 'lancar', 'liga' => $importLeague]));
            }
        }
    }

    if ($action === 'reset_link') {
        // Alternativa quando o e-mail não chega: a administração gera o link e manda por outro meio.
        $targetId = (int) ($_POST['user_id'] ?? 0);
        $stmt = db()->prepare('SELECT id, name, email FROM users WHERE id = ?');
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();
        if (!$target) {
            flash_set('alert', 'Usuário não encontrado.');
            redirect(admin_url(['tab' => 'usuarios']));
        }
        $token = create_password_reset((int) $target['id'], 'admin');
        flash_set('admin_reset', [
            'name' => $target['name'],
            'email' => $target['email'],
            'url' => password_reset_url($token),
        ]);
        redirect(admin_url(['tab' => 'usuarios', 'q' => $target['email']]));
    }

    if ($action === 'clear_score') {
        $matchId = (int) ($_POST['match_id'] ?? 0);
        db()->prepare('UPDATE matches SET home_score = NULL, away_score = NULL WHERE id = ?')->execute([$matchId]);
        recalculate_match($matchId);
        flash_set('notice', 'Resultado apagado. Os pontos deste jogo foram zerados.');
        redirect(admin_url(['tab' => 'lancar', 'liga' => $filters['liga'], 'rodada' => $filters['rodada']], '#jogo-' . $matchId));
    }
}

$leagues = db()->query('SELECT id, name, code, country, kind, is_active, external_id FROM leagues ORDER BY sort_order, name')->fetchAll();
usort($leagues, fn ($a, $b) => (COUNTRIES[$a['country']][1] ?? 999) <=> (COUNTRIES[$b['country']][1] ?? 999));

page_start('Administração', $user, 'wrap', 'admin');
?>
<div class="page-head admin-head">
    <div>
        <p class="eyebrow">Só para a administração do site</p>
        <h1 class="h-page">Administração</h1>
    </div>
</div>

<nav class="tabs" aria-label="Seções da administração">
    <?php foreach ($tabs as $key => $label): ?>
        <a class="tab" href="admin_matches.php?tab=<?= e($key) ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($tab === 'lancar'):
    $filterLiga = (int) ($_GET['liga'] ?? 0);
    $filterRodada = (int) ($_GET['rodada'] ?? 0);

    $where = [];
    $params = [];
    if ($filterLiga) {
        $where[] = 'm.league_id = ?';
        $params[] = $filterLiga;
    }
    if ($filterRodada) {
        $where[] = 'm.round = ?';
        $params[] = $filterRodada;
    }
    $where[] = not_void_sql('m'); // adiados e cancelados não esperam placar
    $sql = 'SELECT m.*, l.name AS league_name, ' . team_columns_sql() . ' FROM matches m JOIN leagues l ON l.id = m.league_id ' . team_joins_sql()
        . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY m.kickoff_at, m.id LIMIT 1000';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $now = time();
    $waiting = [];
    $upcoming = [];
    $finished = [];
    foreach ($rows as $m) {
        if ($m['home_score'] !== null) {
            $finished[] = $m;
        } elseif (strtotime($m['kickoff_at']) <= $now) {
            $waiting[] = $m;
        } else {
            $upcoming[] = $m;
        }
    }
    $finished = array_reverse($finished);
    $filtersParams = ['tab' => 'lancar', 'liga' => $filterLiga ?: null, 'rodada' => $filterRodada ?: null];
    ?>

    <form method="get" action="admin_matches.php" class="filters">
        <input type="hidden" name="tab" value="lancar">
        <div class="field">
            <label for="f-liga">Liga</label>
            <div class="control">
                <select id="f-liga" name="liga">
                    <option value="">Todas as ligas</option>
                    <?php foreach ($leagues as $league): ?>
                        <option value="<?= (int) $league['id'] ?>"<?= $filterLiga === (int) $league['id'] ? ' selected' : '' ?>><?= e($league['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="field">
            <label for="f-rodada">Rodada</label>
            <div class="control">
                <input id="f-rodada" type="number" name="rodada" min="1" max="999" inputmode="numeric" value="<?= $filterRodada ?: '' ?>">
            </div>
        </div>
        <div class="actions">
            <button type="submit" class="btn ghost">Filtrar</button>
            <?php if ($filterLiga || $filterRodada): ?>
                <a class="btn ghost" href="admin_matches.php?tab=lancar">Limpar filtros</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if (!$rows): ?>
        <section class="panel empty-state">
            <?php if (!$filterLiga && !$filterRodada): ?>
                <h2>Nenhum jogo cadastrado.</h2>
                <p>Comece pelo cadastro de jogos.</p>
                <div class="actions"><a class="btn primary" href="admin_matches.php?tab=jogos">Cadastrar jogo</a></div>
            <?php else: ?>
                <h2>Nenhum jogo com esse filtro.</h2>
                <p>Tente outra liga ou rodada.</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="admin-section" aria-labelledby="sec-aguardando">
        <h2 class="section-title" id="sec-aguardando">Aguardando placar</h2>
        <?php if (!$waiting): ?>
            <p class="empty-line">Nenhum jogo esperando placar. Tudo em dia.</p>
        <?php else: ?>
            <p class="meta"><?= e(plural(count($waiting), 'jogo esperando placar', 'jogos esperando placar')) ?>. Do mais antigo para o mais recente.</p>
            <ol class="admin-list">
                <?php foreach ($waiting as $m) { admin_row($m, $filtersParams); } ?>
            </ol>
        <?php endif; ?>
    </section>

    <?php if ($upcoming): ?>
    <section class="admin-section" aria-labelledby="sec-proximos">
        <h2 class="section-title" id="sec-proximos">Próximos jogos</h2>
        <ol class="admin-list">
            <?php foreach (array_slice($upcoming, 0, 50) as $m) { admin_row($m, $filtersParams); } ?>
        </ol>
    </section>
    <?php endif; ?>

    <?php if ($finished): ?>
    <section class="admin-section" aria-labelledby="sec-encerrados">
        <h2 class="section-title" id="sec-encerrados">Encerrados</h2>
        <p class="meta">Os 50 mais recentes. Salvar de novo recalcula os pontos.</p>
        <ol class="admin-list">
            <?php foreach (array_slice($finished, 0, 50) as $m) { admin_row($m, $filtersParams); } ?>
        </ol>
    </section>
    <?php endif; ?>

<?php elseif ($tab === 'jogos'):
    $matches = db()->query(
        'SELECT m.*, l.name AS league_name, ' . team_columns_sql() . '
         FROM matches m
         JOIN leagues l ON l.id = m.league_id
         ' . team_joins_sql() . '
         ORDER BY m.kickoff_at DESC, m.id DESC
         LIMIT 500'
    )->fetchAll();
    $syncState = sync_state();
    ?>
    <section class="panel stack sync-panel" aria-labelledby="sec-sync">
        <h2 class="section-title" id="sec-sync">Jogos automáticos</h2>
        <p class="help">Jogos e placares das ligas que estão em algum bolão vêm da ESPN e se atualizam sozinhos: os jogos do momento a cada 5 minutos (a cada minuto com jogo ao vivo) e a tabela de cada liga a cada 6 horas. Uma liga recém-escolhida recebe aos poucos os jogos desde o começo da temporada. As rodadas oficiais das principais ligas vêm do TheSportsDB; nas demais, são estimadas pelas datas.</p>
        <?php if (!function_exists('litespeed_finish_request') && !function_exists('fastcgi_finish_request')): ?>
            <p class="help">Este servidor não libera a página antes de sincronizar, então só a parte rápida roda sozinha. Use o botão abaixo para a tabela completa.</p>
        <?php endif; ?>
        <p class="meta">
            <?php if (!empty($syncState['last_quick'])): ?>
                Última atualização: <?= e(date('d/m', (int) $syncState['last_quick']) . ' às ' . kick_clock((int) $syncState['last_quick'])) ?>.
            <?php else: ?>
                Ainda não sincronizou.
            <?php endif; ?>
            <?php if (!empty($syncState['last_full'])): ?>
                Tabela completa: <?= e(date('d/m', (int) $syncState['last_full']) . ' às ' . kick_clock((int) $syncState['last_full'])) ?>.
            <?php endif; ?>
        </p>
        <?php if (!empty($syncState['last_error'])): ?>
            <?php alert_box(['Último erro: ' . $syncState['last_error']]); ?>
        <?php endif; ?>
        <?php
        $synced = db()->query(
            'SELECT l.id, l.name, l.synced_quick_at, l.synced_full_at, l.sync_failures, l.backfill_day,
                    (SELECT COUNT(*) FROM matches m WHERE m.league_id = l.id) AS games
             FROM leagues l
             WHERE EXISTS (SELECT 1 FROM group_leagues gl WHERE gl.league_id = l.id)
             ORDER BY l.sort_order, l.name'
        )->fetchAll();
        $when = fn (int $ts) => $ts ? date('d/m', $ts) . ' ' . kick_clock($ts) : 'nunca';
        ?>
        <?php if ($synced): ?>
            <div class="table-wrap">
                <table class="ranking sync-table">
                    <caption class="visually-hidden">Sincronização por liga</caption>
                    <thead><tr><th scope="col">Liga</th><th scope="col">Jogos</th><th scope="col">Rápida</th><th scope="col">Completa</th><th scope="col">Falhas</th></tr></thead>
                    <tbody>
                        <?php foreach ($synced as $l): ?>
                            <tr>
                                <th scope="row"><?= e($l['name']) ?></th>
                                <td><?= (int) $l['games'] ?></td>
                                <td><?= e($when((int) $l['synced_quick_at'])) ?></td>
                                <td><?= e($when((int) $l['synced_full_at'])) ?></td>
                                <td><?= (int) $l['sync_failures'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="meta">Nenhuma liga em uso ainda. A sincronização começa quando um bolão escolher uma liga.</p>
        <?php endif; ?>
        <form method="post" action="admin_matches.php?tab=jogos" class="actions">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="sync">
            <button type="submit" class="btn ghost" data-busy="Sincronizando…">Sincronizar agora</button>
        </form>
    </section>

    <details class="disclosure new-match"<?= $fieldErrors ? ' open' : '' ?>>
        <summary class="btn primary">Cadastrar jogo</summary>
        <div class="disclosure-body">
            <?php alert_box(array_values(array_filter($fieldErrors, function ($k) {
                return in_array($k, ['league_id', 'round', 'home_team', 'away_team', 'kickoff'], true);
            }, ARRAY_FILTER_USE_KEY))); ?>
            <form method="post" action="admin_matches.php?tab=jogos" class="stack">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_match">
                <div class="field<?= isset($fieldErrors['league_id']) ? ' is-invalid' : '' ?>">
                    <label for="m-liga">Liga</label>
                    <div class="control">
                        <select id="m-liga" name="league_id" required>
                            <?php foreach ($leagues as $league): ?>
                                <option value="<?= (int) $league['id'] ?>"<?= (int) ($old['league_id'] ?? 0) === (int) $league['id'] ? ' selected' : '' ?>><?= e($league['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if (isset($fieldErrors['league_id'])): ?><p class="field-error"><?= e($fieldErrors['league_id']) ?></p><?php endif; ?>
                </div>
                <div class="grid-2">
                    <?php field('round', 'Rodada', ['type' => 'number', 'min' => 1, 'max' => 999, 'inputmode' => 'numeric', 'required' => true,
                        'value' => $old['round'] ?? '', 'error' => $fieldErrors['round'] ?? null]); ?>
                    <?php field('kickoff', 'Data e horário (Brasília)', ['type' => 'datetime-local', 'required' => true,
                        'value' => $old['kickoff'] ?? '', 'error' => $fieldErrors['kickoff'] ?? null]); ?>
                </div>
                <div class="grid-2">
                    <?php field('home_team', 'Mandante', ['maxlength' => 100, 'required' => true,
                        'value' => $old['home_team'] ?? '', 'error' => $fieldErrors['home_team'] ?? null]); ?>
                    <?php field('away_team', 'Visitante', ['maxlength' => 100, 'required' => true,
                        'value' => $old['away_team'] ?? '', 'error' => $fieldErrors['away_team'] ?? null]); ?>
                </div>
                <div class="actions"><button type="submit" class="btn primary" data-busy="Cadastrando…">Cadastrar jogo</button></div>
            </form>
        </div>
    </details>

    <section class="admin-section">
        <h2 class="section-title">Jogos cadastrados</h2>
        <?php if (!$matches): ?>
            <p class="empty-line">Nenhum jogo cadastrado. Comece pelo cadastro de jogos.</p>
        <?php else: ?>
            <p class="meta">Os 500 mais recentes. Para lançar um placar, use a aba Lançar resultados.</p>
            <ul class="list">
                <?php foreach ($matches as $m):
                    $ts = strtotime($m['kickoff_at']); ?>
                    <li class="list-row">
                        <span class="league"><?= e($m['league_name']) ?>, <?= e(mb_strtolower(round_label($m))) ?><?= is_void($m) ? ' · adiado ou cancelado' : '' ?></span>
                        <span class="list-teams"><?= team_badge($m, 'home', 'sm') ?><?= e($m['home_team']) ?> <span class="vs">×</span> <?= team_badge($m, 'away', 'sm') ?><?= e($m['away_team']) ?></span>
                        <span class="list-meta"><?= e(date('d/m', $ts) . ' às ' . kick_clock($ts)) ?></span>
                        <span class="list-meta"><?= $m['home_score'] !== null
                            ? 'Placar ' . (int) $m['home_score'] . ' × ' . (int) $m['away_score']
                            : 'Sem resultado' ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

<?php elseif ($tab === 'importar'):
    $importLeagueSel = (int) ($old['league_id'] ?? 0);
    ?>
    <section class="panel stack import-form">
        <h2 class="section-title">Importar jogos em lote</h2>
        <p class="help">Uma linha por jogo, com 4 colunas separadas por ponto e vírgula (ou tabulação, ao colar de uma planilha):
            <strong>rodada; data e hora; mandante; visitante</strong>. Horário de Brasília.</p>
        <pre class="import-example">1; 25/10/2026 16:00; Flamengo; Palmeiras
1; 25/10/2026 18h30; Corinthians; São Paulo
2; 01/11 16h; Palmeiras; Corinthians</pre>
        <p class="help">Antes de gravar, você confere cada linha. Jogos já cadastrados na mesma liga e rodada são ignorados.</p>
        <?php alert_box(array_values(array_filter($fieldErrors, function ($k) {
            return in_array($k, ['league_id', 'import_text'], true);
        }, ARRAY_FILTER_USE_KEY))); ?>
        <form method="post" action="admin_matches.php?tab=importar" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="import_preview">
            <div class="field<?= isset($fieldErrors['league_id']) ? ' is-invalid' : '' ?>">
                <label for="i-liga">Liga</label>
                <div class="control">
                    <select id="i-liga" name="league_id" required>
                        <?php foreach ($leagues as $league): ?>
                            <option value="<?= (int) $league['id'] ?>"<?= $importLeagueSel === (int) $league['id'] ? ' selected' : '' ?>><?= e($league['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field<?= isset($fieldErrors['import_text']) ? ' is-invalid' : '' ?>">
                <label for="i-texto">Jogos</label>
                <div class="control">
                    <textarea id="i-texto" name="import_text" rows="10" required spellcheck="false"
                              placeholder="1; 25/10/2026 16:00; Flamengo; Palmeiras"><?= e((string) ($old['import_text'] ?? '')) ?></textarea>
                </div>
            </div>
            <div class="actions"><button type="submit" class="btn primary" data-busy="Conferindo…">Conferir linhas</button></div>
        </form>
    </section>

    <?php if ($importPreview):
        $ic = $importPreview['count'];
        $statusLabel = ['ok' => 'Pronto', 'skip' => 'Ignorado', 'error' => 'Erro'];
        ?>
        <section class="admin-section import-preview" aria-labelledby="sec-conferencia">
            <h2 class="section-title" id="sec-conferencia">Conferência</h2>
            <p class="meta">
                <?= e(plural($ic['ok'], 'jogo pronto', 'jogos prontos')) ?>,
                <?= e(plural($ic['skip'], 'ignorado', 'ignorados')) ?>,
                <?= e(plural($ic['error'], 'linha com erro', 'linhas com erro')) ?>.
            </p>
            <?php if ($ic['error'] > 0): ?>
                <div class="alert" role="alert"><p>Corrija as linhas com erro no texto acima e confira de novo. Nada foi gravado.</p></div>
            <?php endif; ?>
            <div class="table-wrap">
                <table class="import-table">
                    <thead>
                        <tr><th scope="col">Linha</th><th scope="col">Rodada</th><th scope="col">Data e hora</th><th scope="col">Jogo</th><th scope="col">Situação</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($importPreview['rows'] as $r): ?>
                        <tr class="is-<?= e($r['status']) ?>">
                            <th scope="row"><?= (int) $r['line'] ?></th>
                            <?php if ($r['status'] === 'error' && $r['round'] === null && $r['kickoff'] === null && $r['home'] === ''): ?>
                                <td colspan="3"><code><?= e(mb_strimwidth($r['raw'], 0, 120, '…')) ?></code></td>
                            <?php else: ?>
                                <td><?= $r['round'] !== null ? (int) $r['round'] : '–' ?></td>
                                <td><?= $r['kickoff'] !== null ? e(date('d/m/Y', strtotime($r['kickoff'])) . ' às ' . kick_clock(strtotime($r['kickoff']))) : '–' ?></td>
                                <td><?= e($r['home']) ?> × <?= e($r['away']) ?></td>
                            <?php endif; ?>
                            <td><strong><?= e($statusLabel[$r['status']]) ?></strong><?= $r['message'] !== '' ? ': ' . e($r['message']) : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($ic['error'] === 0 && $ic['ok'] > 0): ?>
                <form method="post" action="admin_matches.php?tab=importar" class="actions">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="import_save">
                    <input type="hidden" name="league_id" value="<?= (int) $old['league_id'] ?>">
                    <input type="hidden" name="import_text" value="<?= e((string) $old['import_text']) ?>">
                    <button type="submit" class="btn primary" data-busy="Gravando…">Gravar <?= e(plural($ic['ok'], 'jogo', 'jogos')) ?></button>
                </form>
            <?php elseif ($ic['error'] === 0): ?>
                <p class="empty-line">Nenhum jogo novo para gravar.</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

<?php elseif ($tab === 'usuarios'):
    $q = trim((string) ($_GET['q'] ?? ''));
    $adminReset = flash_take('admin_reset');
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $stmt = db()->prepare('SELECT id, name, email, created_at FROM users WHERE name LIKE ? OR email LIKE ? ORDER BY name LIMIT 50');
        $stmt->execute([$like, $like]);
    } else {
        $stmt = db()->query('SELECT id, name, email, created_at FROM users ORDER BY created_at DESC LIMIT 50');
    }
    $users = $stmt->fetchAll();
    ?>
    <?php if ($adminReset): ?>
        <section class="panel stack admin-reset-link" role="status">
            <h2 class="section-title">Link de redefinição gerado</h2>
            <p>Mande este link para <strong><?= e($adminReset['name']) ?></strong> (<?= e($adminReset['email']) ?>) por um canal seguro. Vale por 24 horas e funciona uma vez só. Ele não aparece de novo.</p>
            <div class="copy-box">
                <label class="copy-label" for="admin-reset-url">Link</label>
                <div class="copy-row">
                    <input id="admin-reset-url" type="text" readonly value="<?= e($adminReset['url']) ?>" data-select-on-focus>
                </div>
                <div class="copy-actions">
                    <button type="button" class="btn ghost small" data-copy="admin-reset-url"><?= icon('copy') ?>Copiar link</button>
                    <span class="copy-status" role="status" data-copy-status></span>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <form method="get" action="admin_matches.php" class="filters">
        <input type="hidden" name="tab" value="usuarios">
        <div class="field">
            <label for="f-q">Buscar por nome ou e-mail</label>
            <div class="control">
                <input id="f-q" type="search" name="q" maxlength="150" value="<?= e($q) ?>">
            </div>
        </div>
        <div class="actions">
            <button type="submit" class="btn ghost">Buscar</button>
            <?php if ($q !== ''): ?>
                <a class="btn ghost" href="admin_matches.php?tab=usuarios">Limpar</a>
            <?php endif; ?>
        </div>
    </form>

    <section class="admin-section">
        <h2 class="section-title">Usuários</h2>
        <p class="meta"><?= $q !== '' ? 'Resultados da busca (até 50).' : 'Os 50 cadastros mais recentes.' ?> Se o e-mail de "Esqueci minha senha" não chegar, gere aqui um link de redefinição.</p>
        <?php if (!$users): ?>
            <p class="empty-line">Ninguém encontrado.</p>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($users as $u): ?>
                    <li class="list-row user-row">
                        <span class="list-teams"><?= e($u['name']) ?></span>
                        <span class="list-meta"><?= e($u['email']) ?></span>
                        <span class="list-meta">Desde <?= e(date('d/m/Y', strtotime($u['created_at']))) ?></span>
                        <form method="post" action="admin_matches.php?tab=usuarios">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reset_link">
                            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                            <button type="submit" class="btn ghost small" data-busy="Gerando…">Gerar link de nova senha</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

<?php else: ?>
    <section class="admin-section">
        <h2 class="section-title">Ligas</h2>
        <?php if (!$leagues): ?>
            <p class="empty-line">Nenhuma liga cadastrada.</p>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($leagues as $league): ?>
                    <li class="list-row" id="liga-<?= (int) $league['id'] ?>">
                        <span class="list-teams"><?= country_flag_svg((string) $league['country']) ?> <?= e($league['name']) ?><?= (int) $league['is_active'] ? '' : ' <span class="tag is-off">desativada</span>' ?></span>
                        <span class="list-meta"><?= e(country_name((string) $league['country'])) ?> · <?= $league['kind'] === 'copa' ? 'Copa' : 'Liga' ?> · Código <?= e($league['code']) ?><?= $league['external_id'] ? ' · ESPN ' . e($league['external_id']) : ' · sem sincronização' ?></span>
                        <form method="post" action="admin_matches.php?tab=ligas" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle_league">
                            <input type="hidden" name="league_id" value="<?= (int) $league['id'] ?>">
                            <input type="hidden" name="active" value="<?= (int) $league['is_active'] ? 0 : 1 ?>">
                            <button type="submit" class="btn ghost small"><?= (int) $league['is_active'] ? 'Desativar' : 'Ativar' ?></button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="panel stack">
        <h2 class="section-title">Nova liga</h2>
        <form method="post" action="admin_matches.php?tab=ligas" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_league">
            <?php field('league_name', 'Nome', ['placeholder' => 'Ex.: Copa do Brasil', 'maxlength' => 100, 'required' => true,
                'value' => $old['league_name'] ?? '', 'error' => $fieldErrors['league_name'] ?? null]); ?>
            <?php field('league_code', 'Código', ['placeholder' => 'Ex.: BRA-CDB', 'maxlength' => 20, 'required' => true,
                'value' => $old['league_code'] ?? '', 'help' => 'Único. Não pode repetir o de outra liga.',
                'error' => $fieldErrors['league_code'] ?? null]); ?>
            <div class="actions"><button type="submit" class="btn primary" data-busy="Cadastrando…">Cadastrar liga</button></div>
        </form>
    </section>
<?php endif;

page_end();

/** Uma linha de lançamento: times, horário e o formulário de placar. */
function admin_row(array $m, array $filters): void
{
    $id = (int) $m['id'];
    $ts = strtotime($m['kickoff_at']);
    $finished = $m['home_score'] !== null;
    $hidden = '';
    foreach ($filters as $k => $v) {
        if ($v !== null) {
            $hidden .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">';
        }
    }
    ?>
    <li class="admin-row" id="jogo-<?= $id ?>">
        <div class="admin-info">
            <span class="league"><?= e($m['league_name']) ?>, <?= e(mb_strtolower(round_label($m))) ?><?= is_void($m) ? ' · adiado ou cancelado' : '' ?></span>
            <p class="admin-teams"><?= team_badge($m, 'home', 'sm') ?><?= e($m['home_team']) ?> <span class="vs" aria-hidden="true">×</span> <?= team_badge($m, 'away', 'sm') ?><?= e($m['away_team']) ?></p>
            <span class="list-meta"><?= e(date('d/m', $ts) . ' às ' . kick_clock($ts)) ?></span>
        </div>

        <form method="post" action="admin_matches.php" class="score-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="score">
            <input type="hidden" name="match_id" value="<?= $id ?>">
            <?= $hidden ?>
            <span class="score">
                <label class="visually-hidden" for="s-h-<?= $id ?>">Gols de <?= e($m['home_team']) ?></label>
                <input id="s-h-<?= $id ?>" class="tile small" type="number" name="home_score" min="0" max="30" inputmode="numeric"
                       enterkeyhint="next" required value="<?= $finished ? (int) $m['home_score'] : '' ?>">
                <span class="colon" aria-hidden="true">:</span>
                <label class="visually-hidden" for="s-a-<?= $id ?>">Gols de <?= e($m['away_team']) ?></label>
                <input id="s-a-<?= $id ?>" class="tile small" type="number" name="away_score" min="0" max="30" inputmode="numeric"
                       enterkeyhint="done" required value="<?= $finished ? (int) $m['away_score'] : '' ?>">
            </span>
            <button type="submit" class="btn primary small" data-busy="Salvando…">Salvar</button>
        </form>

        <?php if ($finished): ?>
            <details class="disclosure confirm admin-clear">
                <summary class="btn ghost small">Apagar resultado</summary>
                <div class="disclosure-body">
                    <p><strong><?= e($m['home_team']) ?> × <?= e($m['away_team']) ?></strong>: os pontos deste jogo serão zerados para todos os palpites.</p>
                    <form method="post" action="admin_matches.php" class="confirm-actions">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="clear_score">
                        <input type="hidden" name="match_id" value="<?= $id ?>">
                        <?= $hidden ?>
                        <button type="submit" class="btn danger" data-busy="Apagando…">Apagar resultado</button>
                        <a class="btn ghost" href="<?= e($_SERVER['REQUEST_URI'] ?? 'admin_matches.php') ?>" data-cancel>Cancelar</a>
                    </form>
                </div>
            </details>
        <?php endif; ?>
    </li>
    <?php
}
