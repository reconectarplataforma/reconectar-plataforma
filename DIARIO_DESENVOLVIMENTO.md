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

## 2026-09-21 — Task 5/5b: Tema customizado "reconectar" e identidade visual

**O que foi feito**
- Criado `wp-content/themes/reconectar/`, child theme do **Storefront**
  (tema oficial do WooCommerce, GPL), com:
  - `style.css`: cabeçalho de tema (`Template: storefront`), variáveis CSS
    com a paleta de cores do projeto (`--reconectar-cor-primaria: #31BEB1`,
    `--reconectar-cor-secundaria: #CF6442`, `--reconectar-cor-destaque:
    #F1BF3D`, `--reconectar-cor-institucional: #663191`) e regras aplicando
    a paleta a links, botões e ao branding do site.
  - `functions.php`: enfileira o estilo do tema pai (Storefront), a folha
    `assets/css/fonts.css` e o `style.css` do child theme (com
    `filemtime()` para cache-busting); e registra suporte nativo a
    **`add_theme_support('custom-logo', ...)`** do WordPress, para que o
    logo seja gerenciável via Personalizador em vez de hard-coded em
    templates.
  - `assets/css/fonts.css`: 18 declarações `@font-face` para a fonte
    **Poppins** (todos os pesos 100–900, normal e itálico, formato
    `truetype`) e 1 para a fonte **Thoge** (formato `opentype`) — Poppins
    usada no corpo do texto/UI, Thoge em títulos e no nome do site.
  - `assets/fonts/poppins/` (20 arquivos `.ttf`) e `assets/fonts/thoge/`
    (`thoge.otf`), copiados de `identidade_visual/Fonts/` (pasta local do
    usuário, fora do repositório).
  - `assets/img/`: três variações do logo copiadas de
    `identidade_visual/Logo/` (`logo-principal-cor.png`,
    `logo-apoio-cor.png`, `logo-apoio-branco.png`).
- Tema ativado com sucesso via reexecução idempotente do
  `scripts/provision.sh` (`wp theme list` confirmou `reconectar | active`).
- Logo configurado via WP-CLI: `wp media import` da variante horizontal
  colorida (`logo-apoio-cor.png`) seguido de
  `wp theme mod set custom_logo <ID>`.
- Validado visualmente em `http://localhost:8090`: logo exibido no
  cabeçalho, paleta de cores aplicada a links/botões, tipografia Thoge nos
  títulos e Poppins no corpo do texto.

**Decisões técnicas**
- **Contorno de permissão de arquivo (UID do host vs. do container)**: a
  pasta `wp-content/themes/`, criada pelo container `wordpress`
  (Debian-based, `www-data` = UID/GID 33), não pode ser escrita
  diretamente pelo usuário do host (UID 1000) — o mesmo problema de UID já
  identificado e resolvido na Task 4 para o serviço `wpcli`. Em vez de
  alterar permissões no host (`sudo chown`), o que quebraria a estratégia
  de UID já validada, os arquivos do tema foram preparados em `/tmp`
  (permissão do host) e copiados para dentro do volume via
  `docker compose run --rm -v /tmp/...:/mnt/theme-src:ro wpcli bash -c
  "cp ..."`, já que o serviço `wpcli` roda fixado como `user: "33:33"`.
- Preferido `add_theme_support('custom-logo', ...)` (recurso nativo do
  WordPress, gerenciável via Personalizador/WP-CLI) em vez de hard-code de
  `<img>` no template do tema, por ser a forma idiomática e mais flexível
  de aplicar o logo.
- **Ajuste no `.gitignore`**: identificado que a regra original
  (`wp-content/*` com negação apenas para `plugins/reconectar-core` e
  `themes/reconectar`/`themes/storefront`) deixaria passar
  `wp-content/index.php` (arquivo padrão do WordPress core) e todo o
  conteúdo de `wp-content/languages/` (arquivos `.mo`/`.po`/`.l10n.php`
  gerados pela instalação do idioma pt_BR) — nenhum dos dois é código
  autoral. Adicionadas exclusões explícitas para ambos, mantendo a
  estratégia já documentada na Task 1 de versionar apenas código autoral.

**Pendências que dependem de decisão da equipe/processo participativo**
- Nenhuma nesta task.

---

## 2026-09-21 — Task 6: Plugin autoral "reconectar-core" (esqueleto do módulo de Governança Digital)

**O que foi feito**
- Criado `wp-content/plugins/reconectar-core/`, plugin autoral (GPL-2.0-or-later)
  com:
  - `reconectar-core.php`: arquivo principal do plugin, com cabeçalho de
    metadados do WordPress e carregamento das duas classes do plugin via
    `require_once`, inicializadas no hook `plugins_loaded`.
  - `includes/class-reconectar-proposta-votacao.php`: classe
    `Reconectar_Proposta_Votacao`, que registra o Custom Post Type
    `proposta_votacao` ("Proposta de Votação", com labels em pt-BR,
    `show_in_rest` habilitado para compatibilidade com Gutenberg/REST API,
    suporte a título/editor/resumo) e dois post meta —
    `_reconectar_votos_favor` e `_reconectar_votos_contra` — via
    `register_post_meta()`, ambos expostos na REST API e protegidos por
    `auth_callback` (exige capacidade `edit_posts` para escrita).
  - `includes/class-reconectar-painel-transparencia.php`: classe
    `Reconectar_Painel_Transparencia`, que registra o shortcode
    `[reconectar_painel_transparencia]`. O callback do shortcode executa
    uma consulta (`get_posts`) pelas propostas publicadas e renderiza, para
    cada uma, título, resumo e o placar de votos (a favor, contra e
    total), em marcação HTML simples, com todo texto dinâmico escapado
    (`esc_html`, `esc_attr`, `wp_kses_post`) e um estado vazio ("nenhuma
    proposta publicada") quando não há propostas.
  - `README.md`: explica o escopo do plugin, deixando explícito que é um
    MVP/esqueleto — apenas a estrutura de dados e o painel de leitura
    estão implementados; a interface de votação interativa (quem pode
    votar, uma vez por beneficiário, prazo) depende da definição
    participativa das regras de funcionamento da plataforma.
- Plugin sincronizado para dentro do volume Docker e ativado com sucesso
  via WP-CLI (`wp plugin activate reconectar-core`); confirmado em
  `wp plugin list` como `active`, sem conflito com os demais plugins já
  ativos (WooCommerce, Dokan Lite, BuddyPress, bbPress).
- Validado funcionalmente: criadas duas propostas de teste via WP-CLI
  ("Instalação de ponto de wi-fi comunitário" e "Horário de funcionamento
  do espaço de coworking"), cada uma com valores de votos definidos nos
  post meta; criada uma página "Transparência" com o shortcode
  `[reconectar_painel_transparencia]`; confirmado visualmente em
  `http://localhost:8090/?page_id=19` que as duas propostas aparecem com
  título, resumo e placar de votos corretos (ex.: "A favor: 12 Contra: 3
  Total de votos: 15").

**Decisões técnicas**
- Reaplicada a mesma estratégia de contorno de permissão de arquivo (UID
  do host vs. UID 33/`www-data` do container) já usada na Task 5 para o
  tema: arquivos do plugin preparados em `/tmp/reconectar-core-plugin/`
  (gravável pelo host) e copiados para dentro do volume via
  `docker compose run --rm -v /tmp/reconectar-core-plugin:/mnt/plugin-src:ro
  wpcli bash -c "cp -r ..."`.
- Post meta de votos implementado como simples contadores inteiros
  (`_reconectar_votos_favor`/`_reconectar_votos_contra`), sem lógica de
  incremento/decremento neste momento — a forma como um voto individual é
  registrado (endpoint REST customizado, formulário, validação de
  duplicidade) é parte da interface de votação ainda não implementada,
  dependente da Atividade 2.10.
- Painel de transparência implementado como shortcode (em vez de bloco
  Gutenberg customizado) por ser a forma mais simples de embutir o
  conteúdo em qualquer página existente do tema Storefront/reconectar sem
  exigir build de assets JavaScript nesta etapa inicial do MVP.
- `scripts/provision.sh` já continha, desde a Task 3, a lógica condicional
  de ativação do `reconectar-core` assim que o diretório do plugin
  existisse — não foi necessário alterar o script nesta task.

**Pendências que dependem de decisão da equipe/processo participativo**
- Definição das regras de funcionamento da votação (Atividade 2.10):
  quem pode votar, se cada beneficiário vota uma única vez por proposta,
  prazo de abertura/encerramento de cada votação, e se o voto é público ou
  anônimo. Essas regras determinam o desenho da interface de votação
  interativa, ainda não implementada.

---
