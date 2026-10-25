# Bolão

Site de bolão de futebol em PHP e MySQL, pensado para hospedagem compartilhada (Hostinger, plano PHP/HTML).

Qualquer usuário cadastrado pode criar um bolão, escolher as ligas e as regras de pontuação, e convidar pessoas por código, link ou senha. Não há apostas em dinheiro nem prêmios.

## Estrutura

```
sql/schema.sql          Tabelas do banco
includes/config.php     Dados de conexão (não vai para o GitHub)
includes/app.php        Regras do bolão: pontuação, membros, ranking
includes/auth.php       Sessão, login, proteção CSRF
includes/db.php         Conexão com o banco
includes/layout.php     Cabeçalho e rodapé das páginas
public/                 Páginas. O conteúdo desta pasta vai para public_html/
public/assets/          CSS
```

## Instalação na Hostinger

1. No hPanel, crie um banco MySQL e anote nome, usuário e senha.
2. Crie o arquivo `includes/config.php` no servidor, com as constantes `DB_HOST`, `DB_NAME`, `DB_USER` e `DB_PASS`. Ele não vai para o GitHub, porque contém a senha do banco.
3. No phpMyAdmin, importe `sql/schema.sql`.
4. Envie a pasta `includes/` para o mesmo nível de `public_html/`, e o conteúdo de `public/` para dentro de `public_html/`.
5. Confira o e-mail de administrador em `includes/app.php` (`ADMIN_EMAIL`). Essa conta cadastra jogos e resultados.

## Uso básico

- **Administração** (`admin_matches.php`): cadastre ligas e jogos, e lance os placares finais. Ao salvar um placar, os pontos dos palpites são recalculados.
- **Organizador do bolão**: pode alterar nome, senha, ligas e pontuação em *Configurar*, e remover participantes em *Membros*.
- **Participantes**: fazem palpites até o início de cada jogo e acompanham o ranking.

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
