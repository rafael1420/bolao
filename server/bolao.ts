// Consultas do bolão: jogos, contexto das rodadas, ranking, pontos e palpites da galera.
import { exec, notVoid, one, ph, q, type Row } from './db.ts'
import { calculatePoints, canPredict, pointsReason, roundCtx, type RMatch, type RoundCtx } from './rounds.ts'
import { fromSql, nowTs, sqlTime } from './time.ts'

export type Group = {
  id: number
  name: string
  owner_id: number
  invite_code: string
  password_hash: string | null
  exact_points: number
  winner_points: number
  goal_diff_points: number
  round_mode: string
  deadline_mode: string
}

/** Jogo do banco com o horário convertido (kick) e os dados dos times. */
export type MatchRow = RMatch & Row & {
  league_id: number
  home_team: string
  away_team: string
  kickoff_at: string
  note: string | null
  status_detail: string | null
  live_home_score: number | null
  live_away_score: number | null
}

export const TEAM_COLUMNS = `ht.logo_url AS home_logo, ht.color AS home_color, ht.alt_color AS home_color2, ht.abbreviation AS home_abbr, ht.short_name AS home_short,
  at.logo_url AS away_logo, at.color AS away_color, at.alt_color AS away_color2, at.abbreviation AS away_abbr, at.short_name AS away_short`
export const TEAM_JOINS = (a = 'm') => `LEFT JOIN teams ht ON ht.id = ${a}.home_team_id LEFT JOIN teams at ON at.id = ${a}.away_team_id`

export const withKick = <T extends Row>(rows: T[]) => rows.map((r) => ({ ...r, kick: fromSql(r.kickoff_at) })) as (T & { kick: number })[]

export const isLive = (m: { home_score: number | null; status: string | null }) => m.home_score === null && m.status === 'in'

export const rulesOf = (g: Group) => ({ exact: g.exact_points, winner: g.winner_points, diff: g.goal_diff_points })

/** O bolão, se a pessoa participa dele. */
export async function memberGroup(groupId: number, userId: number) {
  return one<Group>('SELECT g.* FROM bolao_groups g JOIN group_members m ON m.group_id = g.id WHERE g.id = ? AND m.user_id = ?', [groupId, userId])
}

/** IDs das ligas do bolão (nunca vazio, para os IN (...) funcionarem). */
export async function groupLeagueIds(groupId: number) {
  const rows = await q<{ league_id: number }>('SELECT league_id FROM group_leagues WHERE group_id = ?', [groupId])
  return rows.length ? rows.map((r) => r.league_id) : [0]
}

/** Jogos da temporada atual de uma liga (inclui adiados), com os times. */
export async function seasonMatches(leagueId: number): Promise<MatchRow[]> {
  const l = await one<{ season_start: string | null }>('SELECT season_start FROM leagues WHERE id = ?', [leagueId])
  const rows = await q(
    `SELECT m.*, ${TEAM_COLUMNS} FROM matches m ${TEAM_JOINS()} WHERE m.league_id = ? AND m.kickoff_at >= ? ORDER BY m.kickoff_at, m.id`,
    [leagueId, l?.season_start ?? '2000-01-01'],
  )
  return withKick(rows) as MatchRow[]
}

/** Contexto das rodadas de uma liga para o modo do bolão. Guardado por alguns segundos (várias telas pedem juntas). */
const ctxCache = new Map<string, { at: number; value: Promise<[RoundCtx, Map<number, MatchRow>]> }>()
export function roundContext(leagueId: number, group: Pick<Group, 'round_mode' | 'deadline_mode'>, now = nowTs()) {
  const key = `${leagueId}|${group.round_mode}|${group.deadline_mode}`
  const hit = ctxCache.get(key)
  if (hit && Date.now() - hit.at < 5000) return hit.value
  const value = seasonMatches(leagueId).then((ms) => [roundCtx(ms, group.round_mode, group.deadline_mode, now), new Map(ms.map((m) => [m.id, m]))] as [RoundCtx, Map<number, MatchRow>])
  ctxCache.set(key, { at: Date.now(), value })
  value.catch(() => ctxCache.delete(key))
  return value
}
/** Depois de gravar jogos ou placares. */
export const clearRoundCache = () => ctxCache.clear()

export type RankRow = { id: number; name: string; total: number; exact: number; outcomes: number; scored: number; played: number; pos: number }

/**
 * Ranking de vários bolões de uma vez: grupo → linhas ordenadas (pontos, exatos, acertos, nome).
 * Só contam jogos encerrados das ligas atuais do bolão. round/leagueId limitam a uma rodada.
 */
export async function groupRankings(groupIds: number[], round?: number, leagueId?: number): Promise<Map<number, RankRow[]>> {
  const ids = [...new Set(groupIds)]
  const out = new Map<number, RankRow[]>(ids.map((id) => [id, []]))
  if (!ids.length) return out
  const extra = (round !== undefined ? ' AND m.round = ?' : '') + (leagueId !== undefined ? ' AND m.league_id = ?' : '')
  const params: (number | string)[] = [...ids]
  if (round !== undefined) params.push(round)
  if (leagueId !== undefined) params.push(leagueId)
  const rows = await q(
    `SELECT gm.group_id, u.id, u.name,
            COALESCE(SUM(s.points), 0) AS total, COALESCE(SUM(s.exact), 0) AS exact, COALESCE(SUM(s.outcome), 0) AS outcomes,
            COALESCE(SUM(s.points > 0), 0) AS scored, COUNT(s.id) AS played
     FROM group_members gm
     JOIN users u ON u.id = gm.user_id
     LEFT JOIN (
       SELECT p.id, p.user_id, p.group_id, p.points,
              (p.home_pred = m.home_score AND p.away_pred = m.away_score) AS exact,
              ((p.home_pred > p.away_pred) - (p.home_pred < p.away_pred)) = ((m.home_score > m.away_score) - (m.home_score < m.away_score)) AS outcome
       FROM predictions p
       JOIN matches m ON m.id = p.match_id
       JOIN group_leagues gl ON gl.group_id = p.group_id AND gl.league_id = m.league_id
       WHERE p.group_id IN (${ph(ids)}) AND m.home_score IS NOT NULL AND ${notVoid()}${extra}
     ) s ON s.user_id = gm.user_id AND s.group_id = gm.group_id
     WHERE gm.group_id IN (${ph(ids)})
     GROUP BY gm.group_id, u.id, u.name
     ORDER BY gm.group_id, total DESC, exact DESC, outcomes DESC, u.name ASC`,
    [...params, ...ids],
  )
  for (const r of rows) {
    out.get(r.group_id)!.push({
      id: r.id, name: r.name, total: Number(r.total), exact: Number(r.exact), outcomes: Number(r.outcomes), scored: Number(r.scored), played: Number(r.played), pos: 0,
    })
  }
  for (const list of out.values()) withPositions(list)
  return out
}

export async function groupRanking(groupId: number, round?: number, leagueId?: number) {
  return (await groupRankings([groupId], round, leagueId)).get(groupId) ?? []
}

/** Posição com empate compartilhado (2º, 2º, 4º): só empata quem tem os mesmos pontos, exatos e acertos. */
function withPositions(rows: RankRow[]) {
  let prev = ''
  rows.forEach((r, i) => {
    const key = `${r.total}|${r.exact}|${r.outcomes}`
    if (key !== prev) {
      r.pos = i + 1
      prev = key
    } else r.pos = rows[i - 1].pos
  })
  return rows
}

/** Recalcula os pontos dos palpites (todos de um jogo, ou todos de um bolão). */
async function recalc(where: string, params: number[]) {
  const rows = await q(
    `SELECT p.id, p.home_pred, p.away_pred, m.home_score, m.away_score, g.exact_points, g.winner_points, g.goal_diff_points
     FROM predictions p JOIN matches m ON m.id = p.match_id JOIN bolao_groups g ON g.id = p.group_id WHERE ${where}`,
    params,
  )
  for (const r of rows) {
    const points = r.home_score === null ? null : calculatePoints(r.home_pred, r.away_pred, r.home_score, r.away_score, { exact: r.exact_points, winner: r.winner_points, diff: r.goal_diff_points })
    await exec('UPDATE predictions SET points = ? WHERE id = ?', [points, r.id])
  }
}
export const recalculateMatch = (matchId: number) => recalc('p.match_id = ?', [matchId])
export const recalculateGroup = (groupId: number) => recalc('p.group_id = ?', [groupId])

/** Quantos palpites já foram pontuados (aviso de recálculo). */
export async function scoredCounts(groupId: number) {
  const r = await one(
    `SELECT COUNT(p.id) AS preds, COUNT(DISTINCT m.id) AS matches FROM predictions p JOIN matches m ON m.id = p.match_id
     WHERE p.group_id = ? AND m.home_score IS NOT NULL AND ${notVoid()}`,
    [groupId],
  )
  return { preds: Number(r?.preds ?? 0), matches: Number(r?.matches ?? 0) }
}

/**
 * Palpites de todos nos jogos já fechados (o início passou). Jogos abertos nunca entram: antes do jogo,
 * ninguém vê o palpite dos outros. match_id → linhas (mais pontos primeiro).
 */
export async function closedPredictions(groupId: number, matches: MatchRow[], now = nowTs()) {
  const closed = matches.filter((m) => m.status !== 'void' && (m.home_score !== null || m.status === 'in' || m.status === 'post' || m.kick <= now))
  const out = new Map<number, { user_id: number; name: string; home_pred: number | null; away_pred: number | null; points: number | null; reason: string }[]>()
  if (!closed.length) return out
  const members = await q<{ id: number; name: string }>('SELECT u.id, u.name FROM group_members gm JOIN users u ON u.id = gm.user_id WHERE gm.group_id = ? ORDER BY u.name', [groupId])
  const ids = closed.map((m) => m.id)
  const preds = await q(`SELECT match_id, user_id, home_pred, away_pred, points FROM predictions WHERE group_id = ? AND match_id IN (${ph(ids)})`, [groupId, ...ids])
  const by = new Map<string, Row>(preds.map((p) => [`${p.match_id}|${p.user_id}`, p]))
  for (const m of closed) {
    const finished = m.home_score !== null
    const rows = members.map((u) => {
      const p = by.get(`${m.id}|${u.id}`)
      return {
        user_id: u.id,
        name: u.name,
        home_pred: p ? p.home_pred : null,
        away_pred: p ? p.away_pred : null,
        points: p && finished ? Number(p.points ?? 0) : null,
        reason: p && finished ? pointsReason(p.home_pred, p.away_pred, m.home_score!, m.away_score!) : p ? 'Aguardando o resultado' : 'Não palpitou',
      }
    })
    rows.sort((a, b) => (b.points ?? -1) - (a.points ?? -1) || Number(a.home_pred === null) - Number(b.home_pred === null) || a.name.localeCompare(b.name))
    out.set(m.id, rows)
  }
  return out
}

/** Rodadas com jogo encerrado nas ligas do bolão (seletor do ranking), da mais recente para a mais antiga. */
export async function scoredRounds(groupId: number) {
  const rows = await q(
    `SELECT m.league_id, m.round, MAX(m.round_label) AS round_label, l.name AS league_name, MAX(m.kickoff_at) AS last_kick
     FROM matches m JOIN group_leagues gl ON gl.league_id = m.league_id JOIN leagues l ON l.id = m.league_id
     WHERE gl.group_id = ? AND m.home_score IS NOT NULL AND ${notVoid()}
     GROUP BY m.league_id, m.round, l.name ORDER BY last_kick DESC`,
    [groupId],
  )
  const multi = new Set(rows.map((r) => r.league_id)).size > 1
  return rows.map((r) => {
    const label = r.round_label || `Rodada ${r.round}`
    return { key: `${r.league_id}-${r.round}`, league_id: r.league_id as number, round: r.round as number, label: multi ? `${r.league_name} · ${label}` : label, round_name: label as string }
  })
}

/** Pontos por participante por rodada: user → {"liga-rodada": pontos}. */
export async function pointsByRound(groupId: number) {
  const rows = await q(
    `SELECT p.user_id, m.league_id, m.round, COALESCE(SUM(p.points), 0) AS pts
     FROM predictions p JOIN matches m ON m.id = p.match_id JOIN group_leagues gl ON gl.group_id = p.group_id AND gl.league_id = m.league_id
     WHERE p.group_id = ? AND m.home_score IS NOT NULL AND ${notVoid()} GROUP BY p.user_id, m.league_id, m.round`,
    [groupId],
  )
  const out: Record<number, Record<string, number>> = {}
  for (const r of rows) (out[r.user_id] ??= {})[`${r.league_id}-${r.round}`] = Number(r.pts)
  return out
}

/**
 * Para cada bolão: jogos palpitáveis agora sem o meu palpite (pending) e o próximo prazo (next).
 * Segue a regra de rodada de cada bolão. Poucas consultas no total.
 */
export async function groupPredictable(groups: Pick<Group, 'id' | 'round_mode' | 'deadline_mode'>[], userId: number, now = nowTs()) {
  const out = new Map<number, { pending: number; next: number | null }>(groups.map((g) => [g.id, { pending: 0, next: null }]))
  if (!groups.length) return out
  const gids = groups.map((g) => g.id)
  const gl = await q<{ group_id: number; league_id: number }>(`SELECT group_id, league_id FROM group_leagues WHERE group_id IN (${ph(gids)})`, gids)
  const leaguesOf = new Map<number, number[]>()
  for (const r of gl) leaguesOf.set(r.group_id, [...(leaguesOf.get(r.group_id) ?? []), r.league_id])
  const lids = [...new Set(gl.map((r) => r.league_id))]
  if (!lids.length) return out
  const ms = withKick(
    await q(`SELECT id, league_id, round, round_label, kickoff_at, home_score, away_score, status FROM matches WHERE league_id IN (${ph(lids)}) AND kickoff_at BETWEEN ? AND ? ORDER BY kickoff_at, id`, [
      ...lids, sqlTime(now - 20 * 86400), sqlTime(now + 90 * 86400),
    ]),
  ) as unknown as (RMatch & { league_id: number })[]
  const byLeague = new Map<number, RMatch[]>()
  for (const m of ms) byLeague.set(m.league_id, [...(byLeague.get(m.league_id) ?? []), m])
  const picked = new Set(
    (await q(`SELECT p.group_id, p.match_id FROM predictions p JOIN matches m ON m.id = p.match_id WHERE p.user_id = ? AND p.group_id IN (${ph(gids)}) AND m.kickoff_at > ?`, [userId, ...gids, sqlTime(now)])).map(
      (r) => `${r.group_id}|${r.match_id}`,
    ),
  )
  const cache = new Map<string, RoundCtx>()
  for (const g of groups) {
    const o = out.get(g.id)!
    for (const lid of leaguesOf.get(g.id) ?? []) {
      const key = `${lid}|${g.round_mode}|${g.deadline_mode}`
      if (!cache.has(key)) cache.set(key, roundCtx(byLeague.get(lid) ?? [], g.round_mode, g.deadline_mode, now))
      const ctx = cache.get(key)!
      for (const m of byLeague.get(lid) ?? []) {
        if (!canPredict(m, ctx)) continue
        if (!picked.has(`${g.id}|${m.id}`)) o.pending++
        let close = m.kick
        const [n, resched] = ctx.where.get(m.id) ?? [m.round, false]
        const start = ctx.rounds.get(n)?.start
        if (!resched && ctx.deadline === 'rodada' && start) close = Math.min(close, start)
        o.next = o.next === null ? close : Math.min(o.next, close)
      }
    }
  }
  return out
}
