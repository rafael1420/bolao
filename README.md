# Bolão

Site de bolão de futebol em PHP e MySQL, para hospedagem compartilhada (Hostinger, plano PHP/HTML).

Qualquer usuário cadastrado pode criar um bolão, escolher as ligas e as regras de pontuação, e convidar pessoas por código, link ou senha. Não há apostas em dinheiro nem prêmios.

## Instalação na Hostinger

1. **Crie o banco** no hPanel, em *Bancos de dados → Gerenciamento*. Anote o nome completo (com o prefixo, ex.: `u329075197_bolao`), o usuário e a senha.
2. **Envie o projeto:** no Gerenciador de Arquivos, entre em `public_html`, envie o arquivo `bolao-hostinger.zip` e extraia. Os arquivos devem ficar direto dentro de `public_html`, sem uma pasta extra.
3. **Instale:** abra `seusite.com/install.php`, preencha os dados do banco e o e-mail do administrador, e clique em *Instalar*. O instalador cria as tabelas, grava o `includes/config.php` e apaga a si mesmo.
4. **Crie sua conta** no site com o mesmo e-mail do administrador. Essa conta cadastra jogos e lança resultados.

Se o instalador não apagar `install.php` sozinho, apague o arquivo pelo gerenciador de arquivos.

## Estrutura

```
index.php, login.php, ...  Páginas do site
install.php                Instalador (se apaga após usar)
includes/                  Regras, conexão e sessão (bloqueado pela internet)
sql/schema.sql             Tabelas do banco (bloqueado pela internet)
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
