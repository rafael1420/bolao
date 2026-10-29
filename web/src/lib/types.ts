export type User = { id: number; name: string; email: string; isAdmin: boolean }
export type Session = { user: User | null; csrf: string; siteName: string }

export type Team = { name: string; short: string; abbr: string; logo: string | null; color: string | null; color2: string | null }

export type League = { id: number; name: string; short: string; country: string; kind: string; logo: string | null }

export type GroupCard = {
  id: number
  name: string
  isOwner: boolean
  members: number
  pos: number | null
  points: number
  leader: { name: string; total: number } | null
  pending: number
  next: number | null
}

export type RankRow = { id: number; name: string; pos: number; total: number; exact: number; outcomes: number; played: number }

export type GroupInfo = {
  id: number
  name: string
  ownerId: number
  isOwner: boolean
  inviteCode: string
  inviteUrl: string
  hasPassword: boolean
  rules: {
    exact: number
    winner: number
    diff: number
    roundMode: 'rodada' | 'duas' | 'livre'
    deadlineMode: 'jogo' | 'rodada'
    roundModeText: [string, string]
    deadlineModeText: [string, string]
  }
  leagues: League[]
  ranking: RankRow[]
  me: RankRow | null
  above: RankRow | null
  scored: { preds: number; matches: number }
}

export type OtherPick = { user_id: number; name: string; home_pred: number | null; away_pred: number | null; points: number | null; reason: string }

export type Match = {
  id: number
  leagueId: number
  round: number
  kickoff: number
  closeAt: number
  note: string | null
  origin: string | null
  home: Team
  away: Team
  score: [number, number] | null
  live: boolean
  liveScore: [number, number] | null
  statusDetail: string | null
  open: boolean
  locked: boolean
  reason: null | 'finished' | 'started' | 'void' | 'locked' | 'deadline'
  lockText: string | null
  pred: { home: number; away: number } | null
  points: number | null
  pointsReason: string | null
  exact: boolean
  others: OtherPick[] | null
}

export type RoundState = 'aberta' | 'aguardando' | 'encerrada' | 'bloqueada'

export type RoundInfo = {
  number: number
  label: string
  chip: string
  state: RoundState
  stats: { total: number; finished: number; open: number; pending: number }
}

export type RoundData = {
  leagueId: number
  leagueName: string
  current: number | null
  over: boolean
  deadlineMode: 'jogo' | 'rodada'
  round: number
  label: string
  state: RoundState
  deadline: number | null
  lockedText: string | null
  rounds: RoundInfo[]
  matches: Match[]
  rescheduled: Match[]
  roundMe: { pos: number; total: number; played: number } | null
  now: number
}

export type GameRow = {
  id: number
  leagueId: number
  round: number
  kickoff: number
  home: Team
  away: Team
  score: [number, number] | null
  live: boolean
  liveScore: [number, number] | null
  statusDetail: string | null
}

export type ScoredRound = { key: string; league_id: number; round: number; label: string; round_name: string; short: string }

export type RankingData = {
  rounds: ScoredRound[]
  round: ScoredRound | null
  roundRanking: RankRow[]
  matrix: Record<string, Record<string, number>>
}

export type PickerLeague = { id: number; name: string; short: string; kind: string; season: string | null; offSeason: boolean; logo: string | null }
export type PickerCountry = { code: string; name: string; featured: boolean; leagues: PickerLeague[] }
