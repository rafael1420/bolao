// Numeração das rodadas (funções puras, testadas em numbering.test.ts).
// A ESPN não informa a rodada das ligas: vem do TheSportsDB quando há, senão é estimada pelas datas.
import { CUP_STAGES, TEAM_ALIASES } from '../leagues.ts'

export type NMatch = { id: number; kick: number; home_team: string; away_team: string; status: string | null; note?: string | null }

/** Nome da fase em português ("Rd of 16" vira "Oitavas de final", "3rd Round" vira "3ª fase"). */
export function cupStageLabel(label: string) {
  if (CUP_STAGES[label]) return CUP_STAGES[label]
  const m = label.match(/^(\d+)(st|nd|rd|th) (Round|Stage)$/i)
  if (m) return `${Number(m[1])}ª fase`
  return label.slice(0, 40)
}

/** Nome do time sem acento, espaço nem pontuação, igual nas duas fontes. */
export function teamKey(name: string) {
  const key = name
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]/g, '')
  return TEAM_ALIASES[key] ?? key
}

/**
 * Converte o nome da ESPN na chave do TheSportsDB: primeiro teamKey(); se essa chave não existe lá,
 * procura uma única parecida, sem "and", "fc" e "afc" ("AFC Bournemouth" = "Bournemouth").
 */
export function officialKeyMatcher(pairKeys: string[]) {
  const teams = new Set(pairKeys.flatMap((p) => p.split('|')))
  const loose = (k: string) => k.replace(/afc|fc|and/g, '')
  const looseTeams = [...teams].map((k) => [k, loose(k)] as const)
  const cache = new Map<string, string>()
  return (name: string) => {
    const key = teamKey(name)
    if (teams.has(key) || !teams.size) return key
    if (!cache.has(key)) {
      const lk = loose(key)
      const found = looseTeams.filter(([, lo]) => lo === lk || (lo.length >= 4 && lk.length >= 4 && (lo.includes(lk) || lk.includes(lo)))).map(([k]) => k)
      cache.set(key, found.length === 1 ? found[0] : key)
    }
    return cache.get(key)!
  }
}

/**
 * Estimativa das rodadas pela tabela: jogos em ordem de data; rodada nova quando um time jogaria duas vezes
 * nela ou quando passam mais de 60 h sem jogos. Grupos pequenos (remarcados) entram numa rodada vizinha.
 */
export function guessRounds(matches: NMatch[]): Map<number, number> {
  const teamsOf = new Map<number, [string, string]>()
  type G = { ids: number[]; teams: Set<string> }
  const groups: G[] = []
  let cur: G | null = null
  let last = 0
  for (const m of matches) {
    teamsOf.set(m.id, [m.home_team, m.away_team])
    const clash = cur !== null && (cur.teams.has(m.home_team) || cur.teams.has(m.away_team))
    if (cur === null || clash || m.kick - last > 60 * 3600) {
      cur = { ids: [], teams: new Set() }
      groups.push(cur)
    }
    cur.ids.push(m.id)
    cur.teams.add(m.home_team)
    cur.teams.add(m.away_team)
    last = m.kick
  }
  const fits = (g: G, ids: number[]) => ids.every((id) => teamsOf.get(id)!.every((t) => !g.teams.has(t)))
  const absorb = (g: G, ids: number[]) => {
    for (const id of ids) {
      g.ids.push(id)
      for (const t of teamsOf.get(id)!) g.teams.add(t)
    }
  }
  for (let i = 0; i < groups.length; i++) {
    const ids = groups[i].ids
    if (ids.length >= 4) continue
    const next = groups[i + 1]
    const prev = i > 0 ? groups[i - 1] : undefined
    if (next && fits(next, ids)) absorb(next, ids)
    else if (prev && fits(prev, ids)) absorb(prev, ids)
    else if (next) absorb(next, ids)
    else if (prev) absorb(prev, ids)
    else continue
    groups.splice(i, 1)
    i--
  }
  const out = new Map<number, number>()
  groups.forEach((g, i) => g.ids.forEach((id) => out.set(id, i + 1)))
  return out
}

/**
 * Rodada de cada jogo de uma liga. Com rodada oficial, cada confronto recebe a sua; os demais ficam com a
 * estimativa, alinhada às oficiais (cada grupo estimado vira a rodada oficial mais comum nele; os outros
 * seguem a diferença do grupo com dados mais próximo). Remarcados não votam. Adiados sem rodada oficial
 * ficam com a rodada do jogo conhecido mais próximo em data.
 */
export function numberLeague(all: NMatch[], official: Map<string, number>): Map<number, number> {
  const played = all.filter((m) => m.status !== 'void')
  const keyOf = officialKeyMatcher([...official.keys()])
  const rounds = new Map<number, number>()
  const known: [number, number][] = []
  for (const m of all) {
    const r = official.get(`${keyOf(m.home_team)}|${keyOf(m.away_team)}`)
    if (r !== undefined) {
      rounds.set(m.id, r)
      if (m.status !== 'void') known.push([m.kick, r])
    }
  }
  const guess = guessRounds(played)
  const timesOf = new Map<number, number[]>()
  for (const m of played) {
    const r = rounds.get(m.id)
    if (r !== undefined) timesOf.set(r, [...(timesOf.get(r) ?? []), m.kick])
  }
  const median = new Map<number, number>()
  for (const [r, times] of timesOf) {
    times.sort((a, b) => a - b)
    median.set(r, times[Math.floor(times.length / 2)])
  }
  const votes = new Map<number, Map<number, number>>()
  for (const m of played) {
    const r = rounds.get(m.id)
    const g = guess.get(m.id)
    if (r !== undefined && g !== undefined && Math.abs(m.kick - median.get(r)!) <= 4 * 86400) {
      const v = votes.get(g) ?? new Map<number, number>()
      v.set(r, (v.get(r) ?? 0) + 1)
      votes.set(g, v)
    }
  }
  const groupRound = new Map<number, number>()
  for (const [g, counts] of votes) {
    let best = -1
    let bestN = -1
    for (const [r, n] of counts) if (n > bestN) [best, bestN] = [r, n]
    groupRound.set(g, best)
  }
  for (const m of all) {
    if (rounds.has(m.id)) continue
    const g = guess.get(m.id)
    if (g === undefined) {
      let best: [number, number] | null = null
      for (const k of known) if (!best || Math.abs(k[0] - m.kick) < Math.abs(best[0] - m.kick)) best = k
      if (best) rounds.set(m.id, best[1])
      continue
    }
    if (groupRound.has(g)) {
      rounds.set(m.id, groupRound.get(g)!)
      continue
    }
    let offset = 0
    let nearest: number | null = null
    for (const [og, or] of groupRound) {
      if (nearest === null || Math.abs(og - g) < Math.abs(nearest - g)) {
        nearest = og
        offset = or - og
      }
    }
    rounds.set(m.id, Math.max(1, g + offset))
  }
  return rounds
}

/**
 * Sequência e nome de cada jogo de uma fase de copa. Ida e volta (nota da ESPN): 1 = ida, 2 = volta.
 * Fase de grupos ou de liga: estimada pelas datas ("Fase de liga · Rodada 3"). Jogo único: o nome da fase.
 */
export function cupStageRounds(matches: NMatch[], label: string): Map<number, [number, string]> {
  const out = new Map<number, [number, string]>()
  const legs = matches.filter((m) => m.note === 'Jogo de ida' || m.note === 'Jogo de volta')
  if (legs.length) {
    const t1 = legs.filter((m) => m.note !== 'Jogo de volta').map((m) => m.kick)
    const t2 = legs.filter((m) => m.note === 'Jogo de volta').map((m) => m.kick)
    const dist = (times: number[], t: number) => (times.length ? Math.min(...times.map((x) => Math.abs(x - t))) : Infinity)
    for (const m of matches) {
      const seq = m.note === 'Jogo de volta' ? 2 : m.note === 'Jogo de ida' ? 1 : dist(t2, m.kick) < dist(t1, m.kick) ? 2 : 1
      out.set(m.id, [seq, `${label} · ${seq === 2 ? 'volta' : 'ida'}`])
    }
    return out
  }
  const played = matches.filter((m) => m.status !== 'void')
  const guess = played.length >= 2 ? guessRounds(played) : new Map<number, number>()
  const many = guess.size > 0 && Math.max(...guess.values()) > 1
  for (const m of matches) {
    let seq = guess.get(m.id)
    if (seq === undefined) {
      seq = 1
      let best = Infinity
      for (const p of played) {
        const d = Math.abs(p.kick - m.kick)
        if (d < best && guess.has(p.id)) {
          best = d
          seq = guess.get(p.id)!
        }
      }
    }
    out.set(m.id, [seq, many ? `${label} · Rodada ${seq}` : label])
  }
  return out
}
