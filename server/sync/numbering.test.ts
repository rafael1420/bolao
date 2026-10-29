import { test } from 'node:test'
import assert from 'node:assert/strict'
import { cupStageLabel, cupStageRounds, guessRounds, numberLeague, officialKeyMatcher, teamKey, type NMatch } from './numbering.ts'

const H = 3600
const D = 86400
const T0 = 1_800_000_000
const teams = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H']
let id = 1
/** Rodada completa (4 jogos) começando em t, todos contra todos de forma simples. */
const round = (t: number, shift: number): NMatch[] =>
  [0, 1, 2, 3].map((i) => ({ id: id++, kick: t + i * 2 * H, home_team: teams[(i + shift) % 8], away_team: teams[(7 - i + shift) % 8], status: 'post' }))

test('nomes de fase e chaves de times', () => {
  assert.equal(cupStageLabel('Rd of 16'), 'Oitavas de final')
  assert.equal(cupStageLabel('3rd Round'), '3ª fase')
  assert.equal(teamKey('Grêmio'), 'gremio')
  assert.equal(teamKey('Athletico Paranaense'), 'athleticopr')
  const k = officialKeyMatcher(['bournemouth|brightonandhovealbion'])
  assert.equal(k('AFC Bournemouth'), 'bournemouth')
  assert.equal(k('Brighton & Hove Albion'), 'brightonandhovealbion')
})

test('estimativa de rodadas por data', () => {
  id = 1
  const ms = [...round(T0, 0), ...round(T0 + 7 * D, 1), ...round(T0 + 14 * D, 2)]
  const g = guessRounds(ms)
  assert.deepEqual([...new Set(ms.map((m) => g.get(m.id)))], [1, 2, 3])
})

test('rodada oficial alinha a estimativa', () => {
  id = 1
  const ms = [...round(T0, 0), ...round(T0 + 7 * D, 1), ...round(T0 + 14 * D, 2)]
  // Só um jogo da segunda rodada tem rodada oficial: 12.
  const official = new Map([[`${teamKey(ms[4].home_team)}|${teamKey(ms[4].away_team)}`, 12]])
  const r = numberLeague(ms, official)
  assert.deepEqual([r.get(1), r.get(5), r.get(9)], [11, 12, 13])
})

test('copa com ida e volta', () => {
  const ms: NMatch[] = [
    { id: 1, kick: T0, home_team: 'A', away_team: 'B', status: 'post', note: 'Jogo de ida' },
    { id: 2, kick: T0 + 7 * D, home_team: 'B', away_team: 'A', status: 'pre', note: 'Jogo de volta' },
    { id: 3, kick: T0 + 7 * D + H, home_team: 'C', away_team: 'D', status: 'pre', note: null },
  ]
  const r = cupStageRounds(ms, 'Oitavas de final')
  assert.deepEqual(r.get(1), [1, 'Oitavas de final · ida'])
  assert.deepEqual(r.get(3), [2, 'Oitavas de final · volta'])
})
