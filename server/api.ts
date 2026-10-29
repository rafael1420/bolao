// API JSON do app (/api/...). GET lê; POST muda algo e exige o cabeçalho X-Requested-With (bloqueia
// formulários de outros sites: o navegador não deixa outro site mandar esse cabeçalho sem permissão).
import { Hono, type Context } from 'hono'
import { randomBytes } from 'node:crypto'
import { exec, notVoid, one, ph, q, tx, type Row } from './db.ts'
import { env } from './env.ts'
import {
  checkPassword, clearFailures, clientIp, consumeReset, createReset, currentUser, endOtherSessions, endSession, findReset, hashPassword, isAdmin,
  lockSeconds, recordFailure, resetAllowed, resetUrl, sendResetMail, siteUrl, startSession, waitText, type User,
} from './auth.ts'
import {
  TEAM_COLUMNS, TEAM_JOINS, clearRoundCache, closedPredictions, groupLeagueIds, groupPredictable, groupRanking, groupRankings, isLive, memberGroup,
  pointsByRound, recalculateGroup, recalculateMatch, roundContext, rulesOf, scoredCounts, scoredRounds, withKick, type Group, type MatchRow, type RankRow,
} from './bolao.ts'
import {
  canPredict, pointsReason, predictReasonText, predictStatus, roundChipText, roundLockedText, roundNextDeadline, roundUiState, ROUND_LATE_MARGIN, type RoundCtx,
} from './rounds.ts'
import { COUNTRIES, MAX_GROUP_LEAGUES, countryName, countryOrder } from './leagues.ts'
import { fetchNewLeagues, syncRun } from './sync/espn.ts'
import { getState } from './schema.ts'
import { clock, fromSql, nowTs, plural, sqlDate, sqlTime } from './time.ts'

type Env = { Variables: { user: User | null } }
export const api = new Hono<Env>()

class HttpError extends Error {
  constructor(public status: number, message: string, public fields: Record<string, string> = {}) {
    super(message)
  }
}
const fail = (message: string, status = 400, fields: Record<string, string> = {}): never => {
  throw new HttpError(status, message, fields)
}
/** Erro com mensagens por campo; a primeira vira a mensagem geral. */
const failFields = (fields: Record<string, string>) => fail(Object.values(fields)[0], 422, fields)

api.onError((err, c) => {
  if (err instanceof HttpError) return c.json({ error: err.message, fields: err.fields }, err.status as 400)
  console.error('[bolao]', err)
  return c.json({ error: 'Não foi possível concluir agora. Tente de novo em instantes.' }, 500)
})

api.use('*', async (c, next) => {
  c.header('Cache-Control', 'no-store')
  if (c.req.method === 'POST' && c.req.header('x-requested-with') !== 'bolao') fail('Requisição recusada.', 403)
  await next()
})

/* ---------- Ajudantes ---------- */

const body = async (c: Context): Promise<Row> => {
  try {
    const b = await c.req.json()
    return b && typeof b === 'object' ? b : {}
  } catch {
    return {}
  }
}
const str = (v: unknown) => (typeof v === 'string' || typeof v === 'number' ? String(v).trim() : '')
const raw = (v: unknown) => (typeof v === 'string' ? v : '')
const int = (v: unknown) => {
  const n = Number(v)
  return Number.isInteger(n) ? n : 0
}
/** Pontos de 0 a 20 ou null. */
const points = (v: unknown) => {
  const s = str(v)
  return /^\d{1,2}$/.test(s) && Number(s) <= 20 ? Number(s) : null
}
const validEmail = (e: string) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e) && e.length <= 150

async function needUser(c: Context): Promise<User> {
  return (await currentUser(c)) ?? fail('Entre na sua conta para continuar.', 401)
}
async function needAdmin(c: Context) {
  const u = await needUser(c)
  if (!isAdmin(u)) fail('Só a administração do site pode fazer isso.', 403)
  return u
}
async function needMember(_c: Context, groupId: number, userId: number): Promise<Group> {
  return (await memberGroup(groupId, userId)) ?? fail('Você não está neste bolão, ou ele não existe mais.', 404)
}
const needOwner = (g: Group, u: User) => {
  if (g.owner_id !== u.id) fail('Só o organizador pode alterar este bolão.', 403)
}

const userOut = (u: User | null) => (u ? { ...u, isAdmin: isAdmin(u) } : null)
const inviteCode = () => randomBytes(5).toString('hex')

/** Escudo da ESPN já reduzido pelo próprio CDN (64 px pesa ~6 KB; o original, ~60 KB). */
export function espnImg(url: string | null | undefined, size: number) {
  const m = url?.match(/^https:\/\/a\.espncdn\.com(\/i\/[A-Za-z0-9/_.-]+\.png)$/)
  return m ? `https://a.espncdn.com/combiner/i?img=${m[1]}&w=${size}&h=${size}` : null
}

const initials = (name: string) => {
  const skip = new Set(['de', 'da', 'do', 'das', 'dos', 'e', 'fc', 'ec', 'sc', 'ac', 'cf', 'cr', 'club', 'clube', 'esporte', 'sport'])
  const words = name.replace(/[^\p{L}\p{N}]+/gu, ' ').trim().split(/\s+/).filter(Boolean)
  const main = words.filter((w) => !skip.has(w.toLowerCase()))
  const use = main.length ? main : words
  if (!use.length) return '?'
  return (use.length === 1 ? use[0].slice(0, 3) : use.slice(0, 3).map((w) => w[0]).join('')).toUpperCase()
}

const teamOut = (m: Row, side: 'home' | 'away') => {
  const name = String(m[`${side}_team`] ?? '')
  const abbr = String(m[`${side}_abbr`] ?? '').replace(/[^\p{L}\p{N}]/gu, '')
  return {
    name,
    short: m[`${side}_short`] || name,
    abbr: abbr ? abbr.slice(0, 3).toUpperCase() : initials(name),
    logo: espnImg(m[`${side}_logo`], 96),
    color: m[`${side}_color`] ?? null,
    color2: m[`${side}_color2`] ?? null,
  }
}

const leagueOut = (l: Row) => ({
  id: l.id as number,
  name: l.name as string,
  short: (l.short_name || l.name) as string,
  country: (l.country || 'OUT') as string,
  kind: (l.kind || 'liga') as string,
  logo: espnImg(l.logo_url, 64),
})

const rankOut = (r: RankRow) => ({ id: r.id, name: r.name, pos: r.pos, total: r.total, exact: r.exact, outcomes: r.outcomes, played: r.played })

const ROUND_MODES: Record<string, [string, string]> = {
  rodada: ['Rodada atual', 'Só a rodada da vez aceita palpite. A próxima abre quando todos os jogos da atual começarem, ou 48 h antes do primeiro jogo dela.'],
  duas: ['Atual e próxima', 'Dá para palpitar na rodada da vez e na seguinte.'],
  livre: ['Livre', 'Qualquer jogo já marcado aceita palpite até começar.'],
}
const DEADLINE_MODES: Record<string, [string, string]> = {
  jogo: ['Até cada jogo', 'Cada palpite pode mudar até o seu jogo começar.'],
  rodada: ['Até o 1º jogo da rodada', 'Quando a rodada começa, todos os palpites dela fecham.'],
}

/** Ligas que podem entrar num bolão: ativas, ou que o bolão já tinha. */
async function validLeagueIds(keep: number[] = []) {
  const rows = await q('SELECT id, is_active FROM leagues')
  return new Set(rows.filter((r) => r.is_active || keep.includes(r.id)).map((r) => r.id as number))
}
const pickLeagues = (v: unknown, valid: Set<number>) => [...new Set((Array.isArray(v) ? v : []).map(int).filter((id) => valid.has(id)))]

/* ---------- Sessão e conta ---------- */

api.get('/session', async (c) => c.json({ user: userOut(await currentUser(c)), csrf: '', siteName: env.siteName, mail: !!env.smtp.host }))

api.post('/auth/login', async (c) => {
  const b = await body(c)
  const email = str(b.email).toLowerCase()
  const ip = clientIp(c)
  const lockedMsg = (w: number) => `Muitas tentativas sem acertar a senha. Por segurança, espere ${waitText(w)} e tente de novo, ou use "Esqueci minha senha".`
  const wait = await lockSeconds(email, ip)
  if (wait > 0) fail(lockedMsg(wait), 429)
  const row = await one('SELECT id, name, email, password_hash FROM users WHERE email = ?', [email])
  if (row && (await checkPassword(raw(b.password), row.password_hash))) {
    await clearFailures(email, ip)
    await startSession(c, row.id)
    return c.json({ user: userOut({ id: row.id, name: row.name, email: row.email }) })
  }
  await recordFailure(email, ip)
  const w = await lockSeconds(email, ip)
  fail(w > 0 ? lockedMsg(w) : 'E-mail ou senha incorretos.', w > 0 ? 429 : 400)
})

api.post('/auth/register', async (c) => {
  const b = await body(c)
  const name = str(b.name)
  const email = str(b.email).toLowerCase()
  const password = raw(b.password)
  const errors: Record<string, string> = {}
  if (!name || name.length > 100) errors.name = 'O nome precisa ter entre 1 e 100 letras.'
  if (!validEmail(email)) errors.email = 'Informe um e-mail válido, como nome@exemplo.com.'
  if (password.length < 8) errors.password = 'A senha precisa ter pelo menos 8 caracteres.'
  else if (password.length > 200) errors.password = 'A senha pode ter no máximo 200 caracteres.'
  if (!errors.email && (await one('SELECT id FROM users WHERE email = ?', [email]))) errors.email = 'Já existe uma conta com este e-mail. Entre com ele ou use outro.'
  if (Object.keys(errors).length) failFields(errors)
  const res = await exec('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)', [name, email, await hashPassword(password)])
  await startSession(c, res.insertId)
  return c.json({ user: userOut({ id: res.insertId, name, email }) })
})

api.post('/auth/logout', async (c) => {
  await endSession(c)
  return c.json({ user: null })
})

api.post('/auth/forgot', async (c) => {
  const email = str((await body(c)).email).toLowerCase()
  if (!validEmail(email)) failFields({ email: 'Informe um e-mail válido, como nome@exemplo.com.' })
  const ip = clientIp(c)
  if (!(await resetAllowed(ip))) fail('Muitos pedidos de link a partir desta conexão. Espere uma hora e tente de novo.', 429)
  const u = await one('SELECT id, name, email FROM users WHERE email = ?', [email])
  // Resposta igual exista ou não a conta.
  if (u && (await resetAllowed(ip, u.id))) {
    const token = await createReset(u.id, 'user', ip)
    await sendResetMail(u.email, u.name, resetUrl(c, token))
  }
  return c.json({ ok: true })
})

api.get('/auth/reset', async (c) => {
  const r = await findReset(str(c.req.query('token')).toLowerCase())
  if (!r) fail('Este link de redefinição não funciona mais.', 404)
  return c.json({ email: r!.email })
})

api.post('/auth/reset', async (c) => {
  const b = await body(c)
  const password = raw(b.password)
  if (password.length < 8 || password.length > 200) failFields({ password: 'A senha precisa ter entre 8 e 200 caracteres.' })
  const r = await consumeReset(str(b.token).toLowerCase(), password)
  if (!r) fail('Este link de redefinição não funciona mais. Peça um novo.', 404)
  await endSession(c)
  return c.json({ ok: true })
})

api.post('/account/name', async (c) => {
  const u = await needUser(c)
  const name = str((await body(c)).name)
  if (!name || name.length > 100) failFields({ name: 'O nome precisa ter entre 1 e 100 letras.' })
  await exec('UPDATE users SET name = ? WHERE id = ?', [name, u.id])
  return c.json({ user: userOut({ ...u, name }) })
})

api.post('/account/password', async (c) => {
  const u = await needUser(c)
  const b = await body(c)
  const row = await one('SELECT password_hash FROM users WHERE id = ?', [u.id])
  const errors: Record<string, string> = {}
  if (!(await checkPassword(raw(b.current), row!.password_hash))) errors.current = 'A senha atual está incorreta.'
  const pw = raw(b.password)
  if (pw.length < 8) errors.password = 'A nova senha precisa ter pelo menos 8 caracteres.'
  else if (pw.length > 200) errors.password = 'A nova senha pode ter no máximo 200 caracteres.'
  if (Object.keys(errors).length) failFields(errors)
  await exec('UPDATE users SET password_hash = ? WHERE id = ?', [await hashPassword(pw), u.id])
  await exec('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [sqlTime(nowTs()), u.id])
  await endOtherSessions(c, u.id)
  return c.json({ ok: true })
})

/* ---------- Bolões ---------- */

api.get('/groups', async (c) => {
  const u = await needUser(c)
  const groups = await q<Group & { members: number }>(
    `SELECT g.id, g.name, g.owner_id, g.round_mode, g.deadline_mode, (SELECT COUNT(*) FROM group_members WHERE group_id = g.id) AS members
     FROM bolao_groups g JOIN group_members m ON m.group_id = g.id WHERE m.user_id = ? ORDER BY g.name`,
    [u.id],
  )
  const ids = groups.map((g) => g.id)
  const [rankings, predictable] = await Promise.all([groupRankings(ids), groupPredictable(groups, u.id)])
  const leagues = ids.length
    ? await q(`SELECT gl.group_id, l.id, l.name, l.short_name, l.country, l.kind, l.logo_url FROM group_leagues gl JOIN leagues l ON l.id = gl.league_id WHERE gl.group_id IN (${ph(ids)}) ORDER BY l.sort_order`, ids)
    : []
  return c.json({
    groups: groups.map((g) => {
      const ranked = rankings.get(g.id) ?? []
      const mine = ranked.find((r) => r.id === u.id)
      return {
        id: g.id,
        name: g.name,
        isOwner: g.owner_id === u.id,
        members: Number(g.members),
        pos: mine?.pos ?? null,
        points: mine?.total ?? 0,
        leader: ranked[0] ? { name: ranked[0].name, total: ranked[0].total } : null,
        pending: predictable.get(g.id)?.pending ?? 0,
        next: predictable.get(g.id)?.next ?? null,
        leagues: leagues.filter((l) => l.group_id === g.id).map(leagueOut),
      }
    }),
  })
})

api.get('/leagues', async (c) => {
  await needUser(c)
  const selected = str(c.req.query('selected')).split(',').map(int).filter(Boolean)
  const now = nowTs()
  const rows = await q(
    `SELECT l.id, l.name, l.short_name, l.country, l.kind, l.logo_url, l.season_label, l.is_active,
            (SELECT MAX(kickoff_at) FROM matches m WHERE m.league_id = l.id) AS last_kick,
            (SELECT MIN(kickoff_at) FROM matches m WHERE m.league_id = l.id AND m.kickoff_at > ?) AS next_kick
     FROM leagues l ORDER BY l.sort_order, l.name`,
    [sqlTime(now)],
  )
  const byCountry = new Map<string, Row[]>()
  for (const r of rows) {
    if (!r.is_active && !selected.includes(r.id)) continue
    const last = r.last_kick ? fromSql(r.last_kick) : null
    const next = r.next_kick ? fromSql(r.next_kick) : null
    // Sem jogo nos próximos 60 dias nem nos últimos 30 (sem jogos no banco, não dá para saber).
    const offSeason = last !== null && (next === null || next > now + 60 * 86400) && last < now - 30 * 86400
    const list = byCountry.get(r.country) ?? []
    list.push({ id: r.id, name: r.name, short: r.short_name || r.name, kind: r.kind, season: r.season_label, offSeason, logo: espnImg(r.logo_url, 64) })
    byCountry.set(r.country, list)
  }
  const countries = [...byCountry]
    .sort((a, b) => countryOrder(a[0]) - countryOrder(b[0]))
    .map(([code, leagues]) => ({ code, name: countryName(code), featured: COUNTRIES[code]?.[2] ?? false, leagues }))
  return c.json({ countries, max: MAX_GROUP_LEAGUES })
})

api.post('/groups/create', async (c) => {
  const u = await needUser(c)
  const b = await body(c)
  const name = str(b.name)
  const password = raw(b.password)
  const leagues = pickLeagues(b.leagues, await validLeagueIds())
  const pts = { exact: points(b.exact), winner: points(b.winner), diff: points(b.diff) }
  const errors: Record<string, string> = {}
  if (!name || name.length > 100) errors.name = 'O nome precisa ter entre 1 e 100 letras.'
  if (password && password.length < 4) errors.password = 'A senha do bolão precisa ter pelo menos 4 caracteres, ou fique em branco.'
  if (!leagues.length) errors.leagues = 'Marque pelo menos uma liga para o bolão.'
  else if (leagues.length > MAX_GROUP_LEAGUES) errors.leagues = `Escolha no máximo ${MAX_GROUP_LEAGUES} ligas.`
  if (Object.values(pts).some((v) => v === null)) errors.points = 'Os pontos devem ficar entre 0 e 20.'
  if (Object.keys(errors).length) failFields(errors)
  const id = await tx(async (t) => {
    const res = await t.exec('INSERT INTO bolao_groups (name, owner_id, invite_code, password_hash, exact_points, winner_points, goal_diff_points) VALUES (?, ?, ?, ?, ?, ?, ?)', [
      name, u.id, inviteCode(), password ? await hashPassword(password) : null, pts.exact, pts.winner, pts.diff,
    ])
    await t.exec("INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, 'owner')", [res.insertId, u.id])
    for (const l of leagues) await t.exec('INSERT INTO group_leagues (group_id, league_id) VALUES (?, ?)', [res.insertId, l])
    return res.insertId
  })
  const fetching = await fetchNewLeagues(leagues)
  return c.json({ id, fetching })
})

const codeOf = (v: unknown) => str(v).replace(/\s+/g, '').toLowerCase().slice(0, 20)

api.get('/invite', async (c) => {
  const u = await needUser(c)
  const code = codeOf(c.req.query('code'))
  const g = code ? await one<Group>('SELECT * FROM bolao_groups WHERE invite_code = ?', [code]) : null
  if (!g) fail('Esse convite não existe mais. Peça um novo link para quem organiza.', 404)
  const [member, owner, count, leagues] = await Promise.all([
    one('SELECT 1 AS ok FROM group_members WHERE group_id = ? AND user_id = ?', [g!.id, u.id]),
    one('SELECT name FROM users WHERE id = ?', [g!.owner_id]),
    one('SELECT COUNT(*) AS n FROM group_members WHERE group_id = ?', [g!.id]),
    q('SELECT l.* FROM group_leagues gl JOIN leagues l ON l.id = gl.league_id WHERE gl.group_id = ? ORDER BY l.sort_order, l.name', [g!.id]),
  ])
  return c.json({
    id: g!.id, name: g!.name, owner: owner?.name ?? '', members: Number(count?.n ?? 0), isMember: !!member, needsPassword: g!.password_hash !== null,
    leagues: leagues.map(leagueOut), rules: rulesOf(g!),
  })
})

api.post('/invite/join', async (c) => {
  const u = await needUser(c)
  const b = await body(c)
  const code = codeOf(b.code)
  const g = code ? await one<Group>('SELECT * FROM bolao_groups WHERE invite_code = ?', [code]) : null
  if (!g) fail('Esse convite não existe mais. Peça um novo link para quem organiza.', 404)
  if (g!.password_hash !== null) {
    const key = `bolao:${g!.id}`
    const ip = clientIp(c)
    const wait = await lockSeconds(key, ip)
    if (wait > 0) fail(`Muitas tentativas erradas. Espere ${waitText(wait)} e tente de novo.`, 429, { password: 'Muitas tentativas.' })
    if (!(await checkPassword(raw(b.password), g!.password_hash))) {
      await recordFailure(key, ip)
      fail('Senha incorreta. Confira com quem organiza.', 422, { password: 'Senha incorreta.' })
    }
  }
  await exec("INSERT IGNORE INTO group_members (group_id, user_id, role) VALUES (?, ?, 'member')", [g!.id, u.id])
  return c.json({ id: g!.id })
})

api.get('/group', async (c) => {
  const u = await needUser(c)
  const g = await needMember(c, int(c.req.query('id')), u.id)
  const ids = await groupLeagueIds(g.id)
  const [leagues, ranking, scored] = await Promise.all([
    q(`SELECT * FROM leagues WHERE id IN (${ph(ids)}) ORDER BY sort_order, name`, ids),
    groupRanking(g.id),
    scoredCounts(g.id),
  ])
  const me = ranking.find((r) => r.id === u.id) ?? null
  const above = me ? [...ranking].reverse().find((r) => r.pos < me.pos && r.total > me.total) ?? null : null
  return c.json({
    id: g.id,
    name: g.name,
    ownerId: g.owner_id,
    isOwner: g.owner_id === u.id,
    inviteCode: g.invite_code,
    inviteUrl: `${siteUrl(c)}/convite/${g.invite_code}`,
    hasPassword: g.password_hash !== null,
    rules: {
      ...rulesOf(g),
      roundMode: g.round_mode,
      deadlineMode: g.deadline_mode,
      roundModeText: ROUND_MODES[g.round_mode] ?? ROUND_MODES.rodada,
      deadlineModeText: DEADLINE_MODES[g.deadline_mode] ?? DEADLINE_MODES.jogo,
    },
    leagues: leagues.map(leagueOut),
    ranking: ranking.map(rankOut),
    me: me && rankOut(me),
    above: above && rankOut(above),
    scored,
  })
})

/** Um jogo na aba Rodada: situação para palpite, prazo, meu palpite, pontos e (jogo fechado) os palpites de todos. */
function matchOut(m: MatchRow & { origin_label?: string }, ctx: RoundCtx, pred: Row | undefined, now: number, others: unknown) {
  const st = predictStatus(m, ctx)
  const finished = m.home_score !== null
  let close = m.kick
  const [number, resched] = ctx.where.get(m.id) ?? [m.round, false]
  const start = ctx.rounds.get(number)?.start
  if (st.ok && !resched && ctx.deadline === 'rodada' && start) close = Math.min(close, start)
  const lockText = st.reason === 'deadline' ? 'Prazo da rodada encerrado' : st.reason === 'locked' ? (resched ? 'Abre na semana do jogo' : roundLockedText(ctx, number, now)) : null
  const reason = pred && finished ? pointsReason(pred.home_pred, pred.away_pred, m.home_score!, m.away_score!) : null
  return {
    id: m.id,
    leagueId: m.league_id,
    round: m.round,
    kickoff: m.kick,
    closeAt: close,
    note: m.note,
    origin: m.origin_label ?? null,
    home: teamOut(m, 'home'),
    away: teamOut(m, 'away'),
    score: finished ? [m.home_score, m.away_score] : null,
    live: !st.ok && isLive(m),
    liveScore: isLive(m) ? [m.live_home_score, m.live_away_score] : null,
    statusDetail: m.status_detail,
    open: st.ok,
    locked: st.reason === 'locked' || st.reason === 'deadline',
    reason: st.reason,
    lockText,
    pred: pred ? { home: pred.home_pred, away: pred.away_pred } : null,
    points: pred && finished ? Number(pred.points ?? 0) : null,
    pointsReason: reason,
    exact: reason === 'Placar exato',
    others: others ?? null,
  }
}

api.get('/group/round', async (c) => {
  const u = await needUser(c)
  const g = await needMember(c, int(c.req.query('id')), u.id)
  const now = nowTs()
  const ids = await groupLeagueIds(g.id)
  const names = new Map((await q(`SELECT id, name FROM leagues WHERE id IN (${ph(ids)}) ORDER BY sort_order, name`, ids)).map((r) => [r.id as number, r.name as string]))
  let leagueId = int(c.req.query('league'))
  if (!names.has(leagueId)) {
    const next = await one(`SELECT league_id FROM matches WHERE league_id IN (${ph(ids)}) AND kickoff_at > ? AND ${notVoid('matches')} ORDER BY kickoff_at LIMIT 1`, [...ids, sqlTime(now)])
    leagueId = next?.league_id ?? [...names.keys()][0] ?? 0
  }
  const [ctx, byId] = await roundContext(leagueId, g, now)
  const mine = new Map((await q('SELECT match_id, home_pred, away_pred, points FROM predictions WHERE user_id = ? AND group_id = ?', [u.id, g.id])).map((p) => [p.match_id as number, p]))

  const rounds = [...ctx.rounds.values()].map((info) => {
    const stats = { total: info.ids.length, finished: 0, open: 0, pending: 0 }
    for (const id of info.ids) {
      const m = byId.get(id)!
      if (m.home_score !== null) stats.finished++
      else if (canPredict(m, ctx)) {
        stats.open++
        if (!mine.has(id)) stats.pending++
      }
    }
    return {
      number: info.number,
      label: info.label,
      chip: info.number >= 20 && info.label !== `Rodada ${info.number}` ? roundChipText(info.label) : String(info.number),
      state: roundUiState(ctx, info.number, byId),
      stats,
    }
  })
  const reqRound = int(c.req.query('round'))
  const round = ctx.numbers.includes(reqRound) ? reqRound : (ctx.current ?? (ctx.numbers.length ? Math.max(...ctx.numbers) : 1))
  const matches = (ctx.rounds.get(round)?.ids ?? []).map((id) => byId.get(id)!)

  // Remarcados de outras rodadas que caem na semana da rodada atual.
  const rescheduled: (MatchRow & { origin_label: string })[] = []
  const cur = ctx.rounds.get(round)
  if (round === ctx.current && !ctx.over && cur && cur.start !== null) {
    const from = cur.start - 86400
    const until = ctx.horizon ?? (cur.end ?? cur.start) + ROUND_LATE_MARGIN
    for (const [id, [origin, isResched]] of ctx.where) {
      const m = byId.get(id)!
      if (isResched && origin !== round && m.kick >= from && m.kick <= until) rescheduled.push({ ...m, origin_label: ctx.rounds.get(origin)?.label ?? '' })
    }
    rescheduled.sort((a, b) => a.kick - b.kick)
  }
  const all = [...matches, ...rescheduled]
  const others = await closedPredictions(g.id, all, now)
  const out = (list: typeof all) => list.map((m) => matchOut(m, ctx, mine.get(m.id), now, others.get(m.id)))

  let state = rounds.find((r) => r.number === round)?.state ?? 'bloqueada'
  let deadline = cur ? roundNextDeadline(ctx, round, new Map(all.map((m) => [m.id, m]))) : null
  for (const m of rescheduled) {
    if (canPredict(m, ctx)) {
      deadline = deadline === null ? m.kick : Math.min(deadline, m.kick)
      if (state !== 'encerrada') state = 'aberta'
    }
  }
  const roundRank = await groupRanking(g.id, round, leagueId)
  const meRound = roundRank.find((r) => r.id === u.id && r.played > 0)

  return c.json({
    leagueId,
    leagueName: names.get(leagueId) ?? '',
    current: ctx.current,
    over: ctx.over,
    deadlineMode: ctx.deadline,
    round,
    label: cur?.label ?? `Rodada ${round}`,
    state,
    deadline,
    lockedText: state === 'bloqueada' ? roundLockedText(ctx, round, now) : null,
    rounds,
    matches: out(matches),
    rescheduled: out(rescheduled),
    roundMe: meRound ? { pos: meRound.pos, total: meRound.total, played: meRound.played } : null,
    now,
  })
})

api.post('/group/predict', async (c) => {
  const u = await needUser(c)
  const b = await body(c)
  const g = await needMember(c, int(b.id), u.id)
  const leagueIds = await groupLeagueIds(g.id)
  const preds: Row = b.preds && typeof b.preds === 'object' ? b.preds : {}
  const ids = Object.keys(preds).map(int).filter(Boolean).slice(0, 200)
  const matches = ids.length
    ? new Map(withKick(await q(`SELECT * FROM matches WHERE id IN (${ph(ids)}) AND league_id IN (${ph(leagueIds)})`, [...ids, ...leagueIds])).map((m) => [m.id as number, m as unknown as MatchRow]))
    : new Map<number, MatchRow>()
  const now = nowTs()
  const saved: number[] = []
  const skipped: { id: number; label?: string; reason: string }[] = []
  for (const id of ids) {
    const m = matches.get(id)
    const score = preds[id]
    if (!m || !score || typeof score !== 'object') {
      skipped.push({ id, reason: 'jogo não encontrado neste bolão' })
      continue
    }
    const home = str(score.home)
    const away = str(score.away)
    const [ctx] = await roundContext(m.league_id, g, now)
    const st = predictStatus(m, ctx)
    let reason: string | null = null
    if (!st.ok) reason = predictReasonText(st, ctx, now)
    else if (home === '' || away === '') reason = home === '' ? 'falta o placar do mandante' : 'falta o placar do visitante'
    else if (!/^\d{1,2}$/.test(home) || !/^\d{1,2}$/.test(away) || Number(home) > 30 || Number(away) > 30) reason = 'use placares de 0 a 30'
    if (reason) {
      skipped.push({ id, label: `${m.home_team} × ${m.away_team}`, reason })
      continue
    }
    await exec(
      'INSERT INTO predictions (user_id, group_id, match_id, home_pred, away_pred) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE home_pred = VALUES(home_pred), away_pred = VALUES(away_pred)',
      [u.id, g.id, id, Number(home), Number(away)],
    )
    saved.push(id)
  }
  return c.json({ saved, skipped, time: clock(now) })
})

api.get('/group/games', async (c) => {
  const u = await needUser(c)
  const g = await needMember(c, int(c.req.query('id')), u.id)
  let ids = await groupLeagueIds(g.id)
  const filter = int(c.req.query('league'))
  if (filter && ids.includes(filter)) ids = [filter]
  const now = nowTs()
  const rows = withKick(
    await q(`SELECT m.*, ${TEAM_COLUMNS} FROM matches m ${TEAM_JOINS()} WHERE m.league_id IN (${ph(ids)}) AND m.kickoff_at BETWEEN ? AND ? AND ${notVoid()} ORDER BY m.kickoff_at, m.id`, [
      ...ids, `${sqlDate(now - 3 * 86400)} 00:00:00`, `${sqlDate(now + 14 * 86400)} 23:59:59`,
    ]),
  )
  const days = new Map<string, unknown[]>()
  for (const m of rows) {
    const day = String(m.kickoff_at).slice(0, 10)
    days.set(day, [
      ...(days.get(day) ?? []),
      {
        id: m.id, leagueId: m.league_id, round: m.round, kickoff: m.kick, home: teamOut(m, 'home'), away: teamOut(m, 'away'),
        score: m.home_score !== null ? [m.home_score, m.away_score] : null, live: isLive(m as never),
        liveScore: isLive(m as never) ? [m.live_home_score, m.live_away_score] : null, statusDetail: m.status_detail,
      },
    ])
  }
  return c.json({ days: [...days].map(([day, matches]) => ({ day, matches })), today: sqlDate(now) })
})

api.get('/group/ranking', async (c) => {
  const u = await needUser(c)
  const g = await needMember(c, int(c.req.query('id')), u.id)
  const list = await scoredRounds(g.id)
  const pick = list.find((r) => r.key === c.req.query('rodada')) ?? list[0] ?? null
  return c.json({
    rounds: list.map((r) => ({ ...r, short: /^Rodada (\d+)$/.test(r.round_name) ? `R${r.round_name.slice(7)}` : roundChipText(r.round_name) })),
    round: pick,
    roundRanking: pick ? (await groupRanking(g.id, pick.round, pick.league_id)).map(rankOut) : [],
    matrix: await pointsByRound(g.id),
  })
})

api.post('/group/settings', async (c) => {
  const u = await needUser(c)
  const b = await body(c)
  const g = await needMember(c, int(b.id), u.id)
  needOwner(g, u)
  let message = 'Alterações salvas.'
  const recalcMsg = async (before: { preds: number }) => {
    await recalculateGroup(g.id)
    if (before.preds > 0) message = `Alterações salvas. Pontos de ${plural(before.preds, 'palpite', 'palpites')} recalculados.`
  }
  switch (str(b.action)) {
    case 'name': {
      const name = str(b.name)
      if (!name || name.length > 100) failFields({ name: 'O nome precisa ter entre 1 e 100 letras.' })
      await exec('UPDATE bolao_groups SET name = ? WHERE id = ?', [name, g.id])
      break
    }
    case 'password': {
      const pw = raw(b.password)
      if (pw.length < 4 || pw.length > 200) failFields({ password: 'A nova senha precisa ter pelo menos 4 caracteres.' })
      await exec('UPDATE bolao_groups SET password_hash = ? WHERE id = ?', [await hashPassword(pw), g.id])
      message = 'Senha do bolão salva.'
      break
    }
    case 'remove_password':
      await exec('UPDATE bolao_groups SET password_hash = NULL WHERE id = ?', [g.id])
      message = 'Senha removida. Quem tiver o link entra direto.'
      break
    case 'leagues': {
      const before = (await groupLeagueIds(g.id)).filter(Boolean)
      const leagues = pickLeagues(b.leagues, await validLeagueIds(before))
      if (!leagues.length) failFields({ leagues: 'Marque pelo menos uma liga.' })
      if (leagues.length > MAX_GROUP_LEAGUES) failFields({ leagues: `Escolha no máximo ${MAX_GROUP_LEAGUES} ligas.` })
      const scored = await scoredCounts(g.id)
      await tx(async (t) => {
        await t.exec('DELETE FROM group_leagues WHERE group_id = ?', [g.id])
        for (const l of leagues) await t.exec('INSERT INTO group_leagues (group_id, league_id) VALUES (?, ?)', [g.id, l])
      })
      await recalcMsg(scored)
      if (await fetchNewLeagues(leagues.filter((l) => !before.includes(l)))) message += ' Buscando a tabela completa; pode levar alguns minutos.'
      break
    }
    case 'rounds': {
      const rm = str(b.roundMode)
      const dm = str(b.deadlineMode)
      if (!ROUND_MODES[rm] || !DEADLINE_MODES[dm]) fail('Escolha uma opção em cada grupo.', 422)
      await exec('UPDATE bolao_groups SET round_mode = ?, deadline_mode = ? WHERE id = ?', [rm, dm, g.id])
      clearRoundCache()
      break
    }
    case 'points': {
      const pts = { exact: points(b.exact), winner: points(b.winner), diff: points(b.diff) }
      if (Object.values(pts).some((v) => v === null)) fail('Os pontos devem ser um número entre 0 e 20.', 422)
      await exec('UPDATE bolao_groups SET exact_points = ?, winner_points = ?, goal_diff_points = ? WHERE id = ?', [pts.exact, pts.winner, pts.diff, g.id])
      await recalcMsg(await scoredCounts(g.id))
      break
    }
    case 'new_code':
      await exec('UPDATE bolao_groups SET invite_code = ? WHERE id = ?', [inviteCode(), g.id])
      message = 'Novo código gerado. O link antigo não funciona mais.'
      break
    case 'transfer': {
      const to = int(b.newOwnerId)
      if (to <= 0 || to === u.id) failFields({ newOwnerId: 'Escolha quem vai organizar o bolão.' })
      if (str(b.confirmName) !== g.name) failFields({ confirmName: 'Para transferir, digite o nome exato do bolão.' })
      const target = await one('SELECT u.name FROM group_members gm JOIN users u ON u.id = gm.user_id WHERE gm.group_id = ? AND gm.user_id = ?', [g.id, to])
      if (!target) fail('Essa pessoa não está mais no bolão. Escolha outra.', 422)
      await tx(async (t) => {
        await t.exec('UPDATE bolao_groups SET owner_id = ? WHERE id = ?', [to, g.id])
        await t.exec("UPDATE group_members SET role = 'member' WHERE group_id = ? AND user_id = ?", [g.id, u.id])
        await t.exec("UPDATE group_members SET role = 'owner' WHERE group_id = ? AND user_id = ?", [g.id, to])
      })
      message = `${target!.name} agora organiza o bolão. Você continua como participante.`
      break
    }
    case 'delete':
      if (str(b.confirmName) !== g.name) failFields({ confirmName: 'Para excluir, digite o nome exato do bolão.' })
      await exec('DELETE FROM bolao_groups WHERE id = ?', [g.id])
      return c.json({ ok: true, deleted: true, message: 'Bolão excluído.' })
    default:
      fail('Ação desconhecida.')
  }
  return c.json({ ok: true, message })
})

api.post('/group/remove-member', async (c) => {
  const u = await needUser(c)
  const b = await body(c)
  const g = await needMember(c, int(b.id), u.id)
  needOwner(g, u)
  if (int(b.userId) === g.owner_id) fail('O organizador não pode ser removido.')
  await exec('DELETE FROM group_members WHERE group_id = ? AND user_id = ?', [g.id, int(b.userId)])
  return c.json({ ok: true })
})

api.post('/group/leave', async (c) => {
  const u = await needUser(c)
  const g = await needMember(c, int((await body(c)).id), u.id)
  if (g.owner_id === u.id) fail('O organizador não pode sair do próprio bolão. Transfira a organização antes.')
  await exec('DELETE FROM group_members WHERE group_id = ? AND user_id = ?', [g.id, u.id])
  return c.json({ ok: true })
})

/* ---------- Administração ---------- */

api.get('/admin/leagues', async (c) => {
  await needAdmin(c)
  const rows = await q('SELECT * FROM leagues ORDER BY sort_order, name')
  rows.sort((a, b) => countryOrder(a.country) - countryOrder(b.country))
  return c.json({ leagues: rows.map((l) => ({ ...leagueOut(l), code: l.code, active: !!l.is_active, auto: !!l.external_id })) })
})

api.get('/admin/results', async (c) => {
  await needAdmin(c)
  const where = [notVoid()]
  const params: number[] = []
  const league = int(c.req.query('league'))
  const round = int(c.req.query('round'))
  if (league) {
    where.push('m.league_id = ?')
    params.push(league)
  }
  if (round) {
    where.push('m.round = ?')
    params.push(round)
  }
  const rows = withKick(await q(`SELECT m.*, l.name AS league_name, ${TEAM_COLUMNS} FROM matches m JOIN leagues l ON l.id = m.league_id ${TEAM_JOINS()} WHERE ${where.join(' AND ')} ORDER BY m.kickoff_at, m.id LIMIT 1000`, params))
  const now = nowTs()
  const out = { waiting: [] as unknown[], upcoming: [] as unknown[], finished: [] as unknown[] }
  for (const m of rows) {
    const row = { id: m.id, league: m.league_name, round: m.round, kickoff: m.kick, home: teamOut(m, 'home'), away: teamOut(m, 'away'), score: m.home_score !== null ? [m.home_score, m.away_score] : null, auto: !!m.external_id }
    ;(m.home_score !== null ? out.finished : m.kick <= now ? out.waiting : out.upcoming).push(row)
  }
  return c.json({ waiting: out.waiting, upcoming: out.upcoming.slice(0, 60), finished: out.finished.reverse().slice(0, 100) })
})

api.post('/admin/score', async (c) => {
  await needAdmin(c)
  const b = await body(c)
  const h = str(b.home)
  const a = str(b.away)
  if (!/^\d{1,2}$/.test(h) || !/^\d{1,2}$/.test(a) || Number(h) > 30 || Number(a) > 30) fail('Informe um placar válido, de 0 a 30.', 422)
  const id = int(b.matchId)
  await exec("UPDATE matches SET home_score = ?, away_score = ? WHERE id = ?", [Number(h), Number(a), id])
  await recalculateMatch(id)
  clearRoundCache()
  const n = await one('SELECT COUNT(*) AS n FROM predictions WHERE match_id = ?', [id])
  return c.json({ message: `Salvo. ${plural(Number(n?.n ?? 0), 'palpite recalculado', 'palpites recalculados')}.` })
})

api.post('/admin/clear-score', async (c) => {
  await needAdmin(c)
  const id = int((await body(c)).matchId)
  await exec('UPDATE matches SET home_score = NULL, away_score = NULL WHERE id = ?', [id])
  await recalculateMatch(id)
  clearRoundCache()
  return c.json({ message: 'Resultado apagado. Os pontos deste jogo foram zerados.' })
})

api.get('/admin/sync', async (c) => {
  await needAdmin(c)
  const state = await getState()
  const rows = await q(
    `SELECT l.id, l.name, l.synced_quick_at, l.synced_full_at, l.sync_failures, (SELECT COUNT(*) FROM matches m WHERE m.league_id = l.id) AS games
     FROM leagues l WHERE EXISTS (SELECT 1 FROM group_leagues gl WHERE gl.league_id = l.id) ORDER BY l.sort_order, l.name`,
  )
  return c.json({
    lastQuick: Number(state.last_quick ?? 0) || null,
    lastFull: Number(state.last_full ?? 0) || null,
    lastError: state.last_error || null,
    detached: true,
    leagues: rows.map((l) => ({ id: l.id, name: l.name, games: Number(l.games), quick: l.synced_quick_at || null, full: l.synced_full_at || null, failures: l.sync_failures })),
  })
})

api.post('/admin/sync', async (c) => {
  await needAdmin(c)
  const r = await syncRun('full')
  if (r.skipped) fail('Já existe uma sincronização em andamento. Tente de novo em um minuto.', 409)
  return c.json({
    message: `Sincronizado: ${r.leagues} ligas, ${r.requests} consultas, ${r.events} jogos lidos, ${r.new} novos, ${r.results} resultados atualizados.${r.failed ? ` ${r.failed} consultas à ESPN falharam.` : ''}`,
  })
})

api.post('/admin/match', async (c) => {
  await needAdmin(c)
  const b = await body(c)
  const leagueId = int(b.leagueId)
  const round = int(b.round)
  const home = str(b.home)
  const away = str(b.away)
  const kick = str(b.kickoff)
  const errors: Record<string, string> = {}
  if (!(await one('SELECT 1 AS ok FROM leagues WHERE id = ?', [leagueId]))) errors.leagueId = 'Escolha uma liga.'
  if (round < 1 || round > 999) errors.round = 'Informe a rodada, de 1 a 999.'
  if (!home || home.length > 100) errors.home = 'Informe o mandante, com até 100 letras.'
  if (!away || away.length > 100) errors.away = 'Informe o visitante, com até 100 letras.'
  else if (home.toLowerCase() === away.toLowerCase()) errors.away = 'Mandante e visitante precisam ser times diferentes.'
  if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(kick)) errors.kickoff = 'Informe a data e o horário do jogo.'
  if (Object.keys(errors).length) failFields(errors)
  await exec('INSERT INTO matches (league_id, round, home_team, away_team, kickoff_at) VALUES (?, ?, ?, ?, ?)', [leagueId, round, home, away, `${kick.replace('T', ' ')}:00`])
  clearRoundCache()
  return c.json({ message: 'Jogo cadastrado.' })
})

/** Lê "25/10/2026 16:00", "25/10 18h30", "2026-10-25 16:00". Sem ano, usa o atual. */
function parseImportDate(rawText: string): string | null {
  const m = rawText.trim().replace(/\s+/g, ' ').match(/^(\d{1,4})[/.-](\d{1,2})(?:[/.-](\d{2,4}))?(?:,|\s|T)+(\d{1,2})(?:[:h](\d{2})?)?h?$/i)
  if (!m) return null
  let y: number, mo: number, d: number
  if (m[1].length === 4) {
    if (!m[3]) return null
    ;[y, mo, d] = [Number(m[1]), Number(m[2]), Number(m[3])]
  } else {
    ;[d, mo] = [Number(m[1]), Number(m[2])]
    y = m[3] ? Number(m[3]) + (m[3].length === 2 ? 2000 : 0) : Number(sqlDate(nowTs()).slice(0, 4))
  }
  const h = Number(m[4])
  const mi = m[5] ? Number(m[5]) : 0
  const dt = new Date(Date.UTC(y, mo - 1, d))
  if (dt.getUTCMonth() !== mo - 1 || dt.getUTCDate() !== d || h > 23 || mi > 59 || y < 2000 || y > 2100) return null
  const p = (n: number) => String(n).padStart(2, '0')
  return `${y}-${p(mo)}-${p(d)} ${p(h)}:${p(mi)}:00`
}

api.post('/admin/import', async (c) => {
  await needAdmin(c)
  const b = await body(c)
  const leagueId = int(b.leagueId)
  const text = raw(b.text)
  if (!(await one('SELECT 1 AS ok FROM leagues WHERE id = ?', [leagueId]))) failFields({ leagueId: 'Escolha uma liga.' })
  if (!text.trim() || text.length > 100_000) failFields({ text: 'Cole de 1 a 500 jogos.' })
  const existing = new Set((await q('SELECT round, home_team, away_team FROM matches WHERE league_id = ?', [leagueId])).map((m) => `${m.round}|${m.home_team.toLowerCase()}|${m.away_team.toLowerCase()}`))
  const seen = new Map<string, number>()
  const rows: Row[] = []
  let counted = 0
  for (const [i, line0] of text.split(/\r\n|\r|\n/).entries()) {
    const line = line0.trim()
    if (!line || line.startsWith('#')) continue
    counted++
    if (counted > 500) {
      rows.push({ line: i + 1, raw: line, status: 'error', message: 'Limite de 500 jogos por importação. Divida o texto em partes.' })
      break
    }
    const parts = (line.includes(';') ? line.split(';') : line.includes('\t') ? line.split('\t') : line.split(',')).map((p) => p.trim().replace(/^["']|["']$/g, ''))
    if (counted === 1 && /rodada/i.test(parts[0] ?? '')) {
      counted--
      continue
    }
    const row: Row = { line: i + 1, raw: line, round: null, kickoff: null, home: '', away: '', status: 'ok', message: '' }
    const errs: string[] = []
    if (parts.length !== 4) errs.push(`esperava 4 colunas (rodada; data e hora; mandante; visitante), achei ${parts.length}`)
    else {
      const [r, d, h, a] = parts
      row.home = h
      row.away = a
      if (!/^\d{1,3}$/.test(r) || Number(r) < 1) errs.push('rodada inválida (use um número de 1 a 999)')
      else row.round = Number(r)
      row.kickoff = parseImportDate(d)
      if (!row.kickoff) errs.push('data e hora inválidas (use 25/10/2026 16:00)')
      if (!h || h.length > 100) errs.push('mandante vazio ou com mais de 100 letras')
      if (!a || a.length > 100) errs.push('visitante vazio ou com mais de 100 letras')
      if (h && h.toLowerCase() === a.toLowerCase()) errs.push('mandante e visitante iguais')
    }
    if (errs.length) {
      row.status = 'error'
      row.message = errs.join('; ').replace(/^./, (x) => x.toUpperCase()) + '.'
    } else {
      const key = `${row.round}|${row.home.toLowerCase()}|${row.away.toLowerCase()}`
      if (existing.has(key)) Object.assign(row, { status: 'skip', message: 'Já cadastrado nesta liga e rodada. Será ignorado.' })
      else if (seen.has(key)) Object.assign(row, { status: 'skip', message: `Repetido no texto (linha ${seen.get(key)}). Será ignorado.` })
      else seen.set(key, i + 1)
    }
    rows.push(row)
  }
  const count = { ok: 0, skip: 0, error: 0 } as Record<string, number>
  for (const r of rows) count[r.status]++
  if (b.save && count.error === 0 && count.ok > 0) {
    await tx(async (t) => {
      for (const r of rows) if (r.status === 'ok') await t.exec('INSERT INTO matches (league_id, round, home_team, away_team, kickoff_at) VALUES (?, ?, ?, ?, ?)', [leagueId, r.round, r.home, r.away, r.kickoff])
    })
    clearRoundCache()
    return c.json({ saved: count.ok, message: `${plural(count.ok, 'jogo importado', 'jogos importados')}.${count.skip ? ` ${plural(count.skip, 'linha ignorada', 'linhas ignoradas')}.` : ''}` })
  }
  return c.json({ preview: { rows, count } })
})

api.get('/admin/users', async (c) => {
  await needAdmin(c)
  const term = str(c.req.query('q'))
  const rows = term
    ? await q('SELECT id, name, email, created_at FROM users WHERE name LIKE ? OR email LIKE ? ORDER BY name LIMIT 50', [`%${term.replace(/[%_\\]/g, '\\$&')}%`, `%${term.replace(/[%_\\]/g, '\\$&')}%`])
    : await q('SELECT id, name, email, created_at FROM users ORDER BY created_at DESC LIMIT 50')
  return c.json({ users: rows.map((u) => ({ id: u.id, name: u.name, email: u.email, since: fromSql(u.created_at) })) })
})

api.post('/admin/reset-link', async (c) => {
  await needAdmin(c)
  const u = await one('SELECT id, name, email FROM users WHERE id = ?', [int((await body(c)).userId)])
  if (!u) fail('Usuário não encontrado.', 404)
  return c.json({ name: u!.name, email: u!.email, url: resetUrl(c, await createReset(u!.id, 'admin', clientIp(c))) })
})

api.post('/admin/league-toggle', async (c) => {
  await needAdmin(c)
  const b = await body(c)
  await exec('UPDATE leagues SET is_active = ? WHERE id = ?', [b.active ? 1 : 0, int(b.leagueId)])
  return c.json({ message: b.active ? 'Liga ativada.' : 'Liga desativada. Ela não aparece mais para novos bolões.' })
})

api.post('/admin/league', async (c) => {
  await needAdmin(c)
  const b = await body(c)
  const name = str(b.name)
  const code = str(b.code).toUpperCase()
  const errors: Record<string, string> = {}
  if (!name || name.length > 100) errors.name = 'Informe o nome da liga, com até 100 letras.'
  if (!/^[A-Z0-9_-]{1,20}$/.test(code)) errors.code = 'Use letras, números e hífen, até 20 (ex.: BRA-A).'
  else if (await one('SELECT 1 AS ok FROM leagues WHERE code = ?', [code])) errors.code = 'Já existe uma liga com este código.'
  if (Object.keys(errors).length) failFields(errors)
  await exec('INSERT INTO leagues (name, code) VALUES (?, ?)', [name, code])
  return c.json({ message: 'Liga cadastrada.' })
})
