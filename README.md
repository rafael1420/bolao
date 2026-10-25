# Bolão

Site de bolão de futebol em PHP e MySQL, para hospedagem compartilhada (Hostinger, plano PHP/HTML).

Qualquer usuário cadastrado pode criar um bolão, escolher as ligas e as regras de pontuação, e convidar pessoas por código, link ou senha. Não há apostas em dinheiro nem prêmios.

## Instalação na Hostinger

São dois arquivos: o site (`bolao-site.zip`) e o banco (`schema.sql`).

1. **Crie o banco** no hPanel, em *Bancos de dados → Gerenciamento*. Anote o nome completo (ex.: `u329075197_bolao`), o usuário e a senha.
2. **Importe o `schema.sql`** no phpMyAdmin: selecione o banco, abra a aba *Importar*, escolha o arquivo e clique em *Executar*.
3. **Envie o site:** no Gerenciador de Arquivos, entre em `public_html`, envie o `bolao-site.zip` e extraia. Os arquivos devem ficar direto dentro de `public_html`.
4. **Instale:** abra `seusite.com/install.php`, preencha os dados do banco e o e-mail do administrador, e clique em *Instalar*. Ele grava o `includes/config.php` e tenta se apagar.
5. **Crie sua conta** no site com o mesmo e-mail do administrador.

Se o `install.php` não se apagar sozinho, apague pelo gerenciador de arquivos.

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
