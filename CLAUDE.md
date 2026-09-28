# Instruções para agentes — Plataforma Reconectar

Marketplace multi-vendedor em WordPress do projeto "Reconectar" (edital
FUNDEPES/UNOPS, "Nosso Chão Nossa História"). Cinco atores: Super Administrador
(`administrator`), Administrador (`company_admin`), Moderador de Conteúdo
(`content_moderator`), Loja (`seller`) e Usuário Comum (`customer`). Licença
GPL-2.0-or-later.

**Vocabulário.** A **empresa** é a Reconectar Incubadora Digital; cada negócio
que ela cadastra é uma **Loja**. No painel de empresas e no código autoral dele,
a entidade se chama loja (`Reconectar_Lojas`, `META_LOJA_ATIVA`,
`/painel-empresas/loja/`). "Vendedor" sobrevive onde o nome é do **Dokan** — o
papel `seller`, a capacidade `dokandar`, as metas `dokan_*` — e ali não se
traduz. `Reconectar_Permissoes::eh_vendedor()` e os dois métodos de isolamento
por vendedor ficam com o nome antigo de propósito: falam do papel, não da
entidade.

Antes de mexer em qualquer coisa, leia a seção **Armadilhas** abaixo. Ela não
é uma lista de boas práticas genéricas: cada item corresponde a um erro que já
aconteceu neste repositório, e vários custaram horas.

## Mapa

| Onde | O que é |
| --- | --- |
| `docker-compose.yml` | 5 serviços: `db`, `wordpress`, `wpcli`, `demo`, `phpmyadmin` |
| `scripts/provision.sh` | instala núcleo, idioma, tema, plugins, páginas, permalinks |
| `scripts/seed/dados-demo.php` | catálogo declarativo da demonstração (só dados) |
| `scripts/seed/demo.php` | motor da carga: cria e remove (só lógica) |
| `scripts/demo-completa.sh` | ambiente + provisionamento + carga, em um comando |
| `scripts/seed-demo.sh` | atalho para `instalar` / `remover` só a carga |
| `scripts/verificar-acessos.sh` | testa as travas de RBAC por HTTP, nos cinco perfis |
| `scripts/configurar-url-dinamica.php` | grava no `wp-config.php` o bloco que resolve a URL pelo `Host` |
| `scripts/normalizar-urls.php` | converte em caminho as URLs absolutas já gravadas no banco |
| `wp-content/themes/reconectar/` | tema autoral (child theme do Storefront) |
| `wp-content/plugins/reconectar-core/` | plugin autoral: RBAC, status, governança |
| `…/includes/class-reconectar-painel-empresas.php` | painel gerencial, rota própria fora do `/wp-admin` |
| `…/includes/painel-empresas/` | os templates das telas do painel |
| `…/includes/class-reconectar-migracoes.php` | migrações de dados versionadas (meta e capacidade) |
| `wp-content/themes/reconectar/bbpress/` | overrides de template do fórum |
| `wp-content/themes/reconectar/inc/forum/` | consultas, componentes e telas do Q&A |
| `docs/STACKS.md` | as camadas da plataforma e por que cada uma existe |
| `docs/PERFIS_E_PERMISSOES.md` | os cinco atores e a matriz de permissões |
| `docs/ROTEIRO_PERFIS.md` | roteiro de demonstração, com credenciais |
| `docs/DADOS_DEMONSTRACAO.md` | o que a carga cria, em detalhe |
| `DIARIO_DESENVOLVIMENTO.md` | histórico cronológico das entregas |

Plugins e temas de terceiros **não são versionados**. Só `wp-content/themes/reconectar/`
e `wp-content/plugins/reconectar-core/` entram no Git.

## Comandos

```bash
./scripts/demo-completa.sh
```

Sobe o ambiente, provisiona e popula. Idempotente: rodar de novo não duplica
nada. Site em http://localhost:8090, painel em `/wp-admin/`, phpMyAdmin em
http://localhost:8081.

```bash
docker compose run --rm demo
```

O mesmo, supondo os serviços já de pé.

```bash
./scripts/seed-demo.sh remover
```

Apaga só os dados de demonstração, preservando a instalação.

```bash
./scripts/verificar-acessos.sh
```

127 casos de permissão por HTTP, nos cinco perfis. Sai com status 1 se algum
falhar. **Rode depois de mexer em qualquer coisa de RBAC** — as travas não têm
teste automatizado além deste.

```bash
./scripts/permissoes-dev.sh
```

Dono e permissões de `wp-content/`, quando host e container brigam por um
arquivo. `--conferir` só relata.

```bash
docker compose --project-directory /caminho/absoluto/para/reconectar-plataforma run --rm --entrypoint php wpcli -l /var/www/scripts/seed/demo.php
```

Lint de PHP. Não existe atalho: veja a primeira armadilha.

## Armadilhas

### PHP não existe no host

Só dentro do container. `php -l` no host falha com exit 127. Todo lint passa
por `docker compose run --rm --entrypoint php wpcli -l <arquivo>`.

### `scripts/` não está montado no serviço `wordpress`

O bind-mount `./scripts:/var/www/scripts` existe apenas em `wpcli` e `demo`.
`docker compose exec wordpress php -l /var/www/scripts/...` falha por arquivo
inexistente — um erro que parece de sintaxe e não é.

### Nunca use `cd` relativo no Bash

O diretório de trabalho da sessão persiste entre chamadas. Use caminhos
absolutos e `--project-directory` no `docker compose`.

### `wc_get_orders()` descarta filtros em silêncio

Esta é a pior. A função reconhece uma lista fechada de argumentos e **ignora
sem aviso** o que está fora dela — `meta_key`, `meta_value` e também
`meta_query`, que parece suportado por ser o nome que a `WP_Query` usa.

O resultado de uma consulta "filtrada" por meta não é vazio: é o banco inteiro.
Com um `limit` pequeno, esse banco inteiro passa por uma resposta plausível.

Foi exatamente isso que fez a carga recriar pedidos a cada execução, e o mesmo
defeito estava no caminho de remoção — onde teria apagado **todos** os pedidos
da instalação, em definitivo, sem lixeira.

Argumentos que funcionam de verdade: `limit`, `status`, `return`, `customer`,
`parent`. Para filtrar por meta, traga o conjunto e filtre em PHP.

### O Dokan mantém tabelas paralelas

`wp_dokan_orders` e `wp_dokan_vendor_balance` guardam as vendas, e é delas que
o painel do vendedor lê o faturamento. Apagar o pedido no WooCommerce **não**
limpa essas linhas: o plugin as remove a partir dos seus próprios hooks de
estorno, não da exclusão definitiva. Linhas órfãs viram faturamento fantasma no
painel. Veja `reconectar_demo_limpar_tabelas_dokan()`.

### O Dokan descarta em silêncio meio de pagamento que ele não conhece

`Dashboard\Templates\Settings::insert_settings_info()` tem `bank` e `paypal`
escritos à mão no ramo do nonce `dokan_payment_settings_nonce`. Um
`$_POST['settings']['pix']` chega, não é lido, e a tela recarrega sem erro e sem
o dado — o sintoma é o campo voltar vazio depois de salvar.

O único gancho que alcança a gravação é `dokan_store_profile_settings_args`, e
ele dispara em **todos** os caminhos de salvamento do perfil. Por isso a injeção
do PIX é guardada por `wp_verify_nonce( …, 'dokan_payment_settings_nonce' )`:
sem essa guarda, salvar a loja em outra aba apagaria os dados de pagamento, sem
erro e sem aviso. Veja `Reconectar_Pagamento_Pix::gravar()`.

E a opção `dokan_withdraw['withdraw_methods']` precisa ligar `pix` e `bank` —
sem isso **toda loja vê "No withdraw method is available"**: a tela existe, está
vazia, e nada indica o porquê. O `provision.sh` a ajusta.

O formato é um **mapa**, não uma lista: medido, o valor de fábrica é
`{"paypal":"","bank":"bank"}` — chave desligada guarda string vazia, ligada
repete o próprio nome. Gravar `["pix","bank"]` não liga nada e ainda apaga o
`bank` que já estava de pé, com o mesmo sintoma mudo. O valor correto é
`{"paypal":"","bank":"bank","pix":"pix"}`.

### O bloco `woocommerce/checkout` não enxerga gateway clássico

Ele desenha **apenas** o que vier registrado em
`woocommerce_blocks_payment_method_type_registration` — uma classe
`AbstractPaymentMethodType` mais um script chamando `registerPaymentMethod` no
navegador. Um `WC_Payment_Gateway` que não faça isso some da tela, e some sem
erro: o comprador lê "Não há métodos de pagamento disponíveis", com o gateway
ligado e `is_available()` devolvendo `true` no servidor.

Medido, com o carrinho montado e a loja com chave PIX cadastrada:

```
paymentMethodSortOrder: ["reconectar_pix","reconectar_transferencia"]
paymentMethodData:      []
payment_methods:        ["reconectar_pix"]   (Store API do carrinho)
```

Conferir pelo servidor — `get_available_payment_gateways()`, `is_available()`,
a opção `enabled` — responde "está tudo certo" três vezes seguidas. Só o HTML da
página acusa.

O `provision.sh` converte `cart` e `checkout` aos shortcodes
`[woocommerce_cart]` / `[woocommerce_checkout]`. Não é só cosmético: `payment_fields()`
e `woocommerce_after_checkout_validation` **não rodam** no caminho de blocos, e
são eles que imprimem o aviso de qual loja não recebe e que recusam um pedido
impossível. A divisão em sub-pedidos do Dokan não entra nessa conta — ele pendura
`split_vendor_orders` também em `woocommerce_store_api_checkout_order_processed`,
e olhar só para `woocommerce_store_api_checkout_update_order_meta`, que está
vazio, leva à conclusão errada.

### Os passos do assistente do Dokan escutam `updated_option`, não `added_option`

O assistente de configuração (`Admin\OnboardingSetup\AdminSetupGuide`) põe a
faixa "Complete your marketplace setup in minutes" sobre as Configurações
enquanto houver etapa pendente, e cada etapa se marca sozinha ao ver a opção
dela mudar. As etapas `basic` e `commission` observam `dokan_selling`, que
**nasce ausente** — e opção ausente é criada por `add_option`, que dispara
`added_option`. O primeiro `update_option( 'dokan_selling', … )` grava o valor e
não marca etapa nenhuma; o segundo não dispara nada, porque o valor é igual.

E marcar as quatro etapas ainda não conclui o assistente: `is_setup_complete()`
lê uma opção **separada**, `dokan_admin_setup_guide_steps_completed`. Medido:
com as quatro em `1`, a faixa seguia na tela.

O caminho idempotente é gravar as cinco opções à mão, como faz o `provision.sh`.
E a comissão tem de ir a **zero** no mesmo bloco: o padrão que o assistente
propõe é 10% mais R$10, e aqui a plataforma não retém nada — aceitar o padrão
exibiria na dashboard de cada loja um desconto que ninguém cobra.

### bbPress e BuddyPress estão ativos

bbPress 2.6.18 e BuddyPress 14.5.2 são instalados e ativados pelo
`provision.sh`. O fórum de perguntas e respostas é bbPress com uma camada
autoral por cima (`Reconectar_Forum`, no plugin; `inc/forum/`, no tema; overrides
em `themes/reconectar/bbpress/`).

### O bbPress processa POST em `template_redirect` **prioridade 8**

`bbp_template_redirect` (`bbpress/includes/core/actions.php:50`) roda **antes**
da prioridade 10, onde está `bloquear_comunidade()`. E o handler de criação só
consulta a capacidade primitiva (`publish_topics`), que todo usuário tem por
causa do papel `bbp_participant` dado no registro. Quem barra a escrita é
`Reconectar_Permissoes::negar_escrita_no_forum()`, em `map_meta_cap` — não o
gate de leitura.

### `admin-post.php` mora dentro de `/wp-admin`

É a rota padrão de `<form method="post">` de front-end, e o portão
`bloquear_area_administrativa()` a interceptava. O sintoma não é erro: é 302
para `/my-account/` ou `/dashboard/` e a ação simplesmente não acontecer, numa
página plausível. Há exceção explícita para ela, irmã da de `wp_doing_ajax()`.
**Todo endpoint novo de front-end precisa dessa exceção ou de outra rota.**

### Qualquer aviso PHP impresso antes de um redirect cancela a ação

`WORDPRESS_DEBUG=1` liga `WP_DEBUG`, e o padrão de `WP_DEBUG_DISPLAY` é
imprimir no corpo da resposta. Uma linha impressa antes de `wp_redirect()`
derruba os dois `header()` de `pluggable.php:1539` e `:1542` com "headers
already sent" — e o efeito não é uma mensagem feia numa tela que funciona: **é
a ação não acontecer**. Salvar pergunta no fórum gravava o tópico e parava numa
tela de avisos, sem nunca chegar nele.

`provision.sh` fixa `WP_DEBUG_LOG=true` e `WP_DEBUG_DISPLAY=false`: o aviso
continua existindo, em `wp-content/debug.log`, e fora do corpo da resposta.
Ao conferir essas constantes, lembre que `wp config get` devolve o valor
**avaliado** (`1` e string vazia), nunca o literal que `--raw` gravou — e que
uma constante inexistente também devolve vazio, igual a `false`.

### A integração bbPress↔BuddyPress chama função removida na 12.0

`BBP_BuddyPress_Members::get_profile_url()`
(`bbpress/includes/extend/buddypress/members.php:232`) testa
`function_exists( 'bp_core_get_user_domain' )` **antes** de
`bp_members_get_user_url()`. No BuddyPress 12+ a primeira continua existindo
como casca depreciada, então o teste passa, o ramo antigo roda e o aviso sai —
junto com o defeito acima, já que `bbp_get_user_profile_url()` é chamado no
fluxo de criação de tópico.

`Reconectar_Forum::substituir_urls_de_perfil()` troca os seis filtros
`bbp_pre_get_user_*` por versões que usam o substituto oficial. Silenciar o
aviso resolveria a tela e deixaria a chamada obsoleta de pé, para quebrar de
novo quando o BuddyPress remover a casca.

### Ordenar por meta no `WP_Query`: as duas rotas óbvias falham

`meta_key` + `orderby => 'meta_value'` monta INNER JOIN e some com quem não tem
a meta. E `meta_query` com `relation => 'OR'` e ramo `NOT EXISTS` traz todos de
volta **estragando a ordem** — a condição sai do `ON` para o `WHERE`, o join
casa todas as metas do post e o `GROUP BY` ordena por uma qualquer. Medido: um
tópico com saldo 7 atrás de um sem voto. Use `posts_clauses` com `LEFT JOIN`
próprio e `COALESCE( …, 0 )`, como em `Reconectar_Forum::ordenar_por_votos()`.

### `bbp_new_topic`/`bbp_new_reply` só disparam pelo formulário do frontend

`bbp_insert_topic()` e `bbp_insert_reply()` não os acionam. Criação programática
precisa gravar as metas à mão — e recontar: `bbp_update_reply_walker()` só refaz
as contagens sob `bbp_deleted_reply` ou `save_post`, e em WP-CLI o resultado
seria `_bbp_reply_count` em zero com respostas na tela.

### O bbPress tem uma segunda camada de papéis, e ela **vence** a do WordPress

`bbp_keymaster`, `bbp_moderator`, `bbp_participant`, `bbp_spectator`,
`bbp_blocked`. São gravados como papel **adicional** do usuário e se sobrepõem às
capacidades de fórum declaradas no papel do WordPress.

Todo cadastro recebe `bbp_participant`. Foi por isso que um papel novo declarando
`edit_topics` e `delete_others_topics` respondia "não" às duas: a capacidade era
escrita no papel, aplicada pelo `sincronizar_capacidades()`, e perdida adiante —
o sintoma mais caro deste repositório. Quem resolve é
`Reconectar_Permissoes::aplicar_moderacao_no_forum()`.

O gancho é **`set_user_role`**, nunca `add_user_role`: `bbp_set_user_role()` troca
o papel com `WP_User::remove_role()` e `add_role()`, que disparam
`add_user_role` — o método chamaria a si mesmo.

### `bbp_map_forum_meta_caps()` reserva `edit_forums` ao `keep_gate`

Sob `bbp_map_meta_caps`, em `map_meta_cap` prioridade 10, o bbPress troca
`edit_forums` e `edit_others_forums` por `do_not_allow` para quem não é keymaster,
**independentemente do papel**. Medido, com o papel declarando as duas:

```
allcaps[edit_forums] = true
map_meta_cap( 'edit_forums' ) = do_not_allow
```

`publish_forums` **não** sofre isso — essa o bbPress mapeia para `moderate`. A
assimetria é dele. `Reconectar_Permissoes::restaurar_gestao_de_foruns()` devolve
as duas em **prioridade 11**, e só para quem o papel já tinha autorizado; a
leitura é em `$usuario->allcaps[$cap]` e não com `user_can()`, que reentraria em
`map_meta_cap` com a mesma capacidade e entraria em recursão infinita.

`keep_gate` continua fora de alcance de propósito: ele abre as Configurações do
bbPress e a ferramenta de **redefinição**, que apaga fóruns, tópicos e respostas
da instalação inteira.

### `promote_user` sem o papel de destino devolve `true`

`current_user_can( 'promote_user', $id )` responde "sim" sozinha. O filtro que
impede a escalada — `negar_gestao_de_usuarios_superiores()` — compara o papel de
**destino** com o do ator, e o destino é o **terceiro** argumento. Conferir sem
ele inverte a resposta e dá por segura uma trava que não foi consultada.

### `wp eval` com `wp_set_current_user()` lê capacidade otimista

O código do `eval` roda depois do `init`, então o papel dinâmico que o bbPress
aplica naquele gancho não está no usuário: capacidades de fórum respondem "SIM"
onde o navegador responde 403. Para medir de verdade, `wp --user=<login> eval …`.

### Nem toda negação do `/wp-admin` sai com 403

`plugins.php` morre em `wp-admin/includes/menu.php:384`, num `wp_die( …, 403 )`
explícito, porque `user_can_access_admin_page()` reprova a página inteira. Já
`theme-install.php` **passa** por esse portão — ele pendura em `themes.php` — e só
então bate na verificação do próprio arquivo (`theme-install.php:16`), um
`wp_die()` **sem argumento de status**; o padrão de `_default_wp_die_handler()` é
**500**. A tela diz a mesma coisa nos dois casos.

E `admin.php?page=wc-orders` devolve **301** para `edit.php?post_type=shop_order`
quando o HPOS está desligado — é ali que o 403 acontece. Um verificador que
espere 403 em toda negação acusa falha onde não há, e um que leia só o primeiro
código lê 301 como sucesso.

### O menu "Produtos" pode apontar para as avaliações

Para um papel com `moderate_comments` e sem `edit_products`, o item de menu
**Produtos** existe e resolve para `admin.php?page=product-reviews` — as
avaliações, que o WooCommerce registra sob aquele menu. O catálogo
(`edit.php?post_type=product`) responde 403 e nenhum item leva até lá. Quem vir o
rótulo na lateral e concluir que o perfil administra o catálogo terá lido o
rótulo, não o destino.

### Em single-site, `edit_users` alcança o `administrator`

Não existe "editar usuário abaixo de mim" no núcleo. Quem tem `edit_users` pode
abrir a ficha de um `administrator`, **trocar a senha dele** e entrar com a
conta; com `promote_users`, pode promover a si mesmo. As duas juntas transformam
o Administrador restrito em Super Administrador com dois cliques, e a proibição
de instalar plugin vira decoração.

`Reconectar_Permissoes::negar_gestao_de_usuarios_superiores()` barra as duas
rotas em `map_meta_cap`. Ao conferir, lembre que `promote_user` **exige o papel
de destino como terceiro argumento** — a armadilha registrada acima.

### `negar_escrita_ao_admin_de_empresas()` monitora `edit_post` genérico

O filtro vigia `edit_post`, `delete_post` e `publish_post` para qualquer post, e
a exceção é uma **allowlist de post types**. Quando o papel ganhou capacidade de
conteúdo, o filtro passou a negar a edição de post e de página sem uma linha de
mudança: o código estava no lugar certo, correto para o que fora escrito, e
impedia em silêncio o que a especificação nova pedia.

Ao dar capacidade de escrita nova a um desses papéis, acrescente o post type à
allowlist — ou a capacidade é concedida, sincronizada e perdida adiante. Produto
e pedido ficam de fora de propósito: quem administra o produto é a loja dona
dele.

### `reconectar_demo_remover()` lista post types explicitamente

Um tipo novo na carga **não sai sozinho**. E o filtro é sempre
`RECONECTAR_DEMO_META`: `get_posts()` por `post_type => 'topic'` sem a meta
devolve o fórum inteiro da instalação — a forma bbPress do defeito do
`wc_get_orders()`.

### `wp_delete_term()` com a taxonomia errada devolve `false` em silêncio

A carga cria termos em `product_cat` e em `topic-tag`. A remoção busca a
taxonomia no `term_taxonomy` em vez de assumir uma; sem isso, sobrevivem tags
que levam a listas vazias.

### O `functions.php` do filho carrega **antes** do pai

`wp-settings.php` inclui `STYLESHEETPATH` primeiro. Um `remove_action` escrito no
corpo do `functions.php` do tema filho — ou de qualquer arquivo que ele inclua —
tenta remover um gancho que o pai ainda não registrou: devolve `false`, nada
acontece, e o sintoma é o pior de todos, porque o código está no lugar certo e
não faz nada. Ganchos do Storefront se removem em `after_setup_theme`, prioridade
20. Veja `reconectar_reposicionar_paginacao_do_catalogo()`.

### As duas `.storefront-sorting` do catálogo são ganchos simétricos

`storefront-woocommerce-template-hooks.php:45-55` monta a mesma sequência duas
vezes — wrapper em 9, ordenação em 10, contagem em 20, paginação em 30,
fechamento em 31 —, uma em `woocommerce_before_shop_loop` e outra em
`woocommerce_after_shop_loop`. Mexer em uma e esquecer a outra deixa a página
meio corrigida. E a paginação herda o `float: right` da barra: para centralizá-la
não basta CSS, é preciso tirá-la de dentro (prioridade > 31).

### `woocommerce_show_page_title` **apaga** o `<h1>`, não o esconde

O filtro decide se `loop/header.php` imprime o cabeçalho. Filtrar para `false`
tira o elemento do DOM, e quem navega por cabeçalhos fica sem saber em que página
está. Para sumir da tela e ficar para o leitor de tela, o caminho é classe no
`<body>` mais a técnica de `.screen-reader-text` — veja
`reconectar_marcar_pagina_sem_titulo()` e `.rc-sem-titulo-de-pagina`.

### O Storefront veste campos de formulário por seletor de atributo

`input[type="text"], … input[type="search"], …` tem especificidade **(0,1,1)** e
vence uma classe sozinha (0,1,0). Uma regra autoral que declare `background` ou
`border` num campo é escrita, aplicada e perdida na cascata — medido: o campo de
busca do fórum saía em `#f2f2f2` ao lado de um select branco, no mesmo
formulário. Repita a classe (`.rc-forum__busca.rc-forum__busca`) para chegar a
(0,2,0).

O pai também declara `select { color: initial; font-family: "Source Sans Pro", … }`,
o que tira a Poppins herdada do corpo. Todo `<select>` do projeto precisa de
`font-family: inherit` explícito.

### CSS de plugin não herda o reset do tema — `box-sizing` inclusive

O Storefront aplica `box-sizing: border-box` a `*`, e por causa disso um
`width: 100%` num campo de formulário **parecia** funcionar dentro do painel de
empresas, que é plugin. Sem o tema, o padding e a borda voltam a somar por fora:
medido em 375px, o campo saía 398px numa coluna de 375. O painel não pode exigir
tema nenhum — é a mesma razão pela qual os tokens `--rc-pe-*` trazem fallback
literal. Declare `box-sizing` no próprio componente.

### Vários cartões `rc-` **são** o `<a>`, não o contêm

`reconectar_card_categoria()` imprime `<a class="rc-card-categoria">` com dois
`<span>` dentro. A regra `.rc-card-categoria a` não casava com nada: era escrita,
ignorada, e o nome da categoria caía no link padrão do tema, `#31BEB1` — medido,
**2,30:1** sobre o branco, reprovando o critério 1.4.3 da WCAG 2.1 em toda a faixa
da home. É de novo o sintoma pior: o código está no lugar certo e não faz nada.
Antes de escrever regra descendente em componente `rc-`, confira o markup.

### O "Ver todos" de um carrossel aponta para a listagem **do mesmo tipo**

Já errou duas vezes na home. Em lojas em destaque,
`reconectar_url_base_da_vitrine()` devolvia a própria página; em categorias,
`reconectar_url_loja()` levava ao catálogo de produtos. Nos dois casos o link
existia, era clicável e abria uma página plausível. Os destinos são
`reconectar_url_loja()` para produtos, `reconectar_url_das_lojas()` para lojas e
`reconectar_url_das_categorias()` para categorias — esta última resolvida pelo
**slug** da página `categorias`, que o `provision.sh` cria.

### `get_page_by_path()` devolve rascunho e lixeira

Sem conferir `post_status`, um helper de URL entrega o permalink de uma página
despublicada — link válido que leva ao 404 para quem não está logado.

### Clearfix do tema pai vira **grid item**

Ao pôr `display: grid` num contêiner que o Storefront limpava com
`::before`/`::after`, os pseudo-elementos deixam de ser invisíveis: `content`
gera caixa, e caixa dentro de grade ocupa célula. Em `ul.products` isso dava
quatro colunas declaradas e três cartões por linha, com o primeiro começando na
célula 2. `content: none` apaga a caixa; `display: none` deixaria o item na
contagem. Vale para qualquer contêiner do pai que vire grade.

### `max-width` do tema pai vence `width` autoral — e a cascata não acusa

Regra escrita, regra aplicada, tela igual. O `woocommerce.css` declara
`table.cart .product-thumbnail img { max-width: 3.70633em }` (59,3px) e
`table.cart .qty { max-width: 3.632em }` (58,1px). Uma regra autoral de `width`
**ganha a cascata** — o DevTools mostra a nossa vencendo, e nada muda, porque o
teto veio de **outra propriedade**, que ninguém disputou.

É o sintoma mais caro deste repositório: código no lugar certo que não faz nada.
E a investigação vai para a especificidade, que não é o problema. Ao vestir
elemento que o WooCommerce ou o Storefront já dimensionam, declare `max-width`
ao lado de `width` — ou varra o CSSOM antes, atrás de `max-width`, `min-width` e
`flex-basis`, não só da propriedade que você está escrevendo.

### `<td>` em `display: flex` perde o `colspan`

A célula deixa de ser célula, e o papel vai embora junto com a atribuição de
largura. Medido: `td.actions[colspan="6"]` caiu de 1187px para 331px e empilhou
em três linhas. Pôr a `<tr>` em `display: block` **não** resolve — a célula
anônima resultante continua dimensionada pelo algoritmo de tabela, e o valor
seguiu 331. A saída é manter a célula em `table-cell` e usar float nos filhos:
`table-cell` já estabelece um bloco de formatação, então contém os floats sem
clearfix.

### `shop_table_responsive` não tira o `display: table` da `<table>`

A classe põe as `<td>` em bloco e esconde o `<thead>`, e é fácil concluir que a
tabela virou lista. O elemento continua tabela, e tabela dimensiona pela largura
mínima do conteúdo, **ignorando o contêiner**: medido no carrinho, cartões de
473px numa tela de 375 — transbordo horizontal, que é o que faz a barra inferior
sumir no celular. Só `table`, `tbody` e `tr` em `display: block` resolvem.

E `a.remove` do WooCommerce **já nasce `position: absolute`**. Tirar
`td.product-remove` do fluxo sem devolver o link ao fluxo faz ele se posicionar
contra a própria célula, que passou a ter 6px: medido, o "×" foi parar em x=474
numa tela de 375. `.remove { position: static }` dentro do `td` absoluto.

### Em `flex-wrap: wrap`, quem decide a quebra é a **base**, não o encolhimento

`flex-shrink: 1` e `min-width: 0` no item certo não impedem a quebra de linha:
quando o navegador decide quantos itens cabem, ele soma as `flex-basis`, e só
depois flexiona o que sobrou na linha. Um item com base `auto` entra nessa conta
com a largura do próprio conteúdo.

Medido no cabeçalho do celular: `.rc-cabecalho__acoes` com base `auto` valia
285px de conteúdo; somados aos 145px da marca e aos 16px de gap, davam 446
contra 343 úteis, e a **marca** ia para uma linha só dela. O município, que tem
`min-width: 0` e reticências justamente para absorver a falta de espaço, nunca
chegava a encolher. `flex: 1 1 0` resolve — e exige `justify-content: flex-end`
junto, porque o item passa a ocupar toda a sobra e um `margin-left: auto` fica
sem espaço livre para empurrar.

### Esconder do olho e manter no leitor: as duas técnicas não são intercambiáveis

`position: absolute` é a técnica padrão do WordPress e tem a armadilha logo
abaixo: dentro de contêiner estático, o elemento sobe até o viewport. A variante
no fluxo (`width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%)`,
sem `position`) evita isso, e tem o preço oposto — como `inline-block`, ela gera
line box com a altura do strut do bloco. Medido: o gatilho do município ficava
com 61px de altura para exibir uma linha de texto.

Dentro de uma linha flex, a variante no fluxo não custa nada (é o caso de
`.rc-carrinho__unidade`). Num bloco de texto, só o absoluto zera a altura — e aí
confira que existe ancestral posicionado.

### `overflow-x: auto` não clipa absoluto solto — e foi assim que a barra sumiu

Um scroll container só clipa descendente **cujo containing block seja ele**. Se
o elemento é `position: static`, um descendente `position: absolute` sobe até o
primeiro ancestral posicionado — e, não havendo nenhum, até o **viewport**. Ele
passa a ocupar coordenada de documento, fora do alcance do `overflow` que
deveria contê-lo.

Foi o que aconteceu em `.rc-carrossel__faixa`. Os cards trazem
`span.screen-reader-text`, que o WordPress declara `position: absolute`: medido,
um deles ficava em **x=1645 numa tela de 375**, e a área de rolagem do documento
ia a exatamente esses 1645px. Nada aparece fora de lugar — os spans têm 1px e
`clip-path: inset(50%)` —, o carrossel rola certo, e a página parece correta.

O preço aparece longe da causa: a **barra inferior some no celular**. Com o
documento rolando na horizontal sob o `overflow-x: hidden` que o Storefront
propaga do `<body>` para o viewport, `position: fixed` deixa de acompanhar a
tela. Sintoma seletivo por página — só onde há carrossel —, o que manda a
investigação para o componente errado: gastou-se uma rodada inteira
reposicionando a barra no `footer.php`, que não era o defeito.

O diagnóstico que fecha é de uma linha:

```js
window.scrollTo( 600, 0 ); window.scrollX   // 0 = são; > 0 = documento rola na horizontal
```

`document.documentElement.scrollWidth` maior que o `clientWidth` também acusa, e
`body.scrollWidth` **não** — o `hidden` do corpo mascara. Todo scroll container
horizontal do projeto declara `position: relative` por isso.

### Renomear meta ou capacidade exige migração versionada

Uma chave de meta ou capacidade renomeada **não migra sozinha**: o valor
continua gravado no banco sob o nome velho, e o código novo lê vazio. O sintoma
não é erro — é dado que some. `user_can()` com capacidade inexistente devolve
`false` em silêncio, e a loja desativada volta a parecer ativa.

`Reconectar_Migracoes` (`init`, prioridade **5** — antes das capacidades, que
rodam em 10) guarda esses passos, com opção própria `reconectar_migracoes_versao`
no molde de `Reconectar_Permissoes::VERSAO_CAPACIDADES`. Duas assimetrias que
custam tempo se descobertas depois:

- **`remove_role()`/`add_role()` cobre o `company_admin`, não o `administrator`.**
  `sincronizar_papel_do_admin_de_empresas()` recria o papel do zero, então a
  capacidade velha sai junto. O `administrator` recebe as dele por `add_cap()` e
  **só perde o que for explicitamente removido** — daí a lista `CAPS_LEGADAS` e
  o `remove_cap` sobre todos os papéis, que fica lá permanentemente: é o que faz
  uma instalação provisionada há meses migrar sozinha.
- **`UPDATE` direto em `meta_key` não invalida o cache.** O WordPress guarda a
  usermeta em cache de objeto, e a leitura seguinte entrega o estado antigo por
  toda a requisição. Colha os `user_id` **antes** do `UPDATE` — depois dele a
  consulta pela chave velha volta vazia — e chame `clean_user_cache()` em cada
  um. Antes do `UPDATE`, apague as linhas legadas de quem já tem a chave nova:
  sem isso a renomeação cria duplicata, e `get_user_meta( …, true )` passa a
  devolver uma das duas arbitrariamente.

### O diretório do plugin é `includes/`, não `inc/`

`inc/` é a convenção do **tema**. Confundir os dois leva a criar arquivo em
lugar que nada carrega.

### `set_date_created()`, não `set_created_date()`

O segundo não existe e o erro só aparece em tempo de execução, como fatal.

### `--ssl=0` só vale em `wp db *`

Qualquer outro comando do WP-CLI rejeita a flag.

### `wp config set` não enxerga constante cujo valor é expressão

O `wp-config-transformer`, por baixo do comando, reconhece literais. Uma linha
como `define( 'WP_SITEURL', 'http://' . RECONECTAR_HOST );` é **invisível** para
ele: medido, `wp config delete WP_SITEURL --type=constant` responde "is not
defined" com a linha bem ali no arquivo.

O sintoma não aparece na primeira execução, e é por isso que custa caro. Como o
comando nunca encontra a definição anterior, ele **acrescenta outra** a cada
provisionamento. Depois de duas rodadas o PHP começa a avisar "Constant already
defined" — e aviso impresso antes de um redirect cancela a ação, a armadilha
registrada mais acima. Um provisionamento idempotente passa a quebrar o site
devagar, uma linha por execução.

Constante com valor de expressão se escreve por bloco delimitado, no molde do
`# BEGIN WordPress` do `.htaccess`: reescrever é apagar o trecho entre os
marcadores e pôr o novo, sem depender de localizar cada `define`. Veja
`scripts/configurar-url-dinamica.php`.

### URL gravada no banco não acompanha `WP_HOME` dinâmico

`WP_HOME` e `WP_SITEURL` saem do `Host` da requisição, para que a mesma
instalação atenda `localhost:8090` e o IP da máquina na rede. Isso resolve tudo
que o WordPress monta na hora — asset, permalink, miniatura — e **não alcança o
que já está gravado como texto**: o `_menu_item_url` dos itens `custom` do menu
e o HTML dos widgets `custom_html`. Um item nascido com `http://localhost:8090/`
manda o celular para o próprio celular. Os itens `add-post` são imunes: guardam
o ID e resolvem o permalink na hora.

Vale também para **cache**. Qualquer transient que guarde URL absoluta precisa
do host na chave — sem isso o valor gravado num acesso serve links do outro, e o
sintoma é dos piores de diagnosticar: intermitente, porque some sozinho quando o
transient expira. Veja `reconectar_lojas_*` e `reconectar_sugestoes_*`.

Escrever caminho relativo resolve daqui para frente; a instalação antiga passa
pelas guardas de idempotência do `provision.sh` — menu já atribuído, coluna de
rodapé já preenchida — e **nunca seria reescrita**. Quem a alcança é
`scripts/normalizar-urls.php`.

### O filtro de `Host` é uma allowlist, não o cabeçalho cru

Cabeçalho de requisição é dado do cliente. A plataforma **envia link de
definição de senha** no cadastro de loja (`network_site_url()`, em
`class-reconectar-lojas.php`), e um `Host` forjado sairia dentro desse link.
Passam `localhost`, o loopback e os três blocos privados — o alcance de um
ambiente de desenvolvimento em rede local, e nada além.

Ao conferir, lembre que um `Host` desconhecido **sem porta** ainda recebe 301
para ele mesmo: é `redirect_canonical()` do núcleo, que monta a URL requisitada
a partir de `$_SERVER['HTTP_HOST']` independentemente de `WP_HOME`. Não é a
allowlist falhando — o corpo da resposta não reflete nada. O teste que vale é
contar quantas URLs do HTML trazem o host forjado: tem de ser zero.

### UID divergente entre as imagens

A imagem CLI é Alpine (`www-data` = 82); a Apache é Debian (`www-data` = 33), e
é ela que cria os arquivos no volume. Por isso `wpcli` e `demo` declaram
`user: "33:33"`. Quando a escrita falhar mesmo assim:

```bash
./scripts/permissoes-dev.sh
```

Ele acerta dono, grupo e setgid em `wp-content/` inteiro — não faça o `chown` à
mão. `--conferir` relata sem alterar nada.

### Blocksy está instalado e **inativo**

A migração nunca aconteceu, e o tema ativo continua sendo o `reconectar`. Não
trate a presença do Blocksy como decisão tomada. Há um `docker/backup-pre-blocksy.sql`.

### `.env` é bloqueado por regra de segurança

Não leia nem escreva `.env*`. Se precisar de uma variável nova, entregue o
trecho no chat para o usuário criar à mão. O `.env.example` documenta as chaves.

### A porta é 8090

A 8080 estava ocupada na máquina de desenvolvimento. Documentação que diga 8080
está desatualizada.

### O deploy sincroniza **dois** diretórios de `wp-content/`, nunca a pasta

`git ls-files wp-content/` devolve exatamente `plugins/reconectar-core/` e
`themes/reconectar/`. O núcleo do WordPress, o Storefront, o WooCommerce, o
Dokan, o bbPress, o BuddyPress e **todo** o `uploads/` não estão no Git — chegam
pelo `provision.sh` no destino.

Um `rsync --delete` no nível de `wp-content/` apaga a instalação inteira do
servidor. Os plugins e o tema pai voltariam num provisionamento; os **uploads
não têm origem nenhuma** para serem restaurados. Dentro de cada diretório
versionado, o `--delete` é o que se quer: arquivo removido do tema tem de sumir
de lá.

`.github/workflows/implantar.yml` traz esse recorte com um comentário longo por
cima. É o tipo de bloco que alguém "simplifica" para uma linha só seis meses
depois — e o estrago não aparece no job, que termina em verde.

E o `rsync` precisa de `--rsync-path="sudo rsync"`: os arquivos do bind-mount
pertencem ao UID 33 e o `ec2-user` é 1000, a mesma assimetria registrada acima.

### Fora do `localhost`, o site depende de `WP_URL` apontar para o host público

A allowlist de `Host` (`configurar-url-dinamica.php:82`) só aceita `localhost`,
o loopback e os três blocos privados. Um DNS público — da AWS ou de onde for —
não casa e cai em `RECONECTAR_HOST_PADRAO`, que o `provision.sh:96` deriva de
`WP_URL`.

Com o default `http://localhost:8090`, o servidor sobe funcionando e carimba
**todo** asset e todo link com `localhost`. O sintoma não é erro: é a página
crua, sem CSS, para quem acessa de fora — enquanto para quem faz um túnel SSH
tudo parece certo.

E o `wp-config.php` nasce na primeira subida e não é reescrito quando as
variáveis mudam depois. `WP_URL`, senha de banco e porta têm de estar certas
**antes** do primeiro `up`; consertar depois é derrubar o volume, o que apaga o
banco.

## Convenções

**Idioma.** Todo código autoral é escrito em português: nomes de função,
variáveis, comentários e documentação. A exceção é o que a API do WordPress
impõe (nomes de hook, chaves de meta com prefixo, parâmetros de terceiros).

**Comentários explicam o porquê, nunca o quê.** Um comentário que descreve o
que a linha faz é ruído; um que registra a razão de ela ser assim — a armadilha
evitada, a alternativa descartada, o defeito que já aconteceu — é o que
sobrevive à próxima leitura. Vários comentários deste repositório são o único
registro de um bug real; não os remova ao refatorar.

**PHPDoc em toda função.** Uma regressão já apagou três blocos por descuido:
revise o diff antes de commitar.

**Prefixo `reconectar_`** em funções globais, `RECONECTAR_` em constantes,
`rc-` em classes CSS do marketplace.

**Idempotência.** Todo script de carga ou provisionamento roda mais de uma vez
sem duplicar nada. Isso é requisito, não cortesia.

**Honestidade de dados.** Tempo de entrega, taxa e distância vêm de metas
gravadas. Nunca calcule um número plausível para preencher a interface: um
valor inventado que parece certo é pior que um campo vazio.

**Acessibilidade.** WCAG 2.1 é requisito do edital, junto de LGPD, GDI e GPL.
Sem `aria-pressed` em `<a>` (use `aria-current`), sem `<details open>` que
abriria menu no celular, contraste conferido.

**Sem build, sem CDN.** Bootstrap 5.3.3 auto-hospedado em `assets/bootstrap/`.
Não introduza etapa de compilação.

## Cores e tipografia

Declaradas como custom properties em `wp-content/themes/reconectar/style.css`:

| Variável | Valor |
| --- | --- |
| `--reconectar-cor-primaria` | `#31BEB1` |
| `--reconectar-cor-secundaria` | `#CF6442` |
| `--reconectar-cor-destaque` | `#F1BF3D` |
| `--reconectar-cor-institucional` | `#663191` |

Poppins na interface. Thoge **apenas** em `.site-title`.

**Nunca escreva um `font-size` literal em componente `rc-`.** A escala está no
`:root` de `assets/css/marketplace.css`, em nove degraus de razão 1,125 sobre os
16px do documento:

| Token | Valor | Papel |
| --- | --- | --- |
| `--rc-fonte-2xs` | 12px | selo, badge — **piso**, nada desce daqui |
| `--rc-fonte-xs` | 13px | rótulo auxiliar |
| `--rc-fonte-sm` | 14px | meta, apoio |
| `--rc-fonte-base` | 16px | corpo, nome de item |
| `--rc-fonte-md` | 18px | destaque |
| `--rc-fonte-lg` | 20px | subtítulo |
| `--rc-fonte-xl` | 24px | título de seção |
| `--rc-fonte-2xl` | 28px | — |
| `--rc-fonte-3xl` | 36px | título de página |

Mais `--rc-entrelinha-justa` (1.25) e `--rc-entrelinha-compacta` (1.4), para
rótulo e título: sem elas o Storefront entrega 1,618 a tudo, e um rótulo de uma
palavra vira ar. Antes da escala eram 16 valores avulsos entre 0.65rem e 2rem —
o menor deles rendia selo de 10,4px dentro de linha de produto de 122px.

A base do `html` fica em 16px de propósito: subi-la mexeria em carrinho,
checkout e painel do vendedor, que são Woo e Dokan. O painel de empresas, por
ser plugin e não poder exigir o tema, consome os degraus com fallback literal
(`var( --rc-fonte-sm, 0.875rem )`).

## Antes de dar algo por pronto

Rode o fluxo completo e leia a saída. A idempotência da carga só foi
comprovada quando a segunda execução imprimiu "já existia" em todas as linhas —
e foi a primeira execução real que revelou o bug do `wc_get_orders()`. Ler o
código não teria encontrado; rodar encontrou.
