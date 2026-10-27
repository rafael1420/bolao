<?php
// Partes da tela do bolão (cabeçalho e cada aba). Carregadas por group.php.

function tab_href(int $groupId, string $tab, string $extra = ''): string
{
    return 'group.php?id=' . $groupId . '&amp;tab=' . $tab . $extra;
}

/** Endereço da própria página, para o link "Cancelar" dos disclosures (funciona sem JS). */
function self_href(): string
{
    return $_SERVER['REQUEST_URI'] ?? 'group.php';
}

function copy_box(string $id, string $label, string $value, string $shareId = ''): void
{
    ?>
    <div class="copy-box">
        <label class="copy-label" for="<?= e($id) ?>"><?= e($label) ?></label>
        <div class="copy-row">
            <input id="<?= e($id) ?>" type="text" readonly value="<?= e($value) ?>" data-select-on-focus>
        </div>
        <div class="copy-actions">
            <button type="button" class="btn ghost small" data-copy="<?= e($id) ?>"><?= icon('copy') ?>Copiar link</button>
            <button type="button" class="btn ghost small" data-share="<?= e($id) ?>" hidden><?= icon('share') ?>Compartilhar</button>
            <span class="copy-status" role="status" data-copy-status></span>
        </div>
    </div>
    <?php
}

/** Cabeçalho do bolão: caminho, nome, resumo, convite e abas. */
function group_header(array $c): void
{
    $group = $c['group'];
    $gid = $c['groupId'];
    $me = $c['me'];
    ?>
    <nav class="crumbs" aria-label="Você está em">
        <a href="index.php">Meus bolões</a>
        <span class="crumb-sep" aria-hidden="true">›</span>
        <span aria-current="page"><?= e($group['name']) ?></span>
    </nav>
    <a class="back" href="index.php"><?= icon('arrow-left') ?>Meus bolões</a>

    <header class="group-head">
        <div class="group-title">
            <h1 class="h-page"><?= e($group['name']) ?></h1>
            <p class="meta"><?= e(implode(', ', $c['leagueNames'])) ?></p>
            <p class="meta"><?= e(plural($c['memberCount'], 'participante', 'participantes')) ?><?php if ($me): ?>, você em <?= (int) $me['pos'] ?>º com <?= (int) $me['total'] ?> pontos<?php endif; ?></p>
        </div>

        <details class="disclosure invite">
            <summary class="btn ghost"><?= icon('share') ?>Convidar</summary>
            <div class="disclosure-body">
                <p>Mande o link ou o código para quem você quer no bolão.</p>
                <?php copy_box('invite-link', 'Link para os amigos', $c['inviteUrl']); ?>
                <p class="meta">Código: <strong class="code-text"><?= e($group['invite_code']) ?></strong></p>
                <p class="meta"><?= $group['password_hash'] !== null ? 'Este bolão pede senha para entrar.' : 'Este bolão não pede senha.' ?></p>
            </div>
        </details>
    </header>

    <nav class="tabs" aria-label="Seções do bolão">
        <?php foreach ($c['tabs'] as $key => $label): ?>
            <a class="tab" href="<?= tab_href($gid, $key) ?>"<?= $c['tab'] === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php
}

/** Texto do chip de rodada, com o estado para leitor de tela. */
function round_state(array $stat): array
{
    if ($stat['finished'] === $stat['total']) {
        return ['done', 'encerrada'];
    }
    if ($stat['pending'] > 0) {
        return ['pending', 'faltam ' . plural($stat['pending'], 'palpite', 'palpites')];
    }
    if ($stat['open'] > 0) {
        return ['ready', 'palpites em dia'];
    }
    return ['wait', 'aguardando resultados'];
}

/** Aba Palpites: rodada, faixa de rodadas, jogos e barra de salvar. */
function render_palpites(array $c): void
{
    $gid = $c['groupId'];
    $round = $c['round'];
    $now = $c['now'];
    $matches = $c['matches'];
    $mine = $c['mine'];
    $pf = $c['predictFlash'];
    $user = $c['user'];

    $openMatches = array_values(array_filter($matches, 'is_open'));
    $openCount = count($openMatches);
    $filled = 0;
    foreach ($openMatches as $m) {
        if (isset($mine[(int) $m['id']])) {
            $filled++;
        }
    }
    $firstOpen = $openCount ? strtotime($openMatches[0]['kickoff_at']) : null;

    $idx = array_search($round, $c['rounds'], true);
    $prevRound = ($idx !== false && $idx > 0) ? $c['rounds'][$idx - 1] : null;
    $nextRound = ($idx !== false && $idx < count($c['rounds']) - 1) ? $c['rounds'][$idx + 1] : null;

    if ($c['welcome']) {
        ?>
        <section class="panel welcome" aria-labelledby="welcome-title">
            <h2 id="welcome-title">Bolão criado. Mande o convite para a galera.</h2>
            <?php copy_box('welcome-link', 'Link do bolão', $c['inviteUrl']); ?>
            <p class="meta">Código: <strong class="code-text"><?= e($c['group']['invite_code']) ?></strong></p>
            <div class="actions">
                <a class="btn primary" href="#rodada">Dar meu primeiro palpite</a>
            </div>
        </section>
        <?php
    }
    ?>

    <?php if ($c['rounds']): ?>
    <nav class="rounds" aria-label="Rodadas">
        <?php if ($prevRound): ?>
            <a class="round-step" href="<?= tab_href($gid, 'palpites', '&amp;round=' . $prevRound) ?>" aria-label="Rodada anterior, rodada <?= $prevRound ?>"><?= icon('chevron-l') ?></a>
        <?php else: ?>
            <span class="round-step is-off" aria-hidden="true"><?= icon('chevron-l') ?></span>
        <?php endif; ?>

        <ol class="round-strip" data-current-scroll>
            <?php foreach ($c['rounds'] as $r):
                $stat = $c['roundStats'][$r];
                [$state, $stateText] = round_state($stat);
                $isCurrent = $r === $round;
            ?>
                <li>
                    <a class="chip is-<?= e($state) ?>" href="<?= tab_href($gid, 'palpites', '&amp;round=' . $r) ?>"<?= $isCurrent ? ' aria-current="page" data-current' : '' ?>>
                        <span class="chip-num"><?= (int) $r ?></span>
                        <span class="visually-hidden">, rodada <?= (int) $r ?>, <?= e($stateText) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ol>

        <?php if ($nextRound): ?>
            <a class="round-step" href="<?= tab_href($gid, 'palpites', '&amp;round=' . $nextRound) ?>" aria-label="Próxima rodada, rodada <?= $nextRound ?>"><?= icon('chevron-r') ?></a>
        <?php else: ?>
            <span class="round-step is-off" aria-hidden="true"><?= icon('chevron-r') ?></span>
        <?php endif; ?>
    </nav>
    <?php endif; ?>

    <section class="round-head" id="rodada">
        <h2 class="round-title">Rodada <?= (int) $round ?></h2>
        <?php if (!$matches): ?>
            <p class="meta"><?= is_admin($user)
                ? 'Esta rodada ainda não tem jogos cadastrados. Cadastre-os no menu Administração.'
                : 'A tabela desta rodada ainda não saiu. Tente outra rodada.' ?></p>
        <?php elseif ($openCount): ?>
            <p class="meta">
                <?= e(plural($openCount, 'jogo aberto', 'jogos abertos')) ?>.
                Primeiro fechamento:
                <time data-kickoff data-first datetime="<?= e(date('c', $firstOpen)) ?>"><?= e(kickoff_phrase($firstOpen, $now, true)) ?></time>
            </p>
        <?php else: ?>
            <p class="meta">Rodada encerrada para palpites.
                <a href="<?= tab_href($gid, 'ranking') ?>">Ver o ranking</a>
            </p>
        <?php endif; ?>
    </section>

    <?php if ($pf): ?>
        <?php if ($pf['skipped']): ?>
            <div class="alert" role="alert">
                <p>
                    <?= $pf['saved'] > 0
                        ? e('Salvamos ' . plural($pf['saved'], 'palpite', 'palpites') . '.')
                        : 'Nenhum palpite foi salvo.' ?>
                    <?= e(plural(count($pf['skipped']), 'ficou de fora', 'ficaram de fora')) ?>. Veja abaixo.
                </p>
                <ul class="skipped">
                    <?php foreach ($pf['skipped'] as $s): ?>
                        <li><a href="#jogo-<?= (int) $s['id'] ?>"><?= e($s['label']) ?></a>: <?= e($s['reason']) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php elseif ($pf['saved'] > 0): ?>
            <div class="notice" role="status">
                <p>Palpites salvos às <?= e($pf['time']) ?>. Dá para mudar até cada jogo começar.</p>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!$matches): ?>
        <section class="panel empty-state"><p>Nenhum jogo nesta rodada.</p></section>
        <?php return; ?>
    <?php endif; ?>

    <form method="post" action="predict.php" class="matches-form" data-dirty-guard>
        <?= csrf_field() ?>
        <input type="hidden" name="group_id" value="<?= (int) $gid ?>">
        <input type="hidden" name="round" value="<?= (int) $round ?>">

        <ol class="matches">
        <?php foreach ($matches as $m):
            $id = (int) $m['id'];
            $pred = $mine[$id] ?? null;
            $open = is_open($m);
            $finished = $m['home_score'] !== null;
            $kick = strtotime($m['kickoff_at']);
            $keep = ($pf && isset($pf['keep'][$id])) ? $pf['keep'][$id] : null;
            $skipReason = null;
            if ($pf) {
                foreach ($pf['skipped'] as $s) {
                    if ((int) $s['id'] === $id) {
                        $skipReason = $s['reason'];
                    }
                }
            }
            $homeVal = $keep ? $keep['home'] : ($pred ? (string) (int) $pred['home_pred'] : '');
            $awayVal = $keep ? $keep['away'] : ($pred ? (string) (int) $pred['away_pred'] : '');
            $pts = $pred ? (int) ($pred['points'] ?? 0) : 0;
            $reason = ($pred && $finished)
                ? points_reason((int) $pred['home_pred'], (int) $pred['away_pred'], (int) $m['home_score'], (int) $m['away_score'])
                : '';
        ?>
            <li class="match <?= $open ? 'is-open' : 'is-closed' ?>" id="jogo-<?= $id ?>"
                data-kick="<?= $kick ?>" data-open="<?= $open ? '1' : '0' ?>">
                <div class="match-top">
                    <span class="league"><?= e($m['league_name']) ?></span>
                    <?php if ($open): ?>
                        <?php if ($skipReason): ?>
                            <span class="badge is-warn"><?= icon('alert') ?>Não salvo</span>
                        <?php elseif ($pred): ?>
                            <span class="badge is-saved"><?= icon('check') ?>Palpite salvo</span>
                        <?php else: ?>
                            <span class="badge is-missing">Falta palpitar</span>
                        <?php endif; ?>
                    <?php elseif (!$pred && !$finished): ?>
                        <span class="badge is-muted">Sem palpite</span>
                    <?php endif; ?>
                </div>

                <div class="board">
                    <?php if ($open): ?>
                        <label class="team" for="h-<?= $id ?>"><?= e($m['home_team']) ?></label>
                    <?php else: ?>
                        <span class="team"><?= e($m['home_team']) ?></span>
                    <?php endif; ?>
                    <span class="board-gap" aria-hidden="true"></span>
                    <?php if ($open): ?>
                        <label class="team" for="a-<?= $id ?>"><?= e($m['away_team']) ?></label>
                    <?php else: ?>
                        <span class="team"><?= e($m['away_team']) ?></span>
                    <?php endif; ?>

                    <?php if ($open): ?>
                        <div class="tile-set">
                            <button type="button" class="step" data-step="-1" data-target="h-<?= $id ?>" hidden aria-label="Diminuir o placar de <?= e($m['home_team']) ?>"><?= icon('minus') ?></button>
                            <input id="h-<?= $id ?>" class="tile" type="number" inputmode="numeric" min="0" max="30" step="1"
                                   enterkeyhint="next" autocomplete="off"
                                   name="preds[<?= $id ?>][home]" value="<?= e($homeVal) ?>">
                            <button type="button" class="step" data-step="1" data-target="h-<?= $id ?>" hidden aria-label="Aumentar o placar de <?= e($m['home_team']) ?>"><?= icon('plus') ?></button>
                        </div>
                        <span class="colon" aria-hidden="true">:</span>
                        <div class="tile-set">
                            <button type="button" class="step" data-step="-1" data-target="a-<?= $id ?>" hidden aria-label="Diminuir o placar de <?= e($m['away_team']) ?>"><?= icon('minus') ?></button>
                            <input id="a-<?= $id ?>" class="tile" type="number" inputmode="numeric" min="0" max="30" step="1"
                                   enterkeyhint="next" autocomplete="off"
                                   name="preds[<?= $id ?>][away]" value="<?= e($awayVal) ?>">
                            <button type="button" class="step" data-step="1" data-target="a-<?= $id ?>" hidden aria-label="Aumentar o placar de <?= e($m['away_team']) ?>"><?= icon('plus') ?></button>
                        </div>
                    <?php elseif ($finished): ?>
                        <span class="tile is-official"><?= (int) $m['home_score'] ?></span>
                        <span class="colon" aria-hidden="true">:</span>
                        <span class="tile is-official"><?= (int) $m['away_score'] ?></span>
                    <?php elseif ($pred): ?>
                        <span class="tile is-locked"><?= (int) $pred['home_pred'] ?></span>
                        <span class="colon" aria-hidden="true">:</span>
                        <span class="tile is-locked"><?= (int) $pred['away_pred'] ?></span>
                    <?php else: ?>
                        <span class="tile is-empty" aria-label="Sem palpite">–</span>
                        <span class="colon" aria-hidden="true">:</span>
                        <span class="tile is-empty" aria-label="Sem palpite">–</span>
                    <?php endif; ?>
                </div>

                <div class="match-foot">
                    <?php if ($open): ?>
                        <p class="status">
                            <?= icon('clock') ?>
                            <?php if ($skipReason): ?>
                                <span class="status-text is-warn"><?= e('Não salvo: ' . $skipReason) ?></span>
                            <?php else: ?>
                                <time data-kickoff datetime="<?= e(date('c', $kick)) ?>" class="countdown"><?= e(kickoff_phrase($kick, $now)) ?></time>
                            <?php endif; ?>
                        </p>
                    <?php elseif ($finished): ?>
                        <p class="status">
                            <span>Resultado final</span>
                            <?php if ($pred): ?>
                                <span class="your-pick">Seu palpite: <?= (int) $pred['home_pred'] ?> × <?= (int) $pred['away_pred'] ?></span>
                            <?php else: ?>
                                <span class="your-pick">Você não palpitou</span>
                            <?php endif; ?>
                        </p>
                        <p class="points-badge<?= $pts > 0 ? ' is-scored' : '' ?>">
                            <strong><?= $pts ?></strong> <?= $pts === 1 ? 'ponto' : 'pontos' ?>
                            <span class="reason"><?= e($pred ? $reason : 'Sem palpite') ?></span>
                        </p>
                    <?php else: ?>
                        <p class="status">
                            <?= icon('lock') ?>
                            <span>Fechou às <?= e(kick_clock($kick)) ?></span>
                            <span class="status-text"><?= $pred ? 'Aguardando o resultado' : 'Você não palpitou' ?></span>
                        </p>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
        </ol>

        <?php if ($openCount): ?>
            <div class="save-bar" data-save-bar>
                <p class="save-count">
                    <span data-filled><?= (int) $filled ?></span> de <span data-total><?= (int) $openCount ?></span> preenchidos
                    <span class="badge is-dirty" data-dirty hidden>Não salvo</span>
                </p>
                <p class="save-status" aria-live="polite" data-save-status>
                    <?= $pf && !$pf['skipped'] && $pf['saved'] > 0
                        ? e('Palpites salvos às ' . $pf['time'] . '. Dá para mudar até cada jogo começar.')
                        : 'Dá para mudar até cada jogo começar.' ?>
                </p>
                <button type="submit" class="btn primary" data-busy="Salvando…">Salvar palpites</button>
            </div>
        <?php endif; ?>
    </form>
    <?php
}

/** Aba Ranking: minha posição e a tabela com empates na mesma posição. */
function render_ranking(array $c): void
{
    $me = $c['me'];
    $above = $c['above'];
    ?>
    <h2 class="section-title">Ranking</h2>

    <?php if ($me): ?>
        <section class="panel standing" aria-live="polite">
            <p class="standing-line">
                <?php if ((int) $me['pos'] === 1): ?>
                    Você lidera com <?= (int) $me['total'] ?> pontos.
                <?php else: ?>
                    Você está em <?= (int) $me['pos'] ?>º, com <?= (int) $me['total'] ?> pontos.
                    <?php if ($above): ?>
                        Faltam <?= (int) ($above['total'] - $me['total']) ?> para o <?= (int) $above['pos'] ?>º.
                    <?php endif; ?>
                <?php endif; ?>
            </p>
        </section>
    <?php endif; ?>

    <p class="meta">Atualizado a cada resultado lançado. Quem empata divide a mesma posição.</p>

    <div class="table-wrap">
        <table class="ranking">
            <caption class="visually-hidden">Classificação do bolão</caption>
            <thead>
                <tr><th scope="col" class="col-pos">Posição</th><th scope="col">Participante</th><th scope="col" class="col-pts">Pontos</th></tr>
            </thead>
            <tbody>
            <?php foreach ($c['ranking'] as $row):
                $isMe = (int) $row['id'] === (int) $c['user']['id'];
            ?>
                <tr<?= $isMe ? ' class="is-me"' : '' ?>>
                    <th scope="row" class="col-pos"><?= (int) $row['pos'] ?>º</th>
                    <td><?= e($row['name']) ?><?= $isMe ? ' <span class="muted-small">(você)</span>' : '' ?></td>
                    <td class="col-pts"><?= (int) $row['total'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/** Aba Participantes: nome e pontos. Só o organizador vê o botão Remover. */
function render_participantes(array $c): void
{
    $gid = $c['groupId'];
    $ownerId = (int) $c['group']['owner_id'];
    $isOwner = $c['isOwner'];
    ?>
    <h2 class="section-title">Participantes (<?= (int) $c['memberCount'] ?>)</h2>

    <ul class="people">
        <?php foreach ($c['ranking'] as $row):
            $rowId = (int) $row['id'];
            $isOwnerRow = $rowId === $ownerId;
        ?>
            <li class="person">
                <div class="person-main">
                    <span class="person-name"><?= e($row['name']) ?></span>
                    <?php if ($isOwnerRow): ?><span class="tag">organiza</span><?php endif; ?>
                    <?php if ($rowId === (int) $c['user']['id']): ?><span class="muted-small">(você)</span><?php endif; ?>
                </div>
                <span class="person-points"><strong><?= (int) $row['total'] ?></strong> pontos</span>

                <?php if ($isOwner && !$isOwnerRow): ?>
                    <details class="disclosure confirm person-remove">
                        <summary class="btn ghost small">Remover</summary>
                        <div class="disclosure-body">
                            <p><strong>Remover <?= e($row['name']) ?>?</strong> Essa pessoa sai do bolão. Os palpites dela ficam guardados e voltam se ela entrar de novo.</p>
                            <form method="post" action="members.php" class="confirm-actions">
                                <?= csrf_field() ?>
                                <input type="hidden" name="group_id" value="<?= (int) $gid ?>">
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="user_id" value="<?= $rowId ?>">
                                <button type="submit" class="btn danger" data-busy="Removendo…">Remover participante</button>
                                <a class="btn ghost" href="<?= e(self_href()) ?>" data-cancel>Cancelar</a>
                            </form>
                        </div>
                    </details>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php
}

/** Mensagem de um bloco da aba Configurar (vinda do PRG). */
function config_block_notice(?array $flash, string $block): void
{
    if (!$flash || ($flash['block'] ?? '') !== $block) {
        return;
    }
    if (!empty($flash['errors'])) {
        alert_box($flash['errors']);
    } elseif (!empty($flash['ok'])) {
        notice($flash['ok']);
    }
}

function config_old(?array $flash, string $key, string $block, string $default): string
{
    if ($flash && ($flash['block'] ?? '') === $block && isset($flash['old'][$key])) {
        return (string) $flash['old'][$key];
    }
    return $default;
}

function config_error(?array $flash, string $block, string $key): ?string
{
    if ($flash && ($flash['block'] ?? '') === $block && isset($flash['field_errors'][$key])) {
        return $flash['field_errors'][$key];
    }
    return null;
}

/** Aviso de recálculo: conta os palpites que já têm pontos. */
function recalc_warning(array $scored): string
{
    if ($scored['preds'] === 0) {
        return 'Ainda não há jogos encerrados. Nada será recalculado.';
    }
    return 'Ao salvar, os pontos de ' . plural($scored['preds'], 'palpite', 'palpites')
        . ' de ' . plural($scored['matches'], 'jogo encerrado', 'jogos encerrados') . ' serão recalculados.';
}

/** Aba Configurar (só organizador). Cada bloco salva sozinho. */
function render_configurar(array $c): void
{
    $group = $c['group'];
    $gid = $c['groupId'];
    $flash = $c['configFlash'];
    $leagues = db()->query('SELECT id, name FROM leagues ORDER BY name')->fetchAll();
    $stmt = db()->prepare('SELECT league_id FROM group_leagues WHERE group_id = ?');
    $stmt->execute([$gid]);
    $currentLeagues = array_map('intval', array_column($stmt->fetchAll(), 'league_id'));
    if ($flash && ($flash['block'] ?? '') === 'ligas' && isset($flash['old']['leagues'])) {
        $currentLeagues = $flash['old']['leagues'];
    }
    $action = 'group_settings.php?id=' . $gid;
    $hasPassword = $group['password_hash'] !== null;
    ?>
    <h2 class="section-title">Configurar bolão</h2>

    <section class="block" id="bloco-nome">
        <h3>Dados do bolão</h3>
        <?php config_block_notice($flash, 'nome'); ?>
        <form method="post" action="<?= e($action) ?>" class="stack" data-dirty-guard>
            <?= csrf_field() ?>
            <input type="hidden" name="group_id" value="<?= (int) $gid ?>">
            <input type="hidden" name="action" value="name">
            <?php field('name', 'Nome do bolão', ['value' => config_old($flash, 'name', 'nome', $group['name']),
                'maxlength' => 100, 'required' => true, 'error' => config_error($flash, 'nome', 'name')]); ?>
            <div class="actions"><button type="submit" class="btn primary" data-busy="Salvando…">Salvar nome</button></div>
        </form>
    </section>

    <section class="block" id="bloco-entrada">
        <h3>Entrada</h3>
        <?php config_block_notice($flash, 'entrada'); ?>
        <p><?= $hasPassword
            ? 'Este bolão tem senha. Quem tiver o link e a senha entra.'
            : 'Este bolão não tem senha. Quem tiver o link entra direto.' ?></p>

        <details class="disclosure">
            <summary class="btn ghost"><?= $hasPassword ? 'Trocar senha' : 'Colocar senha' ?></summary>
            <div class="disclosure-body">
                <form method="post" action="<?= e($action) ?>" class="stack">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group_id" value="<?= (int) $gid ?>">
                    <input type="hidden" name="action" value="password">
                    <?php field('password', 'Nova senha', ['type' => 'password', 'autocomplete' => 'new-password', 'required' => true,
                        'help' => 'Mínimo de 4 caracteres.', 'error' => config_error($flash, 'entrada', 'password')]); ?>
                    <div class="actions"><button type="submit" class="btn primary" data-busy="Salvando…">Salvar nova senha</button></div>
                </form>
            </div>
        </details>

        <?php if ($hasPassword): ?>
            <details class="disclosure confirm">
                <summary class="btn ghost">Remover senha</summary>
                <div class="disclosure-body">
                    <p>Sem senha, quem tiver o link entra direto.</p>
                    <form method="post" action="<?= e($action) ?>" class="confirm-actions">
                        <?= csrf_field() ?>
                        <input type="hidden" name="group_id" value="<?= (int) $gid ?>">
                        <input type="hidden" name="action" value="remove_password">
                        <button type="submit" class="btn danger" data-busy="Removendo…">Remover senha</button>
                        <a class="btn ghost" href="<?= e(self_href()) ?>" data-cancel>Cancelar</a>
                    </form>
                </div>
            </details>
        <?php endif; ?>
    </section>

    <section class="block" id="bloco-ligas">
        <h3>Ligas</h3>
        <?php config_block_notice($flash, 'ligas'); ?>
        <form method="post" action="<?= e($action) ?>" class="stack" data-dirty-guard>
            <?= csrf_field() ?>
            <input type="hidden" name="group_id" value="<?= (int) $gid ?>">
            <input type="hidden" name="action" value="leagues">
            <fieldset class="fieldset">
                <legend>Ligas do bolão</legend>
                <?php foreach ($leagues as $league): ?>
                    <?php check_option('leagues[]', (string) $league['id'], $league['name'],
                        in_array((int) $league['id'], $currentLeagues, true), 'cfg-liga-' . $league['id']); ?>
                <?php endforeach; ?>
            </fieldset>
            <p class="help"><?= e(recalc_warning($c['scored'])) ?></p>
            <div class="actions"><button type="submit" class="btn primary" data-busy="Salvando…">Salvar ligas</button></div>
        </form>
    </section>

    <section class="block" id="bloco-pontos">
        <h3>Pontuação</h3>
        <?php config_block_notice($flash, 'pontos'); ?>
        <form method="post" action="<?= e($action) ?>" class="stack" data-dirty-guard>
            <?= csrf_field() ?>
            <input type="hidden" name="group_id" value="<?= (int) $gid ?>">
            <input type="hidden" name="action" value="points">
            <div class="grid3">
                <?php field('exact_points', 'Placar exato', ['type' => 'number', 'min' => 0, 'max' => 20, 'inputmode' => 'numeric', 'id' => 'cfg-exact',
                    'value' => config_old($flash, 'exact', 'pontos', (string) (int) $group['exact_points'])]); ?>
                <?php field('winner_points', 'Vencedor ou empate', ['type' => 'number', 'min' => 0, 'max' => 20, 'inputmode' => 'numeric', 'id' => 'cfg-winner',
                    'value' => config_old($flash, 'winner', 'pontos', (string) (int) $group['winner_points'])]); ?>
                <?php field('goal_diff_points', 'Saldo de gols', ['type' => 'number', 'min' => 0, 'max' => 20, 'inputmode' => 'numeric', 'id' => 'cfg-diff',
                    'value' => config_old($flash, 'diff', 'pontos', (string) (int) $group['goal_diff_points'])]); ?>
            </div>
            <p class="help example" data-points-example aria-live="polite"><?= e(points_example(['exact_points' => (int) $group['exact_points'], 'winner_points' => (int) $group['winner_points'], 'goal_diff_points' => (int) $group['goal_diff_points']])) ?></p>
            <p class="help"><?= e(recalc_warning($c['scored'])) ?></p>
            <div class="actions"><button type="submit" class="btn primary" data-busy="Salvando…">Salvar pontuação</button></div>
        </form>
    </section>

    <section class="block" id="bloco-convite">
        <h3>Convite</h3>
        <?php config_block_notice($flash, 'convite'); ?>
        <?php copy_box('cfg-invite-link', 'Link para os amigos', $c['inviteUrl']); ?>
        <p class="meta">Código atual: <strong class="code-text"><?= e($group['invite_code']) ?></strong></p>
        <details class="disclosure confirm">
            <summary class="btn ghost">Gerar novo código</summary>
            <div class="disclosure-body">
                <p>O link atual deixa de funcionar. Quem já está no bolão continua nele.</p>
                <form method="post" action="<?= e($action) ?>" class="confirm-actions">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group_id" value="<?= (int) $gid ?>">
                    <input type="hidden" name="action" value="new_code">
                    <button type="submit" class="btn danger" data-busy="Gerando…">Gerar novo código</button>
                    <a class="btn ghost" href="<?= e(self_href()) ?>" data-cancel>Cancelar</a>
                </form>
            </div>
        </details>
    </section>

    <section class="block danger-zone" id="bloco-perigo">
        <h3>Zona de perigo</h3>
        <?php config_block_notice($flash, 'perigo'); ?>
        <details class="disclosure confirm">
            <summary class="btn danger-outline">Excluir bolão</summary>
            <div class="disclosure-body">
                <p><strong>Excluir o bolão <?= e($group['name']) ?>?</strong> Somem os participantes, os palpites e o ranking. Não tem como desfazer.</p>
                <form method="post" action="<?= e($action) ?>" class="stack">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group_id" value="<?= (int) $gid ?>">
                    <input type="hidden" name="action" value="delete">
                    <?php field('confirm_name', 'Digite o nome do bolão para confirmar', ['autocomplete' => 'off', 'required' => true,
                        'help' => 'Exatamente como aparece no topo: ' . $group['name'] . '.', 'error' => config_error($flash, 'perigo', 'confirm_name')]); ?>
                    <div class="confirm-actions">
                        <button type="submit" class="btn danger" data-requires="<?= e($group['name']) ?>" data-busy="Excluindo…">Excluir bolão</button>
                        <a class="btn ghost" href="<?= e(self_href()) ?>" data-cancel>Cancelar</a>
                    </div>
                </form>
            </div>
        </details>
    </section>
    <?php
}

/** Aba Regras (membros): pontuação e ligas, em leitura, e sair do bolão. */
function render_regras(array $c): void
{
    $group = $c['group'];
    $gid = $c['groupId'];
    ?>
    <h2 class="section-title">Regras</h2>

    <section class="block">
        <h3>Pontuação</h3>
        <dl class="rules">
            <div><dt>Placar exato</dt><dd><?= (int) $group['exact_points'] ?> pontos</dd></div>
            <div><dt>Vencedor ou empate</dt><dd><?= (int) $group['winner_points'] ?> pontos</dd></div>
            <div><dt>Saldo de gols certo</dt><dd>+<?= (int) $group['goal_diff_points'] ?> pontos</dd></div>
        </dl>
        <p class="help">Os pontos de um palpite se somam. Quem acerta o placar exato não ganha os outros pontos.</p>
    </section>

    <section class="block">
        <h3>Ligas</h3>
        <p><?= e(implode(', ', $c['leagueNames'])) ?></p>
        <p class="help">Dá para palpitar até o início de cada jogo.</p>
    </section>

    <section class="block danger-zone">
        <h3>Sair do bolão</h3>
        <p>Você deixa de ver este bolão. Seus palpites ficam guardados se você voltar.</p>
        <details class="disclosure confirm">
            <summary class="btn danger-outline">Sair do bolão</summary>
            <div class="disclosure-body">
                <form method="post" action="leave_group.php" class="confirm-actions">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group_id" value="<?= (int) $gid ?>">
                    <button type="submit" class="btn danger" data-busy="Saindo…">Sair do bolão</button>
                    <a class="btn ghost" href="<?= e(self_href()) ?>" data-cancel>Cancelar</a>
                </form>
            </div>
        </details>
    </section>
    <?php
}
