# Bolão

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
3. Deve aparecer a lista de 7 tabelas na coluna da esquerda.

### 4. Enviar o site
1. No **Gerenciador de Arquivos** do site `bolao.rafaelgomes.site`, entre em `public_html`.
2. Apague qualquer arquivo padrão (como `index.html` ou `default.php`).
3. Envie o `bolao-site.zip`, clique com o botão direito e escolha **Extrair** dentro de `public_html`.
4. Os arquivos devem ficar direto dentro de `public_html`, sem subpasta.

### 5. Instalar
1. Abra `bolao.rafaelgomes.site/install.php`.
2. Preencha: servidor `localhost`, banco, usuário, senha e o e-mail do administrador.
3. Clique em **Instalar**. Ele grava `includes/config.php` e tenta se apagar.

### 6. Criar sua conta
Cadastre-se no site com o mesmo e-mail do administrador.

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
- **Organizador do bolão:** altera nome, senha, ligas e pontuação em *Configurar*, e remove participantes em *Membros*.
- **Participantes:** fazem palpites até o início de cada jogo e acompanham o ranking.

## Pontuação padrão

| Situação | Pontos |
|---|---|
| Placar exato | 5 |
| Acertou o vencedor ou o empate | 3 |
| Acertou também o saldo de gols | +1 |

Cada bolão pode alterar esses valores (de 0 a 20).

## Ainda não existe

- Importação automática de jogos e resultados por API
- Recuperação de senha
- Edição de perfil
