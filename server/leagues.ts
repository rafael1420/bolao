// Catálogo de campeonatos por país ou região. A tabela leagues é preenchida a partir daqui (seedLeagues em schema.ts),
// e a sincronização usa o slug da ESPN gravado em leagues.external_id.

export const MAX_GROUP_LEAGUES = 6

/** País ou região: [nome, ordem, destaque]. Sem destaque, fica em "Mais países". */
export const COUNTRIES: Record<string, [string, number, boolean]> = {
  "BRA": ["Brasil",10,true],
  "CONMEBOL": ["América do Sul",20,true],
  "UEFA": ["Europa",30,true],
  "FIFA": ["Seleções e Mundial",40,true],
  "ENG": ["Inglaterra",50,true],
  "ESP": ["Espanha",60,true],
  "ITA": ["Itália",70,true],
  "GER": ["Alemanha",80,true],
  "FRA": ["França",90,true],
  "POR": ["Portugal",100,true],
  "NED": ["Holanda",110,true],
  "ARG": ["Argentina",120,true],
  "USA": ["Estados Unidos",130,true],
  "MEX": ["México",140,true],
  "CONCACAF": ["América do Norte e Central",150,true],
  "URU": ["Uruguai",200,false],
  "COL": ["Colômbia",210,false],
  "CHI": ["Chile",220,false],
  "PAR": ["Paraguai",230,false],
  "PER": ["Peru",240,false],
  "ECU": ["Equador",250,false],
  "SCO": ["Escócia",300,false],
  "TUR": ["Turquia",310,false],
  "BEL": ["Bélgica",320,false],
  "KSA": ["Arábia Saudita",330,false],
  "JPN": ["Japão",340,false],
  "OUT": ["Outros",900,false],
}

export type CatalogLeague = {
  slug: string
  name: string
  short: string
  country: string
  kind: "liga" | "copa"
  sort: number
  /** [id no TheSportsDB, formato da temporada ("Y" = 2026, "Y-Y" = 2026-2027), nº de times]: rodada oficial. */
  tsdb?: [number, "Y" | "Y-Y", number]
}

export const LEAGUE_CATALOG: Record<string, CatalogLeague> = {
  "BRA-A": {slug: "bra.1",name: "Brasileirão Série A",short: "Série A",country: "BRA",kind: "liga",sort: 10,tsdb: [4351,"Y",20]},
  "BRA-B": {slug: "bra.2",name: "Brasileirão Série B",short: "Série B",country: "BRA",kind: "liga",sort: 20},
  "BRA-C": {slug: "bra.3",name: "Brasileirão Série C",short: "Série C",country: "BRA",kind: "liga",sort: 30},
  "BRA-CDB": {slug: "bra.copa_do_brazil",name: "Copa do Brasil",short: "Copa do Brasil",country: "BRA",kind: "copa",sort: 40},
  "BRA-NE": {slug: "bra.copa_do_nordeste",name: "Copa do Nordeste",short: "Nordestão",country: "BRA",kind: "copa",sort: 50},
  "BRA-SP": {slug: "bra.camp.paulista",name: "Campeonato Paulista",short: "Paulistão",country: "BRA",kind: "liga",sort: 60},
  "BRA-RJ": {slug: "bra.camp.carioca",name: "Campeonato Carioca",short: "Carioca",country: "BRA",kind: "liga",sort: 70},
  "BRA-MG": {slug: "bra.camp.mineiro",name: "Campeonato Mineiro",short: "Mineiro",country: "BRA",kind: "liga",sort: 80},
  "BRA-RS": {slug: "bra.camp.gaucho",name: "Campeonato Gaúcho",short: "Gauchão",country: "BRA",kind: "liga",sort: 90},
  "CONMEBOL-LIB": {slug: "conmebol.libertadores",name: "Libertadores",short: "Libertadores",country: "CONMEBOL",kind: "copa",sort: 10},
  "CONMEBOL-SUD": {slug: "conmebol.sudamericana",name: "Copa Sul-Americana",short: "Sul-Americana",country: "CONMEBOL",kind: "copa",sort: 20},
  "CONMEBOL-REC": {slug: "conmebol.recopa",name: "Recopa Sul-Americana",short: "Recopa",country: "CONMEBOL",kind: "copa",sort: 30},
  "UEFA-UCL": {slug: "uefa.champions",name: "Champions League",short: "Champions",country: "UEFA",kind: "copa",sort: 10},
  "UEFA-UEL": {slug: "uefa.europa",name: "Liga Europa",short: "Liga Europa",country: "UEFA",kind: "copa",sort: 20},
  "UEFA-UECL": {slug: "uefa.europa.conf",name: "Liga Conferência",short: "Conferência",country: "UEFA",kind: "copa",sort: 30},
  "UEFA-SUP": {slug: "uefa.super_cup",name: "Supercopa da UEFA",short: "Supercopa",country: "UEFA",kind: "copa",sort: 40},
  "UEFA-NL": {slug: "uefa.nations",name: "Liga das Nações",short: "Liga das Nações",country: "UEFA",kind: "copa",sort: 50},
  "FIFA-WC": {slug: "fifa.world",name: "Copa do Mundo",short: "Copa do Mundo",country: "FIFA",kind: "copa",sort: 10},
  "FIFA-CWC": {slug: "fifa.cwc",name: "Mundial de Clubes",short: "Mundial",country: "FIFA",kind: "copa",sort: 20},
  "FIFA-WCQ-SA": {slug: "fifa.worldq.conmebol",name: "Eliminatórias (América do Sul)",short: "Eliminatórias",country: "FIFA",kind: "copa",sort: 30},
  "FIFA-WCQ-EU": {slug: "fifa.worldq.uefa",name: "Eliminatórias (Europa)",short: "Elim. Europa",country: "FIFA",kind: "copa",sort: 40},
  "FIFA-AMI": {slug: "fifa.friendly",name: "Amistosos internacionais",short: "Amistosos",country: "FIFA",kind: "liga",sort: 50},
  "ENG-1": {slug: "eng.1",name: "Premier League",short: "Premier League",country: "ENG",kind: "liga",sort: 10,tsdb: [4328,"Y-Y",20]},
  "ENG-2": {slug: "eng.2",name: "Championship",short: "Championship",country: "ENG",kind: "liga",sort: 20},
  "ENG-3": {slug: "eng.3",name: "League One",short: "League One",country: "ENG",kind: "liga",sort: 30},
  "ENG-FA": {slug: "eng.fa",name: "Copa da Inglaterra (FA Cup)",short: "FA Cup",country: "ENG",kind: "copa",sort: 40},
  "ENG-EFL": {slug: "eng.league_cup",name: "Copa da Liga Inglesa",short: "Copa da Liga",country: "ENG",kind: "copa",sort: 50},
  "ESP-1": {slug: "esp.1",name: "LaLiga",short: "LaLiga",country: "ESP",kind: "liga",sort: 10,tsdb: [4335,"Y-Y",20]},
  "ESP-2": {slug: "esp.2",name: "LaLiga 2",short: "LaLiga 2",country: "ESP",kind: "liga",sort: 20},
  "ESP-CR": {slug: "esp.copa_del_rey",name: "Copa do Rei",short: "Copa do Rei",country: "ESP",kind: "copa",sort: 30},
  "ITA-1": {slug: "ita.1",name: "Campeonato Italiano (Série A)",short: "Série A Italiana",country: "ITA",kind: "liga",sort: 10,tsdb: [4332,"Y-Y",20]},
  "ITA-2": {slug: "ita.2",name: "Série B Italiana",short: "Série B Italiana",country: "ITA",kind: "liga",sort: 20},
  "ITA-CI": {slug: "ita.coppa_italia",name: "Copa da Itália",short: "Copa da Itália",country: "ITA",kind: "copa",sort: 30},
  "GER-1": {slug: "ger.1",name: "Bundesliga",short: "Bundesliga",country: "GER",kind: "liga",sort: 10,tsdb: [4331,"Y-Y",18]},
  "GER-2": {slug: "ger.2",name: "2. Bundesliga",short: "2. Bundesliga",country: "GER",kind: "liga",sort: 20},
  "GER-CUP": {slug: "ger.dfb_pokal",name: "Copa da Alemanha",short: "Copa da Alemanha",country: "GER",kind: "copa",sort: 30},
  "FRA-1": {slug: "fra.1",name: "Ligue 1",short: "Ligue 1",country: "FRA",kind: "liga",sort: 10,tsdb: [4334,"Y-Y",18]},
  "FRA-2": {slug: "fra.2",name: "Ligue 2",short: "Ligue 2",country: "FRA",kind: "liga",sort: 20},
  "FRA-CUP": {slug: "fra.coupe_de_france",name: "Copa da França",short: "Copa da França",country: "FRA",kind: "copa",sort: 30},
  "POR-1": {slug: "por.1",name: "Liga Portugal",short: "Liga Portugal",country: "POR",kind: "liga",sort: 10,tsdb: [4344,"Y-Y",18]},
  "POR-TP": {slug: "por.taca.portugal",name: "Taça de Portugal",short: "Taça de Portugal",country: "POR",kind: "copa",sort: 20},
  "NED-1": {slug: "ned.1",name: "Eredivisie",short: "Eredivisie",country: "NED",kind: "liga",sort: 10,tsdb: [4337,"Y-Y",18]},
  "NED-CUP": {slug: "ned.cup",name: "Copa da Holanda",short: "Copa da Holanda",country: "NED",kind: "copa",sort: 20},
  "ARG-1": {slug: "arg.1",name: "Liga Profissional Argentina",short: "Argentino",country: "ARG",kind: "liga",sort: 10},
  "ARG-CUP": {slug: "arg.copa",name: "Copa Argentina",short: "Copa Argentina",country: "ARG",kind: "copa",sort: 20},
  "USA-1": {slug: "usa.1",name: "MLS",short: "MLS",country: "USA",kind: "liga",sort: 10},
  "MEX-1": {slug: "mex.1",name: "Liga MX",short: "Liga MX",country: "MEX",kind: "liga",sort: 10},
  "CONCACAF-CC": {slug: "concacaf.champions",name: "Copa dos Campeões da Concacaf",short: "Concachampions",country: "CONCACAF",kind: "copa",sort: 10},
  "URU-1": {slug: "uru.1",name: "Campeonato Uruguaio",short: "Uruguaio",country: "URU",kind: "liga",sort: 10},
  "COL-1": {slug: "col.1",name: "Campeonato Colombiano",short: "Colombiano",country: "COL",kind: "liga",sort: 10},
  "CHI-1": {slug: "chi.1",name: "Campeonato Chileno",short: "Chileno",country: "CHI",kind: "liga",sort: 10},
  "PAR-1": {slug: "par.1",name: "Campeonato Paraguaio",short: "Paraguaio",country: "PAR",kind: "liga",sort: 10},
  "PER-1": {slug: "per.1",name: "Campeonato Peruano",short: "Peruano",country: "PER",kind: "liga",sort: 10},
  "ECU-1": {slug: "ecu.1",name: "Campeonato Equatoriano",short: "Equatoriano",country: "ECU",kind: "liga",sort: 10},
  "SCO-1": {slug: "sco.1",name: "Campeonato Escocês",short: "Escocês",country: "SCO",kind: "liga",sort: 10},
  "TUR-1": {slug: "tur.1",name: "Campeonato Turco",short: "Turco",country: "TUR",kind: "liga",sort: 10},
  "BEL-1": {slug: "bel.1",name: "Campeonato Belga",short: "Belga",country: "BEL",kind: "liga",sort: 10},
  "KSA-1": {slug: "ksa.1",name: "Campeonato Saudita",short: "Saudita",country: "KSA",kind: "liga",sort: 10},
  "JPN-1": {slug: "jpn.1",name: "J1 League (Japão)",short: "J1 League",country: "JPN",kind: "liga",sort: 10},
}

/** Times com nomes diferentes na ESPN e no TheSportsDB (já sem acento e espaço, ver teamKey). */
export const TEAM_ALIASES: Record<string, string> = {
  "athleticoparanaense": "athleticopr",
  "atleticomineiro": "atleticomg",
  "atleticogoianiense": "atleticogo",
  "americamineiro": "americamg",
  "redbullbragantino": "bragantino"
}

/** Fases das copas como a ESPN escreve. Outros "3rd Round" viram "3ª fase". */
export const CUP_STAGES: Record<string, string> = {
  "First Round": "1ª fase",
  "Second Round": "2ª fase",
  "Third Round": "3ª fase",
  "Fourth Round": "4ª fase",
  "Fifth Round": "5ª fase",
  "First Stage": "1ª fase",
  "Second Stage": "2ª fase",
  "Third Stage": "3ª fase",
  "Preliminary Round": "Fase preliminar",
  "Qualifying": "Fase preliminar",
  "Group": "Fase de grupos",
  "Group Stage": "Fase de grupos",
  "League Phase": "Fase de liga",
  "League Stage": "Fase de liga",
  "Knockout Round Playoffs": "Playoffs",
  "Playoff Round": "Playoffs",
  "Play-off Round": "Playoffs",
  "Rd of 32": "16 avos de final",
  "Round of 32": "16 avos de final",
  "Rd of 16": "Oitavas de final",
  "Round of 16": "Oitavas de final",
  "Quarterfinals": "Quartas de final",
  "Semifinals": "Semifinal",
  "Final": "Final",
  "Finals": "Final"
}

export const countryName = (code: string) => (COUNTRIES[code] ?? COUNTRIES.OUT)[0]

export const countryOrder = (code: string) => COUNTRIES[code]?.[1] ?? 999
