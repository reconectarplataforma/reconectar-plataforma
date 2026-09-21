# Diário de Desenvolvimento — Plataforma Reconectar

Registro cronológico de cada etapa concluída no desenvolvimento técnico da
plataforma, com data, o que foi feito, decisões técnicas tomadas e
pendências que dependem de ação humana ou processo participativo.

---

## 2026-09-21 — Task 1: Estrutura-base do repositório

**O que foi feito**
- Criado `.gitignore`, excluindo segredos (`.env`), núcleo do WordPress,
  uploads/cache, plugins e temas de terceiros (instalados via
  `scripts/provision.sh`, não versionados) e dados do banco de dados.
- Criado `LICENSE` com o texto da **GPL-2.0-or-later**.
- Criado `README.md` inicial com visão geral do projeto, stack técnica,
  estrutura do repositório e instruções de subida do ambiente local.

**Decisões técnicas**
- Licença do repositório: **GPL-2.0-or-later**, por compatibilidade
  obrigatória com WordPress/WooCommerce e por atender à exigência do edital
  de manter o código-fonte livre e aberto.
- Apenas código autoral (tema `reconectar` e plugin `reconectar-core`) é
  versionado; dependências de terceiros (WooCommerce, Dokan Lite,
  BuddyPress, bbPress, Storefront) ficam de fora do Git e são instaladas via
  script de provisionamento.

**Pendência técnica registrada**
- O arquivo `.env.example` **não pôde ser criado por mim**: as regras de
  segurança configuradas pelo próprio usuário (hook de bash e regra de
  permissão de arquivos) bloqueiam qualquer ferramenta de tocar em arquivos
  com padrão `.env*`, mesmo sem conteúdo sensível. O conteúdo sugerido foi
  entregue ao usuário no chat para que ele mesmo crie o arquivo, caso deseje
  usar um `.env` local — o `docker-compose.yml` (Task 2) foi projetado para
  funcionar com valores-padrão mesmo sem esse arquivo.

**Pendências que dependem de decisão da equipe/processo participativo**
- Nenhuma nesta task.

---

## 2026-09-21 — Task 2: Ambiente Docker

**O que foi feito**
- Criado `docker-compose.yml` com quatro serviços:
  - `db` (MariaDB 10.11), com healthcheck para garantir que o banco esteja
    pronto antes do WordPress subir.
  - `wordpress` (imagem oficial `wordpress:6-php8.2-apache`), com
    `wp-content/` mapeado via bind-mount para o repositório — assim, o tema
    e o plugin autoral desenvolvidos localmente aparecem imediatamente no
    container, sem rebuild de imagem.
  - `wpcli` (imagem oficial `wordpress:cli-php8.2`), usada sob demanda via
    `docker compose run --rm wpcli ...` para rodar comandos WP-CLI e o
    script de provisionamento.
  - `phpmyadmin`, para facilitar inspeção/suporte do banco de dados durante
    o desenvolvimento (não é dependência de produção).
- Todas as variáveis (credenciais de banco, portas, dados do admin) têm
  valores-padrão seguros para uso local via sintaxe `${VAR:-padrão}`, então
  o ambiente sobe mesmo sem um arquivo `.env`.
- Validada a sintaxe do arquivo com `docker compose config` (sem erros).

**Decisões técnicas**
- MariaDB (em vez de MySQL) por ser a opção padrão recomendada pela imagem
  oficial do WordPress para bancos compatíveis.
- `wpcli` não fica em execução contínua (evita consumir recursos à toa);
  é invocado pontualmente via `docker compose run --rm`.

**Pendências que dependem de decisão da equipe/processo participativo**
- Nenhuma nesta task.

---

## 2026-09-21 — Task 3: Script de provisionamento (WP-CLI)

**O que foi feito**
- Criado `scripts/provision.sh`, executado via
  `docker compose run --rm wpcli bash /var/www/scripts/provision.sh`.
- O script é **idempotente** (pode ser rodado novamente sem causar erro ou
  duplicar conteúdo) e realiza:
  - Instalação do núcleo do WordPress (se ainda não instalado) e do idioma
    pt_BR.
  - Instalação do tema `storefront` (dependência do child theme
    `reconectar`); ativa `reconectar` automaticamente assim que ele existir
    (Task 5) — até lá, ativa `storefront` como tema provisório.
  - Instalação e ativação de WooCommerce, Dokan Lite, BuddyPress e bbPress.
  - Ativação do plugin autoral `reconectar-core` assim que ele existir
    (Task 6).
  - Criação da página "Comunidade" com o shortcode `[buddypress]` e de um
    fórum inicial ("Fórum Geral") via bbPress.
  - As páginas essenciais do WooCommerce (Loja, Carrinho, Checkout, Minha
    Conta) são criadas automaticamente pelo próprio WooCommerce ao ser
    ativado — não é necessário criá-las manualmente no script.
- Validada apenas a sintaxe do script (`bash -n`); a execução real dentro
  do container acontece na Task 4.

**Decisões técnicas**
- Script escrito para ser seguro de rodar múltiplas vezes, já que o
  ambiente evoluirá em tasks futuras (tema e plugin autoral ainda não
  existem nesta task).

**Pendências que dependem de decisão da equipe/processo participativo**
- Nenhuma nesta task.

---

## 2026-09-21 — Task 4: Subida do ambiente Docker e validação

**O que foi feito**
- Executado `docker compose up -d` e, em seguida, o script completo
  `docker compose run --rm wpcli bash /var/www/scripts/provision.sh`.
- Confirmado com sucesso: núcleo do WordPress instalado, idioma pt_BR
  ativado, tema Storefront ativado (provisório até a Task 5), WooCommerce,
  Dokan Lite, BuddyPress e bbPress instalados e **ativos**, página
  "Comunidade" (shortcode `[buddypress]`) e fórum inicial "Fórum Geral"
  criados.
- Validado o acesso ao site em `http://localhost:8090` via navegador —
  título "Reconectar – Incubadora Digital" carregado corretamente.
- Durante a execução real, foram descobertos e corrigidos **cinco
  problemas técnicos de infraestrutura** não previstos no plano original:

  1. **Volume `wordpress-data` compartilhado**: o núcleo do WordPress
     precisa ser persistido em um volume Docker nomeado compartilhado entre
     os serviços `wordpress` e `wpcli`, para que ambos enxerguem a mesma
     instalação.
  2. **Conflito de porta 8080**: a porta padrão inicialmente escolhida para
     o serviço `wordpress` já estava em uso por outro serviço de terceiros
     (`transfer-service`) na máquina. Migrado para a porta `8090`
     (`WORDPRESS_PORT:-8090`), refletido no `docker-compose.yml`, no
     `scripts/provision.sh` (`WP_URL`) e no `README.md`.
  3. **Incompatibilidade de SSL/TLS entre cliente e servidor MariaDB**: a
     imagem `wordpress:cli-php8.2` traz um cliente MariaDB Connector/C que
     exige TLS por padrão para comandos externos (`mariadb-check`,
     `mariadb-dump`), enquanto o serviço `db` (MariaDB local) não tem TLS
     habilitado. O WP-CLI invoca essas ferramentas com `--no-defaults`, que
     ignora qualquer arquivo `my.cnf` — por isso uma tentativa anterior de
     resolver o problema com um arquivo `.my.cnf` montado no container não
     funcionou e foi removida. **Solução definitiva**: passar `--ssl=0`
     diretamente como argumento na linha de comando do WP-CLI (ex.:
     `wp db check --skip-plugins --skip-themes --ssl=0`), mantendo a
     checagem de saúde do banco no início do script.
  4. **Incompatibilidade de UID entre imagens Docker**: a imagem
     `wordpress:cli-php8.2` (usada pelo serviço `wpcli`) é *Alpine-based*,
     onde o usuário `www-data` tem UID/GID **82**; já a imagem
     `wordpress:*-php8.2-apache` (usada pelo serviço `wordpress`) é
     *Debian-based*, onde `www-data` tem UID/GID **33**. Como é o serviço
     `wordpress` quem cria os arquivos padrão em `wp-content/` (bind-mount
     compartilhado com o host), esses arquivos ficam com dono UID 33 — e o
     `wpcli`, rodando por padrão como UID 82, não conseguia escrever em
     subpastas como `wp-content/uploads/` e `wp-content/upgrade/` (erros de
     "Unable to create directory" / "Could not create directory").
     **Solução**: fixar `user: "33:33"` no serviço `wpcli` no
     `docker-compose.yml`, alinhando-o ao UID/GID do serviço `wordpress`.
  5. **Incompatibilidade de versão mínima do WordPress exigida pelo
     WooCommerce**: a imagem `wordpress:6-php8.2-apache` trava o WordPress
     na branch major 6 (versão instalada: 6.9.4), mas a versão atual do
     WooCommerce no repositório oficial exige WordPress mínimo 7.0,
     resultando em "This plugin does not work with your version of
     WordPress" e falha na instalação de todos os plugins subsequentes.
     **Solução**: migrar a imagem do serviço `wordpress` para
     `wordpress:7-php8.2-apache` (tag confirmada disponível no Docker Hub).
     Como essa mudança exige um núcleo novo, os volumes Docker nomeados
     `db-data` e `wordpress-data` foram recriados do zero
     (`docker compose down -v` + `docker compose up -d`) — sem perda de
     trabalho relevante, pois eram dados de teste efêmeros desta sessão; o
     bind-mount `wp-content` (tema e idioma já instalados) não foi afetado.

**Decisões técnicas**
- Preferida a flag `--ssl=0` no comando WP-CLI, em vez de um arquivo de
  configuração `my.cnf`, por ser a solução que de fato funciona com o
  `--no-defaults` usado internamente pelo WP-CLI.
- Fixado o UID/GID do serviço `wpcli` via `user: "33:33"` em vez de alterar
  permissões do bind-mount no host, mantendo a solução inteiramente dentro
  do `docker-compose.yml` (mais portável entre máquinas da equipe).
- Migração para `wordpress:7-php8.2-apache` em vez de fixar uma versão
  antiga do WooCommerce, para manter a stack alinhada com versões
  correntes e evitar dívida técnica de compatibilidade.

**Pendências que dependem de decisão da equipe/processo participativo**
- Nenhuma nesta task.

---
