# Conferência Bolão

Site de bolão de futebol em PHP e MySQL, para hospedagem compartilhada (Hostinger, plano PHP/HTML).

Qualquer usuário cadastrado pode criar um bolão, escolher as ligas e as regras de pontuação, e convidar pessoas por código, link ou senha. Não há apostas em dinheiro nem prêmios.

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
4. Os arquivos devem ficar direto dentro de `public_html`, sem subpasta.

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
index.php, login.php, ...  Páginas do site
install.php                Instalador (se apaga após usar)
includes/                  Regras, conexão e sessão (bloqueado pela internet)
assets/                    CSS
```

## Uso

- **Administração** (`admin_matches.php`): cadastre ligas e jogos, e lance os placares finais. Ao salvar um placar, os pontos são recalculados.
- **Conta** (`perfil.php`): qualquer usuário troca o próprio nome (que aparece no ranking) e a senha, informando a senha atual.
- **Organizador do bolão** (*Configurar*): altera nome, senha de entrada, ligas e pontuação; gera um novo código de convite (o link antigo deixa de funcionar); e exclui o bolão, digitando o nome exato para confirmar. Em *Membros*, remove participantes.
- **Importar jogos** (aba da administração): cole várias linhas `rodada; data e hora; mandante; visitante` (ou copie de uma planilha), confira linha a linha e grave tudo de uma vez. Jogos repetidos são ignorados.
- **Usuários** (aba da administração): busca contas e gera um link de nova senha (vale 24 h, uso único) para mandar por WhatsApp quando o e-mail não chegar.
- **Organizador do bolão** também pode **transferir a organização** para outro participante (em *Configurar*). Ele continua no bolão como participante.
- **Participantes:** fazem palpites até o início de cada jogo, acompanham o ranking (geral ou de uma rodada) e podem sair do bolão. Depois que um jogo começa, cada um pode ver os palpites da galera naquele jogo. Antes disso, ninguém vê o palpite dos outros.
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

## Jogos automáticos (ESPN)

`includes/sync.php` busca jogos e placares do Brasileirão Série A (`BRA-A`), da Copa do Brasil (`BRA-CDB`) e da Libertadores (`CONMEBOL-LIB`) na API pública da ESPN, e as rodadas do Brasileirão no TheSportsDB. Não precisa de chave nem de cron:

- Roda depois que a página é entregue ao visitante: a cada 5 minutos, ou a cada minuto com jogo ao vivo; a tabela completa, a cada 6 horas.
- Cria as ligas que faltam e, na primeira vez, as colunas e tabelas de que precisa (`sync_state`, `official_rounds` e as colunas novas de `matches`). Instalações novas já recebem tudo pelo `schema.sql`.
- Jogos adiados ou cancelados (`status = 'void'`) ficam fora dos palpites, dos pendentes e do ranking.
- Na aba **Jogos** da administração aparecem a última atualização, o último erro e o botão **Sincronizar agora**.
- Precisa da extensão `curl` do PHP (vem ligada na Hostinger).
