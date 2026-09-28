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

## 2026-09-25 — Fluxo de listagem de lojas

**O que foi feito**
- A vitrine de lojas saiu de `inc/home/secao-lojas.php` e virou componente
  próprio em `inc/marketplace/vitrine.php`, com
  `reconectar_vitrine_de_lojas( $args )`. Três argumentos governam o que
  muda entre os contextos: `titulo` (vazio suprime o `<h2>`), `busca` (se
  imprime o campo por nome) e `mais` (`expandir`, o `?lojas=N` de sempre,
  ou `pagina`, link para a listagem completa). `secao-lojas.php` ficou só
  com o hook e a chamada.
- A página `/store-listing/` passou a renderizar essa vitrine: um filtro
  em `do_shortcode_tag` troca a saída do `[dokan-stores]` pelo componente
  do tema. Antes ela era o shortcode cru do plugin — cards de terceiro,
  filtros em inglês e nenhum dos filtros do marketplace.
- Busca por nome de loja: parâmetro `?busca=`, lido em
  `reconectar_filtros_ativos()`, aplicado em
  `reconectar_loja_passa_nos_filtros()` por
  `reconectar_nome_casa_com_busca()` e exposto como pílula removível na
  barra de filtros. O estado vazio ganhou um terceiro caso, que ecoa o
  termo buscado em vez de falar em "filtros selecionados".
- Ligados os caminhos que morriam na home: o "Ver todos" do carrossel de
  destaques, o botão da vitrine da home ("Ver todas as lojas") e um item
  "Lojas" na posição 3 do menu principal. A página foi renomeada de
  "Store List" para "Lojas parceiras".
- `provision.sh` acompanha: o item de menu entra no bloco do menu
  principal e o renome da página só acontece enquanto o título ainda for
  o padrão do Dokan.

**Decisões técnicas**
- **Reaproveitar `/store-listing/` em vez de criar uma página nova.** Ela
  já é o destino do breadcrumb do Dokan em cada loja (`Rewrites.php:58`) e
  do link do rodapé; uma página paralela criaria dois endereços para a
  mesma lista, com o breadcrumb apontando para a errada.
- **Substituir a saída do shortcode, e não o `post_content` da página.**
  `dokan_is_store_listing()` identifica a página pelo ID **ou** pela
  presença de `[dokan-stores` no conteúdo, e dela dependem o breadcrumb, a
  classe do `<body>` e o item da barra de administração. Mantendo o
  conteúdo intacto, nada disso precisa ser reimplementado, e instalações
  já existentes ganham a vitrine sem migração de dados.
- **`remove_accents()` nos dois lados da busca.** Quem digita "raizes" no
  celular, sem parar para achar o til, está procurando o "Ateliê Raízes" —
  e um resultado vazio aí é lido como "a loja não existe".
- **Corte do termo em 80 caracteres**, não por segurança
  (`sanitize_text_field()` já resolveu isso), mas porque a chave do
  transient de `reconectar_obter_lojas()` é o `md5` dos argumentos: sem
  teto, cada termo absurdo colado na URL cria a sua própria entrada no
  cache.
- **O campo de busca não tem autocomplete**, ao contrário do cabeçalho. O
  do cabeçalho tem endpoint REST próprio e cobre o site inteiro; aqui a
  busca é mais um filtro da vitrine, e se comporta como os outros — fica
  na URL, é compartilhável e o botão "voltar" a desfaz.
- **A home não expande mais a lista.** O botão leva à listagem completa:
  a home volta a ser descoberta, e a busca por nome ganha o lugar onde
  cabe.

**Regra de CSS removida**
- Saiu de `marketplace.css` a seção "Barra de filtros do Dokan
  (/store-listing/)", com `#dokan-store-listing-filter-wrap .right
  { flex-wrap: wrap; row-gap: … }`. Aquele markup não é mais renderizado
  em lugar nenhum. O defeito que ela corrigia fica registrado aqui: o
  `.right` é um flex container que o plugin nunca declara como quebrável,
  e `nowrap` é o valor inicial; em 320px o botão "Filter" (98px) e o
  formulário de ordenação (184px) somavam 282px num espaço interno de
  233px, e a borda direita do `<select>` ficava fora da tela. A correção
  entrava pela cascata — `marketplace.css` sai na prioridade 40, depois do
  CSS do plugin, então especificidade idêntica vence sem `!important`. Sem
  media query de propósito: o ponto de ruptura depende do comprimento do
  rótulo, e "Ordenar por:" é bem mais largo que "Sort by:".
- `.rc-busca-loja` foi renomeado para `.rc-busca-filtro`: o mesmo campo
  com a mesma lupa serve à busca no catálogo de uma loja e à busca por
  nome na vitrine. O que varia é só a largura, resolvida pelo ancestral
  (`.rc-loja__controles` e `.rc-vitrine`).

**Verificação**
- Lint de cada arquivo PHP tocado no contêiner; `bash -n` no
  `provision.sh`.
- `/store-listing/`: `200`, `<h1>` "Lojas parceiras" sem `<h2>`
  duplicado, `.rc-vitrine` presente, nenhum `.dokan-single-seller`, e o
  `<body>` sem `right-sidebar`.
- Filtros por URL, contra as 5 lojas da carga: sem filtro 5 cards,
  `?busca=raizes` 1 ("Ateliê Raízes"), `?busca=zzz` 0 com a mensagem
  "Nenhuma loja com “zzz” no nome.", `?cidade=Maceió` 2,
  `?entrega=gratis` 2, `?categoria=alimentos-e-bebidas` 1. Combinando
  `?cidade=Maceió&busca=raizes` os dois sobrevivem.
- Os três caminhos até a página chegam em `/store-listing/`, e o
  breadcrumb de `/store/demo-sabor-da-terra/` continua apontando para lá.
- Página de loja revisitada por causa do renome: campo com recuo de 44px
  e `?product_name=mel` ainda devolvendo 1 produto.
- Mobile 375px: sem estouro horizontal no documento; campo de 343px com
  47,9px de altura e lupa de 40×40; a barra de pílulas rola dentro de si
  mesma, como já fazia.
- `./scripts/verificar-acessos.sh` — 38 casos, nenhuma falha.

---

## 2026-09-25 — Escala tipográfica

**O problema, medido**

A queixa era de fonte desproporcional ao tamanho dos elementos. A base não
tinha culpa: `html` e `body` estão em 16px, e a hipótese de um
`html { font-size: 62.5% }` herdado do Storefront foi testada e refutada —
`1rem` vale 16px aqui.

O que havia era ausência de sistema. O `marketplace.css` trazia **38
declarações de `font-size` em 16 valores avulsos** (0.65, 0.7, 0.75, 0.78, 0.8,
0.8125, 0.82, 0.85, 0.87, 0.9, 0.95, 1, 1.25, 1.35, 1.5 e 2rem), cada um
escolhido no momento em que o componente foi escrito. O resultado, medido em
1280×900 antes da mudança:

| Elemento | Caixa | Fonte |
| --- | --- | --- |
| `.rc-card-loja` | 283 × 114 | nome 15,2px, meta 12,8px |
| `.rc-produto-linha` | 576 × 122 | nome 15,2px, resumo 13,12px, **selo 10,4px** |
| `.rc-pilula` | 205 × **37** | 13,12px |
| `.rc-vitrine__titulo` | linha de 1168px | 20px |

Cards e linhas dimensionados com folga, texto uma ou duas medidas abaixo do que
o espaço pedia. O caso extremo, `0.65rem` no selo da linha de produto, dava
10,4px — abaixo de qualquer mínimo de legibilidade.

**A escala**

Nove degraus de razão 1,125 sobre 16px, declarados no `:root` de
`marketplace.css`, ao lado dos demais `--rc-*`:

| Token | Valor | Papel |
| --- | --- | --- |
| `--rc-fonte-2xs` | 12px | selo, badge |
| `--rc-fonte-xs` | 13px | rótulo auxiliar |
| `--rc-fonte-sm` | 14px | meta, apoio |
| `--rc-fonte-base` | 16px | corpo, nome de item |
| `--rc-fonte-md` | 18px | destaque |
| `--rc-fonte-lg` | 20px | subtítulo |
| `--rc-fonte-xl` | 24px | título de seção |
| `--rc-fonte-2xl` | 28px | — |
| `--rc-fonte-3xl` | 36px | título de página |

Mais `--rc-entrelinha-justa: 1.25` e `--rc-entrelinha-compacta: 1.4`. Elas
existem porque o `marketplace.css` tinha **um único `line-height` em 1839
linhas**: todo o resto herdava 1,618 do Storefront, valor certo para parágrafo e
frouxo para rótulo de card — 12,8px de texto ocupando 20,7px de linha.

Por que 0.65rem virou 0.75rem, e não 0.7: o piso da escala é o menor tamanho que
este projeto assume como legível, e um degrau que existe só para acomodar uma
escolha antiga não é um degrau, é a mesma bagunça com nome novo.

**Decisões de alcance**

- A base do `html` **não** sobe. Carrinho, checkout e painel do vendedor são
  Woo e Dokan; mexer na raiz mudaria telas que não são nossas. Medidos depois da
  mudança: `html`/`body` em 16px e `h1` em 41,89px, iguais ao diagnóstico.
- O painel de empresas consome os degraus **com fallback literal**
  (`var( --rc-fonte-sm, 0.875rem )`), pelo mesmo motivo da paleta: o plugin não
  pode exigir o tema. Os literais de reserva são os da escala, não os que o
  arquivo tinha — 0,9375rem e 0,8125rem eram medidas avulsas.
- `class-reconectar-aviso-demo.php` fica com o seu `0.875rem` inline: é CSS de
  um aviso que precisa funcionar antes de qualquer folha carregar.

**O que teve de acompanhar a fonte**

- **`.rc-pilula`** — o recuo vertical existe pelo alvo de toque, não por
  respiro: com 13,12px e 7px de recuo a pílula media 37px, abaixo dos 44px da
  WCAG 2.5.5. Com 14px, 10px de recuo fecham a conta — medida hoje em 45px.
- **Iniciais de avatar** — desviaram do que o plano previa. A tabela mandava
  `--rc-fonte-lg` (20px), o que **reduziria** `.rc-card-categoria__inicial` (24px
  hoje) e `.rc-loja__inicial` (32px), contradizendo a própria instrução de que as
  iniciais sobem junto para não boiarem no círculo. Ficaram em `xl` (24px) nos
  círculos de 56px e `3xl` (36px) no de 96px.
- **`.reconectar-hero__titulo`** — 36px é a medida certa para a linha de 1168px
  e vira um bloco de cinco linhas (219px de altura) nos 375px de um celular.
  Passou a `clamp(var(--rc-fonte-2xl), 5vw, var(--rc-fonte-3xl))`, pelo mesmo
  motivo que `.rc-loja__nome` já usava `clamp()`: 36px no desktop, 28px e 136px
  de altura no celular.

**Verificação**

- `marketplace.css` servido por HTTP: 217 chaves abrindo e 217 fechando, iguais
  às de antes, e **zero** `font-size` com valor literal fora do `:root`. O mesmo
  no `painel-empresas.css`.
- Medição em 1280×900 contra a tabela de origem: selo 12px (era 10,4), meta de
  card 14px (era 12,8), nome de card 16px (era 15,2), título de seção 24px (era
  20), `.rc-loja__nome` 36px no desktop e 28px em 375px pelo `clamp`.
- 375px na home, em `/store-listing/` e em `/store/demo-sabor-da-terra/`:
  `body.scrollWidth - clientWidth === 0` nas três. O estouro de 413px que o
  `documentElement` acusa na home é dos carrosséis, que rolam dentro de si
  mesmos — a página não rola na horizontal (`body { overflow-x: hidden }`).
- Painel de empresas autenticado, nas três telas (lista, detalhe, formulário):
  trilha e sessão 14px, título 28px, seção 24px, selo 14px, ajuda 16px.
- `./scripts/verificar-acessos.sh` — 38 casos, nenhuma falha.

**Achados que não entraram**

- **A tabela do painel de empresas estoura em celular, e já estourava.** Em
  375px ela mede 485px; com os valores anteriores à escala, media 478px. A
  coluna "Situação" fica fora da tela, e o `overflow-x: hidden` do `body`
  esconde o excedente em vez de dar rolagem. A escala contribuiu com 7px de um
  defeito de 103px, e a correção — rolagem própria ou empilhamento em cards —
  é decisão de layout, não de tipografia.
- **O `-webkit-line-clamp: 2` de `.rc-produto-linha__resumo` não foi
  exercitado.** Os oito resumos da carga cabem em uma linha, nenhum trunca.
  Nada a concluir sobre o corte em 14px com estes dados.
- **`.rc-card-loja__inicial` e `.rc-loja__inicial` não foram medidos no
  navegador**: todas as lojas da carga têm logo, e a inicial só aparece sem ela.

---

## 2026-09-25 — Cor de texto em #717171

**O pedido e o que ele alcança**

Aplicar `#717171` nas fontes do projeto. O texto na tela vinha de cinco
origens, e três eram do Storefront, não do código do projeto:

| Origem | Valor anterior | Alcance |
| --- | --- | --- |
| `storefront_text_color` | `#6d6d6d` | `body`, todo o Woo e o Dokan |
| `storefront_footer_text_color` | `#6d6d6d` | `.site-footer` |
| `storefront_heading_color` | `#333333` | `h1`–`h6` |
| `--rc-texto` | `#27272a` | 20 usos no `marketplace.css` |
| `--rc-texto-suave` | `#71717a` | 21 usos no `marketplace.css` |

Duas medições mudaram o desenho da entrega. A primeira: `--rc-texto-suave` já
era `#71717a`, visualmente idêntico ao pedido — a mudança real estava em
`--rc-texto` e no corpo do Storefront. A segunda: **`#767676` é o cinza mais
claro que ainda dá 4,5:1 sobre branco**, e `#717171` está a cinco pontos desse
teto. Não existe um "suave" mais claro que este e ainda conforme, então os dois
tokens convergiram por obrigação matemática, não por descuido. A hierarquia
entre texto principal e texto de apoio passou inteira para tamanho e peso — que
a escala `--rc-fonte-*` da entrega anterior acabara de estabelecer.

**O gancho do Storefront**

`get_storefront_default_setting_values()` devolve
`apply_filters( 'storefront_setting_default_values', $args )`. Filtrar ali vale
porque `theme_mods_reconectar` **não tem nenhuma cor salva**: o filtro dos
padrões não disputa com valor de Customizer. E o tema filho carrega antes do
pai, então o `add_filter` de `inc/setup.php` chega bem antes do `init` em que o
Storefront registra os `theme_mod_*`. Foi assim que a cor alcançou carrinho,
checkout, minha conta e painel do vendedor sem uma linha de CSS nova.

**Os dois casos que não podiam clarear**

- **Os selos.** `#717171` sobre `--reconectar-cor-destaque` (`#F1BF3D`) mede
  2,85:1. `.rc-card-produto__selo` e `.rc-produto-linha__selo` passaram a um
  token próprio, `--rc-texto-sobre-destaque: #27272a` — medido no navegador em
  `rgb(39, 39, 42)` sobre `rgb(241, 191, 61)`, 11,0:1. São os **únicos** dois
  entre as 41 declarações que não estão sobre branco ou sobre `--rc-fundo`.
- **O rodapé.** O fundo nativo do Storefront é `#f0f0f0`, e `#717171` sobre ele
  dá 4,28:1 — reprova. `storefront_footer_background_color` passou a `#f7f7f8`,
  que é o `--rc-fundo` e o mesmo fundo do rodapé autoral: 4,56:1.

Ficaram de fora `storefront_heading_color` (títulos seguem em `#333333`, como
decidido), `storefront_header_text_color` (vale para a barra de rodapé handheld,
que tem fundo próprio não medido) e `storefront_button_text_color` (`#333333`
sobre `#eeeeee` já está em 4,21:1; clarear pioraria).

**Verificação**

- `marketplace.css`: `#27272a` aparece **uma vez** (só o token do selo) e
  `#71717a`, **nenhuma**.
- CSS inline do Storefront em `/cart/`: 14 ocorrências de `#717171` e 30 de
  `#333333`, nos títulos.
- Varredura de contraste pelo DOM em `/`, `/store-listing/`,
  `/store/demo-sabor-da-terra/`, `/my-account/` e `/cart/`: **nenhum par
  reprovado envolve `rgb(113, 113, 113)`**. O que reprova são três coisas
  anteriores a esta entrega — os links em `#31BEB1`, as estrelas de nota e os
  separadores `·`.

**Os quatro `#f0f0f0` que sobraram e por que não foram tocados**

O CSS inline do Storefront ainda serve `#f0f0f0` em quatro lugares. Nenhum é
texto que reprove, porque **nenhum dos quatro existe no DOM desta instalação**:
`.main-navigation ul.nav-menu ul.children` e `.site-header-cart
.widget_shopping_cart` pertencem ao cabeçalho do tema pai, que o cabeçalho
autoral substituiu; `#payment .payment_methods > li:hover` pertence ao checkout
por shortcode, e este checkout é o de blocos. Conferido elemento a elemento no
navegador antes de decidir não mexer: uma correção ali seria CSS para seletor
morto.

**Achado preexistente, não corrigido**

`a { color: var(--reconectar-cor-primaria) }` (`style.css:57`) dá **2,30:1**
sobre branco. Os links do site reprovam AA hoje, e reprovavam antes desta
entrega — o varredor os encontra em todas as páginas. Não foi corrigido aqui
porque mudar a cor de link é decisão de identidade visual, não de contraste
isolado, e o edital exige WCAG 2.1: vale uma decisão em separado.

---

## 2026-09-25 — Tela de acesso e SSO

**O que havia**

`/my-account/` usava o `form-login.php` nativo do WooCommerce, sem override no
tema: duas colunas lado a lado, "Login" e "Register", com o checkbox "I am a
vendor" do Dokan injetado no meio e sem tradução. Nenhum plugin de SSO
instalado.

**O que passou a haver**

Override em `woocommerce/myaccount/form-login.php`, espelhando o protótipo:
coluna de arte à esquerda com o gradiente institucional → primária (o mesmo de
`.reconectar-hero`, reaproveitado em vez de pedir arte nova) e painel à direita
com os botões de SSO no topo, separador, e os dois formulários sob gatilhos.

**Os dois formulários nativos são preservados inteiros**, com todos os
`do_action` do WooCommerce no lugar — `woocommerce_login_form_start`,
`woocommerce_login_form`, `woocommerce_login_form_end` e os três de registro.
Remover qualquer um quebraria o Dokan e o próprio Nextend, que se penduram
neles.

Os botões saem dos shortcodes do plugin (`[nextend_social_login
provider="facebook"]` e `provider="google"`), e não da API PHP interna, porque
shortcode muda menos entre versões. **Sem credenciais configuradas o shortcode
devolve string vazia** — confirmado no ambiente — e o `trim()` no template faz o
separador "ou" desaparecer junto. É essa degradação que deixa a tela funcional
antes de existirem os apps OAuth.

**Progressive enhancement, como no resto do tema**

Os dois gatilhos nascem com `hidden` no HTML e `aria-expanded="true"`; é o
`login.js` que os revela e fecha os blocos correspondentes. Sem script a tela é
linear — formulários abertos, nada a clicar antes de digitar — e **nunca exibe
um botão que não abriria coisa alguma**. Conferido no HTML servido: os dois
`<button>` chegam com `hidden`, os dois `<div class="rc-login__bloco">` chegam
sem, e os dois `submit` estão lá.

O script também respeita os avisos do WooCommerce: se a página trouxe
`.woocommerce-error`, `.woocommerce-message` ou `.woocommerce-info`, ele sai sem
colapsar nada — um erro de login dentro de um bloco fechado seria um erro
invisível.

**A regressão de acessibilidade que a própria entrega criou**

`storefront_page_header()` imprime `<h1 class="entry-title">` com o título do
post — "My account", em inglês, vindo do WooCommerce. Com o painel novo a tela
passou a ter **dois `<h1>`**, o segundo sendo o "Acessar a plataforma" que é o
título verdadeiro do documento. Quem navega por cabeçalhos ouviria os dois e
teria de decidir qual nomeia a página. `reconectar_remover_titulo_da_tela_de_acesso()`
em `inc/layout.php` remove o do tema pai, e só para quem ainda não entrou —
`is_account_page()` responde verdadeiro também no painel do cliente, onde
"Minha conta" é o único título e precisa continuar de pé.

**Verificação**

- **1280×900:** sem estouro horizontal, coluna de arte visível em 568px, painel
  em 480px, **um** `<h1>`.
- **375px:** sem estouro, arte em `display: none`, os dois gatilhos com 46px de
  altura (acima dos 44px da WCAG 2.5.5), `aria-expanded="false"` e blocos
  `hidden` no carregamento.
- **Login ponta a ponta por `curl` com cookie**, nunca digitando senha no
  navegador: POST em `/my-account/` com o nonce → 302 para `/my-account/`,
  sessão autenticada, painel renderizado.
- **Interação:** abrir alterna `aria-expanded` para `"true"`, revela o bloco e
  move o foco ao primeiro campo — abrir sem mover o foco obrigaria quem navega
  por teclado a percorrer de novo o caminho até lá.
- **Teclado:** a ordem de Tab acompanha a ordem visual (gatilho "E-mail e
  senha" → campos → gatilho "Criar conta"), e o foco é visível — contorno de
  3px sólido em `#663191` com 2px de afastamento, medido com `:focus-visible`
  ativo por Tab real.

**O que a automação não conseguiu provar**

A ativação por Enter. O automatizador entrega o `keydown` ao elemento, mas o
navegador não sintetiza dali o `click` — o espião registrou `keydown: 1` e
`click: 0`. Como o gatilho é um `<button type="button">` e o listener é de
`click`, essa ativação é a nativa do elemento, garantida pelo navegador. Mesma
limitação do clique por coordenada, que também não dispara o handler enquanto um
`click()` em JavaScript dispara.

**As credenciais OAuth são do usuário**

Client ID e Client Secret do Google e do Facebook precisam ser criados no Google
Cloud Console e no Meta for Developers e colados na tela do Nextend, no
`wp-admin`. Não entram no repositório, não vão para o `.env` e não foram
digitados aqui. Enquanto não existirem, a tela funciona com e-mail e senha e os
botões simplesmente não aparecem.

---

## 2026-09-25 — Só o administrador cadastra lojas

**A falha, medida antes**

`curl` em `/my-account/` deslogado devolvia os campos `role`, `shopname`,
`shopurl` e `phone`. Qualquer visitante abria uma loja.

**A descoberta que desmonta a solução óbvia**

A opção `show_register_as_vendor` do Dokan **não fecha o cadastro de vendedor**.
São quatro portas independentes e ela alcança uma — e mesmo essa pela metade:

| Via | Onde | A opção fecha? |
| --- | --- | --- |
| Checkbox "I am a vendor" | `/my-account/` | sim, só o checkbox |
| `[dokan-vendor-onboarding-registration]` | `/vendor-onboarding/`, publicada | **não** |
| `[dokan-vendor-registration]` | qualquer página | **não** |
| "Become a vendor" | `/my-account/` logado | **não existe opção** |

As duas do meio escapam porque `Registration::get_allowed_registration_roles()`
reabre `seller` assim que `is_vendor_form_request()` reconhece o nonce do
formulário dedicado. A quarta é outro caminho inteiro:
`BecomeAVendor::become_a_seller_form_handler()` chama
`dokan_user_update_to_seller()` direto, sem consultar opção nenhuma.

**Pior: desligar a opção sozinha piora a tela.** Ela remove o checkbox mas
mantém `dokan_seller_reg_form_fields()` pendurada em `woocommerce_register_form`
— e era o JavaScript do checkbox que ocultava aqueles campos. Desligada a opção
e nada mais, "Nome da loja", "URL" e "Telefone" passam a aparecer **sempre**, em
todo cadastro de cliente, e com `required`.

**A trava**

`Reconectar_Cadastro_De_Lojas`, em cinco camadas para quatro portas — a última
não fecha porta nenhuma; existe para o dia em que uma atualização do Dokan abrir
a quinta:

1. `dokan_register_user_role` devolvendo `array( 'customer' )`. É o gancho que
   `get_allowed_registration_roles()` aplica por último, então fecha as três
   vias de formulário de uma vez, inclusive quando o nonce reabriria `seller`.
2. `remove_shortcode()` nos três shortcodes, em `init` prioridade 20, mais o
   `remove_action` de `dokan_seller_reg_form_fields`. Sem isso a página de
   onboarding exibiria um formulário que o servidor recusa — pior experiência
   que não ter formulário, e pior indício de segurança.
3. `remove_action` nos três hooks de `BecomeAVendor`, na instância real do
   contêiner.
4. Rede de segurança em `set_user_role` e `add_user_role`: papel `seller`
   atribuído por quem não pode `CAP_GERIR_VENDEDORES` é revertido.
5. `show_register_as_vendor = off` e `/vendor-onboarding/` em `draft`, no
   `provision.sh` — não são a trava, são para a tela não oferecer o que o
   servidor recusa.

`class-reconectar-vendedores.php` **não mudou uma linha**: ele já era o caminho
autorizado, já valida capacidade e já fixa o papel em constante. Esta entrega só
removeu os atalhos que o contornavam.

**Os dois defeitos que a execução encontrou e a leitura não encontraria**

- **Remover `dokan_seller_reg_form_fields()` levava junto o `<input
  type="hidden" name="role">`**, e `Registration::validate_registration()`
  recusa com "Cheating, eh?" todo registro que chegue sem `role` preenchido — o
  cadastro de cliente comum, que esta entrega precisa manter aberto, quebrava
  inteiro. Daí `campo_papel_cliente()`, que repõe o campo no mesmo hook com o
  único valor que o servidor aceita. O método foi renomeado de
  `remover_formularios()` para `ajustar_formularios()` para o nome não mentir.
- **`isset()` em propriedade mágica sem `__isset()` responde sempre `false`.**
  O trait `ChainableContainer` do Dokan declara `__get()` e não `__isset()`,
  então `isset( $frontend->become_a_vendor )` é falso mesmo com o controlador
  presente — e a primeira versão de `remover_virar_vendedor()` saía por ali, em
  silêncio, deixando os três hooks no ar. A guarda certa é `is_object()` sobre
  o resultado de `__get()`. Depurado com `spl_object_id`, que mostrou a
  instância correta no contêiner e o mesmo objeto no callback registrado.

**Verificação**

Quatro casos novos no `verificar-acessos.sh`, e para eles uma função nova,
`conferir_corpo()`: os casos de cadastro não cabem em `conferir`, porque o
servidor devolve 200 e o formulário certo — o que se verifica é que um campo
específico não está mais lá. Um 200 sozinho não distingue "o cadastro de cliente
funciona" de "o formulário de vendedor continua no ar".

- `/vendor-onboarding/` → 404.
- `/my-account/` sem `name="shopname"`.
- `/my-account/` **com** `name="role"` — é este caso que teria pego o defeito
  acima antes de ele chegar ao usuário.
- POST forjado com `role=seller` e nonce de registro válido → nenhum usuário
  criado. A limpeza vai dentro do mesmo caso, e não num passo seguinte: se a
  trava falhar, o vendedor forjado não pode sobreviver à verificação que o
  criou.

Fora do script, no ambiente: `wp eval` confirma os três shortcodes "removido",
`dokan_seller_reg_form_fields` "removido", `dokan_register_user_role`
devolvendo só `customer` e os três hooks de `BecomeAVendor` "removido".
Cadastro de cliente comum por HTTP criou usuário com `customer,
bbp_participant`. A contagem de `seller` permaneceu em 5 depois do POST forjado.
A camada 4 foi exercitada por HTTP com um mu-plugin temporário — necessário
porque em WP-CLI `autorizado()` retorna `true` de imediato —, e a promoção foi
revertida: `papeis apos add_role(seller): customer,bbp_participant`. O
mu-plugin foi apagado em seguida.

`./scripts/verificar-acessos.sh`: **42 casos, nenhuma falha** (eram 38).

**Idempotência**

`./scripts/demo-completa.sh` duas vezes. A segunda execução imprimiu
"Autocadastro de vendedor já desligado" e "Página '/vendor-onboarding/' já não
está publicada", e as duas saídas diferem apenas no nome efêmero do container e
na ordem de intercalação entre stdout e stderr dos avisos de tradução do WP-CLI.
Nenhuma criação nova.

---

## 2026-09-25 — Fórum de perguntas e respostas

O protótipo é um Q&A, não um fórum clássico. bbPress 2.6.18 e BuddyPress 14.5.2
já estavam ativos desde o provisionamento — e fora do mapa do `CLAUDE.md`. O
bbPress entrega pergunta, resposta, categoria, tags e contagem de respostas de
graça; votos, visualizações, melhor resposta e badge de papel são a camada
autoral desta entrega.

**A camada de autorização que faltava**

"Só vendedores e administradores acessam o fórum" já existia em
`bloquear_comunidade()`, mas só para **leitura**. O bbPress processa o POST de
criação em `bbp_template_redirect`, pendurado em `template_redirect` com
prioridade **8** (`bbpress/includes/core/actions.php:50`) — antes da prioridade
10 do gate. E o handler consulta apenas a capacidade primitiva. Medido antes:
`demo-cliente-ana` respondia **sim** a `publish_topics` e `publish_replies`,
porque todo usuário recebe `bbp_participant` no registro.

Não era brecha demonstrada — o nonce barrava e o cliente não via o formulário —,
mas era camada ausente, do mesmo tipo que a entrega anterior fechou em cinco
frentes. `Reconectar_Permissoes::negar_escrita_no_forum()`, em `map_meta_cap`,
devolve `do_not_allow` para as sete capacidades de escrita quando falta
`CAP_COMUNIDADE`. `spectate` e `read_forum` continuam livres de propósito.

**O defeito que só a execução encontrou: `admin-post.php` mora em `/wp-admin`**

O caso novo do `verificar-acessos.sh` esperava 403 do endpoint de voto e veio
**302**. `bloquear_area_administrativa()` abria exceção só para
`wp_doing_ajax()`, e `admin-post.php` — a rota padrão de `<form method="post">`
de front-end, para onde o voto e a marcação de melhor resposta postam — estava
atrás do portão.

Medido: cliente 302 → `/my-account/`, vendedor 302 → `/dashboard/`. **O voto
nunca funcionou para nenhum perfil não-admin.** Sem erro na tela: o
redirecionamento devolve o painel do próprio usuário, que é uma página
plausível. E o 302 do cliente parecia prova de que a trava funcionava, quando
era o contrário — ninguém alcançava o endpoint, nem quem devia.

Liberar não abre o painel: `admin-post.php` só executa o que estiver em
`admin_post_*` e morre com 400 quando a ação não existe. As travas do voto
seguem sendo o nonce e a capacidade, dentro do handler.

Por isso o caso do script passou a testar **os dois perfis**, e não só o lado da
negação: testar só o cliente teria deixado o voto quebrado com o script verde.

**A ordenação por votos: duas rotas óbvias, as duas erradas**

1. `meta_key => '_reconectar_votos'` com `orderby => 'meta_value'` monta um
   **INNER JOIN** e todo tópico sem a meta some da aba. Lista incompleta, não
   lista com erro — continua plausível.
2. A correção intuitiva, `meta_query` com `relation => 'OR'` e ramo
   `NOT EXISTS`, traz todos de volta e **estraga a ordem**: com relação OR o
   `WP_Meta_Query` tira a condição do `ON` e a joga no `WHERE`, o join passa a
   casar todas as metas do post e o `GROUP BY` escolhe um `meta_value` qualquer
   para ordenar. Medido nesta instalação: um tópico com saldo 7 ficou **atrás**
   de um sem voto nenhum.

A saída foi `posts_clauses` com `LEFT JOIN` próprio — condição no `ON`, no
máximo uma linha por post — e `COALESCE( …, 0 )`, que trata "sem voto" como
zero em vez de `NULL`, que o MySQL joga para o fim em `DESC` e para o começo em
`ASC`.

**Quatro armadilhas menores, todas encontradas rodando**

- `bbp_new_topic` e `bbp_new_reply` só disparam pelo formulário do frontend
  (`topics/functions.php:387`), não dentro de `bbp_insert_topic()`. Criação
  programática não os aciona, e a carga grava as metas à mão.
- `bbp_update_reply_walker()` sobe a árvore mas só refaz as contagens quando
  `current_filter()` é `bbp_deleted_reply` ou `save_post`. Em WP-CLI não é
  nenhum dos dois, e `_bbp_reply_count` ficaria em zero com respostas na tela —
  daí `reconectar_demo_recontar_forum()`.
- `wp_delete_term()` com a taxonomia errada devolve `false` **em silêncio**.
  Desde o fórum a carga cria termos em duas taxonomias, então a remoção busca a
  taxonomia no banco em vez de assumir `product_cat`. O sintoma seria uma coluna
  de tags que sobrevive à remoção e leva a listas vazias.
- O item de menu do fórum é `custom`, e não `post_type`: a listagem é o
  **arquivo** de `forum`, que não tem post a que apontar.
  `ocultar_itens_da_comunidade()` filtrava só por `$item->object`, então o
  cliente veria no menu um link que devolve 403 — exatamente o defeito que o
  filtro existe para evitar. Passou a comparar também o caminho da URL, como o
  widget do rodapé, para sobreviver a alguém renomear o item pelo painel.

**Remoção**

`reconectar_demo_remover()` lista post types explicitamente, então respostas,
perguntas e categorias precisaram entrar na lista — de baixo para cima. O filtro
é `RECONECTAR_DEMO_META`, nunca o tipo de post sozinho: um `get_posts()` por
`post_type => 'topic'` sem a meta devolveria o fórum inteiro da instalação, a
forma bbPress do mesmo defeito que o `wc_get_orders()` produziu nos pedidos.

Uma condição a mais nas categorias: o bbPress apaga em cascata o que estiver
dentro de um fórum, então uma pergunta feita durante a apresentação sairia junto
sem nunca ter recebido a marcação. A categoria fica de pé — preservar um
agrupador vazio de dado fictício custa uma linha; apagar a pergunta de outra
pessoa não tem desfazer.

**O que foi verificado**

- `./scripts/verificar-acessos.sh`: **54 casos, nenhuma falha** (eram 42). Onze
  acréscimos: `/forums/` por vendedor, o POST de voto sem nonce pelos dois
  perfis, e nove de escrita — `publish_topics`, `publish_replies` e
  `assign_topic_tags` negados ao cliente e concedidos ao vendedor, `read_forum`
  preservado ao cliente, `Reconectar_Forum::votar()` devolvendo `WP_Error` para
  quem não participa, e o autor recusado no próprio conteúdo.
- Voto ponta a ponta por HTTP, **sem JavaScript**, com nonce real extraído do
  formulário: saldo 1 → 2, `{"24":1}` → `{"24":1,"23":1}`, redirect de volta ao
  tópico. O voto foi desfeito em seguida e o estado da demonstração restaurado.
- As três abas comparadas contra contagem direta no banco; o ramo sem meta
  provado com um tópico criado à mão, fora do hook.
- Voto duplo não soma dois; trocar de lado troca; desfazer volta ao anterior.
- Provisionamento rodado duas vezes: um único item "Fórum" no menu. O reparo
  retroativo existe porque uma instalação anterior já tem menu atribuído a
  `primary` e cairia no "pulando" — ficaria para sempre sem o link da tela nova.
- Carga rodada duas vezes, "já existia" em todas as linhas.
- Contraste calculado sobre os **19 pares** que o bloco do fórum declara:
  mínimo **4,56:1**, nenhum reprova AA. O 6,48:1 do botão "Aplicar" confirma a
  decisão da entrega da cor — texto escuro sobre a tinta da identidade, nunca
  branco (branco sobre a primária dá 2,30:1).

**O que não foi verificado, e por quê**

Duas ações foram bloqueadas pelo classificador de permissões: servir uma cópia
estática da tela em `wp-content/uploads/` e ler os cookies do jar de sessão para
abrir `/forums/` autenticado no navegador. **A tela nunca foi vista
renderizada.** Os dois itens do plano que dependiam do DOM ficaram assim:

- o contraste foi calculado sobre os valores declarados no CSS, o que cobre
  todos os pares do bloco mas não detecta uma sobrescrita por folha de terceiros;
- o estouro horizontal foi tratado preventivamente — `minmax(0, 1fr)` nas
  faixas, `min-width: 0` em seis pontos e `overflow-wrap: anywhere` nos cinco
  seletores de texto livre — mas **não medido** com
  `scrollWidth - clientWidth === 0` em 1280×900 e 375px.

A remoção da demonstração também foi bloqueada. O critério de seleção foi
provado por consulta, sem apagar: 6 respostas, 6 perguntas e 4 das 5 categorias
selecionadas — o "Fórum Geral" do provisionamento (ID 15) fica de fora, por não
ter a meta —, e as 13 tags do fórum todas marcadas. O ciclo em si não rodou.

---

## 2026-09-25 — Salvar pergunta não redirecionava

Reportado pelo uso real, logo depois da entrega do fórum: ao salvar uma
pergunta, a tela trazia um aviso de depreciação do BuddyPress e duas warnings
de "headers already sent" em `pluggable.php:1539` e `:1542`.

As duas warnings são o diagnóstico inteiro. Elas são os `header()` de
`wp_redirect()` falhando — ou seja, **o redirect não acontecia**. A pergunta era
gravada normalmente e o usuário ficava numa tela de erros, sem nunca chegar ao
tópico que acabara de criar. Um teste que conferisse só o banco teria passado.

**A causa.** `BBP_BuddyPress_Members::get_profile_url()`
(`bbpress/includes/extend/buddypress/members.php:232`) testa
`function_exists( 'bp_core_get_user_domain' )` antes de
`bp_members_get_user_url()`. A função foi removida da API do BuddyPress na
12.0, mas continua existindo como casca depreciada na 14.5.2 que está instalada
— então o teste passa, o ramo antigo roda, `_deprecated_function()` imprime, e a
saída precede o redirect. O comentário do próprio bbPress diz que o código
obsoleto está ali de propósito; o que ele não previu foi a ordem dos testes
envelhecer.

Confirmado sem depender do POST: um `deprecated_function_run` capturado em
`wp eval` mostrou `bp_core_get_user_domain` sendo acionada por
`bbp_get_user_profile_url()`.

**Duas correções, em camadas diferentes.**

`Reconectar_Forum::substituir_urls_de_perfil()` troca os seis filtros
`bbp_pre_get_user_*` por versões que usam o substituto oficial. Silenciar o
aviso com `deprecated_function_trigger_error` limparia a tela e deixaria a
chamada obsoleta de pé, para quebrar de novo quando a casca for removida. As
cinco URLs saem idênticas às de antes — conferidas uma a uma.

`provision.sh` fixa `WP_DEBUG_LOG=true` e `WP_DEBUG_DISPLAY=false`. Isto não é
redundante com a primeira: **qualquer** aviso impresso antes de um redirect
produz o mesmo defeito, e o próximo não virá do BuddyPress. O aviso continua
existindo, no `wp-content/debug.log`, e sai do corpo da resposta.

**A idempotência do bloco novo saiu errada na primeira tentativa**, e a segunda
execução do provisionamento foi quem mostrou: `wp config get` devolve o valor
**avaliado** (`1` para `true`, string vazia para `false`), não o literal que
`--raw` gravou. Comparar com o literal nunca casava e o bloco reescrevia as
constantes toda vez, anunciando "definida" para o que já estava lá. E como
constante inexistente também devolve vazio, `WP_DEBUG_DISPLAY` pareceria
configurada onde nunca fora escrita — daí o `wp config has` antes da comparação.

**O caso de teste que faltava.** `verificar-acessos.sh` cobria ler o fórum e
votar, mas não **criar** — e era exatamente ali que estava o defeito. O caso
novo faz o POST real de nova pergunta como vendedor e mede as duas coisas: o
302, que prova que o redirect aconteceu, e a ausência de saída de depuração no
corpo, que prova o porquê. Ele mesmo remove a pergunta que criou. São 57 casos
agora, contra 54.

Extrair o `_wpnonce` certo exigiu recortar o formulário `id="new-post"` antes
do `grep`: a busca do tema e a barra de administração imprimem campos com o
mesmo nome, e pegar o primeiro da página daria 200 com falha de segurança — um
caso vermelho que não seria o defeito medido.

**Verificado**

- `deprecated_function_run` capturado: nenhuma função obsoleta acionada depois
  da troca dos filtros; as cinco URLs de perfil inalteradas.
- Criação de pergunta por HTTP: **302**, corpo sem `Deprecated`, `Warning:` ou
  `headers already sent`.
- O mesmo caso **com `WP_DEBUG_DISPLAY` religado em `true`**, que é o cenário
  original do usuário: continua verde. É a prova de que a troca dos filtros
  resolve sozinha, e que a mudança de depuração é proteção, não muleta.
- Provisionamento rodado três vezes: "definida" na primeira, "já estava" nas
  seguintes.
- `./scripts/verificar-acessos.sh`: **57 casos, nenhuma falha.**
- O processo do Apache escreve em `wp-content/debug.log` (UID 33 confirmado), e
  o arquivo já cai no `*.log` do `.gitignore`.

---

## 2026-09-25 — Títulos de página, grade de produtos e paginação

O catálogo era a última área da plataforma servida pelo layout do Storefront sem
nenhuma camada autoral por cima, e as três partes do pedido tinham essa mesma
raiz. O medido em `/shop/` a 1280×900, antes: `<h1>` "Shop" de 50,84px repetindo
a trilha "Início / Shop" logo acima; `ul.products` em `display: block`, com
`li.product` flutuando; alturas de 469/513/469 na primeira linha, o botão
"Adicionar ao carrinho" parando num lugar diferente em cada card; **duas**
`.storefront-sorting`, com paginação impressa antes de o visitante ver um único
produto; itens de paginação de 28×32px, `aria-label="Page 1"` em inglês e a seta
"→" como nome acessível de link — falha do critério 2.4.4 da WCAG 2.1.

**Os títulos saem da tela e ficam no documento.** É a diferença entre este caso e
os dois que `inc/layout.php` já tratava: ali havia **dois** `<h1>` na página e um
precisava deixar de existir; aqui há **um só**, que é o nome do documento. Tirá-lo
do DOM deixaria quem navega por cabeçalhos sem saber onde está. Por isso
`reconectar_marcar_pagina_sem_titulo()` põe uma classe no `<body>` e o CSS aplica
a técnica de `.screen-reader-text` — e por isso **não** se usou o filtro
`woocommerce_show_page_title`, que seria o caminho óbvio para o catálogo e apaga
o `<h1>` de `loop/header.php` em vez de escondê-lo.

Vale registrar o que isso não corrige: "Shop", "Cart", "Checkout" e "My account"
continuam em inglês no `<title>`, nas trilhas e nos menus. Esconder o `<h1>` tira
o sintoma da primeira dobra e deixa a origem de pé — o título dos posts que o
WooCommerce cria. Fica para `scripts/i18n/`.

**A listagem do fórum era o outro caso**, o de dois `<h1>`: "Fóruns", do tema pai,
e "Todas as perguntas", da camada autoral. Sai o do tema pai. O gancho é
`storefront_page`, e não `storefront_archive`, embora o `<body>` receba
`post-type-archive-forum`: o bbPress serve a listagem pela camada de
compatibilidade de tema, que injeta o conteúdo num post falso (`post-0`). Medido
no DOM antes de escrever o `remove_action` — presumir custaria uma remoção que não
remove nada.

**A grade é CSS sobre o markup do WooCommerce**, sem template novo. Foi a decisão,
e ela preserva a avaliação em estrelas, os badges de plugins e o "Adicionar ao
carrinho" com AJAX. O que iguala as alturas é `flex: 1` no link que envolve
imagem, título e preço: o botão encosta no rodapé do card independentemente de o
título ter uma ou duas linhas.

**O clearfix do tema pai virou grid item.** Esta foi a surpresa. Com quatro
colunas de 280px declaradas e aplicadas, o primeiro produto nascia em
`left: 345px` e cada linha entregava três cartões. A célula 1 estava ocupada pelo
`::before` do tema pai: pseudo-elemento com `content` gera caixa, e caixa dentro
de grade é grid item. `content: none` apaga a caixa — `display: none` deixaria o
item na contagem.

**As duas barras perdem coisas diferentes.** De cima sai só a paginação, porque
ordenação e contagem dizem o que a lista logo abaixo contém e paginar antes de
existir lista, não. De baixo sai a barra inteira: ali elas eram repetição do que
já está no topo, e repetição custa mais a quem navega por teclado do que a quem
rola a página. A paginação passa de prioridade 30 para 40, para fora do
`storefront_sorting_wrapper_close`: dentro da barra ela herdava o `float: right`,
e era isso que a jogava contra a margem direita.

**A página atual da paginação é roxa, não verde.** `#31BEB1` com branco dá 2,0:1 —
a mesma cor que já reprova AA nos links do projeto. `#663191` com branco dá
8,70:1, medido antes de escrever a regra. Não existe `--rc-texto-sobre-primaria`
no `:root`, e a ausência é informação: o par de cores sobre o primário nunca
passou por medição.

**A especificidade foi medida, não presumida.** A regra concorrente mais forte é
`.storefront-full-width-content .site-main ul.products.columns-3 li.product`, de
(0,5,2). As classes repetidas no seletor levam a (0,6,2) — não é estilo, é a
medida necessária. `marketplace.css` é a folha 22 e `woocommerce.css` a 18, então
o empate bastaria; a folga é para o dia em que essa ordem mudar.

**Verificado**

- `<h1>` presente no DOM e com retângulo de 1×1 em `/shop/`, `/cart/`,
  `/checkout/`, `/my-account/` (logado), `/store-listing/` e numa categoria de
  produto. A home e o fórum seguem com o título visível.
- `/forums/`: exatamente um `<h1>`, "Todas as perguntas".
- Grade real: `display: grid`, quatro colunas de 280px, primeiro cartão alinhado
  à borda esquerda do contêiner (49px, igual à do `<ul>`).
- Alturas iguais dentro de cada linha: 348/348/348/348, depois 375×4 e 375×4.
- Uma `.storefront-sorting`, uma `.woocommerce-pagination`, uma ordenação e uma
  contagem na página.
- Paginação: itens de 44×44px medidos, `aria-current="page"` na atual, `<nav>`
  com rótulo "Paginação de produtos" e a seta com nome acessível "Próxima página"
  (a seta é `aria-hidden`).
- Contraste: página atual 8,70:1; selo "Oferta!" 8,70:1; título, preço e links de
  página 4,88:1. Nenhum par abaixo de 4,5:1.
- 1280×900 e 375×812: `scrollWidth - clientWidth === 0` e a varredura por
  elementos além da borda voltou vazia. Em 375px a grade vira coluna única de
  343px e os alvos de toque seguem em 44×44.
- "Adicionar ao carrinho" pelo botão do card: classe `added` aplicada, link "Ver
  carrinho" impresso e o produto no `/cart/`. É o que a decisão de reestilizar por
  CSS se propôs a preservar. O checkout não foi fechado: criar pedido na base de
  demonstração não prova nada que o carrinho já não tenha provado.
- `./scripts/verificar-acessos.sh`: **57 casos, nenhuma falha.**

---

## 2026-09-25 — Os selects

O pedido veio com o `select` de ordenação do catálogo selecionado na tela. O
levantamento encontrou **dois** `<select>` no site inteiro, e nenhum terceiro:

| Onde | Classe | Estado medido |
| --- | --- | --- |
| `/shop/` e categorias | `.orderby`, do WooCommerce | 288×24px, fundo `#efefef`, borda `#767676` — aparência nativa do sistema |
| `/forums/` | `.rc-forum__select`, autoral | 44px, tokens do projeto, seta nativa |

O do catálogo estava a 24px de altura onde o projeto adota 44px de alvo de
toque, e fora da tipografia da plataforma: o Storefront declara
`select { color: initial; font-family: "Source Sans Pro", … }`, que substitui a
Poppins herdada do corpo. Não era o select estar sem enfeite — era ele nunca ter
passado por nenhuma camada autoral.

A cascata foi medida antes de escrever. Ao contrário do laço de produtos, aqui
não há disputa: a regra mais específica que alcança o controle é
`button, input, optgroup, select, textarea`, de especificidade (0,0,1). Uma
classe basta, e nenhuma repetição de seletor foi necessária.

**O checkout ficou de fora, de propósito.** Aquela tela é bloco do WooCommerce
(`.wc-block-checkout`) e seus campos — `billing-country`, `billing-state` — já
medem 50px, usam 16px de fonte e trazem rótulo flutuante próprio. Sobrescrever
não corrigiria defeito nenhum; só desalinharia um sistema coerente consigo
mesmo. Foi decisão do usuário, e a medição a sustenta.

**A seta é desenhada pelo tema.** `appearance: none` apaga a seta junto com o
resto do controle nativo, e alguma precisa voltar — sem ela nada distingue o
campo de uma caixa de texto. Ela entra como `background-image` em `data:` URI,
não como pseudo-elemento: `select` não hospeda `::after` de forma confiável
entre navegadores, e o wrapper que o receberia é markup de terceiro nos dois
casos. Sem build e sem CDN, como o projeto exige.

O traço fica em `#717171` literal porque `url()` não interpola custom property.
É a única duplicação de token do arquivo, e está anotada no CSS: some no dia em
que a seta virar elemento.

### O campo de busca do fórum, que o select denunciou

Ao medir o select do fórum, o input ao lado apareceu com fundo `#f2f2f2` — cinza,
ao lado de um select branco, no mesmo formulário. A regra autoral declarava
`background: var(--rc-superficie)` e perdia na cascata: o Storefront pinta com
`input[type="text"], … input[type="search"], …`, de especificidade (0,1,1),
maior que a classe sozinha (0,1,0).

Defeito pré-existente, não regressão — mas ficou lado a lado com o select
corrigido, então foi corrigido junto. `.rc-forum__busca.rc-forum__busca` leva a
(0,2,0) e encerra a disputa sem depender da ordem das folhas. O campo ganhou
também o mesmo `:hover` e o mesmo `:focus-visible` do select, para os dois
controles do mesmo formulário não responderem ao teclado de formas diferentes.

O desenho do select saiu do bloco do fórum para uma seção própria, `Selects`,
que os dois compartilham. Antes eram dois blocos que por acaso coincidiam.

**Verificado**

- `/shop/`: de 288×24px para **320×44px**; `appearance: none`; fundo `#ffffff`;
  borda `#e4e4e7` (o token, não o `#767676` do sistema); raio 8px; **Poppins** no
  lugar de Source Sans Pro; `padding-right` de 40px reservado para a seta.
- `/forums/`: select em 243×44 e busca em 44 de altura, **ambos com fundo
  `rgb(255,255,255)`** — antes o segundo saía `rgb(242,242,242)`.
- Foco: `outline` de 2px em `rgb(49,190,177)` nos dois controles, sob
  `:focus-visible`, a mesma forma já usada na paginação.
- Contraste da seta: `#717171` sobre `#ffffff` = 4,88:1, acima dos 3:1 que a WCAG
  2.1 pede a elemento gráfico (critério 1.4.11).
- 375×812: select do catálogo em 343×44 e, no fórum, select e busca em 343×44
  empilhados. `scrollWidth - clientWidth === 0` e a varredura por elementos além
  da borda voltou vazia nas duas telas.
- `./scripts/verificar-acessos.sh`: **57 casos, nenhuma falha.**

---

## 2026-09-25 — O "Ver todos" das categorias

O relato foi curto: o "Ver todos" do carrossel *Explore por categoria* não levava
à listagem de categorias. A causa estava numa linha de
`inc/home/secao-categorias.php` — `'link' => reconectar_url_loja()`, isto é, a
faixa de **categorias** oferecia um atalho para o catálogo de **produtos**. O
link existia, era clicável e abria uma página plausível; só não era a que ele
prometia.

É a segunda vez que esse defeito aparece na home. O carrossel de lojas em
destaque teve o mesmo problema em outra forma: `reconectar_url_base_da_vitrine()`
devolvia a própria home, e o "Ver todos" recarregava a página em que o visitante
já estava. O comentário daquela correção continua no arquivo, e agora tem um
irmão.

**O destino não existia.** Esta é a parte que a leitura do relato não antecipa: o
projeto não tinha nenhuma página que listasse as categorias. `/shop/` lista
produtos, `/store-listing/` lista lojas, e `get_term_link()` leva a **uma**
categoria. Corrigir só a linha do link não teria para onde apontar — por isso a
entrega inclui a listagem.

### A página

`inc/marketplace/categorias.php` traz a consulta e o shortcode
`[reconectar_categorias]`, e o `provision.sh` cria a página `categorias` com o
mesmo padrão idempotente das demais (`[buddypress]`, `[reconectar_painel_empresas]`).
`reconectar_url_das_categorias()` resolve a URL pelo **slug**, não pelo título:
renomear a página no painel não pode quebrar o link da home — a mesma razão
registrada em `reconectar_url_das_lojas()`.

As categorias aparecem agrupadas por categoria-mãe, em ordem alfabética. A
ordenação difere de propósito da usada em `reconectar_obter_categorias()`, que é
por quantidade de produtos: a faixa da home mostra 14 e precisa colocar as mais
movimentadas à frente, porque o resto fica de fora; aqui está tudo, e o que uma
listagem completa deve ao visitante é previsibilidade.

Dois casos foram tratados porque acontecem em catálogo real, não por precaução
genérica: a subcategoria com produtos cuja **mãe** está vazia — `hide_empty`
derruba a mãe e a filha ficaria órfã, fora de todos os grupos e portanto
invisível —, e o `get_term_link()` que devolve `WP_Error`, que chegaria a
`esc_url()` como objeto.

### O que a página denunciou

Ao medir a cor do nome da categoria, ele saiu em `#31BEB1` — **2,30:1** sobre o
branco do cartão, reprovando o critério 1.4.3 da WCAG 2.1. A regra que deveria
pintá-lo era `.rc-card-categoria a`, e **não casa com nada**:
`reconectar_card_categoria()` imprime `<a class="rc-card-categoria">` com dois
`<span>` dentro, sem link descendente. A declaração era escrita, ignorada, e o
texto caía no link padrão do tema.

O defeito não era da página nova: vinha da faixa da home, onde o mesmo componente
é usado desde o começo. Uma página que multiplica o cartão por quinze foi o que
tornou visível o que uma faixa de catorze escondia. Corrigido no seletor, os dois
lugares passaram juntos.

**Verificado**

- Os três carrosséis da home apontam cada um para a listagem do seu tipo:
  categorias → `/categorias/`, lojas → `/store-listing/`, produtos → `/shop/`.
- `/categorias/`: 5 grupos, 15 subcategorias, **um único `<h1>`** ("Categorias"),
  `aria-labelledby` de cada seção resolvendo para um `id` existente, e nenhum
  shortcode cru no corpo.
- Nome da categoria: de `rgb(49,190,177)` (2,30:1) para `rgb(113,113,113)` —
  **4,88:1**, na home e na listagem. "Ver todos os produtos de X" em
  `rgb(39,39,42)`, **14,89:1**, sublinhado para não depender da cor (critério
  1.4.1).
- Cartão em 153×114px, bem acima do alvo de toque de 44px, com `outline` de 2px
  no foco.
- 375×812: grade em duas colunas de 163px, `scrollWidth - clientWidth === 0` e
  varredura por elementos além da borda vazia.
- Fallback do helper medido nos três estados: com a página, `/categorias/`;
  despublicada, `/shop/`; republicada, `/categorias/` — `get_page_by_path()`
  devolve rascunho, e é por isso que a guarda de `post_status` existe.
- Idempotência do bloco novo do `provision.sh`: com a página já criada, a guarda
  cai no ramo "já existe".
- `./scripts/verificar-acessos.sh`: **57 casos, nenhuma falha.**

**Anotado, fora de escopo:** a página não foi acrescentada ao menu nem ao rodapé —
o caminho até ela é o "Ver todos" da home, que era o pedido. E a home acusa
`scrollWidth - clientWidth` de 413px em 1280: medido com e sem a mudança deste
dia, o número é **idêntico**, então é anterior e vem dos carrosséis.

---

## 2026-09-25 — "Vendedor" vira "Loja", e o painel de empresas vira dashboard

O pedido veio em quatro frases, e a primeira era a menor delas: *trocar a palavra
vendedores por lojas no fluxo de cadastro de empresa*. As outras três definem o
modelo mental — a **empresa** é a Reconectar Incubadora Digital, e o que o
código chamava de "Vendedor" é uma **Loja** cadastrada por ela.

A incoerência já estava na tela: o formulário se chamava "Cadastrar vendedor" e
pedia "Nome da loja", "Descrição da loja". Duas palavras para a mesma coisa, no
mesmo campo de visão.

Duas decisões do usuário fixaram o escopo: a troca chega **até o código**
(rotas e identificadores, não só textos), e o painel ganha **cara de dashboard**
mantendo a rota própria.

### A fronteira

"Renomear vendedor" num projeto que roda sobre o Dokan é um convite a mexer no
que não é nosso. A regra adotada: renomeia-se onde "vendedor" designa a
**entidade**; preserva-se onde o nome é do **plugin**.

Ficam intocados `Reconectar_Lojas::PAPEL = 'seller'` — que é, além do nome do
papel, a trava contra escalada de privilégio, já que `wp_insert_user()` não
verifica capacidade nenhuma —, a capacidade `dokandar`, as metas `dokan_*` e o
`'seller_id'` dos argumentos de `dokan()->order->all()`.

Ficaram também, por decisão registrada, `Reconectar_Permissoes::eh_vendedor()`,
`restringir_por_vendedor()` e `restringir_listagens_do_vendedor()`: os três falam
do papel e do isolamento geral da plataforma, não do cadastro de empresa.
Renomeá-los produziria o maior diff do trabalho justamente na camada de RBAC —
cujo único teste é o `verificar-acessos.sh` — em troca de nada além do nome. O
PHPDoc de cada um passou a registrar a distinção.

### As duas migrações, que são o que separa isto de um "localizar e substituir"

`Reconectar_Migracoes` é classe nova, com opção própria
`reconectar_migracoes_versao`, pendurada em `init` na **prioridade 5** — antes
das capacidades, que rodam em 10.

**A meta.** `_reconectar_vendedor_ativo` → `_reconectar_loja_ativa`, em três
passos cuja ordem importa. Primeiro o `DELETE` das linhas legadas de quem já tem
a chave nova: sem ele, o `UPDATE` seguinte criaria duplicata e
`get_user_meta( …, true )` passaria a devolver uma das duas arbitrariamente —
uma loja desativada voltando a vender conforme a ordem de leitura do MySQL.
Depois o `UPDATE`. Por fim o `clean_user_cache()` em cada usuário afetado, com
os IDs colhidos **antes** do `UPDATE`, porque depois dele a consulta pela chave
velha volta vazia. Um `UPDATE` por baixo do `update_user_meta()` não invalida
cache nenhum.

**A capacidade.** `reconectar_gerir_vendedores` → `reconectar_gerir_lojas`.
Metade do caminho já existia: `sincronizar_papel_do_admin_de_empresas()` faz
`remove_role()` **antes** de `add_role()`, então o `company_admin` renasce com a
capacidade nova assim que `VERSAO_CAPACIDADES` muda. A outra metade não existia
— o `administrator` recebe as dele por `add_cap()` e só perde o que for
explicitamente removido, então a capacidade velha ficaria gravada nele para
sempre. Entrou a constante `CAPS_LEGADAS` e um `remove_cap` sobre todos os
papéis, que fica lá permanentemente: é o que faz uma instalação provisionada há
meses migrar sozinha.

### O consumidor silencioso

`Reconectar_Forum::pode_marcar_melhor_resposta()` usa essa capacidade como
atalho para "quem administra a operação". Sem acompanhar a renomeação, o fórum
perderia o caminho de moderação **sem erro visível**: `user_can()` com
capacidade inexistente devolve `false` e pronto.

### A rota

`ENDPOINT_VENDEDOR` virou `ENDPOINT_LOJA`, e `/painel-empresas/vendedor/12/`
virou `/painel-empresas/loja/12/`. Exige `wp rewrite flush`, que o
`provision.sh` já executa no fim.

### Cara de dashboard

A leitura literal do pedido — "esse painel pode ficar dentro do Dashboard" —
apontaria para o `/dashboard/` do Dokan, e essa porta continua fechada pela
razão já registrada no cabeçalho da classe: `Shortcodes/Dashboard.php:31` barra
quem não passa em `dokan_is_user_seller()`, literalmente
`user_can( $id, 'dokandar' )`, e conceder `dokandar` faria do Administrador de
Empresas **um vendedor para todo o plugin**. O que mudou foi a aparência, não a
fronteira.

`renderizar()` passou a envolver a tela em duas colunas: um `<aside>` com o menu
e o "Sair" no rodapé, e a área de conteúdo. O menu marca a seção corrente com
`aria-current="page"` — nunca `aria-pressed`, que é proibido em `<a>` — e em
tela estreita vira faixa horizontal rolável, não um `<details open>` que abriria
sozinho no celular.

**A tela que faltava.** Um dashboard com um item só não é um dashboard, e o dado
do segundo já existia e estava morto: `lojas_no_escopo()` era definida e não
tinha uma única chamada. Virou `painel-empresas/lista-lojas.php`, sem endpoint
novo — `/painel-empresas/loja/` com o valor **vazio** caía antes em tela de
ficha com id 0, terminando em 403. Em `contexto()` o ramo do valor vazio vem
**antes** do teste de id, porque `(int) 'nova'` é 0 tanto quanto `(int) ''`.

Três contagens no topo da listagem de empresas: empresas no escopo, lojas no
escopo, lojas em operação. Todas de consulta real. Faturamento agregado ficou de
fora de propósito — exigiria somar os ganhos de cada loja a cada carregamento, e
um número estimado para preencher o espaço é pior que espaço nenhum.

### O defeito que a medição encontrou

Os campos do formulário saíam **398px numa coluna de 375px**. A causa não estava
no CSS do painel: estava na sua ausência. O `box-sizing: border-box` vinha do
reset que o Storefront aplica a `*`, e o painel é **plugin** — não pode exigir
tema nenhum, pela mesma razão que os tokens `--rc-pe-*` trazem fallback literal.
Funcionava por acidente. Declarado no próprio componente, o estouro foi a zero.

**Verificado**

- Lint de todo o PHP do plugin e da carga, no container. Nenhuma falha.
- Varredura por `vendedor` no plugin: sobram apenas as ocorrências declaradas
  fora de escopo e as referências à chave legada dentro da própria migração.
- `./scripts/verificar-acessos.sh`: **59 casos, nenhuma falha** — com os dois
  novos, `/painel-empresas/loja/` respondendo `403` a quem toca uma loja e `200`
  ao Administrador de Empresas.
- Capacidade migrada, conferida no banco: o `company_admin` e o `administrator`
  têm `reconectar_gerir_lojas` e **não** têm a legada. Totais por papel
  inalterados (`company_admin` segue com seis capacidades).
- Meta migrada, conferida por consulta: nenhuma linha
  `_reconectar_vendedor_ativo` sobrou, e o ciclo desativar/reativar pela tela
  prova que o valor migrado continua sendo lido e escrito pelo mesmo caminho.
- `Reconectar_Lojas::criar()` exercitada ponta a ponta — papel `seller`, metas
  do Dokan, vínculo com a empresa, link de senha gerado, e recusa de login
  duplicado com `reconectar_login_em_uso`. A conta de teste foi removida.
- Fórum: `pode_marcar_melhor_resposta()` devolve verdadeiro para o administrador
  e para o Administrador de Empresas — a capacidade renomeada é reconhecida.
- `aria-current="page"` presente e **único** em cada uma das três telas, com o
  item certo por tela; nenhum `<details open>`; foco visível percorrendo o menu
  inteiro pelo teclado (medido com Tab real — `.focus()` programático não dispara
  `:focus-visible` e reporta ausência de contorno que não existe).
- Contraste do item ativo do menu e do texto de apoio conferido, nada abaixo de
  4,5:1 — o `--rc-pe-texto-suave` é o mais apertado da casca, em 4,88:1 sobre o
  branco. Nenhum `font-size` literal nos componentes novos: todos saem dos
  degraus `--rc-pe-*`, que trazem fallback porque o painel é plugin.
- 1280×900: `scrollWidth - clientWidth === 0` nas três telas.

**Anotado, não silenciado:** em 375px as duas telas com tabela estouram (35px e
154px), e a varredura mostra que o excedente é **inteiramente** da `.rc-tabela` —
nenhum elemento fora dela ultrapassa a borda. É defeito pré-existente da tabela,
não da casca nova. A tela de cadastro, que estourava 24px, passou a zero.

---

## 2026-09-25 — Acesso pelo IP da máquina e ordem das seções da home

**O que foi feito**
- Reordenadas as seis seções da home pela prioridade da action `reconectar_home`
  (categorias 10, lojas em destaque 20, produtos em destaque 30, vitrine 40,
  hero 50, comunidade/transparência 60).
- `WP_HOME` e `WP_SITEURL` passaram a ser resolvidas a partir do `Host` da
  requisição, num bloco delimitado do `wp-config.php` escrito por
  `scripts/configurar-url-dinamica.php`.
- Criado `scripts/normalizar-urls.php`, que converte em caminho as URLs
  absolutas já gravadas no banco (itens `custom` de menu e widgets
  `custom_html`) e descarta os transients de catálogo antigos.
- O host entrou na chave dos transients `reconectar_lojas_*` e
  `reconectar_sugestoes_*`.

**O problema**
Aberto pelo celular em `192.168.1.5:8090`, o site vinha **sem CSS nenhum** —
HTML cru, links roxos, imagens quebradas. `wp core install --url` gravara
`home` e `siteurl` como `http://localhost:8090`: o HTML saía do servidor, mas
todo asset vinha carimbado com `localhost`, que no celular é o próprio celular.

O segundo sintoma relatado — a barra inferior não aparecer no rodapé — era
**consequência** deste: sem CSS, `position: fixed` não existe. Medida a barra
com emulação de dispositivo, `antes: 3191, depois: 3191` após rolar, ou seja, o
`fixed` funciona; o que engana é o navegador embutido, que aplica a media query
no tamanho emulado (`clientWidth: 375`) mas ancora o `fixed` no viewport real da
janela (`innerHeight: 3248`).

**Decisões técnicas**
- **Constante, não filtro.** `wp-settings.php` congela `WP_CONTENT_URL` e
  `WP_PLUGIN_URL` em `wp_plugin_directory_constants()`, que roda antes de
  incluir os mu-plugins. Um filtro `option_siteurl` chegaria tarde e deixaria
  justamente o CSS do tema e o do plugin presos ao host antigo.
- **Allowlist, não `Host` cru.** A plataforma envia link de definição de senha
  no cadastro de loja; um `Host` forjado sairia dentro dele. Passam `localhost`,
  o loopback e os três blocos privados.
- **Bloco delimitado, não `wp config set`.** O `wp-config-transformer` não
  enxerga definição cujo valor é expressão: `wp config delete WP_SITEURL`
  responde "is not defined" com a linha no arquivo. O comando então acrescentava
  uma linha nova a cada provisionamento, até o PHP avisar "Constant already
  defined" — e aviso antes de redirect cancela a ação. Descoberto na segunda
  execução, não na primeira.
- **Caminho relativo nas gravações novas**, mais um script de reparo para a
  instalação antiga, que passa pelas guardas de idempotência e nunca seria
  reescrita.

**Verificação**
- Pelos dois hosts, 228 URLs cada, todas do host da requisição; zero vazamento
  entre eles. 14 arquivos CSS testados pelo IP, todos 200.
- `Host: evil.example.com:8090` → **zero** ocorrências do host forjado no corpo;
  as 228 URLs saem no padrão. (Um `Host` desconhecido sem porta ainda recebe 301
  de `redirect_canonical()`, do núcleo — não reflete nada no corpo.)
- `./scripts/verificar-acessos.sh`: 59 casos, nenhuma falha.
- `docker compose run --rm demo` rodado de novo: "bloco já estava em dia",
  "0 item(ns) de menu, 0 widget(s)", tudo "já existia".

- Menu principal conferido item a item: os seis continuam gravados (`Início`,
  `Lojas`, `Comunidade`, `Transparência`, `Minha Conta`, `Fórum`). Os dois que
  não saem no HTML para visitante deslogado são `Comunidade` e `Fórum`, que
  `ocultar_itens_da_comunidade()` esconde por permissão. `Início` e `Fórum`
  passaram a guardar caminho (`/` e `/forums/`); os demais são `post_type` e
  resolvem o permalink na hora, acompanhando o host sozinhos.

---

## 2026-09-25 — A barra inferior sumia só na home

**O relato**

A barra de navegação do celular aparecia em todas as páginas menos numa: a
home. O usuário fechou o cerco sozinho — **removendo as `<section>` com
`data-rc-carrossel`, a barra voltava**. Foi esse teste que deu a causa; as duas
hipóteses anteriores, minhas, estavam erradas.

**A causa**

Um scroll container só clipa descendente **cujo containing block seja ele**.
`.rc-carrossel__faixa` tem `overflow-x: auto`, mas era `position: static` — e os
cards trazem `span.screen-reader-text`, que o WordPress declara
`position: absolute`. Sem nenhum ancestral posicionado no caminho, o containing
block desses spans era o **viewport**: eles ficavam em coordenada de documento,
fora do alcance do `overflow` que deveria contê-los.

Medido, um deles em **x=1645 numa tela de 375**, e a área de rolagem do
documento em exatamente esses 1645px. Nada aparecia fora de lugar — os spans
têm 1px e `clip-path: inset(50%)` — e o carrossel rolava certo. O que quebrava
era outra coisa, três seções abaixo: com o documento rolando na horizontal sob
o `overflow-x: hidden` que o Storefront propaga do `<body>` para o viewport,
`position: fixed` deixa de acompanhar a tela.

**A correção**

`position: relative` em `.rc-carrossel__faixa` e em `.rc-filtros__lista`, os
dois scroll containers horizontais do tema. Não posiciona nada: faz deles o
containing block do que carregam.

**O caminho errado, registrado porque custou uma rodada**

Antes disto a barra foi movida para fora de `#page`, com a justificativa de que
`.site { overflow-x: hidden }` do Storefront a transformava em scroll container
(`overflow` computado `hidden auto`, o que é verdade) e de que `fixed` dentro
dele seria o defeito. Não era: o Chrome de mesa não reproduzia nada, e um
`fixed` dentro e outro fora do `#page` paravam no mesmo pixel. A chamada ficou
onde está — é a posição defensável de um componente fixo, e o lugar no DOM
decide a ordem de tabulação —, mas o comentário no `footer.php` foi reescrito
para não creditar a ela uma correção que não é dela.

O diagnóstico que teria fechado o caso na primeira rodada é de uma linha:

```js
window.scrollTo( 600, 0 ); window.scrollX   // 0 = são; > 0 = documento rola na horizontal
```

`body.scrollWidth` **não** acusa — o `hidden` do corpo mascara, e foi por isso
que a medição inicial passou batido. Quem acusa é o `documentElement`.

**Verificação**

- Home a 375px: `documentElement.scrollWidth` de 1645 para **375**, igual ao
  `clientWidth`; `scrollTo( 600, 0 )` deixa `scrollX` em **0** (era 132).
- Os três carrosséis continuam rolando: `scrollWidth` 568, 1348 e 1908 contra
  343 de `clientWidth`, e `scrollLeft = 400` de fato move.
- `/transparencia/`, `/store-listing/` e `/shop/` a 375px: sem transbordo, sem
  rolagem horizontal, barra com quatro itens e pai `BODY`. Na listagem de lojas
  a faixa de filtros segue rolando (832 contra 343).
- Varredura por absoluto órfão fora da tela em `/shop/`: vazia.
- 1280×900: `scrollWidth` igual ao `clientWidth`, barra `display: none`,
  `padding-bottom` do corpo zerado, dois carrosséis com setas (o de categorias
  cabe inteiro e corretamente não as recebe).

---

## 2026-09-25 — O cabeçalho do celular em uma linha a menos

### O pedido

Três ajustes, todos de celular: o carrinho não precisa do valor, só da
contagem; a logo deve ficar na mesma linha em vez de estourar para cima; e o
ícone de perfil é redundante, já que a barra inferior tem o mesmo destino.

### O que estava acontecendo

O `.rc-cabecalho__interno` media **224px de altura em 375px de largura**: a
marca ocupava uma linha inteira sozinha, as ações outra, e a busca a terceira.

A causa não era a largura da logo. Num contêiner `flex-wrap: wrap` a quebra de
linha é decidida pelas **`flex-basis`, antes de qualquer flexão** — e as ações
tinham base `auto`, isto é, a largura do próprio conteúdo: 285px. Somados aos
145px da marca e aos 16px de gap, davam 446 contra 343 de largura útil, e a
marca era empurrada para fora da linha. O `flex-shrink: 1` e o `min-width: 0`
que já existiam no município não tinham chance de agir: a decisão de quebrar
já estava tomada.

`flex: 1 1 0` nas ações resolve — zerada a base, a linha nunca transborda e o
encolhimento volta a acontecer onde estava previsto. Com ele veio
`justify-content: flex-end`, porque o item passa a ocupar toda a sobra e o
`margin-left: auto` da regra geral fica sem espaço livre para empurrar.

### O efeito colateral, e o que ele custou

Com os três na mesma linha, o município ficou com **42px de texto visível** —
"Entre…" e "Todos…". Trocar um desperdício de altura por um rótulo ilegível não
é correção.

Os 135px que faltavam vieram de duas palavras que saíram do olho e ficaram no
leitor de tela: o "itens" do carrinho (34px) e o "Entregando em" do município
(101px). É o oposto do que se fez com o subtotal, que sumiu para todo mundo —
e a diferença é que o subtotal continua a um toque, num link que leva ao
carrinho, enquanto estas duas não têm para onde levar: sem a unidade, o nome
acessível do link do carrinho seria "3"; sem o rótulo, o pin seria a única
pista de que aquele nome é um município.

A unidade exigiu separar número e palavra em dois `<span>` no PHP, no lugar do
`_n( '%s itens' )` montado numa string só. O preço é a ordem fixa: um idioma
que ponha a unidade antes do número não tem como invertê-la pela tradução.
Fica registrado no comentário, para quem traduzir reabrir a decisão.

### As duas técnicas de esconder, e por que não são a mesma

A unidade do carrinho fica **no fluxo**, com 1px recortado por `clip-path`. A
técnica padrão do WordPress usa `position: absolute`, e um absoluto dentro de
contêiner estático sobe até o viewport, onde estica a área de rolagem do
documento — foi exatamente assim que a barra inferior sumiu na home, na entrega
anterior. O carrinho não tem ancestral posicionado.

O rótulo do município sai do fluxo **porque precisa**: no fluxo, um inline-block
de 1px gera line box com a altura do strut do bloco, e medido o gatilho
continuava com 61px para exibir uma linha de texto. Ali o absoluto é seguro —
`.rc-municipio` é `position: relative` desde que ganhou a lista suspensa.

### Verificação

| Largura | Resultado |
| --- | --- |
| 375 | marca, município e carrinho na mesma linha; `interno` 224 → **152px**; carrinho 88 → 55px; texto do município 42 → **91px**; `scrollWidth` = `clientWidth` = 375; `scrollX` 0 após `scrollTo(600,0)` |
| 320 | nada transborda (320 = 320); município com 36px de texto; barra inferior visível |
| 768 | atalho de conta e subtotal de volta; unidade visível (31px); rótulo `static` |
| 1280 | `interno` 88px; município sem truncar; `carrinho` = "R$ 0,00 0 itens"; barra `display: none` |

O `innerText` do gatilho continua trazendo "Entregando em" e o do link do
carrinho, "0 itens": as duas palavras estão escondidas do olho e presentes na
árvore de acessibilidade, que é o ponto.

### O que fica truncado, e é honesto dizer

"Todos os municípios" mede 145px e a caixa tem 91px a 375px — sai "Todos os
mun…". Nomes de cidade reais cabem inteiros ("Arapiraca" mede 68px); os
compostos, como "Marechal Deodoro", não. Não há como evitar sem encolher a
logo, que já foi motivo de reclamação quando estava em 44px. A alternativa
seria devolver o município a uma linha própria — o que anula justamente o
ganho que o pedido pedia.

---

## 2026-09-25 — O conteúdo passa a seguir a régua do cabeçalho

### O pedido

> a classe class="col-full" pode ser responsiva em width de 100% com padding
> seguindo o header

### O desalinho que existia, e por que não era acidente

O cabeçalho saiu da régua compartilhada quando virou barra de aplicativo: faixa
inteira, recuado por `--rc-recuo-lateral`, que é `clamp(16px, 3vw, 40px)`. O
`#content` e o rodapé ficaram com `max-width: 1200px` e `padding-inline: 16px`.

Os dois limites são grandezas diferentes — um recuo de um lado, uma largura
máxima do outro —, e grandezas diferentes só coincidem por acidente, numa
largura de tela específica. Medido em 1232px de viewport: o logotipo nascia em
36,9px e o primeiro card de categoria em 16px, os 21px que a captura do pedido
mostra. Acima de 1200 a distância crescia sem teto.

O comentário que ficava em `.rc-cabecalho__interno` registrava isso como
"conhecido e aceito", com a saída anotada: soltar `--rc-largura` também no
conteúdo. É o que foi feito.

### O que mudou

`.col-full` passa a copiar o cabeçalho, literalmente a mesma expressão:

```css
.col-full {
  box-sizing: border-box;
  margin-inline: auto;
  max-width: none;
  padding-inline: var(--rc-recuo-lateral);
  width: 100%;
}
```

`margin-inline` continua declarado, e não é redundante: o Storefront tem duas
regras em `@media` (abaixo de 66.4989em e de 568px) que zeram o `padding` e
devolvem o recuo como `margin-left`/`margin-right`. Sem o `auto` aqui, as telas
estreitas somariam o recuo do pai ao nosso — justamente onde ele é mais caro.

`--rc-largura` não sumiu: deixou de ser a régua do site e passou a ser o teto de
**medida de leitura** de quem tem texto corrido — `.rc-forum`,
`.rc-forum-topico`, `.rc-login`. É onde o limite pertence. No contêiner geral
ele também governava grade de produtos, tabela de pedidos e o painel do Dokan,
onde só desperdiçava faixa.

### Verificação

Logotipo do cabeçalho e primeiro card do conteúdo, coordenada `x` de cada um:

| Viewport | Logotipo | Conteúdo | Recuo efetivo |
| --- | --- | --- | --- |
| 375 | 16 | 16 | 16px (piso do `clamp`) |
| 560 | 16,8 | 16,8 | 16,8px |
| 1024 | 30,7 | 30,7 | 30,7px (3vw) |
| 1232 | 36,9 | 36,9 | 36,9px |
| 1920 | 40 | 40 | 40px (teto do `clamp`) |

Nas cinco larguras, `margin-left` computado do `.col-full` é `0px` — as regras
do tema pai perdem na cascata, como esperado. Em `/`,
`/product-category/alimentos-e-bebidas/`, `/store-listing/`, `/cart/` e
`/my-account/`, `scrollWidth - clientWidth` é zero em 375 e em 1920, e o
`scrollTo(600, 0)` da home continua devolvendo `scrollX` zero — a armadilha do
carrossel segue fechada.

O rodapé acompanha: ele também é `.col-full`, e sua primeira coluna nasce nos
mesmos 40px em 1920.

### O efeito de tabela ampla, e o que ele traz junto

A grade do catálogo é `repeat(auto-fill, minmax(260px, 1fr))` e se ajusta
sozinha: em 1920 passou de quatro colunas de 283px para **seis de 290,8px**, com
o card mantendo o mesmo tamanho. Não foi preciso tocar nela.

O preço da mudança é a medida de texto na tela muito larga. Sem teto no
contêiner, um parágrafo de descrição de produto atravessa os 2560px de um
monitor ultrawide. As telas de texto corrido têm teto próprio, mas as páginas do
WooCommerce e do Dokan não — se isso incomodar, o ajuste é de uma linha
(`max-width` generoso em `.col-full`, na casa de 1600px), e não o retorno a
1200, que traria o desalinho de volta.

## 2026-09-28 — Três perfis administrativos e pagamento direto à loja

Dois pedidos independentes, feitos juntos: um degrau de permissão que faltava
entre o `administrator` e o `company_admin`, e um meio de a loja receber por
PIX ou transferência sem que a plataforma toque no dinheiro. Um terceiro eixo
veio junto porque o pedido o exigia — banners de campanha na home, como conteúdo
com vigência.

### O que decidiu o eixo dos perfis

`restringir_gestao_de_plugins()` barrava as capacidades de plugin **acrescentando
`manage_options` ao conjunto exigido**, e `eh_administracao_tecnica()` — o portão
do `/wp-admin` — era exatamente `manage_options || manage_woocommerce`. Dar
`manage_options` ao Administrador restrito para que ele entrasse no painel
devolveria a ele, pela mesma linha, a instalação de plugins: precisamente o que
o pedido proibia.

`manage_woocommerce` abriria a porta sem quebrar a trava, e tinha outro preço: os
menus de WooCommerce e Dokan apareceriam, e as listagens do `/wp-admin` **não
têm escopo por empresa**. O `company_admin` só é limitado às empresas dele dentro
do `/painel-empresas/`; no painel ele veria produtos e pedidos de todas.

A saída foi uma capacidade própria, `CAP_ADMIN_WP =
'reconectar_acessar_wp_admin'`, consultada em **dois lugares só** —
`bloquear_area_administrativa()` e `ocultar_barra_administrativa()`.
`eh_administracao_tecnica()` não mudou uma linha: os outros dois lugares que a
consultam falam de isolamento entre vendedores, e ali a resposta certa para os
perfis novos continua sendo "não é administração técnica".

Medido, é essa capacidade que separa quem entra de quem não entra:

```
customer           manage_options=nao manage_woocommerce=nao wp_admin=nao total=1
seller             manage_options=nao manage_woocommerce=nao wp_admin=nao total=67
content_moderator  manage_options=nao manage_woocommerce=nao wp_admin=SIM total=31
company_admin      manage_options=nao manage_woocommerce=nao wp_admin=SIM total=40
administrator      manage_options=SIM manage_woocommerce=SIM wp_admin=SIM total=166
```

### A escalada que `edit_users` abria

"Configurar os usuários das lojas" exige `edit_users`, e em single-site **não
existe** "editar usuário abaixo de mim": quem a tem pode abrir a ficha de um
`administrator`, trocar a senha e entrar com a conta. Com `promote_users`, pode
promover a si mesmo. As duas juntas transformavam o Administrador restrito em
Super Administrador com dois cliques, e a proibição de instalar plugin virava
decoração.

`negar_gestao_de_usuarios_superiores()` fecha as duas rotas em `map_meta_cap`:
alvo com `manage_options` é intocável para quem não a tem, e `promote_user` recusa
papel de destino que contenha capacidade que o ator não possua — o que cobre o
`administrator` e qualquer papel futuro criado por cima.

Ao conferir isso pela primeira vez a resposta veio errada, e o erro merece
registro: `current_user_can( 'promote_user', $id )` devolve `true` sozinha. O
papel de destino é o **terceiro** argumento, e omiti-lo dá por segura uma trava
que não chegou a ser consultada.

### O filtro que teria negado o conteúdo em silêncio

`negar_escrita_ao_admin_de_empresas()` vigiava `edit_post`, `delete_post` e
`publish_post` genéricos e liberava **só** o CPT `reconectar_empresa`. Com o
Administrador passando a moderar conteúdo, ele passaria a negar a edição de post
e de página sem uma linha de mudança — o sintoma pior deste repositório: código
no lugar certo, correto para o que fora escrito, impedindo em silêncio o que a
especificação nova pedia.

A exceção virou allowlist de post types que o ator **pode** escrever: empresa,
`post`, `page`, campanha e os três do bbPress. Produto e pedido continuam de
fora, de propósito.

### A lacuna do fórum, que só a medição encontrou

Os dois perfis recebiam as capacidades de fórum, a sincronização as gravava, e
no `/wp-admin` nada funcionava. A causa são **duas**, e nenhuma aparece lendo o
papel:

O bbPress mantém uma segunda camada de papéis — `bbp_keymaster`,
`bbp_moderator`, `bbp_participant` — gravada como papel **adicional** do usuário,
e ela se sobrepõe às capacidades de fórum do papel do WordPress. Todo cadastro
nasce em `bbp_participant`.

E `bbp_map_forum_meta_caps()` troca `edit_forums` e `edit_others_forums` por
`do_not_allow` para quem não tem `keep_gate`, independentemente do papel:

```
allcaps[edit_forums] = true
map_meta_cap( 'edit_forums' ) = do_not_allow
```

`publish_forums` **não** sofre isso — essa o bbPress mapeia para `moderate`. A
assimetria é dele.

`restaurar_gestao_de_foruns()` devolve as duas em `map_meta_cap` **prioridade
11**, e só para quem o papel já tinha autorizado. Ele lê
`$usuario->allcaps[$cap]` direto, e não com `user_can()`: a segunda reentraria em
`map_meta_cap` com a mesma capacidade e entraria em recursão infinita.
`aplicar_moderacao_no_forum()` põe os dois perfis em `bbp_moderator`, pendurado
em **`set_user_role`** — `bbp_set_user_role()` troca o papel com `remove_role()`
e `add_role()`, que disparam `add_user_role`, e o método chamaria a si mesmo.

`keep_gate` fica fora de alcance de propósito: ele abre as Configurações do
bbPress e a ferramenta de **redefinição**, que apaga o fórum inteiro da
instalação.

Quem já estava gravado no banco não passa por `set_user_role` nunca mais, então
`Reconectar_Migracoes::promover_moderadores_no_forum()` resolve o passado, com a
`VERSAO` em 2. A assimetria de sempre: o gancho cuida do futuro, a migração do
que já existe.

### Campanhas

CPT `reconectar_campanha` com capacidades próprias, no molde de
`Reconectar_Empresa`: imagem destacada, link, vigência, ordem e texto
alternativo **obrigatório** — banner é imagem com função.

A vigência é o que justifica o post type em vez de um widget: campanha tem data
de fim, e o moderador não deveria precisar lembrar de apagar. Sem campanha
vigente a seção **não imprime nada** — nem título, nem moldura vazia.

A carga declara duas, e a segunda é a que importa: uma já **expirada**, que é o
que prova que a vigência funciona. Com só a vigente no banco, uma regressão que
ignorasse as datas passaria despercebida, porque não haveria nada de errado para
aparecer. As datas são deslocamentos em dias, não absolutas: uma data fixa
expiraria sozinha e levaria a campanha "vigente" embora sem ninguém ter mexido
em nada.

### Pagamento direto à loja

A plataforma não toca no dinheiro. A loja cadastra chave PIX e conta bancária em
**Configurações → Pagamento** da dashboard do Dokan, e o comprador paga direto.
Nenhum provedor a escolher, nenhuma credencial para vazar, e o split — que é o
que exigiria o Dokan Pro — deixa de ser necessário, porque o dinheiro nunca é
agregado.

A única lacuna do Dokan era a gravação. `insert_settings_info()` tem `bank` e
`paypal` escritos à mão no ramo do nonce `dokan_payment_settings_nonce`; um
`$_POST['settings']['pix']` chega e não é lido. O único gancho que alcança é
`dokan_store_profile_settings_args`, que dispara em **todos** os caminhos de
salvamento do perfil — por isso a injeção é guardada por `wp_verify_nonce()`.
Sem essa guarda, salvar a loja em outra aba apagaria os dados de pagamento, sem
erro e sem aviso.

Dois gateways autorais, `reconectar_pix` e `reconectar_transferencia`, ambos
sobre `Reconectar_Gateway_Direto`. Nenhum processa transação: `process_payment()`
marca o pedido como aguardando pagamento e devolve a tela de agradecimento. A
confirmação é manual, feita pela loja — que é o que acontece de fato quando
alguém paga numa chave PIX pessoal. Inventar confirmação automática seria
fabricar um dado.

**A regra da interseção** é o que o carrinho multi-vendedor exige: o meio
oferecido tem de ser um que *todas* as lojas do carrinho aceitem. `is_available()`
esconde o que nenhuma aceita; `validar_checkout()` recusa com o **nome** de quem
não recebe por ali. Uma lista vazia sem explicação mandaria o comprador embora
sem saber o que fazer.

A tela de agradecimento e o e-mail imprimem **um bloco por loja**, com o valor do
sub-pedido correspondente. Um bloco só, com a soma, mandaria o comprador pagar
tudo para uma das lojas.

`reconectar_pix_br_code()` monta o payload EMV MPM — TLV mais CRC16-CCITT/FALSE
—, cálculo puro, sem dependência, sem build e sem rede. A conferência que vale é
colar o código no app de um banco real: um código que o banco recusa é pior que
nenhum código.

E `provision.sh` ganhou a linha sem a qual nada disso aparece:

```bash
wp option patch update dokan_withdraw withdraw_methods --format=json '["pix","bank"]'
```

O padrão é `["paypal"]`, e com ele **toda loja vê "No withdraw method is
available"** — a tela existe, está vazia, e nada indica o porquê. O menu
**Withdraw** foi ocultado pelo motivo oposto: a plataforma não retém saldo, e um
menu de saque prometeria um repasse que não existe.

### Verificação

`scripts/verificar-acessos.sh` passou de 114 para **127 casos**, verde. Os novos
cobrem os dois perfis item a item. As negações medidas, para o Moderador:

| Tentativa | Resposta |
| --- | --- |
| `plugins.php` | 403 |
| `theme-install.php` | 500 |
| `theme-editor.php` | 403 |
| `edit.php?post_type=product` | 403 |
| `admin.php?page=wc-orders` | 301 → 403 |
| `admin.php?page=dokan` | 403 |
| `options-general.php` | 403 |
| `users.php` | 403 |
| `edit.php?post_type=reconectar_empresa` | 403 |
| `/painel-empresas/` | 403 |
| `nav-menus.php` | **200** |
| `customize.php` | **200** |
| `post-new.php?post_type=forum` | **200** |

Três dessas linhas custaram tempo e viraram armadilha registrada no `CLAUDE.md`.
`plugins.php` morre num `wp_die( …, 403 )` explícito de `menu.php:384`, enquanto
`theme-install.php` **passa** por esse portão — ele pendura em `themes.php` — e
bate num `wp_die()` sem argumento de status, cujo padrão é **500**. Um
verificador que espere 403 em toda negação acusa falha onde não há.

`admin.php?page=wc-orders` devolve **301** para `edit.php?post_type=shop_order`
com o HPOS desligado, e é lá que a negação acontece. Ler só o primeiro código
leria 301 como sucesso.

E o menu **Produtos** aparece na lateral do Moderador resolvendo para
`admin.php?page=product-reviews` — as avaliações, que o WooCommerce registra sob
aquele menu. Quem vir o rótulo e concluir que o perfil administra o catálogo terá
lido o rótulo, não o destino.

`edit_theme_options` abre a Aparência inteira porque é a **única** capacidade que
o WordPress oferece para editar menus, e ela vem grudada ao Customizer e aos
widgets. Não há granularidade menor no núcleo: ou o Moderador cria menus e
alcança o Customizer, ou não cria menus. O pedido é explícito, então ela entra —
e a amplitude é do WordPress, não uma escolha nossa.

Uma nota sobre como medir: `wp eval` com `wp_set_current_user()` roda **depois**
do `init` e não tem o papel dinâmico que o bbPress aplica ali; capacidades de
fórum respondem "SIM" onde o navegador responde 403. O que vale é
`wp --user=<login> eval …` — ou o próprio `curl` com a sessão.

### O que ficou fora, e anotado

Split automático de comissão, confirmação automática de pagamento, conciliação e
estorno pela plataforma: nenhum é possível neste cenário, e cada um está na
tabela de `docs/PAGAMENTOS.md` com o que exigiria. QR Code em imagem exigiria
biblioteca nova, e o copia-e-cola cobre o caso no celular. "Super Admin" no
sentido literal do WordPress é vocabulário de Multisite — o rótulo mudou, a
arquitetura não, e a chave do papel `administrator` continua a mesma.

### Os dois avisos que o painel do Dokan cobrava para sempre

Depois da entrega, o painel abria com a faixa **"Complete your marketplace setup
in minutes"** sobre as Configurações e, logo abaixo, com **"Vendor Onboarding
page is not published!"**. Nenhum dos dois é resolvível seguindo o que eles
pedem, e os dois passaram a ser estado declarado no `provision.sh` e no plugin.

A faixa é o assistente de configuração (`Admin\OnboardingSetup\AdminSetupGuide`),
com duas das quatro etapas pendentes: `basic` e `commission`, as que dependem da
opção `dokan_selling`. A opção nunca existiu nesta instalação, e aí está a
armadilha — os passos escutam **`updated_option`**, e uma opção ausente é criada
por `add_option`, que dispara `added_option`. Gravar `dokan_selling` pela
primeira vez não marca etapa nenhuma; na segunda execução o valor é igual e
também não dispara nada. (As outras duas etapas já apareciam concluídas por
efeito colateral: o provisionamento regrava `dokan_withdraw` e
`dokan_appearance`, que já existiam.)

Marcar as quatro ainda não bastou: medido, `is_setup_complete()` seguia `false`,
porque a conclusão do assistente mora em opção **separada**,
`dokan_admin_setup_guide_steps_completed`. O bloco novo do `provision.sh` grava
as cinco à mão, que é também o único caminho idempotente.

A comissão foi a **zero** no mesmo bloco, e essa é a decisão que não podia ser
copiada do padrão da tela. O assistente propõe 10% mais R$10 fixos; medido em
`wp_dokan_orders`, `net_amount` é igual ao `order_total` em todos os pedidos —
a plataforma não retém nada, porque o comprador paga a loja direto. Aceitar o
padrão faria a dashboard de cada loja exibir um desconto que ninguém cobra.

O segundo aviso acusa como erro de configuração exatamente a decisão da
plataforma: a página `/vendor-onboarding/` fica em rascunho porque o shortcode
dela sai do ar em `Reconectar_Cadastro_De_Lojas::ajustar_formularios()`.
Publicá-la para calar o aviso reabriria a terceira das quatro portas de
autocadastro que aquela classe existe para fechar. `remover_aviso_de_onboarding()`
tira o callback do filtro `dokan_admin_notices`, no molde já usado por
`remover_virar_vendedor()`: a instância tem de ser a do container, porque o
WordPress compara identidade de objeto ao remover.

Suprimir importa mais do que parece — um alerta permanente que ninguém pode
resolver ensina o administrador a ignorar os alertas que importam.

Uma nota sobre como medir isto: a tela continuou exibindo o aviso depois da
mudança, e a resposta REST recém-buscada já vinha vazia. Era **cache do
navegador**. O que vale é refazer a chamada com `cache: 'no-store'` — conferir
pela tela recarregada leria o estado de antes.

---

## 2026-09-28 — O checkout não oferecia meio de pagamento nenhum

Com a chave PIX cadastrada na dashboard do Dokan, a tela de finalização dizia
**"Não há métodos de pagamento disponíveis. Entre em contato conosco para obter
ajuda na realização do seu pedido."**

### O defeito estava onde não se procura

Toda medição pelo lado do servidor respondia que estava tudo certo. Com o
carrinho simulado em `wp eval`: `lojas_do_carrinho = 27`,
`reconectar_pix is_available = true`, `get_available_payment_gateways()`
devolvendo `reconectar_pix`. A opção `woocommerce_reconectar_pix_settings` nem
existe no banco — e isso é inofensivo, porque `WC_Settings_API::init_settings()`
cai nos defaults do `form_fields`, onde `enabled` já é `yes`.

Três conferências seguidas dizendo "correto" são o sinal de que a pergunta está
errada. O que faltava medir era o **HTML entregue ao navegador**:

```
paymentMethodSortOrder: ["reconectar_pix","reconectar_transferencia"]
paymentMethodData:      []
payment_methods:        ["reconectar_pix"]   (Store API do carrinho)
```

A página `/checkout/` usava o bloco `woocommerce/checkout`, que desenha apenas
os métodos registrados em
`woocommerce_blocks_payment_method_type_registration` — classe
`AbstractPaymentMethodType` mais script chamando `registerPaymentMethod`. Os
gateways autorais são clássicos e não têm essa integração. O servidor sabia que
o PIX estava disponível; o bloco não tinha como desenhá-lo.

### A correção, e por que o clássico não é um retrocesso

`provision.sh` ganhou o bloco `== Carrinho e checkout clássicos ==`, que converte
as duas páginas aos shortcodes `[woocommerce_cart]` e `[woocommerce_checkout]`,
com guarda de idempotência.

Trocar o caminho não é só fazer o gateway aparecer. `payment_fields()` — onde o
aviso de **qual loja** não recebe por aquele meio é impresso — e
`woocommerce_after_checkout_validation`, onde `validar_checkout()` recusa um
pedido que nenhuma loja conseguiria receber por inteiro, **não rodam** no bloco.
Os dois são requisito registrado em `docs/PAGAMENTOS.md`, e o caminho de blocos
exigiria reescrever ambos contra o Store API.

Uma conclusão minha teve de ser corrigida no meio do caminho: ao ver
`woocommerce_store_api_checkout_update_order_meta` vazio, dei o Dokan como sem
integração com o Store API — e a divisão em sub-pedidos como motivo da troca. A
varredura ampla de `$wp_filter` mostrou o contrário:
`woocommerce_store_api_checkout_order_processed` tem `split_vendor_orders` [10] e
`dokan_sync_insert_order` [20]. A divisão funcionaria nos dois caminhos; o motivo
é outro, e está escrito acima.

### A lista vazia que ainda podia acontecer

Nenhuma loja da demonstração está hoje sem meio algum, mas se estivesse, a
lista ficaria vazia e o WooCommerce imprimiria de novo a frase genérica —
exatamente a tela que originou o chamado. `explicar_ausencia_de_meios()`, no
filtro `woocommerce_no_available_payment_methods_message`, passa a nomear as
lojas. A relação sai dos gateways instanciados, não de uma lista de meios
escrita à mão: um meio novo conta sozinho, e não há duas listas para divergirem.

Exercitado com a leitura da meta interceptada, sem tocar no banco — uma loja sem
meio produz o singular com o nome dela; duas produzem o plural com as duas; e com
o meio no lugar o filtro devolve a frase de fábrica intacta, sem sequestrar a
mensagem de outra situação.

### Medições

- `provision.sh` rodado duas vezes: a primeira converteu as duas páginas
  (`Success: Updated post 6/7`), a segunda imprimiu "já usa o shortcode
  clássico" nas duas. Idempotente nos dois caminhos.
- `./scripts/verificar-acessos.sh` — **127 casos, nenhuma falha**.
- Carrinho misto (Moda Reconecta sem PIX, Bem Viver Natural sem banco): os dois
  meios aparecem, cada um com o aviso da loja certa embaixo.
- `/cart/` clássico em 375px: `scrollWidth - clientWidth === 0` e
  `window.scrollTo( 600, 0 ); window.scrollX === 0`. A barra inferior fica.
- **Pedido 428 fechado de verdade** pelo checkout clássico, com produtos de duas
  lojas: a tela de agradecimento traz dois blocos PIX, R$ 38,00 e R$ 54,00,
  somando o total de R$ 92,00, cada um com o `txid` do sub-pedido correspondente
  (430 e 429, criados pelo Dokan).
- Os dois BR Codes decodificados por um percorredor de TLV escrito do zero, com
  CRC16 por implementação diferente da do plugin: estrutura íntegra, CRC
  conferindo, valor e moeda corretos nos dois.

Falta a conferência que nenhuma medição substitui: **colar o copia-e-cola no app
de um banco real**. Um código que o banco recusa é pior que nenhum código — se
não passar, a entrega sai com os dados da chave em texto e sem o copia-e-cola.

O pedido 428 e os sub-pedidos 429/430 são lixo de teste e ficaram no banco de
propósito, para não apagar dado sem pedir. `./scripts/seed-demo.sh remover` e
`instalar` limpam a instalação inteira e convergem a demonstração.

## 2026-09-28 — O carrinho ganha camada autoral

A troca do bloco `woocommerce/cart` pelo shortcode `[woocommerce_cart]`, feita
para que o gateway clássico voltasse a aparecer, teve um efeito colateral que a
entrada anterior não mediu: a página passou a exibir a tabela crua do
WooCommerce vestida pelo Storefront, **sem nenhuma regra `rc-`**. Nada estava
quebrado; estava tudo fora da identidade, e duas coisas reprovavam.

### O que a medição encontrou

Em 1280×900: miniatura de **59×59** dentro de linha de 139px; nome do produto no
azul de link do tema, `#31BEB1` — **2,30:1** sobre o branco, reprovando o
critério 1.4.3 da WCAG 2.1, que é requisito do edital; `.cart_totals` com
`float: right` ocupando 629 de 1188 e deixando 559px de vazio; botão de
finalização de 629×65 com fonte de 22,652px e `<h2>` de 25,888px — dois valores
que não existem na escala tipográfica.

Em 375px: **318px de altura por item**, com o botão de finalizar em y=1564.

### A regra que era escrita, aplicada, e não fazia nada

`width: 88px` na miniatura não mudou um pixel. A varredura do CSSOM — refeita
depois que a primeira versão devolveu listas vazias para regras que eu mesmo
acabara de escrever, inconsistência que denunciou o bug na varredura, não no CSS
— achou a causa: `woocommerce.css` declara `max-width: 3.70633em` na miniatura e
`max-width: 3.632em` no campo de quantidade. A regra autoral vencia a cascata; o
teto vinha de outra propriedade. Corrigido declarando `max-width` ao lado de
`width` nas duas.

Sem essa varredura eu teria concluído "nenhuma regra concorrente" e ido mexer na
especificidade, que não era o problema.

### A tabela fica no desktop, e vira cartão no celular

Produto × preço × quantidade × subtotal é dado tabular. `display: grid` no
desktop faria o navegador descartar os papéis implícitos de linha e célula, e o
leitor de tela perderia a associação entre valor e coluna. Abaixo de 768px a
semântica já está perdida pelo próprio WooCommerce, que esconde o `<thead>` — e
ali cada linha vira cartão em grade, com os `::before` gerados a partir de
`data-title` fazendo o papel dos cabeçalhos. Mantê-los é melhor que injetar
texto por `content`: eles já vêm traduzidos pelo WordPress ("Preço: ",
"Quantidade: ", "Subtotal: ").

Duas correções vieram de medição e não de leitura. `display: flex` em
`td.actions` descartou o `colspan="6"` e encolheu a célula de 1187 para 331px;
pôr a `<tr>` em `display: block` não resolveu (seguiu 331), e a saída foi manter
`table-cell` com float nos filhos. E a `<table>` continuava `display: table` sob
a classe `shop_table_responsive`, produzindo cartões de 473px numa tela de 375.

### Duas traduções que eram dado gravado, não string

O rótulo do Dokan saía como "Vendedor:" na linha de cada item — vocabulário que
esta plataforma aposentou. `reconectar_renomear_vendedor_no_carrinho()`, em
`woocommerce_get_item_data` prioridade 11, troca por "Loja:" depois que o
`dokan_product_seller_info` escreveu.

E as páginas se chamavam **Cart** e **Checkout**: o WooCommerce as cria ao
ativar, antes de o pacote de idioma estar de pé, e o título fica gravado como
dado — nenhuma tradução posterior o alcança. O sintoma aparecia longe da causa,
na trilha de navegação ("Início › Cart") de um site inteiramente em português. O
`provision.sh` passa a gravar "Carrinho" e "Finalizar compra", com guarda de
idempotência. O `post_name` não é tocado de propósito: `/cart/` e `/checkout/`
continuam valendo, e mudar a rota quebraria todo link já publicado.

### Medições, depois

| Elemento | Antes | Depois |
| --- | --- | --- |
| miniatura | 59×59 | 88×88 |
| nome do produto | `#31BEB1`, 2,30:1 | `rgb(113,113,113)`, peso 600, 16px |
| `.qty` | 58px, fundo `#f2f2f2` | 72×44, branco, borda 1px, raio 8px |
| `td.actions` | 331px em três linhas | 1187×77, cupom à esquerda e "Atualizar" à direita |
| `.cart_totals` | `float: right`, 629 de 1188 | cartão de 420px |
| `<h2>` / botão | 25,888px / 22,652px | 20px / 18px, da escala |
| altura da página | 1566 | 1508 |
| item em 375px | 318px de altura, cartão de 473px | 184px, cartão de 343px |
| botão remover em 375px | h=0, x=474 | 36×36 em x=314 |

Em 375px e em 1280×900: `scrollWidth - clientWidth === 0` e
`window.scrollTo( 600, 0 ); window.scrollX === 0`. Nenhum `font-size` literal
entrou — a seção inteira consome os degraus `--rc-fonte-*`.

Toda a investigação foi por medição de DOM, `getComputedStyle`, varredura do
CSSOM e `wp eval`. Os templates do carrinho e o CSS do WooCommerce e do
Storefront são de terceiros e gitignorados, e a autorização de leitura concedida
nesta série valia para um arquivo só. É também por isso que a correção é CSS
sobre o markup existente, e não override de template: o que não se lê, não se
copia.

---

## 2026-09-28 — Esteira de deploy no GitLab CI

> **Substituída no mesmo dia pela entrada seguinte.** O `.gitlab-ci.yml` descrito
> aqui não existe mais; a esteira é GitHub Actions. O registro fica porque as
> decisões técnicas abaixo — o recorte do `rsync`, a ausência de
> `docker-compose.prod.yml`, o `seed-demo.sh` — sobreviveram à troca de
> plataforma e são a razão de o desenho ser o que é.

**O que foi feito**
- Criado `.gitlab-ci.yml` com três estágios: `verificar` (lint de PHP e de
  shell, em toda branch e todo merge request), `implantar` (push na `main`) e
  `demonstracao` (carga e remoção dos dados fictícios, em jobs manuais).
- `docker-compose.yml`: `WORDPRESS_DEBUG` deixa de ser literal `"1"` e passa a
  `${WORDPRESS_DEBUG:-1}` — mesmo default para quem desenvolve, desligável pelo
  `.env` do servidor.
- Criado `docs/DEPLOY.md` com a preparação única do servidor, a tabela de
  variáveis CI/CD, o roteiro de verificação e a lista do que a esteira **não**
  faz.
- Duas armadilhas novas no `CLAUDE.md`.

**Decisões técnicas**

O código vai por `rsync` a partir do runner, e não por `git pull` no servidor: o
runner já tem o checkout e já tem a chave, então a instância não precisa de
nenhuma credencial do GitLab.

O recorte da sincronização é o ponto de risco da entrega, e o comentário que o
protege é a parte mais importante do arquivo. Só
`wp-content/themes/reconectar/` e `wp-content/plugins/reconectar-core/` são
versionados; o núcleo, o Storefront, os cinco plugins de terceiros e **todo** o
`uploads/` chegam pelo `provision.sh` no destino. Um `--delete` no nível de
`wp-content/` apagaria a instalação inteira, e os uploads não teriam origem
nenhuma para serem restaurados. Dentro de cada diretório versionado o `--delete`
é justamente o comportamento desejado.

Não há `docker-compose.prod.yml`. Listas de `ports` no Compose **concatenam** em
vez de substituir — um override declarando `80:80` deixaria a 8090 aberta
também, em silêncio. A porta vem do `.env` do servidor, e o phpMyAdmin fica fora
do ar porque o job nomeia os serviços no `up` (`db wordpress`).

A chave do host vai numa variável (`SSH_HOST_KEY`, colhida com `ssh-keyscan`), e
não se usa `StrictHostKeyChecking=no`: desligar a verificação faria a esteira
aceitar qualquer servidor que respondesse naquele endereço, numa sessão que
carrega chave privada de produção. E `SSH_CHAVE_PRIVADA` é *File* + *Protected*,
nunca *Masked* — o GitLab só mascara valores de linha única, e uma chave privada
tem várias.

O job de demonstração chama `seed-demo.sh`, não o `demo.php` direto: a guarda de
confirmação da remoção mora no script, e o caminho pelo PHP a contornaria. O
gesto humano que a pergunta pedia é, na esteira, o clique no job manual.

O lint de PHP conta os arquivos antes de conferi-los. Um `find` sobre caminho
renomeado não casa nada e sai com status 0 — o job passaria em verde tendo
conferido zero arquivo, que é o pior resultado possível.

**Pendências que dependem de ação humana**
- `chmod 400 dev.pem`; criar o projeto no GitLab e o remote (o atual é GitHub).
- Security Group liberando 80/tcp e 22/tcp; Docker e o plugin `compose` na
  instância.
- O `.env` do servidor, criado à mão **antes do primeiro `up`** — o
  `wp-config.php` nasce uma vez e não é reescrito depois. As regras de segurança
  do projeto bloqueiam qualquer ferramenta de tocar em `.env*`; o trecho foi
  entregue no chat e está em `docs/DEPLOY.md`.
- `WP_URL` tem de ser o DNS público: a allowlist de `Host` não conhece endereço
  da AWS, e com o default o site sobe carimbando todo asset com `localhost`.
- A esteira ainda não rodou uma vez — nada aqui foi verificado contra a
  instância. O roteiro de verificação está em `docs/DEPLOY.md`.

---

## 2026-09-28 — A esteira migra para GitHub Actions

**Por que**

O remote sempre foi GitHub (`reconectarplataforma/reconectar-plataforma`), e o
projeto no GitLab nunca chegou a ser criado. Manter a esteira na plataforma
errada custaria um espelhamento de repositório para sustentar um passo que a
plataforma de origem já faz sozinha.

**O que foi feito**
- `.gitlab-ci.yml` removido; entram `.github/workflows/verificar.yml`,
  `implantar.yml` e `demonstracao.yml`, mais a ação composta
  `.github/actions/preparar-ssh/`.
- `docs/DEPLOY.md` reescrito: a seção de variáveis CI/CD vira **Secrets e
  variables do repositório**, com a explicação do `environment: producao` no
  lugar da do *File/Masked/Protected*.
- Referências corrigidas em `CLAUDE.md` e `README.md`.

**O que a troca de plataforma impôs**

O GitLab não tem `concurrency`, e este é o ganho mais concreto da migração. Dois
pushes seguidos na `main` disparariam dois `rsync` no mesmo diretório do
servidor, e o segundo escreveria por cima de uma árvore que o primeiro ainda
está montando. `implantar` e `demonstracao` enfileiram
(`cancel-in-progress: false`); `verificar` cancela o anterior, porque uma
verificação de commit que já não é o topo não diz nada.

O GitHub não tem `extends`, que era como os dois jobs remotos compartilhavam o
`before_script`. O preparo do SSH virou ação composta em vez de bloco duplicado:
duas cópias divergem, e a que não recebe o ajuste passa a falhar por um motivo
que ninguém procuraria no arquivo certo.

Não existe variável tipo *File*. `SSH_CHAVE_PRIVADA` é secret comum e o conteúdo
é escrito com `printf '%s\n'`, nunca `echo` — o `echo` de alguns shells
interpreta escapes, e o base64 sairia corrompido com sintoma "invalid format",
que não parece corrupção de escrita.

Os valores chegam aos scripts por `env:`, nunca interpolados no corpo do `run:`.
Um `${{ }}` ali é substituição textual **antes** de o shell existir: um valor com
aspas ou `$(...)` viraria comando.

O *Protected* do GitLab vira duas coisas: o `environment: producao`, onde ficam
required reviewers e a restrição de branch, e o comportamento do próprio GitHub
de **não entregar secret a workflow disparado por fork**. Por isso a chave do
host e o DNS são *variables*, não secrets — a chave do host é pública por
definição, e mascarar o `SSH_HOST` quebraria a URL do environment e deixaria a
mensagem de erro de host mudado ilegível justamente quando ela importa.

A dedup de push/pull_request, que no GitLab era `workflow.rules`, virou um `if`
comparando o repositório de origem do PR. Consequência a lembrar se algum dia
houver required status checks: **job pulado por `if` fica *skipped*, e check
obrigatório não se satisfaz com skipped** — marque como obrigatório o workflow,
não estes jobs.

O `when: manual` virou `workflow_dispatch` com `type: choice`, o que é melhor:
`instalar` e `remover` saem de um só job em vez de dois, e a escolha fica
explícita na tela do disparo.

Uma linha a mais no lint de PHP: a imagem oficial `php:8.2-cli` é Debian slim e
não traz git. Sem ele o `actions/checkout` cai no download por API — que
funciona, mas em silêncio e por outro caminho.

**Pendências que dependem de ação humana**

As mesmas da entrada anterior, menos "criar o projeto no GitLab". No lugar dela:
cadastrar o secret `SSH_CHAVE_PRIVADA` e as quatro variables em Settings →
Secrets and variables → Actions, e criar o environment `producao` em Settings →
Environments se quiser revisor obrigatório antes do deploy.

Os quatro YAML foram validados (`yaml.safe_load` carrega os quatro). Nada rodou
contra a instância.
