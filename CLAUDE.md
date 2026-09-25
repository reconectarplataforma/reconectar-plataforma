# Instruções para agentes — Plataforma Reconectar

Marketplace multi-vendedor em WordPress do projeto "Reconectar" (edital
FUNDEPES/UNOPS, "Nosso Chão Nossa História"). Quatro atores: Administrador,
Administrador de Empresas, Vendedor e Usuário Comum. Licença GPL-2.0-or-later.

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
| `scripts/verificar-acessos.sh` | testa as travas de RBAC por HTTP, nos quatro perfis |
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
| `docs/PERFIS_E_PERMISSOES.md` | os quatro atores e a matriz de permissões |
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

59 casos de permissão por HTTP, nos quatro perfis. Sai com status 1 se algum
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
