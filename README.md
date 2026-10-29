# Conferência Bolão

Bolão de futebol entre amigos, todo em **TypeScript**: app em **React** (Vite + Tailwind) e servidor em **Node.js** (Hono) com **MySQL**. Feito para o Web App Node.js da Hostinger.

Qualquer pessoa cadastrada cria um bolão, escolhe até 6 campeonatos (59 do Brasil e do mundo), as regras de pontuação e de rodada, e convida a galera por link, código ou senha. Jogos, placares, escudos e rodadas chegam sozinhos da ESPN. Não há apostas em dinheiro.

## O que tem

- **Palpites** salvos sozinhos enquanto você digita, com +/− e teclado; contagem regressiva de cada prazo.
- **Ao vivo**: placar parcial que se atualiza sozinho (a cada 30 s com jogo rolando).
- **Escudos** de times e campeonatos da ESPN (reduzidos pelo próprio CDN); sem imagem, um escudo desenhado com as cores do time.
- **Palpites da galera** depois que o jogo começa, com a divisão mandante/empate/visitante.
- **Ranking** geral, por rodada (com destaque) e "rodada a rodada" com mapa de calor; pódio.
- **Organizador**: nome, senha de entrada, campeonatos, modo de rodada, prazo, pontuação, novo código, transferir e excluir.
- **Administração**: lançar resultados, sincronizar agora, cadastrar e importar jogos, ativar ligas, gerar link de nova senha.
- Tema claro e escuro, barra inferior no celular, instalável como app (PWA).

## Estrutura

```
client/            App React (src/pages, src/components, public/ com ícones e manifesto)
server/            Servidor Node (Hono)
  index.ts         Entrada: API em /api, app React no resto, banco e sincronização ao subir
  api.ts           Rotas da API
  rounds.ts        Regras de rodada e pontuação (+ rounds.test.ts)
  bolao.ts         Ranking, recálculo de pontos, palpites da galera
  auth.ts          Sessões, senhas (bcrypt), limite de tentativas, links de nova senha, e-mail
  schema.ts        Cria e atualiza o banco sozinho
  leagues.ts       Catálogo de campeonatos
  sync/            Sincronização com ESPN e TheSportsDB (+ testes)
```

`npm run build` gera `dist/client` (app) e `dist/server.js` (servidor). `npm start` sobe tudo na porta `PORT`.

## Publicar na Hostinger (Web App Node.js)

### 1. Banco de dados

Use o **mesmo banco MySQL** da versão anterior: usuários, bolões e palpites continuam, e as senhas também (o servidor lê os mesmos hashes). Por segurança, antes de trocar, exporte o banco no **phpMyAdmin → Exportar**.

Instalação nova: crie um banco em **Bancos de dados → Gerenciamento**. As tabelas são criadas sozinhas na primeira vez que o app sobe.

### 2. Criar o Web App

1. Se o subdomínio ainda tem o site antigo, apague o site em **Websites** (o banco de dados **não** é apagado junto; confira antes).
2. **Websites → Adicionar website → Node.js Web App**, no domínio `bolao.rafaelgomes.site`.
3. Origem: **GitHub** (repositório `rafael1420/bolao`, branch desta versão), ou envie o zip do código.
4. Configurações de build:
   - Versão do Node: **22** (ou 20)
   - Comando de build: `npm run build`
   - Arquivo de entrada: `dist/server.js` (ou comando de início `npm start`)

### 3. Variáveis de ambiente

No Web App, em **Variáveis de ambiente**:

| Variável | Exemplo | Para quê |
|---|---|---|
| `DB_HOST` | `localhost` | Servidor do banco |
| `DB_NAME` | `u329075197_bolao` | Nome completo do banco (com o prefixo) |
| `DB_USER` | `u329075197_bolao` | Usuário do banco |
| `DB_PASSWORD` | *(a senha do banco)* | Senha do banco |
| `ADMIN_EMAIL` | `seu@email.com` | Quem entra com este e-mail vê a Administração |
| `SITE_URL` | `https://bolao.rafaelgomes.site` | Endereço dos links de convite e de nova senha |
| `SMTP_HOST` | `smtp.hostinger.com` | E-mail de "Esqueci minha senha" (opcional) |
| `SMTP_PORT` | `465` | |
| `SMTP_USER` | `no-reply@rafaelgomes.site` | Uma caixa criada em **E-mails** no hPanel |
| `SMTP_PASSWORD` | *(senha da caixa)* | |

Também aceita `DATABASE_URL` (`mysql://usuario:senha@host:3306/banco`), que a Hostinger cria quando você liga um banco ao Web App pelo painel.

Sem SMTP, o site funciona normalmente; o link de nova senha é gerado pela **Administração → Usuários** e mandado por WhatsApp.

### 4. Conferir

- `https://bolao.rafaelgomes.site/healthz` responde `ok`.
- Se o app não abrir, veja os **logs** do Web App: a mensagem "Não consegui preparar o banco" indica variável `DB_*` errada (troque `DB_HOST` entre `localhost` e `127.0.0.1`).
- Links antigos (`join_group.php?code=…`, `group.php?id=…`, `reset_password.php?token=…`) são redirecionados para as telas novas.
- Quem estava logado na versão anterior precisa entrar de novo uma vez (as sessões mudaram); a senha é a mesma.

A cada push no GitHub, a Hostinger pode refazer o build sozinha (auto-deploy em **Avançado → Git**).

## Desenvolver

Precisa de Node 20+ e um MySQL/MariaDB local.

```
cp .env.example .env     # e preencha
npm install
npm run dev              # servidor em :3000 e app em http://localhost:5173
npm test                 # regras de rodada e numeração
npm run typecheck
```

## Regras

### Pontuação padrão

| Situação | Pontos |
|---|---|
| Placar exato | 5 |
| Acertou o vencedor ou o empate | 3 |
| Acertou também o saldo de gols | +1 |

Cada bolão pode mudar os valores (de 0 a 20). Desempate: pontos, placares exatos, acertos de vencedor ou empate; quem empata em tudo divide a posição. Só contam jogos encerrados das ligas atuais do bolão.

### Rodadas

| Modo | Como funciona |
|---|---|
| Rodada atual (padrão) | Só a rodada da vez. A próxima abre quando todos os jogos da atual começarem, ou 48 h antes do primeiro jogo dela. |
| Atual e próxima | A rodada da vez e a seguinte. |
| Livre | Qualquer jogo até começar. |

Prazo: **até cada jogo** (padrão) ou **até o 1º jogo da rodada**. Jogo adiado para semanas depois abre na semana em que vai ser disputado e aparece em "Jogos remarcados". A rodada atual é a que começa primeiro entre as que não acabaram; uma rodada acaba quando todos os jogos do núcleo (a até 4 dias da data do meio da rodada) têm placar ou começaram há mais de 3 dias.

### Sincronização (ESPN)

Roda dentro do servidor, a cada minuto, sem cron. Só para as ligas que estão em algum bolão: jogos do momento a cada 5 min (1 min com jogo ao vivo), tabela de 7 dias atrás a 35 à frente a cada 6 h, e o começo da temporada aos poucos. Rodadas oficiais das principais ligas vêm do TheSportsDB; nas outras, são estimadas pelas datas. Os escudos dos 59 campeonatos são buscados aos poucos para aparecerem no seletor.

### Segurança

- 5 senhas erradas em 15 minutos (mesmo e-mail e conexão) bloqueiam o login por 15 minutos; o mesmo vale para a senha de um bolão.
- Sessões no banco (o cookie leva só um token); expiram após 7 dias sem uso ou 30 dias no total. Trocar a senha desconecta os outros aparelhos.
- Links de nova senha guardados só como hash, de uso único (1 h; 24 h quando gerados pela administração).
- Toda ação exige um cabeçalho que outro site não consegue enviar (proteção contra CSRF).
