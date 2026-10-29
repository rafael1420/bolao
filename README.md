# Conferência Bolão

Site de bolão de futebol: app em **React** (Vite + TypeScript + Tailwind) com API em **PHP e MySQL**, para hospedagem compartilhada (Hostinger, plano PHP/HTML). O servidor só precisa de PHP; o React vai já compilado no zip.

## Versão 2 (React)

- Palpites salvos sozinhos enquanto você digita (sem botão de salvar), com +/− e teclado (setas, Enter pula para o próximo placar).
- Contagem regressiva dos prazos, placar ao vivo que se atualiza sozinho (a cada 30 s com jogo rolando).
- Palpites da galera com a divisão mandante/empate/visitante; pódio, ranking por rodada e mapa de calor "rodada a rodada".
- Tema claro e escuro, barra inferior no celular, telas carregadas sob demanda (o celular baixa só o que usa).
- Mesmas regras e mesmo banco: `includes/` continua com rodadas, pontuação, ranking e sincronização com a ESPN. A API fica em `api/index.php` (rotas `?r=...`, CSRF por cabeçalho).
- Links antigos (`join_group.php?code=`, `group.php?id=`, `reset_password.php?token=`) são redirecionados pelo `.htaccess`.

### Gerar o zip (no seu computador, precisa de Node 20+)

```
./build-release.sh
```

Gera `release/bolao-site.zip` (React compilado + API + includes + .htaccess, sem o `config.php`) e `release/schema.sql`.

### Atualizar um site que já usa a versão PHP

1. Faça backup de `includes/config.php` (pelo Gerenciador de Arquivos).
2. Apague os arquivos antigos do `public_html` (menos `includes/config.php`).
3. Envie `bolao-site.zip` para o `public_html` e extraia. Mantenha o seu `includes/config.php`.
4. Apague o `install.php` (o site já está instalado).
5. O banco não muda: não precisa importar nada.

Em instalação nova, siga os passos abaixo (o `install.php` cria o `config.php`).

### Desenvolver

```
php -S 127.0.0.1:8080        # API (com includes/config.php apontando para um MySQL local)
cd web && npm install && npm run dev   # app em http://localhost:5173 (encaminha /api para o PHP)
```

Qualquer usuário cadastrado pode criar um bolão, escolher até 6 campeonatos (mais de 50, do Brasil e do mundo, agrupados por país), as regras de pontuação e de rodada, e convidar pessoas por código, link ou senha. Os palpites seguem a rodada da vez. Não há apostas em dinheiro nem prêmios.

## Instalação na Hostinger (bolao.rafaelgomes.site)

São dois arquivos: `bolao-site.zip` (o site) e `schema.sql` (o banco).

### 1. Criar o subdomínio
1. No hPanel, em **Websites → Adicionar website**, escolha a opção de subdomínio e informe `bolao.rafaelgomes.site`.
   Se o domínio `rafaelgomes.site` já tiver um site PHP/HTML, também dá para criar em **Domínios → Subdomínios**, dentro dele.
2. Aguarde alguns minutos até o endereço abrir (mostrará página padrão ou erro, o que é normal até o passo 4).

### 2. Criar o banco
1. Em **Bancos de dados → Gerenciamento**, crie o banco `bolao`, o usuário `bolao` e uma senha forte.
2. Anote os nomes completos com o prefixo (ex.: `u329075197_bolao`) e a senha.

### 3. Importar o `schema.sql`
1. Em **Bancos de dados → phpMyAdmin**, selecione o banco `u329075197_bolao`.
2. Aba **Importar** → escolha o `schema.sql` → **Executar**.
3. Deve aparecer a lista de 12 tabelas na coluna da esquerda.

Em instalação nova, o `schema.sql` já traz tudo. As migrações (abaixo) são só para quem já tinha o banco.

### 4. Enviar o site
1. No **Gerenciador de Arquivos** do site `bolao.rafaelgomes.site`, entre em `public_html`.
2. Apague qualquer arquivo padrão (como `index.html` ou `default.php`).
3. Envie o `bolao-site.zip`, clique com o botão direito e escolha **Extrair** dentro de `public_html`.
4. Os arquivos devem ficar direto dentro de `public_html`, sem subpasta (inclusive o `.htaccess`, que é oculto: no Gerenciador de Arquivos, ative "Mostrar arquivos ocultos" para conferir).

### 5. Instalar
1. Abra `bolao.rafaelgomes.site/install.php`.
2. Preencha: servidor `localhost`, banco, usuário, senha, o endereço do site (ex.: `https://bolao.rafaelgomes.site/`) e o e-mail do administrador.
3. Clique em **Instalar**. Ele grava `includes/config.php` e tenta se apagar.

### 6. Criar sua conta
Cadastre-se no site com o mesmo e-mail do administrador.

## Atualizar um site já instalado (migrações)

Quando uma versão nova muda o banco, ela traz um arquivo em `sql/migrations/`. Quem instalou antes precisa importá-lo **uma vez**, antes de enviar os arquivos novos do site:

1. No **phpMyAdmin**, selecione o banco do bolão.
2. Aba **Importar** → escolha o arquivo da migração → **Executar**.
3. Repita para cada migração ainda não importada, em ordem (001, 002, ...).

As migrações só criam o que falta (tabelas com `CREATE TABLE IF NOT EXISTS` e colunas só quando ainda não existem), então importar de novo não estraga nada.

| Migração | O que faz |
|---|---|
| `001_password_resets_login_attempts.sql` | Cria `password_resets` (links de "Esqueci minha senha") e `login_attempts` (limite de tentativas de login) |
| `002_ligas_times_rodadas.sql` | Cria `teams` (escudos e cores dos times); acrescenta país, tipo e controle de sincronização em `leagues`, os times em `matches` e o modo de rodada em `bolao_groups` |

### Atualizar para a versão "Conferência Bolão" (migração 002)

1. Importe `sql/migrations/002_ligas_times_rodadas.sql` no phpMyAdmin (pode importar de novo sem problema).
2. Envie os arquivos novos do site.
3. No **Gerenciador de Arquivos**, edite `includes/config.php` e troque o nome do site:
   `define('SITE_NAME', 'Conferência Bolão');`
4. Entre como administrador, abra **Administração → Jogos** e clique em **Sincronizar agora**.

Se os arquivos forem enviados antes da migração, o site cria sozinho as colunas que faltam na primeira página aberta (precisa da permissão ALTER no banco, que a Hostinger dá). Mesmo assim, importe a migração antes.

Quem instalou o site como app no celular continua vendo o ícone antigo até remover e adicionar de novo.

### Configurações novas no `includes/config.php`

Sites instalados antes desta versão podem acrescentar ao `includes/config.php` (pelo Gerenciador de Arquivos):

```php
// Endereço público do site, usado nos links enviados por e-mail.
define('SITE_URL', 'https://bolao.rafaelgomes.site/');
// Remetente dos e-mails. Vazio = no-reply@ o domínio do site.
define('MAIL_FROM', '');
```

As duas são opcionais. Sem `SITE_URL`, o link do e-mail usa o endereço pelo qual o site foi aberto. Se os e-mails não chegarem, crie a caixa `no-reply@seudominio` no hPanel (**E-mails**) ou informe em `MAIL_FROM` uma caixa que exista no domínio.

## Se aparecer erro 500
1. Ative a exibição de erros: **Sites → Manage → PHP Configuration → PHP Options**, marque `display_errors` e salve. Desative depois.
2. O log de erros fica em **Gerenciador de Arquivos → logs/** (na raiz, fora de `public_html`).
3. Confira a versão do PHP em **PHP Configuration**. O sistema precisa de PHP 7.4 ou mais novo.

## Estrutura

```
web/                       Código do app React (src/pages, src/components)
api/index.php              API JSON usada pelo app
includes/                  Regras, conexão, sessão e sincronização (bloqueado pela internet)
assets/                    Ícones e manifesto do app
install.php                Instalador (se apaga após usar)
build-release.sh           Gera o zip para a Hostinger
```

## Uso

- **Administração** (`admin_matches.php`): cadastre ligas e jogos, e lance os placares finais. Ao salvar um placar, os pontos são recalculados.
- **Conta** (`perfil.php`): qualquer usuário troca o próprio nome (que aparece no ranking) e a senha, informando a senha atual.
- **Organizador do bolão** (*Configurar*): altera nome, senha de entrada, ligas e pontuação; gera um novo código de convite (o link antigo deixa de funcionar); e exclui o bolão, digitando o nome exato para confirmar. Em *Membros*, remove participantes.
- **Importar jogos** (aba da administração): cole várias linhas `rodada; data e hora; mandante; visitante` (ou copie de uma planilha), confira linha a linha e grave tudo de uma vez. Jogos repetidos são ignorados.
- **Usuários** (aba da administração): busca contas e gera um link de nova senha (vale 24 h, uso único) para mandar por WhatsApp quando o e-mail não chegar.
- **Organizador do bolão** também pode **transferir a organização** para outro participante (em *Configurar*). Ele continua no bolão como participante.
- **Participantes:** fazem palpites na rodada aberta (aba **Rodada**), veem o calendário de todos os campeonatos do bolão (aba **Jogos**), acompanham o ranking (geral, de uma rodada ou rodada a rodada) e podem sair do bolão. Depois que um jogo começa, cada um pode ver os palpites da galera naquele jogo. Antes disso, ninguém vê o palpite dos outros.
- **Esqueci minha senha** (na tela de entrada): envia por e-mail um link que vale 1 hora e funciona uma vez. A resposta é a mesma exista ou não a conta.

## Segurança

- Depois de 5 senhas erradas em 15 minutos (mesmo e-mail e mesma conexão), o login fica bloqueado por 15 minutos. O mesmo vale para a senha de um bolão.
- O login expira após 7 dias sem uso (ou 30 dias no total). Trocar ou redefinir a senha desconecta os outros aparelhos.
- Os links de redefinição são guardados só como hash; quem lê o banco não consegue usá-los.

## Pontuação padrão

| Situação | Pontos |
|---|---|
| Placar exato | 5 |
| Acertou o vencedor ou o empate | 3 |
| Acertou também o saldo de gols | +1 |

Cada bolão pode alterar esses valores (de 0 a 20).

### Desempate no ranking

1. Mais pontos
2. Mais placares exatos
3. Mais acertos de vencedor ou empate (contando os exatos)

Quem empata nos três divide a posição. Só contam jogos encerrados das ligas atuais do bolão.

## Rodadas

Cada bolão escolhe em **Configurar → Rodadas** quais rodadas aceitam palpite (regras em `includes/rounds.php`, testadas por `php tests/rounds_test.php`):

| Modo | Como funciona |
|---|---|
| Rodada atual (padrão) | Só a rodada da vez. A próxima abre quando todos os jogos da atual começarem, ou 48 h antes do primeiro jogo dela. As seguintes ficam bloqueadas. |
| Atual e próxima | A rodada da vez e a seguinte. |
| Livre | Como era antes: qualquer jogo até começar. |

E o prazo: **até cada jogo** (padrão) ou **até o 1º jogo da rodada**. Rodadas passadas ficam só para consulta. Jogo adiado para semanas depois (ou antecipado) abre na semana em que vai ser disputado e aparece em "Jogos remarcados". O servidor (`predict.php`) confere as mesmas regras; palpites já feitos em rodadas futuras continuam guardados.

A rodada atual é a que começa primeiro entre as que ainda não acabaram. Uma rodada acaba quando todos os jogos do núcleo (a até 4 dias da data do meio da rodada) têm placar ou começaram há mais de 3 dias.

## Campeonatos

O catálogo fica em `includes/leagues.php` (`LEAGUE_CATALOG`, agrupado por país em `COUNTRIES`). O site grava as ligas novas no banco sozinho quando o catálogo muda. Na administração, aba **Ligas**, dá para desativar uma liga (ela some do seletor; bolões que já a têm continuam com ela).

## Jogos automáticos (ESPN)

`includes/sync.php` busca jogos, placares, times (nome, sigla, escudo e cores) e o escudo de cada liga na API pública da ESPN, e as rodadas oficiais das principais ligas no TheSportsDB. Não precisa de chave nem de cron:

- Só sincroniza as ligas que estão em algum bolão, cada uma no seu ritmo: os jogos do momento a cada 5 minutos (1 minuto com jogo ao vivo); a tabela de 7 dias atrás a 35 à frente, a cada 6 horas (no máximo 2 ligas por vez); e, para uma liga nova, os jogos desde o começo da temporada, aos poucos.
- No máximo 60 consultas à ESPN por vez. Liga que falha espera 2, 4, 8... até 60 minutos antes de tentar de novo.
- Roda depois que a página é entregue ao visitante. Se o servidor não consegue liberar a página antes, só a parte rápida roda sozinha; a tabela completa fica para o botão **Sincronizar agora**.
- Os escudos vêm do próprio CDN da ESPN, já reduzidos. Se não carregarem, aparece um escudo desenhado com as cores do time.
- Na primeira vez, cria as colunas e tabelas de que precisa (as mesmas das migrações 001 e 002). Instalações novas já recebem tudo pelo `schema.sql`.
- Jogos adiados ou cancelados (`status = 'void'`) ficam fora dos palpites, dos pendentes e do ranking.
- Na aba **Jogos** da administração aparecem a última atualização, a situação de cada liga, o último erro e o botão **Sincronizar agora**.
- Precisa da extensão `curl` do PHP (vem ligada na Hostinger).
