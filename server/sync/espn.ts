// Sincronização automática com a API pública da ESPN (jogos, placares, times, escudos) e o TheSportsDB
// (rodada oficial das principais ligas). Roda sozinha a cada minuto dentro do servidor (ver startScheduler)
// e só para as ligas que estão em algum bolão, cada uma no seu ritmo (syncPlan).
import { exec, notVoid, ph, pool, q, type Row } from '../db.ts'
import { LEAGUE_CATALOG } from '../leagues.ts'
import { getState, setState } from '../schema.ts'
import { clearRoundCache, recalculateMatch } from '../bolao.ts'
import { DAY, HOUR, espnDay, fromSql, isoTs, nowTs, sqlTime } from '../time.ts'
import { cupStageLabel, cupStageRounds, numberLeague, teamKey, type NMatch } from './numbering.ts'

// ESPN_BASE só muda em testes (servidor falso da ESPN).
const ESPN_BASE = process.env.ESPN_BASE || 'https://site.api.espn.com/apis/site/v2/sports/soccer/'
const TSDB_ROUND_URL = (id: number, season: string, round: number) => `https://www.thesportsdb.com/api/v1/json/123/eventsround.php?id=${id}&s=${season}&r=${round}`

const SYNC_TICK = 60 // intervalo entre duas sincronizações automáticas
const FULL_EVERY = 6 * HOUR // tabela completa de cada liga
const IDLE_EVERY = 5 * 60 // jogos do momento
const LIVE_EVERY = 60 // ... com jogo ao vivo
const FULL_PER_RUN = 2
const BUDGET = 60 // consultas por execução automática
const ADMIN_BUDGET = 250 // botão "Sincronizar agora"
const WINDOW_BEFORE = 7 * DAY
const WINDOW_AFTER = 35 * DAY
const BACKFILL_DAYS = 20
const TSDB_PER_RUN = 3
const INFO_PER_RUN = 6 // escudos de ligas ainda sem escudo (seletor de ligas)

type League = Row & { id: number; code: string; slug: string; cup: boolean; synced_quick_at: number; synced_full_at: number; sync_failures: number; backfill_day: string | null; has_live: number }
// eslint-disable-next-line @typescript-eslint/no-explicit-any
type Board = any

/* ---------- Rede ---------- */

/** Baixa várias URLs em paralelo (15 por vez). Chave → JSON (ou null se falhou). */
async function fetchMany<K extends string | number>(urls: Map<K, string>): Promise<Map<K, Board | null>> {
  const out = new Map<K, Board | null>()
  const entries = [...urls]
  for (let i = 0; i < entries.length; i += 15) {
    await Promise.all(
      entries.slice(i, i + 15).map(async ([key, url]) => {
        try {
          const res = await fetch(url, { headers: { 'User-Agent': 'Mozilla/5.0 (Bolao)' }, signal: AbortSignal.timeout(12_000) })
          out.set(key, res.ok ? await res.json() : null)
        } catch {
          out.set(key, null)
        }
      }),
    )
  }
  return out
}

/* ---------- Ligas e plano ---------- */

const asLeague = (r: Row): League => ({ ...r, slug: r.external_id, cup: r.kind === 'copa' }) as League

/** Ligas do catálogo (com slug da ESPN) que estão em algum bolão. */
async function syncLeagues() {
  const rows = await q(
    "SELECT l.* FROM leagues l WHERE l.external_id IS NOT NULL AND l.external_id <> '' AND EXISTS (SELECT 1 FROM group_leagues gl WHERE gl.league_id = l.id) ORDER BY l.id",
  )
  return new Map(rows.map((r) => [r.code as string, asLeague(r)]))
}

type Plan = Map<string, { full: boolean; backfill: boolean }>

/**
 * O que fazer em cada liga: rápida a cada 5 min (1 min com jogo ao vivo) para ligas com jogo entre 3 h atrás
 * e 36 h à frente, com placar atrasado, ou nunca sincronizadas; completa a cada 6 h (2 ligas por vez);
 * começo da temporada, uma liga por vez. Liga que falhou espera 2, 4, 8... até 60 min.
 */
async function syncPlan(leagues: Map<string, League>, mode: 'auto' | 'quick' | 'full', now: number): Promise<Plan> {
  const plan: Plan = new Map()
  if (!leagues.size) return plan
  if (mode === 'full') {
    for (const code of leagues.keys()) plan.set(code, { full: true, backfill: true })
    return plan
  }
  const active = new Set(
    (
      await q(
        `SELECT DISTINCT league_id FROM matches m WHERE ${notVoid()} AND (m.kickoff_at BETWEEN ? AND ? OR (m.home_score IS NULL AND m.external_id IS NOT NULL AND m.kickoff_at BETWEEN ? AND ?))`,
        [sqlTime(now - 3 * HOUR), sqlTime(now + 36 * HOUR), sqlTime(now - 10 * DAY), sqlTime(now)],
      )
    ).map((r) => r.league_id as number),
  )
  const fullQueue: [string, number][] = []
  const backfillQueue: [string, string][] = []
  const backfillBefore = espnDay(now - WINDOW_BEFORE - 2 * DAY)
  for (const [code, l] of leagues) {
    if (l.sync_failures > 0 && now - l.synced_quick_at < Math.min(2 ** l.sync_failures, 60) * 60) continue
    const every = l.has_live ? LIVE_EVERY : IDLE_EVERY
    const never = l.synced_quick_at === 0
    if (now - l.synced_quick_at >= every && (never || active.has(l.id))) plan.set(code, { full: false, backfill: false })
    if (mode === 'auto' && now - l.synced_full_at >= FULL_EVERY) fullQueue.push([code, l.synced_full_at])
    if (mode === 'auto' && (l.backfill_day === null || l.backfill_day < backfillBefore)) backfillQueue.push([code, l.backfill_day ?? ''])
  }
  fullQueue.sort((a, b) => a[1] - b[1])
  for (const [code] of fullQueue.slice(0, FULL_PER_RUN)) plan.set(code, { full: true, backfill: false })
  backfillQueue.sort((a, b) => a[1].localeCompare(b[1]))
  for (const [code] of backfillQueue.slice(0, 1)) plan.set(code, { full: plan.get(code)?.full ?? false, backfill: true })
  return plan
}

/* ---------- Calendário ---------- */

function seasonWindow(board: Board): [number, number] {
  const season = board?.leagues?.[0]?.season ?? {}
  const year = Number(season.year ?? new Date().getFullYear())
  const start = isoTs(season.startDate)
  const end = isoTs(season.endDate)
  return [Number.isFinite(start) ? start : Date.UTC(year, 0, 1) / 1000, Number.isFinite(end) ? end : Date.UTC(year + 1, 0, 1) / 1000]
}

const stagesOf = (board: Board): Board[] => board?.leagues?.[0]?.calendar?.[0]?.entries ?? []

/** Dias (formato da ESPN) com jogos possíveis entre dois horários. Ligas: dias do calendário; copas: dias das fases. */
function daysBetween(league: League, board: Board, from: number, to: number) {
  const days = new Set<string>()
  if (league.cup) {
    const stages = stagesOf(board)
    for (let t = from; t <= to; t += DAY) {
      if (!stages.length || stages.some((s) => t >= isoTs(s.startDate) && t < isoTs(s.endDate))) days.add(espnDay(t))
    }
  } else {
    for (const day of board?.leagues?.[0]?.calendar ?? []) {
      const t = typeof day === 'string' ? isoTs(day) : NaN
      if (Number.isFinite(t) && t >= from - DAY && t <= to) days.add(espnDay(t + 12 * HOUR)) // o calendário marca o começo do dia em UTC
    }
  }
  return [...days].sort()
}

const windowDays = (l: League, b: Board, now: number) => daysBetween(l, b, now - WINDOW_BEFORE, now + WINDOW_AFTER)

/** Dias que faltam do começo da temporada até a janela da tabela completa: [dias, dia coberto ao final]. */
function backfillDays(league: League, board: Board, now: number): [string[], string] {
  const [seasonStart] = seasonWindow(board)
  const until = now - WINDOW_BEFORE
  let from = seasonStart
  if (league.cup) {
    for (const s of stagesOf(board)) {
      const a = isoTs(s.startDate)
      const b = isoTs(s.endDate)
      if (a <= until && until < b) from = Math.max(from, a)
    }
  }
  const after = league.backfill_day ?? ''
  return [daysBetween(league, board, from, until).filter((d) => d > after), espnDay(until)]
}

/** Escudo da liga, temporada ("2026-27") e início da temporada, como vêm na página padrão da ESPN. */
async function saveLeagueInfo(leagueId: number, board: Board) {
  const l = board?.leagues?.[0] ?? {}
  const logo = String(l.logos?.[0]?.href ?? '')
  const label = String(l.season?.displayName ?? '').match(/^(\d{4}(?:[-/]\d{2,4})?)/)?.[1] ?? null
  const start = isoTs(l.season?.startDate)
  await exec('UPDATE leagues SET logo_url = COALESCE(?, logo_url), season_label = COALESCE(?, season_label), season_start = COALESCE(?, season_start) WHERE id = ?', [
    logo.startsWith('https://') && logo.length <= 255 ? logo : null,
    label,
    Number.isFinite(start) ? sqlTime(start).slice(0, 10) : null,
    leagueId,
  ])
}

/* ---------- Jogos ---------- */

type Team = { espn_id: string; name: string; short_name: string | null; abbreviation: string | null; logo_url: string | null; color: string | null; alt_color: string | null }

function espnTeam(t: Board): Team | null {
  const id = String(t?.id ?? '')
  if (!/^[0-9]{1,20}$/.test(id)) return null
  const color = (v: unknown) => (typeof v === 'string' && /^[0-9a-f]{6}$/i.test(v) ? v.toLowerCase() : null)
  const logo = String(t.logo ?? '')
  return {
    espn_id: id,
    name: String(t.displayName ?? '').slice(0, 100),
    short_name: t.shortDisplayName ? String(t.shortDisplayName).slice(0, 60) : null,
    abbreviation: t.abbreviation ? String(t.abbreviation).slice(0, 10) : null,
    logo_url: logo.startsWith('https://') && logo.length <= 255 ? logo : null,
    color: color(t.color),
    alt_color: color(t.alternateColor),
  }
}

/** Jogo da ESPN → colunas de matches. Null para jogos ainda sem times definidos. */
function parseEvent(event: Board, stages: Board[]) {
  const comp = event?.competitions?.[0] ?? {}
  const home = (comp.competitors ?? []).find((c: Board) => c.homeAway === 'home')
  const away = (comp.competitors ?? []).find((c: Board) => c.homeAway === 'away')
  const homeName = String(home?.team?.displayName ?? '')
  const awayName = String(away?.team?.displayName ?? '')
  if (!homeName || !awayName || homeName.startsWith('TBD') || awayName.startsWith('TBD')) return null
  const type = event.status?.type ?? {}
  let state: string = type.state ?? 'pre'
  if (state === 'post' && !type.completed) state = 'void' // adiado ou cancelado
  const final = state === 'post'
  const live = state === 'in'
  const start = isoTs(event.date)
  let round: number | null = null
  let roundLabel: string | null = null
  for (const s of stages) {
    if (start >= isoTs(s.startDate) && start < isoTs(s.endDate)) {
      round = Number(s.value) * 20 + 1 // provisório: numberCup numera de vez
      roundLabel = cupStageLabel(String(s.label))
      break
    }
  }
  const headline = String(comp.notes?.[0]?.headline ?? '')
  const note = headline.startsWith('1st Leg') ? 'Jogo de ida' : headline.startsWith('2nd Leg') ? 'Jogo de volta' : null
  return {
    home: espnTeam(home?.team),
    away: espnTeam(away?.team),
    external_id: String(event.id),
    round,
    round_label: roundLabel,
    note,
    home_team: homeName.slice(0, 100),
    away_team: awayName.slice(0, 100),
    kickoff_at: sqlTime(start).slice(0, 16) + ':00',
    home_score: final ? Number(home.score) : null,
    away_score: final ? Number(away.score) : null,
    live_home_score: live ? Number(home.score) : null,
    live_away_score: live ? Number(away.score) : null,
    status: state,
    status_detail: type.shortDetail ? String(type.shortDetail).slice(0, 40) : null,
  }
}

async function upsertTeams(teams: Map<string, Team>) {
  if (!teams.size) return new Map<string, number>()
  for (const t of teams.values()) {
    await exec(
      `INSERT INTO teams (espn_id, name, short_name, abbreviation, logo_url, color, alt_color) VALUES (?, ?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE name = VALUES(name), short_name = VALUES(short_name), abbreviation = VALUES(abbreviation),
         logo_url = COALESCE(VALUES(logo_url), logo_url), color = COALESCE(VALUES(color), color), alt_color = COALESCE(VALUES(alt_color), alt_color)`,
      [t.espn_id, t.name, t.short_name, t.abbreviation, t.logo_url, t.color, t.alt_color],
    )
  }
  const ids = [...teams.keys()]
  const rows = await q(`SELECT id, espn_id FROM teams WHERE espn_id IN (${ph(ids)})`, ids)
  return new Map(rows.map((r) => [String(r.espn_id), r.id as number]))
}

/** Grava os jogos de uma liga e recalcula os pontos dos que acabaram de terminar. */
async function saveEvents(leagueId: number, events: Board[], stages: Board[]) {
  const existing = new Map(
    (await q('SELECT id, external_id, home_score, away_score FROM matches WHERE league_id = ? AND external_id IS NOT NULL', [leagueId])).map((r) => [r.external_id as string, r]),
  )
  const parsed = events.map((e) => parseEvent(e, stages)).filter((m) => m !== null)
  const teams = new Map<string, Team>()
  for (const m of parsed) for (const t of [m.home, m.away]) if (t) teams.set(t.espn_id, t)
  const teamIds = await upsertTeams(teams)
  const stats = { events: 0, new: 0, results: 0 }
  for (const m of parsed) {
    stats.events++
    const homeId = m.home ? (teamIds.get(m.home.espn_id) ?? null) : null
    const awayId = m.away ? (teamIds.get(m.away.espn_id) ?? null) : null
    const old = existing.get(m.external_id)
    if (!old) {
      await exec(
        `INSERT INTO matches (league_id, external_id, round, round_label, note, home_team, away_team, kickoff_at, home_score, away_score,
           live_home_score, live_away_score, status, status_detail, home_team_id, away_team_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
        [leagueId, m.external_id, m.round ?? 1, m.round_label, m.note, m.home_team, m.away_team, m.kickoff_at, m.home_score, m.away_score, m.live_home_score, m.live_away_score, m.status, m.status_detail, homeId, awayId],
      )
      stats.new++
      continue
    }
    // A rodada e o nome da fase ficam com a numeração (numberLeague).
    await exec(
      `UPDATE matches SET note = ?, home_team = ?, away_team = ?, kickoff_at = ?, home_score = ?, away_score = ?, live_home_score = ?, live_away_score = ?,
         status = ?, status_detail = ?, home_team_id = COALESCE(?, home_team_id), away_team_id = COALESCE(?, away_team_id) WHERE id = ?`,
      [m.note, m.home_team, m.away_team, m.kickoff_at, m.home_score, m.away_score, m.live_home_score, m.live_away_score, m.status, m.status_detail, homeId, awayId, old.id],
    )
    const changed = old.home_score === null ? m.home_score !== null : old.home_score !== m.home_score || old.away_score !== m.away_score
    if (changed && m.home_score !== null) {
      await recalculateMatch(old.id)
      stats.results++
    }
  }
  return stats
}

/* ---------- Numeração das rodadas ---------- */

async function numberLeagueRounds(league: League, board: Board) {
  const [start, end] = seasonWindow(board)
  if (league.cup) return numberCup(league.id, stagesOf(board), start, end)
  const season = Number(board?.leagues?.[0]?.season?.year ?? new Date(start * 1000).getFullYear())
  const tsdb = LEAGUE_CATALOG[league.code]?.tsdb
  if (tsdb) await fetchOfficialRounds(league.id, season, tsdb)
  const all = (await q('SELECT id, round, kickoff_at, home_team, away_team, status FROM matches WHERE league_id = ? AND external_id IS NOT NULL AND kickoff_at >= ? AND kickoff_at < ? ORDER BY kickoff_at, id', [
    league.id, sqlTime(start), sqlTime(end),
  ])).map((r) => ({ ...r, kick: fromSql(r.kickoff_at) })) as (NMatch & { round: number })[]
  if (!all.length) return
  const official = new Map<string, number>()
  if (tsdb) {
    for (const r of await q('SELECT home_key, away_key, round FROM official_rounds WHERE league_id = ? AND season = ?', [league.id, season])) official.set(`${r.home_key}|${r.away_key}`, r.round)
  }
  const rounds = numberLeague(all, official)
  for (const m of all) {
    const r = rounds.get(m.id)
    if (r !== undefined && r !== m.round) await exec('UPDATE matches SET round = ? WHERE id = ?', [r, m.id])
  }
}

/** Copas: fase × 20 + sequência dentro da fase (ver cupStageRounds). Os palpites apontam para o jogo: renumerar não perde nada. */
async function numberCup(leagueId: number, stages: Board[], start: number, end: number) {
  if (!stages.length) return
  const rows = (await q('SELECT id, round, round_label, kickoff_at, home_team, away_team, note, status FROM matches WHERE league_id = ? AND external_id IS NOT NULL AND kickoff_at >= ? AND kickoff_at < ? ORDER BY kickoff_at, id', [
    leagueId, sqlTime(start), sqlTime(end),
  ])).map((r) => ({ ...r, kick: fromSql(r.kickoff_at) })) as (NMatch & { round: number; round_label: string | null })[]
  const byStage = new Map<number, { label: string; matches: typeof rows }>()
  for (const m of rows) {
    const s = stages.find((s) => m.kick >= isoTs(s.startDate) && m.kick < isoTs(s.endDate))
    if (!s) continue
    const g = byStage.get(Number(s.value)) ?? { label: cupStageLabel(String(s.label ?? '')), matches: [] }
    g.matches.push(m)
    byStage.set(Number(s.value), g)
  }
  for (const [value, g] of byStage) {
    const numbered = cupStageRounds(g.matches, g.label)
    for (const m of g.matches) {
      const [seq, label] = numbered.get(m.id)!
      const round = value * 20 + Math.min(19, seq)
      if (m.round !== round || m.round_label !== label) await exec('UPDATE matches SET round = ?, round_label = ? WHERE id = ?', [round, label, m.id])
    }
  }
}

/**
 * Rodadas oficiais do TheSportsDB que ainda não estão completas. No máximo uma leva a cada 10 minutos
 * somando todas as ligas (ele bloqueia quem consulta demais); a leva continua de onde a anterior parou.
 */
async function fetchOfficialRounds(leagueId: number, season: number, [tsdbId, format, teams]: [number, string, number]) {
  const state = await getState()
  if (nowTs() - Number(state.tsdb_at ?? 0) < 600) return
  const total = teams * 2 - 2
  const games = Math.floor(teams / 2)
  const have = new Map((await q('SELECT round, COUNT(*) AS games FROM official_rounds WHERE league_id = ? AND season = ? GROUP BY round', [leagueId, season])).map((r) => [r.round as number, Number(r.games)]))
  const missing = Array.from({ length: total }, (_, i) => i + 1).filter((r) => (have.get(r) ?? 0) < games)
  if (!missing.length) return
  const [curLeague, curRound] = String(state.tsdb_cursor ?? '').split(':').map(Number)
  const after = curLeague === leagueId ? curRound || 0 : 0
  const pick = [...new Set([...missing.filter((r) => r > after), ...missing])].slice(0, TSDB_PER_RUN)
  const seasonText = format === 'Y-Y' ? `${season}-${season + 1}` : String(season)
  await setState({ tsdb_at: nowTs(), tsdb_cursor: `${leagueId}:${pick[pick.length - 1]}` })
  const res = await fetchMany(new Map(pick.map((r) => [r, TSDB_ROUND_URL(tsdbId, seasonText, r)])))
  for (const [round, data] of res) {
    for (const e of data?.events ?? []) {
      if (Number(e.intRound) !== round) continue
      await exec('INSERT INTO official_rounds (league_id, season, home_key, away_key, round) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE round = VALUES(round)', [
        leagueId, season, teamKey(String(e.strHomeTeam)), teamKey(String(e.strAwayTeam)), round,
      ])
    }
  }
}

/* ---------- Execução ---------- */

/** Dias de jogos que já deviam ter começado e ainda não têm placar, por liga. */
async function pendingDays(now: number) {
  const rows = await q("SELECT league_id, kickoff_at FROM matches WHERE external_id IS NOT NULL AND home_score IS NULL AND (status IS NULL OR status <> 'void') AND kickoff_at BETWEEN ? AND ?", [
    sqlTime(now - 10 * DAY), sqlTime(now),
  ])
  const out = new Map<number, Set<string>>()
  for (const r of rows) {
    const s = out.get(r.league_id) ?? new Set<string>()
    s.add(espnDay(fromSql(r.kickoff_at)))
    out.set(r.league_id, s)
  }
  return out
}

/** Marca em cada liga se há jogo ao vivo (começou há menos de 2h30 e não terminou). */
async function markLive(now: number) {
  const live = (
    await q("SELECT DISTINCT league_id FROM matches WHERE external_id IS NOT NULL AND home_score IS NULL AND (status IS NULL OR status <> 'void') AND kickoff_at BETWEEN ? AND ?", [
      sqlTime(now - 150 * 60), sqlTime(now + 5 * 60),
    ])
  ).map((r) => r.league_id as number)
  await exec('UPDATE leagues SET has_live = 0 WHERE has_live = 1')
  if (live.length) await exec(`UPDATE leagues SET has_live = 1 WHERE id IN (${ph(live)})`, live)
}

/** Escudo e temporada das ligas do catálogo que ainda não têm (para o seletor de ligas mostrar todos). */
async function fillLeagueInfo() {
  const rows = await q("SELECT id, external_id FROM leagues WHERE logo_url IS NULL AND external_id IS NOT NULL AND external_id <> '' AND is_active = 1 ORDER BY sort_order LIMIT ?", [INFO_PER_RUN])
  if (!rows.length) return
  const boards = await fetchMany(new Map(rows.map((r) => [r.id as number, `${ESPN_BASE}${r.external_id}/scoreboard`])))
  for (const [id, b] of boards) if (b) await saveLeagueInfo(id, b)
}

/** Uma sincronização por vez, também entre processos (GET_LOCK numa conexão própria). */
let running = false
async function withLock<T>(fn: () => Promise<T>): Promise<T | null> {
  if (running) return null
  running = true
  const conn = await pool.getConnection()
  try {
    const [rows] = await conn.query("SELECT GET_LOCK('bolao_sync', 0) AS ok")
    if (Number((rows as Row[])[0].ok) !== 1) return null
    try {
      return await fn()
    } finally {
      await conn.query("SELECT RELEASE_LOCK('bolao_sync')")
    }
  } finally {
    conn.release()
    running = false
  }
}

export type SyncStats = { skipped?: boolean; leagues: number; requests: number; events: number; new: number; results: number; failed: number }

/** 'auto': cada liga no seu ritmo; 'full' (botão da administração): tabela completa de todas. */
export async function syncRun(mode: 'auto' | 'full'): Promise<SyncStats> {
  const result = await withLock(async () => {
    const now = nowTs()
    await setState({ last_tick: now, last_quick: now, ...(mode === 'full' ? { last_full: now } : {}) })
    try {
      const leagues = await syncLeagues()
      const plan = await syncPlan(leagues, mode, now)
      const budget = mode === 'full' ? ADMIN_BUDGET : BUDGET
      const stats: SyncStats = { leagues: plan.size, requests: 0, events: 0, new: 0, results: 0, failed: 0 }
      if (mode === 'auto') await fillLeagueInfo().catch((e) => console.error('[bolao] escudos das ligas:', e))
      if (!plan.size) return stats

      // 1) Página padrão de cada liga: calendário, temporada e jogos do momento.
      const boards = await fetchMany(new Map([...plan.keys()].map((code) => [code, `${ESPN_BASE}${leagues.get(code)!.slug}/scoreboard`])))
      let requests = boards.size

      // 2) Dias a buscar: jogos sem placar, a janela da tabela completa, o começo da temporada.
      const pending = await pendingDays(now)
      const dayUrls = new Map<string, string>()
      const add = (code: string, day: string) => {
        const key = `${code}|${day}`
        if (dayUrls.has(key)) return true
        if (requests >= budget) return false
        dayUrls.set(key, `${ESPN_BASE}${leagues.get(code)!.slug}/scoreboard?dates=${day}`)
        requests++
        return true
      }
      for (const code of plan.keys()) if (boards.get(code)) for (const d of pending.get(leagues.get(code)!.id) ?? []) add(code, d)
      for (const [code, p] of plan) if (p.full && boards.get(code)) for (const d of windowDays(leagues.get(code)!, boards.get(code), now)) add(code, d)
      const backfill = new Map<string, [string[], string]>()
      for (const [code, p] of plan) {
        const board = boards.get(code)
        if (!p.backfill || !board) continue
        const [days, doneDay] = backfillDays(leagues.get(code)!, board, now)
        const taken: string[] = []
        for (const d of days.slice(0, BACKFILL_DAYS)) {
          if (!add(code, d)) break
          taken.push(d)
        }
        const covered = taken.length === days.length ? doneDay : taken[taken.length - 1]
        if (covered) backfill.set(code, [taken, covered])
      }
      const dayBoards = dayUrls.size ? await fetchMany(dayUrls) : new Map<string, Board>()
      stats.requests = requests

      // 3) Junta os jogos de cada liga, sem repetir.
      const events = new Map<string, Map<string, Board>>()
      const failedDays = new Map<string, Set<string>>()
      const push = (code: string, list: Board[]) => {
        const m = events.get(code) ?? new Map<string, Board>()
        for (const e of list ?? []) m.set(String(e.id), e)
        events.set(code, m)
      }
      for (const [code, b] of boards) if (b) push(code, b.events)
      for (const [key, b] of dayBoards) {
        const [code, day] = key.split('|')
        if (!b) {
          stats.failed++
          failedDays.set(code, (failedDays.get(code) ?? new Set()).add(day))
          continue
        }
        push(code, b.events)
      }

      // 4) Grava, numera as rodadas e anota o controle de cada liga.
      for (const [code, p] of plan) {
        const l = leagues.get(code)!
        const board = boards.get(code)
        if (!board) {
          stats.failed++
          await exec('UPDATE leagues SET synced_quick_at = ?, sync_failures = ? WHERE id = ?', [now, Math.min(20, l.sync_failures + 1), l.id])
          continue
        }
        await saveLeagueInfo(l.id, board)
        const saved = await saveEvents(l.id, [...(events.get(code)?.values() ?? [])], l.cup ? stagesOf(board) : [])
        stats.events += saved.events
        stats.new += saved.new
        stats.results += saved.results
        await numberLeagueRounds(l, board)
        let backfillDay = l.backfill_day
        const bf = backfill.get(code)
        if (bf && !bf[0].some((d) => failedDays.get(code)?.has(d))) backfillDay = bf[1]
        await exec('UPDATE leagues SET synced_quick_at = ?, synced_full_at = ?, sync_failures = 0, backfill_day = ? WHERE id = ?', [now, p.full ? now : l.synced_full_at, backfillDay, l.id])
      }
      await markLive(now)
      await setState({ last_error: '' })
      clearRoundCache()
      return stats
    } catch (e) {
      await setState({ last_error: `${sqlTime(nowTs()).slice(5, 16)} ${(e as Error).message.slice(0, 200)}` })
      throw e
    }
  })
  return result ?? { skipped: true, leagues: 0, requests: 0, events: 0, new: 0, results: 0, failed: 0 }
}

/** Ligas recém-escolhidas que nunca sincronizaram: busca já a página padrão (os jogos do momento aparecem na hora). */
export async function fetchNewLeagues(ids: number[]) {
  if (!ids.length) return false
  const rows = await q(`SELECT * FROM leagues WHERE id IN (${ph(ids)}) AND synced_quick_at = 0 AND external_id IS NOT NULL AND external_id <> ''`, ids)
  if (!rows.length) return false
  await withLock(async () => {
    const boards = await fetchMany(new Map(rows.map((r) => [r.code as string, `${ESPN_BASE}${r.external_id}/scoreboard`])))
    for (const r of rows) {
      const board = boards.get(r.code)
      if (!board) continue
      const l = asLeague(r)
      await saveLeagueInfo(l.id, board)
      await saveEvents(l.id, board.events ?? [], l.cup ? stagesOf(board) : [])
      await numberLeagueRounds(l, board)
    }
    clearRoundCache()
  }).catch((e) => console.error('[bolao] liga nova:', e))
  return true
}

/** Sincronização automática dentro do servidor: a cada minuto, sem cron. */
export function startScheduler() {
  const tick = async () => {
    try {
      const state = await getState()
      if (nowTs() - Number(state.last_tick ?? 0) >= SYNC_TICK) await syncRun('auto')
    } catch (e) {
      console.error('[bolao] sincronização:', (e as Error).message)
    }
  }
  setTimeout(tick, 5_000)
  setInterval(tick, SYNC_TICK * 1000).unref()
}

