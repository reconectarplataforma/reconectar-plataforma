---
paths:
  - "wp-content/themes/reconectar/**"
---

# Armadilhas — tema, CSS e telas do WooCommerce

Continuação da seção **Armadilhas** do `CLAUDE.md` da raiz, carregada só quando
a sessão mexe nos caminhos acima. Quando um item fala em armadilha "registrada
acima" ou "adiante", ela pode estar na raiz ou em outro arquivo de `.claude/rules/`.

## `order` no CSS inverte a pintura, não o foco

Trocar a ordem visual de dois blocos com `order` numa grade ou num flex deixa a
ordem de **tabulação** e a de leitura do leitor de tela como estavam no DOM. Para
dois cartões pequenos isso é tolerável; para os dois blocos do checkout — o
pedido inteiro e os dados de cobrança — significa tabular por trinta campos na
ordem contrária à que se lê, o que reprova o critério 2.4.3 da WCAG 2.1.

A inversão pedida no checkout foi feita movendo o gancho: `reconectar_checkout_pedido()`
saiu de `woocommerce_checkout_order_review` para
`woocommerce_checkout_before_customer_details`. O fragmento continua funcionando
— o seletor `.rc-checkout__pedido` não depende de posição.

## O gancho `wp` não alcança `?wc-ajax=`

`?wc-ajax=` entra por um caminho curto do WooCommerce, sem `wp`, sem
`template_redirect` e sem `wp_loaded` completo. Um `remove_action` escrito em
`wp` — a escolha natural, porque cobre o carregamento da página — **não roda** na
requisição AJAX, e o fragmento volta a trazer o que o carregamento inicial havia
removido.

O sintoma chega depois e é mudo: a tela nasce correta e ganha a cópia indevida no
primeiro recálculo — trocar meio de pagamento, aplicar cupom, sair do CEP. Quem
investigar o carregamento não encontra nada.

O WooCommerce oferece **duas** rotas para a mesma ação, e as duas precisam do
gancho: `wc_ajax_<ação>` e `wp_ajax_woocommerce_<ação>` / `wp_ajax_nopriv_…`. Veja
`reconectar_checkout_tirar_privacidade()`.

## `wc_cart_totals_subtotal_html()` já devolve o subtotal **promocional**

Com produto em promoção no carrinho, a função imprime a soma dos preços
**vigentes**, não a dos preços cheios. Uma linha "Produtos (N)" alimentada por
ela, seguida de uma linha "Desconto do produto", produz três números que não
fecham — medido, antes da correção:

```
Produtos (4)           R$ 283,90
Desconto do produto    -R$ 4,10
Você pagará            R$ 283,90
```

Numa tela de pagamento isso é pior que a linha de desconto não existir: o
comprador não tem como saber qual dos três está certo. A soma dos preços cheios
é `WC_Cart::get_subtotal()` mais o desconto de promoção apurado, e o imposto do
subtotal entra se `display_prices_including_tax()` disser que sim — hoje
irrelevante nesta instalação (`woocommerce_calc_taxes = no`), que é exatamente a
razão de a divergência não aparecer em teste. Veja
`reconectar_checkout_subtotal_cheio_html()`.

## O filtro de fragmentos do checkout aceita **qualquer** seletor

`WC_AJAX::update_order_review()` devolve o array de
`woocommerce_update_order_review_fragments` e o `checkout.js` do WooCommerce faz
`$( chave ).replaceWith( valor )` em cada par — a chave é um seletor livre, não
uma lista fechada. É por isso que o resumo autoral (`.rc-checkout__resumo`) pode
morar **fora** do `#order_review`, na outra coluna da grade, e ainda acompanhar
cupom e recálculo de frete sem recarregar a página. Medido: cupom de R$ 10 sobre
R$ 246,00 levou o resumo a R$ 236,00 na mesma requisição AJAX.

A contrapartida é a armadilha. Um bloco de totais **sem** fragmento registrado
não dá erro nenhum: ele fica no ar com o valor de antes do cupom, e o comprador
lê um total plausível e errado numa tela de pagamento — o pior lugar do projeto
para isso acontecer. Quem escrever linha de total nova fora do `#order_review`
registra o seletor no filtro **no mesmo commit**.

E o botão pode sair do `#payment` junto: o `checkout.js` liga em `submit` do
`form.checkout`, e o `<form>` serializa todo campo que esteja dentro dele
independentemente da posição visual. O nonce, o `_wp_http_referer` e o bloco de
termos seguem em `.place-order`, dentro do fragmento de pagamento; só o
`<button>` muda de lugar, com `woocommerce_order_button_html` devolvendo string
vazia. Medido: pedido criado normalmente com o botão na coluna da direita.

## As funções de total do WooCommerce **imprimem**, e uma delas devolve `<tr>`

`wc_cart_totals_subtotal_html()`, `wc_cart_totals_coupon_html()`,
`wc_cart_totals_fee_html()`, `wc_cart_totals_order_total_html()` e
`wc_cart_totals_shipping_html()` (`includes/wc-cart-functions.php`) dão `echo` e
devolvem `null`. Usá-las como valor — `'<dd>' . wc_cart_totals_subtotal_html() . '</dd>'`
— imprime o número **antes** do `<dd>`, fora de ordem. Ou se captura com
`ob_start()`/`ob_get_clean()`, ou não se monta markup em volta delas. As duas
exceções que **devolvem** string são `wc_cart_totals_coupon_label( $cupom, false )`
e `wc_cart_totals_shipping_method_label( $rate )`.

Duas assimetrias que custam tempo:

- **`wc_cart_totals_coupon_label()` já traz o prefixo.** Ela devolve
  "Cupom: &lt;código&gt;", com o rótulo traduzido pelo filtro
  `woocommerce_cart_totals_coupon_label`. Envolvê-la num
  `sprintf( 'Cupom %s', … )` sai na tela como **"Cupom Cupom: teste-resumo"**.
- **`wc_cart_totals_shipping_html()` produz markup de tabela.** Ela imprime
  `<tr class="shipping">…</tr>`, porque no template original o `tfoot` é de uma
  `<table>`. Dentro de um `<dl>` o navegador **expulsa** a `<tr>` para fora do
  elemento pai — o valor do frete aparece solto abaixo do card, e o CSS do card
  não o alcança. Para lista de definição, monte a linha à mão com
  `wc_cart_totals_shipping_method_label( $rate )`.

## `.site { overflow-x: hidden }` do Storefront mata todo `position: sticky`

Irmã da armadilha do carrossel, e mais silenciosa. Declarar `overflow` em um eixo
faz o outro computar `auto`: o elemento vira **scroll container**. `position: sticky`
adere ao scrollport do ancestral rolável mais próximo — e um scrollport que não
rola nunca desloca o elemento. O computed style segue dizendo `position: sticky`,
o `top` está lá, o DevTools não acusa nada, e o card simplesmente sobe com a
página.

Medido no `#page` do checkout, rolando de 400 em 400 a posição do card:

| | y a cada rolagem |
| --- | --- |
| com `overflow-x: hidden` | `530 → 130 → -370 → -1070` (linear: não adere) |
| com `overflow-x: clip` | `530 → 130 → 16 → 16 → 16` |

`clip` recorta igual e **não** cria scroll container. A correção fica escopada à
página que precisa dela, nunca global: o `hidden` do Storefront é o que segura o
transbordo horizontal no celular, e trocá-lo em todo lugar reabre aquele defeito.

## O `address-i18n.js` reordena as `.form-row` no init, sem avisar por evento

Recolher campos de cobrança num `<details>` parece trivial e não é. O
`address-i18n.js` do WooCommerce reordena as `.form-row` por prioridade com
`appendTo( wrapper )` — o que puxa de volta tudo o que já foi movido para dentro
do colapse. E isso acontece no **init** do checkout, depois de um script de
rodapé, **sem** passar por `country_to_state_changed`: escutar aquele evento
cobre a troca de país e não cobre o carregamento.

O sintoma é o pior tipo: o `<details>` está no DOM, com o corpo **vazio**, e os
dez campos de volta à vista. A tela parece apenas não ter recebido a melhoria.
Medido no carregamento, antes da correção: `corpo = 0 elementos, wrapper = 11
filhos`, com o colapse vazio em primeiro lugar.

A correção é `MutationObserver` em `childList`, e ela **exige guarda**: o próprio
`appendChild` gera registro de mutação (reanexar nó já presente conta como
mutação), então sem um `precisaOrganizar()` que responda "nada a fazer" o par
observador/organizador entra em laço infinito. Veja
`assets/js/checkout.js`.

## A capa do perfil do Nouveau traz CSS inline com dois IDs

Com capa ligada, o BuddyPress imprime um `<style>` no `<head>` com seletores como
`#buddypress #item-header-cover-image #item-header-avatar img.avatar`, de
especificidade (3,1,1) ou mais. Nenhuma regra autoral por classe chega lá: medido,
o avatar do perfil saía com **4px**, com a nossa regra de 168px aplicada no
DevTools e perdida na cascata.

O override `buddypress/members/single/cover-image-header.php` **tira** os IDs
`#item-header-cover-image`, `#item-header-avatar` e `#item-header-content`, e o
CSS inline passa a não casar com nada. Ficam `#cover-image-container` e
`#header-cover-image`, porque o `cover-image.js` os procura para trocar a capa sem
recarregar. O degradê da marca vai no `#cover-image-container`, **atrás** da
capa: no `#header-cover-image` ele apagaria a capa que o membro enviar.

## O `bp-priority-nav` deixa a lista de abas em `inline-block`

O script que recolhe abas excedentes num "Mais" põe `#member-primary-nav` e o
`ul` das subabas em `display: inline-block`, por estilo próprio. Os `<li>` então
empilham um embaixo do outro — Atividade, Perfil, Notificações em coluna, e
Pessoal, Menções, Favoritos também. A regra autoral em classe perde para o
seletor de ID dele.

`#buddypress #object-nav #member-primary-nav { display: flex }` e
`#buddypress #subnav.bp-navs ul { display: flex }`, com `> li { display: block }`.
Veja `assets/css/comunidade.css`.

## O `.bp-wrap` do BuddyPress tem clearfix — e ele vira célula de grade

A mesma armadilha do clearfix do tema pai, registrada adiante, com outro
contêiner. Ao pôr `.bp-wrap` (o corpo do perfil) em grade de duas colunas, o
`::before` virou o primeiro item: o cartão "Sobre" caiu na segunda coluna e as
subabas na primeira. O mesmo aconteceu em `li.activity-item`, `#members-list` e
`.subnav-filters`. `content: none` em cada um.

## O `functions.php` do filho carrega **antes** do pai

`wp-settings.php` inclui `STYLESHEETPATH` primeiro. Um `remove_action` escrito no
corpo do `functions.php` do tema filho — ou de qualquer arquivo que ele inclua —
tenta remover um gancho que o pai ainda não registrou: devolve `false`, nada
acontece, e o sintoma é o pior de todos, porque o código está no lugar certo e
não faz nada. Ganchos do Storefront se removem em `after_setup_theme`, prioridade
20. Veja `reconectar_reposicionar_paginacao_do_catalogo()`.

## As duas `.storefront-sorting` do catálogo são ganchos simétricos

`storefront-woocommerce-template-hooks.php:45-55` monta a mesma sequência duas
vezes — wrapper em 9, ordenação em 10, contagem em 20, paginação em 30,
fechamento em 31 —, uma em `woocommerce_before_shop_loop` e outra em
`woocommerce_after_shop_loop`. Mexer em uma e esquecer a outra deixa a página
meio corrigida. E a paginação herda o `float: right` da barra: para centralizá-la
não basta CSS, é preciso tirá-la de dentro (prioridade > 31).

## `woocommerce_show_page_title` **apaga** o `<h1>`, não o esconde

O filtro decide se `loop/header.php` imprime o cabeçalho. Filtrar para `false`
tira o elemento do DOM, e quem navega por cabeçalhos fica sem saber em que página
está. Para sumir da tela e ficar para o leitor de tela, o caminho é classe no
`<body>` mais a técnica de `.screen-reader-text` — veja
`reconectar_marcar_pagina_sem_titulo()` e `.rc-sem-titulo-de-pagina`.

## O Storefront veste campos de formulário por seletor de atributo

`input[type="text"], … input[type="search"], …` tem especificidade **(0,1,1)** e
vence uma classe sozinha (0,1,0). Uma regra autoral que declare `background` ou
`border` num campo é escrita, aplicada e perdida na cascata — medido: o campo de
busca do fórum saía em `#f2f2f2` ao lado de um select branco, no mesmo
formulário. Repita a classe (`.rc-forum__busca.rc-forum__busca`) para chegar a
(0,2,0).

O pai também declara `select { color: initial; font-family: "Source Sans Pro", … }`,
o que tira a Poppins herdada do corpo. Todo `<select>` do projeto precisa de
`font-family: inherit` explícito.

## CSS de plugin não herda o reset do tema — `box-sizing` inclusive

O Storefront aplica `box-sizing: border-box` a `*`, e por causa disso um
`width: 100%` num campo de formulário **parecia** funcionar dentro do painel de
empresas, que é plugin. Sem o tema, o padding e a borda voltam a somar por fora:
medido em 375px, o campo saía 398px numa coluna de 375. O painel não pode exigir
tema nenhum — é a mesma razão pela qual os tokens `--rc-pe-*` trazem fallback
literal. Declare `box-sizing` no próprio componente.

## A grade do Bootstrap colide com o `.col2-set` do WooCommerce

O checkout clássico monta `<div class="col2-set" id="customer_details"><div
class="col-1">…`, e `.col-1`/`.col-2` são os dois primeiros degraus da grade de
doze do Bootstrap — que o tema enfileira em **toda** página, na prioridade 5.
Medido no checkout a 1600px, antes da correção:

```
.col-1 → 66,39px   (8,333% dos 796,75px da coluna)
.col-2 → 132,78px  (16,667%)
```

O sintoma é o `<h3>` "Detalhes de cobrança" quebrando **letra a letra**: largura
declarada vence a largura mínima do conteúdo, e o texto não tem para onde ir.

É colisão de nomes, não disputa de especificidade, e é por isso que a
investigação não encontra nada — **não há CSS autoral no caminho**. Varrido o
CSSOM inteiro da página, aquelas duas do Bootstrap são as **únicas** regras que
alcançam essas colunas: nem o Storefront nem o WooCommerce declaram largura ali,
e o layout certo é o padrão do bloco. Daí `width: auto`, escopado por
`.col2-set >` em `marketplace.css` — a grade segue valendo onde for usada. O
mesmo markup está no carrinho, em "Minha conta", nos endereços e no recibo do
pedido.

Ao varrer o CSSOM atrás de colisões assim, lembre que **com CSS nesting toda
`CSSStyleRule` tem `.cssRules`**, lista vazia. Um `if ( regra.cssRules ) {
recursa; continue; }` engole todas as regras de estilo e responde "nenhuma
colisão" — um falso negativo que parece verificação feita. Quem distingue
`@media` é `conditionText !== undefined`.

## Vários cartões `rc-` **são** o `<a>`, não o contêm

`reconectar_card_categoria()` imprime `<a class="rc-card-categoria">` com dois
`<span>` dentro. A regra `.rc-card-categoria a` não casava com nada: era escrita,
ignorada, e o nome da categoria caía no link padrão do tema, `#31BEB1` — medido,
**2,30:1** sobre o branco, reprovando o critério 1.4.3 da WCAG 2.1 em toda a faixa
da home. É de novo o sintoma pior: o código está no lugar certo e não faz nada.
Antes de escrever regra descendente em componente `rc-`, confira o markup.

## O "Ver todos" de um carrossel aponta para a listagem **do mesmo tipo**

Já errou duas vezes na home. Em lojas em destaque,
`reconectar_url_base_da_vitrine()` devolvia a própria página; em categorias,
`reconectar_url_loja()` levava ao catálogo de produtos. Nos dois casos o link
existia, era clicável e abria uma página plausível. Os destinos são
`reconectar_url_loja()` para produtos, `reconectar_url_das_lojas()` para lojas e
`reconectar_url_das_categorias()` para categorias — esta última resolvida pelo
**slug** da página `categorias`, que o `provision.sh` cria.

## Clearfix do tema pai vira **grid item**

Ao pôr `display: grid` num contêiner que o Storefront limpava com
`::before`/`::after`, os pseudo-elementos deixam de ser invisíveis: `content`
gera caixa, e caixa dentro de grade ocupa célula. Em `ul.products` isso dava
quatro colunas declaradas e três cartões por linha, com o primeiro começando na
célula 2. `content: none` apaga a caixa; `display: none` deixaria o item na
contagem. Vale para qualquer contêiner do pai que vire grade.

## `max-width` do tema pai vence `width` autoral — e a cascata não acusa

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

## `<td>` em `display: flex` perde o `colspan`

A célula deixa de ser célula, e o papel vai embora junto com a atribuição de
largura. Medido: `td.actions[colspan="6"]` caiu de 1187px para 331px e empilhou
em três linhas. Pôr a `<tr>` em `display: block` **não** resolve — a célula
anônima resultante continua dimensionada pelo algoritmo de tabela, e o valor
seguiu 331. A saída é manter a célula em `table-cell` e usar float nos filhos:
`table-cell` já estabelece um bloco de formatação, então contém os floats sem
clearfix.

## `shop_table_responsive` não tira o `display: table` da `<table>`

A classe põe as `<td>` em bloco e esconde o `<thead>`, e é fácil concluir que a
tabela virou lista. O elemento continua tabela, e tabela dimensiona pela largura
mínima do conteúdo, **ignorando o contêiner**: medido no carrinho, cartões de
473px numa tela de 375 — transbordo horizontal, que é o que faz a barra inferior
sumir no celular. Só `table`, `tbody` e `tr` em `display: block` resolvem.

E `a.remove` do WooCommerce **já nasce `position: absolute`**. Tirar
`td.product-remove` do fluxo sem devolver o link ao fluxo faz ele se posicionar
contra a própria célula, que passou a ter 6px: medido, o "×" foi parar em x=474
numa tela de 375. `.remove { position: static }` dentro do `td` absoluto.

## Em `flex-wrap: wrap`, quem decide a quebra é a **base**, não o encolhimento

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

## Esconder do olho e manter no leitor: as duas técnicas não são intercambiáveis

`position: absolute` é a técnica padrão do WordPress e tem a armadilha logo
abaixo: dentro de contêiner estático, o elemento sobe até o viewport. A variante
no fluxo (`width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%)`,
sem `position`) evita isso, e tem o preço oposto — como `inline-block`, ela gera
line box com a altura do strut do bloco. Medido: o gatilho do município ficava
com 61px de altura para exibir uma linha de texto.

Dentro de uma linha flex, a variante no fluxo não custa nada (é o caso de
`.rc-carrinho__unidade`). Num bloco de texto, só o absoluto zera a altura — e aí
confira que existe ancestral posicionado.

## `overflow-x: auto` não clipa absoluto solto — e foi assim que a barra sumiu

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
