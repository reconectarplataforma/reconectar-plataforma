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

## 2026-09-21 — Task 7: Documentação do módulo de pagamentos (Pix/Cartão/Boleto)

**O que foi feito**
- Criado [docs/PAGAMENTOS.md](../docs/PAGAMENTOS.md), documentando:
  - Por que nenhum gateway de pagamento foi instalado/configurado ainda
    (decisão do provedor — Mercado Pago, Asaas, PagSeguro etc. — ainda
    depende de definição da equipe, incluindo taxas, prazos de repasse e
    suporte a split de pagamento entre vendedores do marketplace).
  - Como a estrutura já está pronta para receber um gateway: o
    WooCommerce (já instalado e ativo) expõe uma API nativa e extensível
    de gateways de pagamento (`WC_Payment_Gateway`), então basta instalar
    o plugin oficial do provedor escolhido — nenhum código autoral
    adicional é necessário no `reconectar-core` para isso.
  - Onde as credenciais deverão ser inseridas quando a equipe decidir
    (interface administrativa do WooCommerce, nunca em código-fonte
    versionado) e o uso do `.env` (já ignorado pelo Git) apenas para
    credenciais de ambiente de teste/sandbox durante o desenvolvimento.
  - Pendências explícitas que dependem de decisão da equipe.
- Atualizado o [README.md](../README.md) para referenciar a nova
  documentação.
- **Nenhum plugin de gateway de pagamento foi instalado e nenhuma
  credencial (real ou de teste) foi criada, inserida ou commitada** nesta
  task — conforme decisão já validada com o usuário antes do início desta
  fase de desenvolvimento.

**Decisões técnicas**
- Documentação centralizada em arquivo próprio (`docs/PAGAMENTOS.md`) em
  vez de apenas expandir a seção já existente no README, para manter o
  README enxuto como visão geral e permitir que a equipe (que decidirá o
  provedor) encontre rapidamente todo o contexto relevante em um único
  lugar.

**Pendências que dependem de decisão da equipe/processo participativo**
- Escolha do provedor de pagamento (Mercado Pago, Asaas, PagSeguro ou
  outro), considerando taxas, prazos de repasse e suporte a split de
  pagamento para o marketplace multi-vendedor.
- Definição de quem (qual CNPJ/conta) recebe os repasses de cada
  vendedor/iniciativa cadastrada na plataforma.
- Decisão sobre uso do Dokan Pro (pago) caso o split de pagamento nativo do
  Dokan Lite não seja suficiente para o modelo de negócio do projeto.
- Homologação/cadastro junto ao provedor escolhido e obtenção das
  credenciais de produção.

---

## 2026-09-22 — Task 8: Bootstrap e layout responsivo

**O que foi feito**
- Diagnosticado o layout quebrado reportado pelo usuário (menu de
  navegação transbordando em várias linhas, listando 11 páginas). Causa
  raiz: nenhum menu estava atribuído ao local `primary` do tema
  (`wp menu list` vazio), o que fazia `wp_nav_menu()` recorrer ao
  fallback padrão do WordPress (`wp_page_menu()`), listando
  automaticamente **todas** as páginas publicadas como itens de nível
  superior — sem nenhuma curadoria nem estrutura responsiva.
- Auto-hospedado o **Bootstrap 5.3.3** (CSS + JS bundle, minificados,
  licença MIT) em
  `wp-content/themes/reconectar/assets/bootstrap/`, sem uso de CDN e sem
  pipeline de build, mantendo a simplicidade já adotada no projeto.
- Enfileirado o Bootstrap em `functions.php`
  (`reconectar_enqueue_assets()`), com `reconectar-style` passando a
  depender também de `reconectar-bootstrap`.
- Criado um menu de navegação curado via WP-CLI (Início, Loja,
  Comunidade, Transparência, Minha Conta) e atribuído ao local
  `primary`. A criação foi adicionada de forma idempotente ao
  `scripts/provision.sh` (verifica se já existe um menu atribuído ao
  local `primary` antes de criar), garantindo que o ambiente continue
  reproduzível do zero.
- Sobrescrita a função `storefront_primary_navigation()` no
  `functions.php` do tema `reconectar` — o Storefront protege essa
  função com `function_exists()`, então bastou declará-la no child
  theme (carregado antes do pai) para substituir a navegação padrão por
  uma navbar Bootstrap (`navbar navbar-expand-lg`) com botão hambúrguer
  colapsável (`navbar-toggler` + `collapse`) em telas pequenas.
- Adicionados os filtros `nav_menu_css_class` e
  `nav_menu_link_attributes` para aplicar as classes Bootstrap
  (`nav-item`, `nav-link`) aos itens do menu primário sem precisar de um
  Walker completo.
- Ajustado `style.css`: cabeçalho com `display: flex` e `flex-wrap`
  (logo + busca lado a lado, empilhando em telas ≤568px), cores da
  navbar seguindo a paleta institucional do projeto, e espaçamento do
  painel de transparência.
- Atualizado o shortcode `[reconectar_painel_transparencia]`
  (`reconectar-core`) para renderizar as propostas como cards Bootstrap
  responsivos (`row row-cols-1 row-cols-md-2 g-3`, `card`, `card-body`,
  badges de placar de votos), por ser conteúdo 100% autoral do projeto.
- Validado visualmente no navegador interno em três larguras de
  viewport (mobile ~375px, tablet ~768px, desktop ~1280px): sem overflow
  horizontal em nenhuma delas, e o menu colapsa corretamente em um botão
  hambúrguer funcional em mobile e tablet.

**Diagnóstico técnico adicional: bug do item "Início" cortado**
- Durante a validação, o primeiro item do menu ("Início") aparecia
  cortado/deslocado para a esquerda. Causa: o WordPress core injeta
  automaticamente a classe `nav-menu` no `<ul>` do menu do
  `theme_location` primário, independentemente do `menu_class` passado
  em `wp_nav_menu()`. Isso ativa a regra legada
  `.main-navigation ul.menu, .main-navigation ul.nav-menu { margin-left: -1em; ... }`
  (dentro de `@media (min-width: 768px)`) do `storefront/style.css`,
  cuja especificidade CSS (2 classes + 1 elemento) supera a de
  `.reconectar-navbar .navbar-nav` (2 classes). Corrigido aplicando
  `!important` na propriedade `margin` desse seletor, documentado em
  comentário no próprio `style.css`.

**Decisões técnicas**
- Escopo delimitado ao "chrome" do site (cabeçalho, navegação) e ao
  conteúdo autoral (tema `reconectar` + shortcode do
  `reconectar-core`). Ficou **fora do escopo** o reskin dos templates
  nativos do WooCommerce/Dokan (loja, produto, checkout, minha conta,
  lista de lojas) — essas páginas já usam o grid responsivo nativo do
  Storefront/WooCommerce (que não estava quebrado, apenas sem a marca
  visual do Bootstrap), evitando retrabalho de reescrever templates de
  terceiros e preservando compatibilidade com atualizações futuras
  desses plugins.
- Itens como Cart, Checkout, My Orders, Store List, Vendor Onboarding e
  Sample Page foram deixados de fora do menu principal — continuam
  acessíveis pelos ícones de carrinho/conta padrão do
  WooCommerce/Dokan, não pela navegação de topo.

**Pendências**
- Nenhuma pendência de negócio/processo participativo nesta task —
  trabalho puramente técnico.

---

## 2026-09-22 — Task 9: Redesenho da home institucional

**O que foi feito**
- Diagnosticado, por leitura de código (sem alterações), que a home
  atual usava o modo "posts" do WordPress
  (`show_on_front = "posts"`), ou seja, era apenas o índice padrão de
  posts do blog (`index.php` → `loop.php`), sem nenhuma seção
  institucional ou de produtos — o tema `reconectar` não possuía
  nenhum template PHP próprio, dependendo 100% dos templates do
  Storefront.
- Confirmado que o Storefront não define `front-page.php`, mas tem um
  page template opcional (`template-homepage.php`) que já registra,
  via `do_action( 'homepage' )`, as seções nativas: conteúdo de página
  (10), categorias de produtos (20), recentes (30), destaque (40),
  populares (50), em promoção (60), mais vendidos (70) e,
  condicionalmente, "Shop by Brand" (80, quando a classe `WC_Brands`
  existe). Como um `front-page.php` presente no child theme é usado
  para a home independentemente do valor de `show_on_front`, não foi
  necessário alterar essa configuração via WP-CLI.
- Criado `wp-content/themes/reconectar/front-page.php`, adaptado da
  estrutura de `template-homepage.php` do tema pai: `get_header()`,
  wrapper `#primary`/`#main`, `do_action( 'homepage' )`, `get_footer()`.
- Em `functions.php`, ajustados os hooks da action `homepage`:
  removidos `storefront_homepage_content` (10, sem page associada à
  home), `storefront_recent_products` (30), `storefront_featured_products`
  (40), `storefront_popular_products` (50), `storefront_on_sale_products`
  (60) e `storefront_best_selling_products` (70) — seções de produtos
  fora do escopo aprovado para esta home. Mantido intacto
  `storefront_product_categories` (20), reaproveitando o widget nativo
  do WooCommerce sem reescrevê-lo. Adicionadas duas novas seções
  autorais: `reconectar_homepage_hero` (prioridade 5, banner
  institucional com título, subtítulo e botão CTA "Ver produtos"
  apontando para `wc_get_page_permalink( 'shop' )`) e
  `reconectar_homepage_comunidade_transparencia` (prioridade 25, cards
  linkando para as páginas "Comunidade" e "Transparência" via
  `get_page_by_path()`, com fallback silencioso caso alguma delas não
  exista).
- Descoberto durante a implementação que o Storefront também registra
  condicionalmente o hook `storefront_woocommerce_brands_homepage_section`
  (80, "Shop by Brand") quando o plugin WooCommerce Brands está ativo
  (`class_exists( 'WC_Brands' )`). Essa seção não fazia parte do
  escopo aprovado (hero, categorias, comunidade/transparência) e foi
  removida junto com as demais seções de produto.
- Em `style.css`, adicionada a seção "Home institucional — Task 9":
  estilo do hero (gradiente com as cores institucional e primária do
  projeto, título em Thoge, botão CTA na cor de destaque), espaçamento
  vertical uniforme entre as seções da home
  (`.reconectar-hero`, `.storefront-product-section`,
  `.reconectar-home-comunidade-transparencia`), e estilo dos cards de
  Comunidade/Transparência reaproveitando o padrão de card já criado
  na Task 8 para o painel de transparência.
- Validado visualmente no navegador interno em três larguras de
  viewport (mobile ~375px, tablet ~768px, desktop ~1280px): hero
  legível e centralizado, categorias em grid responsivo, cards de
  Comunidade/Transparência empilhando corretamente em mobile, sem
  overflow horizontal em nenhuma largura testada.

**Diagnóstico técnico adicional: regressão acidental em comentários PHPDoc**
- Durante a revisão do diff antes do commit, foi identificado que a
  edição de `functions.php` havia removido, por engano, três blocos de
  comentário PHPDoc pré-existentes da Task 8 (documentando
  `storefront_primary_navigation()` e `reconectar_nav_menu_css_class()`).
  Os comentários foram restaurados integralmente, preservando a
  documentação original dessas funções.

**Decisões técnicas**
- Optou-se por reaproveitar os hooks nativos do Storefront em vez de
  reescrever a home do zero, mantendo compatibilidade com atualizações
  futuras do WooCommerce/Dokan e evitando duplicar lógica já resolvida
  pelo tema pai (especialmente a seção de categorias de produtos, que
  já é responsiva e compatível com o marketplace multi-vendedor sem
  código adicional).
- As seções nativas de produtos recentes, em destaque, populares, em
  promoção e mais vendidos foram deliberadamente excluídas do escopo
  desta home institucional, por decisão do usuário — a home prioriza
  a identidade institucional do projeto (hero + chamada para
  comunidade/transparência) sobre uma vitrine de produtos completa.

**Pendências que dependem de decisão da equipe/processo participativo**
- A seção de categorias de produtos (`storefront_product_categories`)
  só exibe categorias que tenham produtos associados; hoje a única
  categoria existente no ambiente de desenvolvimento é "Uncategorized",
  sem produtos vinculados — isso é uma pendência de conteúdo/cadastro,
  não de código, e será resolvida naturalmente à medida que os
  vendedores cadastrarem produtos categorizados na plataforma.

---

## 2026-09-22 — Task 10 (Passo 2): Desacoplamento do child theme do Storefront

**O que foi feito**
- Refatoração pura, sem mudança visual, preparando a eventual troca do
  tema pai (Storefront → Blocksy) enquanto o site ainda funciona sob o
  pai atual.
- `functions.php` deixou de implementar qualquer coisa e passou a
  carregar módulos de `inc/` por uma **lista explícita** — não por
  `glob()` —, com ordem de carregamento previsível.
- A home passou a usar uma action própria, `reconectar_home`, no lugar
  de `do_action( 'homepage' )`. A action `homepage` é disparada **e**
  povoada pelo Storefront: com ela, a home inteira desapareceria junto
  com o pai.
- `reconectar_ajustar_hooks_homepage()` removida por inteiro — os sete
  `remove_action` sobre a action `homepage` viraram código morto assim
  que a home deixou de usá-la.
- `storefront_product_categories` substituída por
  `reconectar_home_categorias()`, com consulta própria a `product_cat`.
  A função do pai montava a seção via shortcode `[product_categories]`
  dentro de `.storefront-product-section`, deixando markup e classes da
  nossa home sob controle do Storefront.
- Todo o código acoplado ao Storefront isolado em
  `inc/legado-storefront.php` (sobrescrita de
  `storefront_primary_navigation()`, os dois filtros `nav_menu_*` e o JS
  do Bootstrap, que só existia por causa do `data-bs-toggle` do botão
  hambúrguer).
- `style.css` do child theme passou a ser enfileirado explicitamente via
  `get_stylesheet_uri()`: o Blocksy não o carrega sozinho, e depender do
  pai deixaria o tema sem estilo próprio após a troca.
- `filemtime()` protegido por `file_exists()` em
  `reconectar_versao_asset()` — sem a guarda, um caminho inexistente
  emite warning e gera `?ver=` vazio.
- `wc_get_page_permalink()` protegida por `reconectar_url_loja()`, para
  que desativar o WooCommerce não derrube a home com erro fatal.

**Verificação**
- `php -l` nos 9 arquivos alterados; home em HTTP 200 sem notices;
  cadeia de enqueue preservada na ordem bootstrap → pai → fontes →
  filho; 7 rotas principais sem fatais; conferência visual da home
  inalterada.

**Pendência**
- O Blocksy 2.1.57 foi **instalado e deixado inativo**. A troca do tema
  pai não aconteceu, e não deve ser tratada como decisão tomada: o tema
  ativo continua sendo o `reconectar`, ainda declarando
  `Template: storefront`. Há um `docker/backup-pre-blocksy.sql` do
  estado anterior à instalação.

---

## 2026-09-24 — Task 11: Layout de marketplace

**O que foi feito**
- A home institucional da Task 9 deu lugar a uma **vitrine de
  marketplace**, tomando como referência as capturas de tela do iFood
  fornecidas pelo usuário. O tema autoral absorveu o layout; não houve
  migração de tema pai para isso.
- `header.php` e `footer.php` passaram a ser templates autorais. O
  `header.php` **mantém a mesma cadeia de wrappers** do Storefront
  (`#page` → `#masthead` → `#content` → `.col-full`) porque templates do
  WooCommerce e do Dokan, além de boa parte do CSS já escrito, se
  posicionam a partir desses contêineres — trocar os nomes quebraria
  loja, carrinho e painel do vendedor sem ganho de layout. O que **não**
  é mais disparado é a action `storefront_header`: é nela que o pai
  pendura logotipo, menu, busca e carrinho, e dispará-la imprimiria um
  segundo cabeçalho logo abaixo do nosso.
- Criado o módulo `inc/marketplace/`, com cinco arquivos:
  - `consultas.php` — a camada de dados: `reconectar_obter_lojas()`,
    `reconectar_normalizar_loja()`, `reconectar_nota_da_loja()`,
    `reconectar_categoria_principal_da_loja()`,
    `reconectar_dados_de_entrega()`, `reconectar_obter_cidades()`,
    `reconectar_obter_categorias()` e
    `reconectar_obter_produtos_destaque()`.
  - `componentes.php` — os blocos reutilizáveis de markup:
    `reconectar_card_loja()`, `reconectar_card_loja_banner()`,
    `reconectar_card_categoria()`, `reconectar_card_produto()`,
    `reconectar_nota_em_estrela()`, `reconectar_taxa_de_entrega()` e o
    par `reconectar_abrir_carrossel()` / `reconectar_fechar_carrossel()`.
  - `filtros.php` — barra de filtros em pílulas, leitura dos parâmetros
    da URL e as constantes de paginação.
  - `cabecalho.php` — busca central, seletor de município, atalho de
    conta e resumo do carrinho, este último atualizado por
    `woocommerce_add_to_cart_fragments`.
  - `rodape.php` — rodapé multi-coluna com áreas de widget e a faixa
    legal.
- Três seções novas na home, cada uma registrando-se sozinha na action
  `reconectar_home` com a prioridade que define sua posição:
  categorias (20), lojas em destaque (30), ofertas (40) e a vitrine de
  lojas (50, com a barra de filtros).
- Carrossel horizontal com setas em `assets/js/marketplace.js` (113
  linhas, sem dependência): as setas se habilitam e desabilitam conforme
  a posição de rolagem, e a faixa continua rolável por toque e por
  teclado quando o JavaScript não carrega.
- `assets/css/marketplace.css` (860 linhas), com prefixo de classe
  `rc-`, reaproveitando as custom properties de cor já definidas em
  `style.css`.

**Decisões técnicas**
- **Os banners promocionais da segunda captura viraram uma faixa de
  produtos reais.** Banner promocional é peça de campanha publicitária;
  fabricar quatro deles com arte fictícia encheria a tela de conteúdo
  que ninguém pode publicar.
- **"As maiores redes" virou "Lojas em destaque", ordenada por
  avaliação.** A plataforma não tem redes, e o critério precisa ser
  algum dado que exista de verdade.
- **O "Ver mais lojas" é um link com `?lojas=N`, não uma busca por
  AJAX.** Custa uma recarga, mas o estado expandido fica na URL —
  compartilhável, favoritável e desfeito pelo botão "voltar" — e a lista
  continua completa para quem navega sem JavaScript ou com leitor de
  tela.
- A consulta pede **uma loja a mais** do que o limite para saber se
  ainda há resultados depois do corte; contar o total exigiria carregar
  e filtrar a lista inteira uma segunda vez.
- `RECONECTAR_LOJAS_POR_PAGINA` é 12 e `RECONECTAR_LOJAS_LIMITE_MAXIMO`
  é 96. O limite tem **piso**, não só teto: `?lojas=3` continua valendo
  12. O teto existe porque o parâmetro é público — sem ele,
  `?lojas=100000` pediria a normalização de todas as lojas de uma vez.

**Armadilhas encontradas**
- `aria-pressed` foi usado em `<a>` nas pílulas de filtro e trocado por
  `aria-current="true"`: o atributo pertence a `button`, e um link com
  `aria-pressed` é anunciado de forma errada por leitor de tela.
- Um `<details open>` na barra de filtros abria por padrão no celular e
  empurrava a vitrine para fora da primeira tela.

---

## 2026-09-24 — Task 12: Base de demonstração

**O que foi feito**
- Criada uma carga de dados fictícios completa, para que a plataforma
  possa ser demonstrada com conteúdo na tela. Fica em `scripts/seed/`,
  separada em duas partes: `dados-demo.php` (489 linhas, catálogo
  declarativo — só dados) e `demo.php` (1376 linhas, o motor que os
  aplica).
- A carga cria 6 categorias de produto (a sexta, "Cultura e Educação",
  propositalmente vazia, para exercitar `hide_empty`), 5 lojas com
  vendedores Dokan, 15 produtos com imagem gerada, 16 avaliações, 3
  clientes com endereço completo e 6 pedidos.
- Os 6 pedidos cobrem todo o fluxo exigido pela especificação —
  "Pedido realizado → Pagamento aprovado → Preparação → Enviado →
  Entregue" — e os três meios de pagamento (PIX, cartão, boleto). O
  último, `ped-006`, tem itens de **três lojas diferentes**: é o caso
  do carrinho multi-vendedor.
- Os dois status intermediários não existem no WooCommerce e foram
  registrados em
  `reconectar-core/includes/class-reconectar-status-pedido.php`
  (`wc-preparacao` e `wc-enviado`).
- `reconectar-core/includes/class-reconectar-aviso-demo.php` imprime uma
  faixa de advertência no site e no painel enquanto houver dado fictício
  no banco. A opção `reconectar_demo_ativo` é o interruptor.
- Três scripts novos em `scripts/`: `seed-demo.sh` (instala ou remove só
  os dados), `demo-completa.sh` (ambiente + provisionamento + carga, em
  um comando) e um serviço `demo` no Compose, atrás de
  `profiles: ["demo"]`.
- As imagens dos produtos e os logotipos das lojas são **geradas por
  GD** na hora da carga, a partir das cores do projeto. Nenhuma
  fotografia entra no repositório.

**Decisões técnicas**
- **Não foi criada uma imagem Docker autoral para a demonstração**,
  embora o usuário tenha aberto essa possibilidade. Um serviço com
  `profiles` resolve o mesmo problema: `docker compose up` comum não o
  levanta, e a carga só roda quando alguém a pede. Uma imagem separada
  duplicaria o WP-CLI e passaria a exigir build.
- **A carga é estritamente opcional.** `provision.sh` não a executa.
  Dado fictício só entra no banco por `seed-demo.sh`, por
  `demo-completa.sh` ou por `docker compose run --rm demo`.
- **Idempotência por chave, não por contagem.** Cada objeto é procurado
  pelo slug (categorias, produtos), pelo login (vendedores e clientes)
  ou por uma chave determinística na meta `_reconectar_demo_chave`
  (`{login-da-loja}-{posição}` para avaliações, `ped-001` e seguintes
  para pedidos).
- **Vendedores e clientes compartilham a senha `reconectar-demo`**,
  substituível por `RECONECTAR_DEMO_SENHA`. Antes recebiam senha
  aleatória, o que tornava impossível demonstrar o painel do vendedor
  sem redefinir cada conta à mão. São contas de ambiente local, não
  credenciais de sistema.
- E-mails no domínio `exemplo.invalid`, reservado pela RFC 2606 — não
  existe e nunca vai existir, então nada escapa para uma caixa real.
- O envio de e-mail é interceptado por `pre_wp_mail`, e não removendo os
  hooks do WooCommerce um a um: a lista de hooks muda entre versões do
  plugin, e a falha seria silenciosa.

**Armadilhas encontradas**
- **`wc_get_orders()` ignora `meta_query`, `meta_key` e `meta_value` sem
  emitir aviso** e devolve o banco inteiro. A remoção dos dados de
  demonstração usava esse filtro para achar os pedidos fictícios — teria
  apagado **todos** os pedidos da instalação. Os argumentos que a função
  de fato respeita são `limit`, `status`, `return`, `customer` e
  `parent`; a filtragem passou a ser feita em PHP, sobre o resultado.
- **`WC_Order::set_created_date()` não existe.** O nome correto é
  `set_date_created()`. O erro era fatal, não warning.
- **O Dokan mantém tabelas paralelas que a exclusão do pedido não
  limpa**: `wp_dokan_orders`, `wp_dokan_vendor_balance`,
  `wp_dokan_order_stats` e `wp_dokan_refund`. O painel do vendedor
  somava faturamento de pedidos que já não existiam. Criada
  `reconectar_demo_limpar_tabelas_dokan()`, que usa como critério a
  **existência do pedido**, e não a marcação de demonstração: linha
  órfã é órfã independentemente de quem a criou.
- O BuddyPress está ativo sem ter criado as próprias tabelas, então cada
  `wp_delete_user()` imprime
  `WordPress database error Table 'reconectar.wp_bp_activity' doesn't exist`.
  É ruído, não falha — a remoção conclui.
- `--ssl=0` só é aceito por `wp db *`; qualquer outro comando do WP-CLI
  rejeita a flag.
- A imagem CLI é Alpine (`www-data` = 82) e a Apache é Debian
  (`www-data` = 33), e é esta que cria os arquivos no volume. Os
  serviços `wpcli` e `demo` passaram a declarar `user: "33:33"`.
- A porta do ambiente local é **8090**: a 8080 estava ocupada na máquina
  de desenvolvimento.

---

## 2026-09-24 — Task 13: RBAC e isolamento entre vendedores

**O que foi feito**
- Implementado o controle de acesso baseado em papéis exigido pela
  especificação, em
  `reconectar-core/includes/class-reconectar-permissoes.php`.
- A autorização é por **capacidade**, não por papel. O acesso à
  comunidade ganhou capacidade própria,
  `reconectar_participar_comunidade`, concedida a `administrator` e
  `seller` por `sincronizar_capacidades()`.
- `sincronizar_capacidades()` roda em `init`, e não na ativação do
  plugin: o papel `seller` é criado pelo **Dokan**, e se o
  `reconectar-core` for ativado antes dele o papel ainda não existe — a
  concessão se perderia em silêncio. Uma constante `VERSAO_CAPACIDADES`
  evita reescrever as capacidades a cada carregamento.
- O isolamento entre vendedores foi implementado em **duas frentes**,
  porque uma só não basta:
  - `restringir_por_vendedor()`, em `map_meta_cap`, intercepta as
    capacidades de objeto e acrescenta `do_not_allow` quando o dono é
    outro vendedor. Vale para painel, REST API e qualquer código que
    consulte `current_user_can()`.
  - `restringir_listagens_do_vendedor()`, em `pre_get_posts`, filtra as
    consultas. `map_meta_cap` protege o acesso a um registro, mas **não
    filtra uma listagem**: sem esse segundo filtro, a lista de produtos
    devolveria o catálogo inteiro — nomes, preços e estoque dos
    concorrentes à vista, ainda que nenhum deles pudesse ser aberto.
    Para informação comercial, ver a lista já é o vazamento.
- Demais travas: `restringir_gestao_de_plugins()` (`map_meta_cap`),
  `bloquear_comunidade()` (`template_redirect`),
  `ocultar_itens_da_comunidade()` (`wp_nav_menu_objects`),
  `bloquear_area_administrativa()` (`admin_init`) e
  `ocultar_barra_administrativa()` (`show_admin_bar`).
- Criado `scripts/verificar-acessos.sh`, que confere 16 casos **por
  HTTP**: faz login como cliente, vendedor e administrador e bate em
  cada URL restrita, conferindo o código de resposta. Sai com status 1
  se algum falhar.

**Decisões técnicas**
- **A verificação é por HTTP de propósito.** A autorização precisa valer
  para a URL digitada à mão, que é o caminho que uma auditoria vai
  tentar. Um teste que apenas consulta `current_user_can()` em PHP prova
  que a função responde o esperado quando alguém pergunta, não que a
  requisição foi barrada.
- **Interface e backend são camadas distintas, e ambas existem.**
  Esconder o link da comunidade no menu é usabilidade — oferecer um link
  que devolve 403 é defeito de interface. Quem barra o acesso é o
  `template_redirect`, e ele barra mesmo com a URL digitada. O edital
  exige as duas.
- `dono_do_objeto()` consulta **pedidos primeiro**: com HPOS ativo eles
  deixam de ser posts, e `get_post_type()` devolveria `false` para um ID
  válido. Quando o objeto não é produto nem pedido, a função devolve
  `null` e nenhuma regra daqui se aplica.
- `bloquear_area_administrativa()` volta cedo em `wp_doing_ajax()`:
  `admin-ajax.php` fica dentro de `/wp-admin` mas atende o front-end,
  inclusive o carrinho e o painel do Dokan. Bloqueá-lo quebraria a loja
  para as duas pessoas que o método protege.
- O redirecionamento tem destino útil: vendedor barrado vai para o
  painel do Dokan; cliente, para "Minha conta". Despejar alguém na home
  seria tecnicamente correto e inútil.

**Armadilhas encontradas**
- Registrou-se antes que `/wp-admin/plugins.php` *redirecionava* o
  vendedor. Medido: devolve **403**. São travas distintas, e o código de
  resposta mostra qual delas agiu — `/wp-admin/` redireciona porque ele
  tem para onde ir; `plugins.php` nega.
- O `/dashboard/` devolve **302 para a home** ao cliente, e a trava é do
  próprio Dokan, não nossa.
- Duas capacidades funcionam como portão em várias travas:
  `manage_options` e `manage_woocommerce`. Isso só é seguro porque o
  papel `seller` **não tem nenhuma das duas** — verificado: 67
  capacidades, nenhuma delas; o `customer` tem exatamente uma (`read`).
  Se um plugin novo conceder `manage_woocommerce` ao vendedor, o
  isolamento cai inteiro, sem erro e sem aviso.

**Limites conhecidos**
- `verificar-acessos.sh` é um script, não uma suíte: depende da carga de
  demonstração instalada, não roda em CI nem antecede um commit.
- `restringir_listagens_do_vendedor()` filtra apenas `product`. Listagem
  de pedidos não passa por ela — hoje o vendedor não entra no
  `/wp-admin`, e a coleção de pedidos da REST API exige
  `manage_woocommerce`, que ele não tem. A proteção existe, mas vem de
  outra camada; se qualquer uma das duas condições mudar, a lacuna se
  abre.

---

## 2026-09-24 — Task 14: Documentação e stacks

**O que foi feito**
- Criado `CLAUDE.md` na raiz: instruções e **armadilhas** para agentes
  de código que venham a trabalhar no repositório. Registra o que já
  custou caro — o `cd` que migra o diretório de trabalho da sessão, o
  `--ssl=0`, o UID divergente entre as imagens, a porta 8090, o Blocksy
  inativo, o `includes/` do plugin que não é o `inc/` do tema, a
  proibição de ler `.env*`.
- Criado `docs/STACKS.md`: as camadas da plataforma e **por que cada uma
  existe**, para que a próxima pessoa não precise deduzir a arquitetura
  do `docker-compose.yml`.
- Criado `docs/PERFIS_E_PERMISSOES.md`: os três atores, a matriz de
  permissões da especificação e, principalmente, **onde no código cada
  regra é aplicada** — método e hook, um a um — mais os comandos para
  conferir capacidades efetivas e o isolamento entre dois vendedores.
- Criado `docs/ROTEIRO_PERFIS.md`: o roteiro de demonstração pedido pelo
  usuário, com as credenciais e o passo a passo de cada perfil —
  Administrador, Vendedor e Usuário Comum — incluindo os limites que
  cada um deve *não* conseguir ultrapassar.
- Criado `docs/DADOS_DEMONSTRACAO.md`: o que a carga cria, tabela por
  tabela, e como removê-la.
- `README.md` atualizado: a árvore do repositório passou a listar
  `docs/` e os quatro scripts, e a seção de ambiente abre com
  `./scripts/demo-completa.sh` e as quatro URLs locais.

**Decisões técnicas**
- **Os pedidos são identificados no roteiro por cliente, status e
  valor, nunca por ID.** Os IDs mudam a cada recarga da base; um roteiro
  que diga "abra o pedido #42" fica errado na primeira reinstalação.
- O roteiro registra explicitamente que **não haverá botão "Ver mais
  lojas"** na demonstração, e que isso está correto: ele só aparece com
  mais de 12 lojas, e a base tem 5. Também não adianta forçar
  `?lojas=3`, por causa do piso. Medido por `curl` em `/`, `/?lojas=3` e
  `/?lojas=13`: zero ocorrências nos três.

**Armadilhas encontradas**
- Uma versão anterior de `docs/PERFIS_E_PERMISSOES.md` afirmava que "não
  há teste automatizado de permissão" **depois** de o
  `verificar-acessos.sh` já existir. Documentação que descreve um estado
  vencido é pior que documentação ausente: a ausente ao menos não mente.
- `docs/DADOS_DEMONSTRACAO.md` afirmava que as senhas dos vendedores
  eram aleatórias, o que deixara de ser verdade. Corrigido junto com o
  registro do porquê da mudança.

**Pendências**
- `scripts/permissoes-dev.sh` ainda não existia ao fim desta etapa — o
  ajuste de permissão de arquivo no volume continuava sendo feito à mão,
  pelo comando anotado no `CLAUDE.md`. Resolvido na Task 15, abaixo.

---

## 2026-09-24 — Task 15: Script de permissões do ambiente local

**O que foi feito**
- Criado `scripts/permissoes-dev.sh`, que substitui o `chown` manual
  anotado no `CLAUDE.md` e contornado à mão desde a Task 5/5b, quando o
  choque de UID entre host e contêiner apareceu pela primeira vez ao
  instalar o tema. Dois modos: sem argumento
  aplica; `--conferir` (ou `-c`) relata e não altera nada; `-h/--help`
  reimprime o cabeçalho do próprio arquivo, como nos demais scripts.
- A regra que ele aplica tem duas metades. O código autoral — tema
  `reconectar` e plugin `reconectar-core` — fica do usuário do host com
  o grupo do WordPress (`1000:33`): quem edita é a pessoa, e ao servidor
  basta ler. Todo o resto de `wp-content/` fica do WordPress com o grupo
  do host (`33:1000`): quem escreve ali é ele — uploads, atualizações,
  traduções — e a pessoa precisa poder apagar e versionar.
- Em ambas as metades o grupo recebe escrita (`chmod -R g+rwX`) e todos
  os diretórios recebem o bit setgid, para que arquivos criados depois
  já nasçam com o grupo correto.
- O UID e o GID do host vêm de `id -u` e `id -g`, não de `1000` fixo. O
  UID do WordPress continua fixo em 33, com o comentário explicando que
  esse é o `www-data` da imagem Debian, e não o 82 da imagem Alpine.
- `README.md` e `CLAUDE.md` atualizados: a árvore de `scripts/` ganhou a
  linha nova, e o bloco que ensinava o `chown` à mão agora aponta para o
  script.

**Decisões técnicas**
- **O script roda tudo dentro do contêiner, como root, e por isso não
  pede `sudo` no host.** `wp-content/` é um bind-mount: o inode é o
  mesmo dos dois lados, então um `chown` feito lá dentro vale aqui fora.
  Pedir `sudo` funcionaria igual, mas trocaria uma elevação contida no
  contêiner por uma elevação na máquina da pessoa.
- Usa `docker compose exec` quando o serviço `wordpress` já está no ar —
  o caso comum, e que não sobe nada — e cai para `run --rm --no-deps`
  quando não está. O `--no-deps` é deliberado: o serviço aqui é apenas
  um root com o volume montado, e esperar o healthcheck do MariaDB
  custaria meio minuto para um `chown`.
- A ordem das duas metades importa e está comentada no código: primeiro
  a regra geral em `wp-content/` inteiro, depois a exceção nos dois
  diretórios autorais, que a sobrescreve. O contrário apagaria a
  exceção.
- O modo `--conferir` existe porque o comando manual não tinha como ser
  verificado: aplicava-se no escuro. Ele lista os donos atuais e depois
  imprime os caminhos fora do padrão; saída vazia é o resultado bom.

**Armadilhas encontradas**
- **O setgid governa o grupo, não o modo.** Testando a herança nos dois
  sentidos, o arquivo criado pelo host no tema nasceu `1000:33` com
  `g+w`, como esperado; o criado dentro do contêiner nasceu com o grupo
  certo mas em `644`, porque o umask do WordPress é 022. O host ainda
  consegue apagá-lo e versioná-lo — isso depende da permissão do
  diretório, que tem `g+w` —, mas para editá-lo à mão é preciso rodar o
  script de novo. Está anotado no cabeçalho em vez de omitido: o script
  reduz a fricção, não a elimina.
- O ajuste é estritamente de desenvolvimento, e o cabeçalho diz isso em
  voz alta. Em produção o dono é um só, e dar escrita de grupo ao
  diretório de código seria abrir mão de uma barreira real — um upload
  malicioso que consiga escrever em `themes/` deixa de ser um arquivo
  parado e passa a ser código executável.

---
