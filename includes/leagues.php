<?php
// Catálogo de campeonatos por país ou região. Fonte única: a tabela leagues é preenchida a partir daqui
// (leagues_seed), e a sincronização usa o slug da ESPN gravado em leagues.external_id.

/** País ou região => [nome, ordem, destaque]. Sem destaque, fica dentro de "Mais países". */
const COUNTRIES = [
    'BRA'      => ['Brasil', 10, true],
    'CONMEBOL' => ['América do Sul', 20, true],
    'UEFA'     => ['Europa', 30, true],
    'FIFA'     => ['Seleções e Mundial', 40, true],
    'ENG'      => ['Inglaterra', 50, true],
    'ESP'      => ['Espanha', 60, true],
    'ITA'      => ['Itália', 70, true],
    'GER'      => ['Alemanha', 80, true],
    'FRA'      => ['França', 90, true],
    'POR'      => ['Portugal', 100, true],
    'NED'      => ['Holanda', 110, true],
    'ARG'      => ['Argentina', 120, true],
    'USA'      => ['Estados Unidos', 130, true],
    'MEX'      => ['México', 140, true],
    'CONCACAF' => ['América do Norte e Central', 150, true],
    'URU'      => ['Uruguai', 200, false],
    'COL'      => ['Colômbia', 210, false],
    'CHI'      => ['Chile', 220, false],
    'PAR'      => ['Paraguai', 230, false],
    'PER'      => ['Peru', 240, false],
    'ECU'      => ['Equador', 250, false],
    'SCO'      => ['Escócia', 300, false],
    'TUR'      => ['Turquia', 310, false],
    'BEL'      => ['Bélgica', 320, false],
    'KSA'      => ['Arábia Saudita', 330, false],
    'JPN'      => ['Japão', 340, false],
    'OUT'      => ['Outros', 900, false], // ligas cadastradas à mão na administração
];

/**
 * Campeonatos: código => slug da ESPN, nome, nome curto, país, tipo (liga ou copa) e ordem dentro do país.
 * 'tsdb' (opcional): [id no TheSportsDB, formato da temporada ('Y' = "2026", 'Y-Y' = "2026-2027"), nº de times],
 * para numerar as rodadas pela rodada oficial. Sem 'tsdb', as rodadas são estimadas pelas datas.
 */
const LEAGUE_CATALOG = [
    // Brasil
    'BRA-A'   => ['slug' => 'bra.1', 'name' => 'Brasileirão Série A', 'short' => 'Série A', 'country' => 'BRA', 'kind' => 'liga', 'sort' => 10, 'tsdb' => [4351, 'Y', 20]],
    'BRA-B'   => ['slug' => 'bra.2', 'name' => 'Brasileirão Série B', 'short' => 'Série B', 'country' => 'BRA', 'kind' => 'liga', 'sort' => 20],
    'BRA-C'   => ['slug' => 'bra.3', 'name' => 'Brasileirão Série C', 'short' => 'Série C', 'country' => 'BRA', 'kind' => 'liga', 'sort' => 30],
    'BRA-CDB' => ['slug' => 'bra.copa_do_brazil', 'name' => 'Copa do Brasil', 'short' => 'Copa do Brasil', 'country' => 'BRA', 'kind' => 'copa', 'sort' => 40],
    'BRA-NE'  => ['slug' => 'bra.copa_do_nordeste', 'name' => 'Copa do Nordeste', 'short' => 'Nordestão', 'country' => 'BRA', 'kind' => 'copa', 'sort' => 50],
    'BRA-SP'  => ['slug' => 'bra.camp.paulista', 'name' => 'Campeonato Paulista', 'short' => 'Paulistão', 'country' => 'BRA', 'kind' => 'liga', 'sort' => 60],
    'BRA-RJ'  => ['slug' => 'bra.camp.carioca', 'name' => 'Campeonato Carioca', 'short' => 'Carioca', 'country' => 'BRA', 'kind' => 'liga', 'sort' => 70],
    'BRA-MG'  => ['slug' => 'bra.camp.mineiro', 'name' => 'Campeonato Mineiro', 'short' => 'Mineiro', 'country' => 'BRA', 'kind' => 'liga', 'sort' => 80],
    'BRA-RS'  => ['slug' => 'bra.camp.gaucho', 'name' => 'Campeonato Gaúcho', 'short' => 'Gauchão', 'country' => 'BRA', 'kind' => 'liga', 'sort' => 90],
    // América do Sul
    'CONMEBOL-LIB' => ['slug' => 'conmebol.libertadores', 'name' => 'Libertadores', 'short' => 'Libertadores', 'country' => 'CONMEBOL', 'kind' => 'copa', 'sort' => 10],
    'CONMEBOL-SUD' => ['slug' => 'conmebol.sudamericana', 'name' => 'Copa Sul-Americana', 'short' => 'Sul-Americana', 'country' => 'CONMEBOL', 'kind' => 'copa', 'sort' => 20],
    'CONMEBOL-REC' => ['slug' => 'conmebol.recopa', 'name' => 'Recopa Sul-Americana', 'short' => 'Recopa', 'country' => 'CONMEBOL', 'kind' => 'copa', 'sort' => 30],
    // Europa
    'UEFA-UCL'  => ['slug' => 'uefa.champions', 'name' => 'Champions League', 'short' => 'Champions', 'country' => 'UEFA', 'kind' => 'copa', 'sort' => 10],
    'UEFA-UEL'  => ['slug' => 'uefa.europa', 'name' => 'Liga Europa', 'short' => 'Liga Europa', 'country' => 'UEFA', 'kind' => 'copa', 'sort' => 20],
    'UEFA-UECL' => ['slug' => 'uefa.europa.conf', 'name' => 'Liga Conferência', 'short' => 'Conferência', 'country' => 'UEFA', 'kind' => 'copa', 'sort' => 30],
    'UEFA-SUP'  => ['slug' => 'uefa.super_cup', 'name' => 'Supercopa da UEFA', 'short' => 'Supercopa', 'country' => 'UEFA', 'kind' => 'copa', 'sort' => 40],
    'UEFA-NL'   => ['slug' => 'uefa.nations', 'name' => 'Liga das Nações', 'short' => 'Liga das Nações', 'country' => 'UEFA', 'kind' => 'copa', 'sort' => 50],
    // Seleções e Mundial
    'FIFA-WC'     => ['slug' => 'fifa.world', 'name' => 'Copa do Mundo', 'short' => 'Copa do Mundo', 'country' => 'FIFA', 'kind' => 'copa', 'sort' => 10],
    'FIFA-CWC'    => ['slug' => 'fifa.cwc', 'name' => 'Mundial de Clubes', 'short' => 'Mundial', 'country' => 'FIFA', 'kind' => 'copa', 'sort' => 20],
    'FIFA-WCQ-SA' => ['slug' => 'fifa.worldq.conmebol', 'name' => 'Eliminatórias (América do Sul)', 'short' => 'Eliminatórias', 'country' => 'FIFA', 'kind' => 'copa', 'sort' => 30],
    'FIFA-WCQ-EU' => ['slug' => 'fifa.worldq.uefa', 'name' => 'Eliminatórias (Europa)', 'short' => 'Elim. Europa', 'country' => 'FIFA', 'kind' => 'copa', 'sort' => 40],
    'FIFA-AMI'    => ['slug' => 'fifa.friendly', 'name' => 'Amistosos internacionais', 'short' => 'Amistosos', 'country' => 'FIFA', 'kind' => 'liga', 'sort' => 50],
    // Inglaterra
    'ENG-1'   => ['slug' => 'eng.1', 'name' => 'Premier League', 'short' => 'Premier League', 'country' => 'ENG', 'kind' => 'liga', 'sort' => 10, 'tsdb' => [4328, 'Y-Y', 20]],
    'ENG-2'   => ['slug' => 'eng.2', 'name' => 'Championship', 'short' => 'Championship', 'country' => 'ENG', 'kind' => 'liga', 'sort' => 20],
    'ENG-3'   => ['slug' => 'eng.3', 'name' => 'League One', 'short' => 'League One', 'country' => 'ENG', 'kind' => 'liga', 'sort' => 30],
    'ENG-FA'  => ['slug' => 'eng.fa', 'name' => 'Copa da Inglaterra (FA Cup)', 'short' => 'FA Cup', 'country' => 'ENG', 'kind' => 'copa', 'sort' => 40],
    'ENG-EFL' => ['slug' => 'eng.league_cup', 'name' => 'Copa da Liga Inglesa', 'short' => 'Copa da Liga', 'country' => 'ENG', 'kind' => 'copa', 'sort' => 50],
    // Espanha
    'ESP-1'  => ['slug' => 'esp.1', 'name' => 'LaLiga', 'short' => 'LaLiga', 'country' => 'ESP', 'kind' => 'liga', 'sort' => 10, 'tsdb' => [4335, 'Y-Y', 20]],
    'ESP-2'  => ['slug' => 'esp.2', 'name' => 'LaLiga 2', 'short' => 'LaLiga 2', 'country' => 'ESP', 'kind' => 'liga', 'sort' => 20],
    'ESP-CR' => ['slug' => 'esp.copa_del_rey', 'name' => 'Copa do Rei', 'short' => 'Copa do Rei', 'country' => 'ESP', 'kind' => 'copa', 'sort' => 30],
    // Itália
    'ITA-1'  => ['slug' => 'ita.1', 'name' => 'Campeonato Italiano (Série A)', 'short' => 'Série A Italiana', 'country' => 'ITA', 'kind' => 'liga', 'sort' => 10, 'tsdb' => [4332, 'Y-Y', 20]],
    'ITA-2'  => ['slug' => 'ita.2', 'name' => 'Série B Italiana', 'short' => 'Série B Italiana', 'country' => 'ITA', 'kind' => 'liga', 'sort' => 20],
    'ITA-CI' => ['slug' => 'ita.coppa_italia', 'name' => 'Copa da Itália', 'short' => 'Copa da Itália', 'country' => 'ITA', 'kind' => 'copa', 'sort' => 30],
    // Alemanha
    'GER-1'   => ['slug' => 'ger.1', 'name' => 'Bundesliga', 'short' => 'Bundesliga', 'country' => 'GER', 'kind' => 'liga', 'sort' => 10, 'tsdb' => [4331, 'Y-Y', 18]],
    'GER-2'   => ['slug' => 'ger.2', 'name' => '2. Bundesliga', 'short' => '2. Bundesliga', 'country' => 'GER', 'kind' => 'liga', 'sort' => 20],
    'GER-CUP' => ['slug' => 'ger.dfb_pokal', 'name' => 'Copa da Alemanha', 'short' => 'Copa da Alemanha', 'country' => 'GER', 'kind' => 'copa', 'sort' => 30],
    // França
    'FRA-1'   => ['slug' => 'fra.1', 'name' => 'Ligue 1', 'short' => 'Ligue 1', 'country' => 'FRA', 'kind' => 'liga', 'sort' => 10, 'tsdb' => [4334, 'Y-Y', 18]],
    'FRA-2'   => ['slug' => 'fra.2', 'name' => 'Ligue 2', 'short' => 'Ligue 2', 'country' => 'FRA', 'kind' => 'liga', 'sort' => 20],
    'FRA-CUP' => ['slug' => 'fra.coupe_de_france', 'name' => 'Copa da França', 'short' => 'Copa da França', 'country' => 'FRA', 'kind' => 'copa', 'sort' => 30],
    // Portugal
    'POR-1'  => ['slug' => 'por.1', 'name' => 'Liga Portugal', 'short' => 'Liga Portugal', 'country' => 'POR', 'kind' => 'liga', 'sort' => 10, 'tsdb' => [4344, 'Y-Y', 18]],
    'POR-TP' => ['slug' => 'por.taca.portugal', 'name' => 'Taça de Portugal', 'short' => 'Taça de Portugal', 'country' => 'POR', 'kind' => 'copa', 'sort' => 20],
    // Holanda
    'NED-1'   => ['slug' => 'ned.1', 'name' => 'Eredivisie', 'short' => 'Eredivisie', 'country' => 'NED', 'kind' => 'liga', 'sort' => 10, 'tsdb' => [4337, 'Y-Y', 18]],
    'NED-CUP' => ['slug' => 'ned.cup', 'name' => 'Copa da Holanda', 'short' => 'Copa da Holanda', 'country' => 'NED', 'kind' => 'copa', 'sort' => 20],
    // Argentina
    'ARG-1'   => ['slug' => 'arg.1', 'name' => 'Liga Profissional Argentina', 'short' => 'Argentino', 'country' => 'ARG', 'kind' => 'liga', 'sort' => 10],
    'ARG-CUP' => ['slug' => 'arg.copa', 'name' => 'Copa Argentina', 'short' => 'Copa Argentina', 'country' => 'ARG', 'kind' => 'copa', 'sort' => 20],
    // América do Norte
    'USA-1'       => ['slug' => 'usa.1', 'name' => 'MLS', 'short' => 'MLS', 'country' => 'USA', 'kind' => 'liga', 'sort' => 10],
    'MEX-1'       => ['slug' => 'mex.1', 'name' => 'Liga MX', 'short' => 'Liga MX', 'country' => 'MEX', 'kind' => 'liga', 'sort' => 10],
    'CONCACAF-CC' => ['slug' => 'concacaf.champions', 'name' => 'Copa dos Campeões da Concacaf', 'short' => 'Concachampions', 'country' => 'CONCACAF', 'kind' => 'copa', 'sort' => 10],
    // Mais países
    'URU-1' => ['slug' => 'uru.1', 'name' => 'Campeonato Uruguaio', 'short' => 'Uruguaio', 'country' => 'URU', 'kind' => 'liga', 'sort' => 10],
    'COL-1' => ['slug' => 'col.1', 'name' => 'Campeonato Colombiano', 'short' => 'Colombiano', 'country' => 'COL', 'kind' => 'liga', 'sort' => 10],
    'CHI-1' => ['slug' => 'chi.1', 'name' => 'Campeonato Chileno', 'short' => 'Chileno', 'country' => 'CHI', 'kind' => 'liga', 'sort' => 10],
    'PAR-1' => ['slug' => 'par.1', 'name' => 'Campeonato Paraguaio', 'short' => 'Paraguaio', 'country' => 'PAR', 'kind' => 'liga', 'sort' => 10],
    'PER-1' => ['slug' => 'per.1', 'name' => 'Campeonato Peruano', 'short' => 'Peruano', 'country' => 'PER', 'kind' => 'liga', 'sort' => 10],
    'ECU-1' => ['slug' => 'ecu.1', 'name' => 'Campeonato Equatoriano', 'short' => 'Equatoriano', 'country' => 'ECU', 'kind' => 'liga', 'sort' => 10],
    'SCO-1' => ['slug' => 'sco.1', 'name' => 'Campeonato Escocês', 'short' => 'Escocês', 'country' => 'SCO', 'kind' => 'liga', 'sort' => 10],
    'TUR-1' => ['slug' => 'tur.1', 'name' => 'Campeonato Turco', 'short' => 'Turco', 'country' => 'TUR', 'kind' => 'liga', 'sort' => 10],
    'BEL-1' => ['slug' => 'bel.1', 'name' => 'Campeonato Belga', 'short' => 'Belga', 'country' => 'BEL', 'kind' => 'liga', 'sort' => 10],
    'KSA-1' => ['slug' => 'ksa.1', 'name' => 'Campeonato Saudita', 'short' => 'Saudita', 'country' => 'KSA', 'kind' => 'liga', 'sort' => 10],
    'JPN-1' => ['slug' => 'jpn.1', 'name' => 'J1 League (Japão)', 'short' => 'J1 League', 'country' => 'JPN', 'kind' => 'liga', 'sort' => 10],
];

/** No máximo tantas ligas por bolão (a tela continua usável e a sincronização, leve). */
const MAX_GROUP_LEAGUES = 6;

/**
 * Grava o catálogo na tabela leagues (cria as que faltam e atualiza país, tipo e ordem).
 * Só roda quando o catálogo muda: compara com o hash guardado em sync_state.catalog.
 * Ligas cadastradas à mão (fora do catálogo) ficam como estão.
 */
function leagues_seed(string $storedHash): void
{
    $hash = md5(serialize(LEAGUE_CATALOG));
    if ($storedHash === $hash) {
        return;
    }
    $upsert = db()->prepare(
        'INSERT INTO leagues (name, code, external_id, country, kind, short_name, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE name = VALUES(name), external_id = VALUES(external_id), country = VALUES(country),
             kind = VALUES(kind), short_name = VALUES(short_name), sort_order = VALUES(sort_order)'
    );
    foreach (LEAGUE_CATALOG as $code => $l) {
        $upsert->execute([$l['name'], $code, $l['slug'], $l['country'], $l['kind'], $l['short'], $l['sort']]);
    }
    sync_set_state(['catalog' => $hash]);
}

/** Dados do catálogo para uma liga do banco (pelo código), ou null se foi cadastrada à mão. */
function league_meta(string $code): ?array
{
    return LEAGUE_CATALOG[$code] ?? null;
}

/** Nome do país ou região. */
function country_name(string $code): string
{
    return COUNTRIES[$code][0] ?? COUNTRIES['OUT'][0];
}

/**
 * Ligas para o seletor agrupado (criar bolão e Configurar): todas as ativas mais as já escolhidas,
 * com país, tipo, escudo, temporada e se está fora de temporada (sem jogo nos próximos 60 dias
 * nem nos últimos 30). Ordem: país, ordem dentro do país, nome.
 */
function league_picker_rows(array $selected = []): array
{
    $now = time();
    // Horário do PHP (São Paulo), igual ao gravado em kickoff_at; o NOW() do banco pode estar em UTC.
    $stmt = db()->prepare(
        'SELECT l.id, l.code, l.name, l.short_name, l.country, l.kind, l.logo_url, l.season_label, l.is_active,
                (SELECT MAX(kickoff_at) FROM matches m WHERE m.league_id = l.id) AS last_kick,
                (SELECT MIN(kickoff_at) FROM matches m WHERE m.league_id = l.id AND m.kickoff_at > ?) AS next_kick
         FROM leagues l
         ORDER BY l.sort_order, l.name'
    );
    $stmt->execute([date('Y-m-d H:i:s', $now)]);
    $rows = $stmt->fetchAll();

    $out = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        if (!(int) $row['is_active'] && !in_array($id, $selected, true)) {
            continue;
        }
        $last = $row['last_kick'] ? strtotime($row['last_kick']) : null;
        $next = $row['next_kick'] ? strtotime($row['next_kick']) : null;
        // Sem nenhum jogo no banco ainda não dá para saber: não marca.
        $row['off_season'] = $last !== null
            && ($next === null || $next > $now + 60 * 86400)
            && $last < $now - 30 * 86400;
        $row['id'] = $id;
        $out[] = $row;
    }
    usort($out, function ($a, $b) {
        $ca = COUNTRIES[$a['country']][1] ?? 999;
        $cb = COUNTRIES[$b['country']][1] ?? 999;
        return $ca <=> $cb;
    });
    return $out;
}

/** IDs de ligas que podem entrar num bolão: ativas, ou já escolhidas antes ($keep). */
function league_valid_ids(array $keep = []): array
{
    $rows = db()->query('SELECT id, is_active FROM leagues')->fetchAll();
    $ids = [];
    foreach ($rows as $row) {
        if ((int) $row['is_active'] || in_array((int) $row['id'], $keep, true)) {
            $ids[] = (int) $row['id'];
        }
    }
    return $ids;
}

/**
 * Bandeira simplificada (desenho próprio, 24×16) do país; globo para as confederações e taça para a FIFA.
 * Bandeiras em emoji não aparecem no Windows, por isso o desenho. Decorativa: o nome vem ao lado.
 */
function country_flag_svg(string $code, string $class = 'flag'): string
{
    $h = function (array $colors): string { // faixas horizontais iguais
        $n = count($colors);
        $out = '';
        foreach ($colors as $i => $c) {
            $out .= '<rect y="' . round($i * 16 / $n, 2) . '" width="24" height="' . round(16 / $n + .02, 2) . '" fill="' . $c . '"/>';
        }
        return $out;
    };
    $v = function (array $colors): string { // faixas verticais iguais
        $out = '';
        foreach ($colors as $i => $c) {
            $out .= '<rect x="' . ($i * 8) . '" width="8" height="16" fill="' . $c . '"/>';
        }
        return $out;
    };
    $globe = function (string $bg): string {
        return '<rect width="24" height="16" fill="' . $bg . '"/>'
            . '<g fill="none" stroke="#fff" stroke-width="1.1"><circle cx="12" cy="8" r="5.2"/>'
            . '<ellipse cx="12" cy="8" rx="2.3" ry="5.2"/><path d="M6.8 8h10.4M7.6 5.3h8.8M7.6 10.7h8.8"/></g>';
    };

    switch ($code) {
        case 'BRA':
            $art = '<rect width="24" height="16" fill="#1f9d55"/><path d="M12 2.2L21.2 8L12 13.8L2.8 8z" fill="#f7d417"/>'
                . '<circle cx="12" cy="8" r="3.4" fill="#22408f"/><path d="M8.8 7.3c2-.6 4.4-.4 6.4.6" stroke="#fff" stroke-width=".7" fill="none"/>';
            break;
        case 'ENG':
            $art = '<rect width="24" height="16" fill="#fff"/><path d="M10.4 0h3.2v16h-3.2zM0 6.4h24v3.2H0z" fill="#cf2b36"/>';
            break;
        case 'SCO':
            $art = '<rect width="24" height="16" fill="#1d5fb4"/><path d="M0 0L24 16M24 0L0 16" stroke="#fff" stroke-width="2.6"/>';
            break;
        case 'JPN':
            $art = '<rect width="24" height="16" fill="#fff"/><circle cx="12" cy="8" r="4.4" fill="#c8102e"/>';
            break;
        case 'ESP':
            $art = '<rect width="24" height="16" fill="#c60b1e"/><rect y="4" width="24" height="8" fill="#f6c600"/>';
            break;
        case 'POR':
            $art = '<rect width="24" height="16" fill="#da291c"/><rect width="9.6" height="16" fill="#046a38"/>'
                . '<circle cx="9.6" cy="8" r="2.8" fill="#f6c600"/>';
            break;
        case 'USA':
            $art = $h(['#b22234', '#fff', '#b22234', '#fff', '#b22234', '#fff', '#b22234']) . '<rect width="10.4" height="8.6" fill="#3c3b6e"/>';
            break;
        case 'ITA':
            $art = $v(['#009246', '#fff', '#ce2b37']);
            break;
        case 'GER':
            $art = $h(['#1a1a1a', '#dd0000', '#ffce00']);
            break;
        case 'FRA':
            $art = $v(['#0055a4', '#fff', '#ef4135']);
            break;
        case 'NED':
            $art = $h(['#ae1c28', '#fff', '#21468b']);
            break;
        case 'BEL':
            $art = $v(['#1a1a1a', '#fdda24', '#ef3340']);
            break;
        case 'COL':
            $art = '<rect width="24" height="16" fill="#fcd116"/><rect y="8" width="24" height="4" fill="#003893"/><rect y="12" width="24" height="4" fill="#ce1126"/>';
            break;
        case 'ECU':
            $art = '<rect width="24" height="16" fill="#ffdd00"/><rect y="8" width="24" height="4" fill="#034ea2"/><rect y="12" width="24" height="4" fill="#ed1c24"/>'
                . '<circle cx="12" cy="8" r="2.2" fill="#6b4c1e"/>';
            break;
        case 'ARG':
            $art = $h(['#74acdf', '#fff', '#74acdf']) . '<circle cx="12" cy="8" r="1.8" fill="#f6b40e"/>';
            break;
        case 'URU':
            $art = $h(['#fff', '#0038a8', '#fff', '#0038a8', '#fff', '#0038a8', '#fff', '#0038a8', '#fff'])
                . '<rect width="9" height="8.9" fill="#fff"/><circle cx="4.5" cy="4.4" r="2.4" fill="#fcd116"/>';
            break;
        case 'MEX':
            $art = $v(['#006847', '#fff', '#ce1126']) . '<circle cx="12" cy="8" r="2" fill="#8c5a2b"/>';
            break;
        case 'CHI':
            $art = '<rect width="24" height="16" fill="#fff"/><rect y="8" width="24" height="8" fill="#d52b1e"/>'
                . '<rect width="8" height="8" fill="#0039a6"/><circle cx="4" cy="4" r="1.6" fill="#fff"/>';
            break;
        case 'PAR':
            $art = $h(['#d52b1e', '#fff', '#0038a8']);
            break;
        case 'PER':
            $art = $v(['#d91023', '#fff', '#d91023']);
            break;
        case 'TUR':
            $art = '<rect width="24" height="16" fill="#e30a17"/><circle cx="9.6" cy="8" r="4" fill="#fff"/>'
                . '<circle cx="10.7" cy="8" r="3.2" fill="#e30a17"/><circle cx="15" cy="8" r="1.3" fill="#fff"/>';
            break;
        case 'KSA':
            $art = '<rect width="24" height="16" fill="#006c35"/><rect x="5" y="10.4" width="14" height="1.4" rx=".7" fill="#fff"/>'
                . '<path d="M6 6.5h12" stroke="#fff" stroke-width="1.6" stroke-dasharray="1.6 1"/>';
            break;
        case 'CONMEBOL':
            $art = $globe('#2c4f9e');
            break;
        case 'UEFA':
            $art = $globe('#20306b');
            break;
        case 'CONCACAF':
            $art = $globe('#3d3a8c');
            break;
        case 'FIFA':
            $art = '<rect width="24" height="16" fill="#2b2766"/>'
                . '<path d="M9 3h6v3a3 3 0 0 1-6 0zM9 4.2H7.4a1.8 1.8 0 0 0 1.8 2.4M15 4.2h1.6a1.8 1.8 0 0 1-1.8 2.4M12 9v2.4M9.6 13h4.8M10.6 11.4h2.8" fill="none" stroke="#f0d59f" stroke-width="1.1" stroke-linecap="round" stroke-linejoin="round"/>';
            break;
        default:
            $art = $globe('#5d6088');
    }

    return '<svg class="' . e($class) . '" viewBox="0 0 24 16" width="24" height="16" aria-hidden="true" focusable="false">'
        . $art . '<rect x=".25" y=".25" width="23.5" height="15.5" rx="2" fill="none" stroke="rgba(0,0,0,.18)" stroke-width=".5"/></svg>';
}
