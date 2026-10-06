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
| `scripts/icones-de-categoria.php` | associa os ícones SVG às categorias; chamado pelo provisionamento e pela carga |
| `wp-content/themes/reconectar/assets/icones/categorias/` | os ícones de categoria, autorais e versionados |
| `…/includes/class-reconectar-servicos.php` | serviços no mercado: preço "A combinar", solicitação sem pagamento, resposta da loja |
| `…/includes/class-reconectar-migracoes.php` | migrações de dados versionadas (meta e capacidade) |
| `…/includes/class-reconectar-incubadora*.php` | a Incubadora, wiki interna: rotas, leitura, ações, sanitizador, arquivos, editor, busca, interação (vídeo em destaque, avaliação, comentários) |
| `…/includes/incubadora/` | os templates da Incubadora |
| `…/assets/vendor/tinymce/8.9.2/` | o editor da Incubadora, vendorizado; origem e licença no `LEIAME.md` |
| `scripts/verificar-incubadora.php` | operações da Incubadora por WP-CLI, chamado pelo `verificar-acessos.sh` |
| `wp-content/themes/reconectar/bbpress/` | overrides de template do fórum |
| `wp-content/themes/reconectar/inc/forum/` | consultas, componentes e telas do Q&A |
| `docs/STACKS.md` | as camadas da plataforma e por que cada uma existe |
| `docs/PERFIS_E_PERMISSOES.md` | os cinco atores e a matriz de permissões |
| `docs/ROTEIRO_PERFIS.md` | roteiro de demonstração, com credenciais |
| `docs/DADOS_DEMONSTRACAO.md` | o que a carga cria, em detalhe |
| `docs/CADASTRO_MANUAL.md` | popular um ambiente real à mão, pelo Super Administrador |
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

363 casos de permissão, nos cinco perfis: a maior parte por HTTP, e as
operações da Incubadora por `scripts/verificar-incubadora.php`, que ele chama.
Sai com status 1 se algum falhar. **Rode depois de mexer em qualquer coisa de
RBAC** — as travas não têm teste automatizado além deste.

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

### Meta de sub-pedido do Dokan exige prioridade ≥ 30 na gravação

O Dokan divide o pedido em `woocommerce_checkout_update_order_meta`, e o mapa
medido daquele gancho é:

```
[10] Dokan\Order\Hooks::split_vendor_orders
[20] dokan_sync_insert_order
```

Na 10 os sub-pedidos **ainda não existem**; na 20 as tabelas paralelas do Dokan
ainda não foram preenchidas. Um `add_action` no padrão — sem terceiro argumento,
portanto prioridade 10 — roda antes dos dois e não tem o que atualizar. O sintoma
não é erro: é `_payment_method` ficando com o valor do pai em todos os filhos, e
a tela de agradecimento imprimindo o mesmo meio para lojas que escolheram meios
diferentes — um número plausível numa tela de pagamento.
`Reconectar_Pagamento_Direto::gravar_meios_no_pedido()` vai na **30**.

### `WC_AJAX::update_order_review()` grava `chosen_payment_method` antes do `do_action`

A ordem, medida: o método lê `payment_method` do POST e grava em
`WC()->session->set( 'chosen_payment_method', … )` **antes** de disparar
`woocommerce_checkout_update_order_review`. Um callback pendurado ali que tente
corrigir aquela chave da sessão escreve depois — e perde, porque o fragmento de
pagamento já foi montado com o valor anterior.

O `set` daquele callback só tem efeito no carregamento **por GET** da página, o
que torna o defeito seletivo: recarregar mostra o certo, trocar o meio mostra o
errado, e a investigação vai para o JavaScript, que está correto. Quem impõe o
gateway na submissão de verdade é `impor_meio_representativo()` em
`woocommerce_checkout_posted_data`, que roda em `get_posted_data()`, antes de
`validate_checkout()`.

### `order` no CSS inverte a pintura, não o foco

Trocar a ordem visual de dois blocos com `order` numa grade ou num flex deixa a
ordem de **tabulação** e a de leitura do leitor de tela como estavam no DOM. Para
dois cartões pequenos isso é tolerável; para os dois blocos do checkout — o
pedido inteiro e os dados de cobrança — significa tabular por trinta campos na
ordem contrária à que se lê, o que reprova o critério 2.4.3 da WCAG 2.1.

A inversão pedida no checkout foi feita movendo o gancho: `reconectar_checkout_pedido()`
saiu de `woocommerce_checkout_order_review` para
`woocommerce_checkout_before_customer_details`. O fragmento continua funcionando
— o seletor `.rc-checkout__pedido` não depende de posição.

### O gancho `wp` não alcança `?wc-ajax=`

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

### `class_exists` numa guarda `||` curto-circuita antes do autoload

`if ( ! class_exists( 'Foo' ) || ! Foo::bar() )` parece defensivo e é o oposto:
com a classe ainda não carregada, o primeiro termo é verdadeiro, o segundo
**nunca roda**, e a função devolve o recuo — lista vazia, meio nenhum — sem que
nada tenha falhado. Como a chamada que dispararia o autoload está no termo
curto-circuitado, a classe segue não carregada para sempre naquela requisição.

O sintoma é a tela sair correta em alguns carregamentos e vazia em outros,
conforme a ordem em que outro código tiver carregado a classe antes.

### `wc_cart_totals_subtotal_html()` já devolve o subtotal **promocional**

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

### O Dokan recusa cupom de plataforma em carrinho multi-loja

Medido, a resposta de `?wc-ajax=apply_coupon` com três lojas no carrinho:
`Este cupom é inválido para multiple vendedores.` Não há erro visível na tela
além dessa linha, e o cupom simplesmente não entra. Para exercitar a linha de
cupom do resumo é preciso montar carrinho de **uma loja só** — do contrário a
investigação vai para o código do cupom, que está correto.

### O filtro de fragmentos do checkout aceita **qualquer** seletor

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

### As funções de total do WooCommerce **imprimem**, e uma delas devolve `<tr>`

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

### `.site { overflow-x: hidden }` do Storefront mata todo `position: sticky`

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

### O `address-i18n.js` reordena as `.form-row` no init, sem avisar por evento

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

### Ida e volta em codec autoral é cega ao erro que os dois lados compartilham

O QR Code do PIX (`includes/pagamento/funcoes-qrcode.php`) é um codificador do
ISO/IEC 18004 escrito aqui, pelas mesmas três ausências do BR Code: sem
dependência externa, sem etapa de compilação e sem chamada de rede — o payload
carrega a chave PIX, que costuma ser o CPF de uma pessoa, e não pode sair pela
rede nem por query string. Escrever o codificador traz junto o problema de
prová-lo, e a prova óbvia não serve.

Codificar e decodificar com o **mesmo** código responde "igual" a qualquer
defeito que esteja nas duas pontas. `reconectar_qr_posicoes_de_formato()` punha
o bit 7 da segunda cópia do formato sobre o **módulo escuro fixo**, em
`( $lado - 8, 8 )`: a tabela de posições estava errada, o decodificador lia por
ela, e os 16 casos de ida e volta passavam — texto idêntico, síndromes em zero.

Quem pegou foi confrontar **duas tabelas independentes**: contar os módulos
livres da matriz e conferir contra `(codewords × 8) + bits de resto` da tabela de
blocos. Sobrava exatamente um módulo, nas quinze versões — e "exatamente um, em
todas" não se explica por acaso. Ao verificar codec autoral, procure a segunda
fonte de verdade; sem ela a verificação mede a si mesma.

Os números que fecham, para quem for mexer: V1 208 livres = 208 bits + 0 resto;
V2 359 = 352 + 7; V7 1568 = 1568 + 0; V14 4651 = 4648 + 3; V15 5243 = 5240 + 3.
O BR Code real de uma loja sai em **V8**, 49×49 módulos.

### Gerador de Reed-Solomon com as duas linhas trocadas sai recíproco

Irmã da anterior, e o motivo de os síndromes existirem na verificação. Em
`reconectar_qr_polinomio_gerador()`, o índice 0 é o coeficiente **líder**, e quem
o mantém ali é multiplicar por `x` — o termo α^i desce uma posição. Trocar os
dois lados do `foreach` multiplica por `(α^i + x)` em vez de `(x + α^i)` e produz
o polinômio **recíproco**: para grau 2, `[2,3,1]` em vez de `[1,3,2]`.

O líder deixa de ser 1, o gerador deixa de ser mônico, e a divisão sintética não
zera mais o coeficiente da vez. O sintoma não toca os dados: o código sai com
tamanho certo, o texto decodifica corretamente, **só a correção sai inválida** —
e todo leitor recusa a imagem ao conferir os síndromes. Uma verificação que só
compare o texto lido com o original dá tudo por certo.

### O topo e a barra React do Dokan decidem por URL, não por papel

Dois defeitos mudos de quem abre a moldura do painel sem ser loja — hoje, o
painel de empresas.

**Item ativo é prefixo.** A barra lateral acende item sem submenu por
`location.href.startsWith( item.url )`. Um item cuja URL é prefixo de outro —
`/painel-empresas/` e `/painel-empresas/loja/` — acende junto nas telas do
outro, e nada no PHP acusa: o `<li class="active">` da barra **clássica**
sai certo, e não é ela que aparece com o layout React. Veja o `#content` em
`Reconectar_Navegacao_Da_Loja::registrar_menu()`.

**"Visitar loja" não está na configuração do layout.** O filtro
`dokan_vendor_dashboard_layout_config` corrige nome, `editUrl` e "Minha conta",
e o botão segue apontando para `/store/<login>/` — uma loja que não existe. O
React lê `window.dokan.urls.storeUrl`, montado em `Assets.php` com
`dokan_get_store_url()` do usuário corrente; o filtro que o alcança é
`dokan_frontend_localize_script`.

E o `style.css` do Dokan zera o padding de
`.dokan-dashboard .dokan-dashboard-content ul li`, com (0,3,2): todo `<ul>`
autoral dentro da moldura perde o recuo dos itens.

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

### `wp option patch update` exige a chave; `patch insert` exige a opção

Nenhuma das duas falha em silêncio — falham com erro, e sob `set -euo pipefail`
isso derruba o `provision.sh` inteiro. O caro é que o estado que dispara cada uma
não existe na máquina de desenvolvimento. Medido:

| Estado da opção | `patch update` | `patch insert` |
| --- | --- | --- |
| não existe | `No data exists for key "…"` | `Cannot create key "…" on data type boolean` |
| existe, chave ausente | `No data exists for key "…"` | cria a chave |
| existe, chave presente | atualiza | atualiza |

A mensagem do `patch update` é a **mesma** nos dois estados de falha, então ela
não diz se falta a opção ou só a chave — quem lê o log do job não consegue
escolher a correção por ela.

`dokan_appearance` **nasce sem** `show_register_as_vendor`, a mesma forma da
armadilha do `dokan_selling` registrada acima. Aqui a chave existia porque a tela
do Dokan já tinha sido salva alguma vez, o provisionamento passava, e o primeiro
deploy na EC2 morreu nela com exit 1 no meio do job.

Para gravar chave dentro de opção-mapa use `reconectar_gravar_chave_de_opcao()`
(`scripts/provision.sh`), que garante a opção e então usa `patch insert`. A
exceção é o laço dos gateways do WooCommerce, logo abaixo dela: ali a chave
`enabled` ausente **é** informação — significa gateway no padrão de fábrica, que
já vem desligado —, e criá-la escreveria configuração para repetir o que já vale.

### Aba nova no painel do Dokan exige flush de reescrita

Os três ganchos que criam a aba — `dokan_query_var_filter`,
`dokan_get_dashboard_nav` e `dokan_load_custom_template` — não bastam. A query
var nova só passa a valer depois de as regras de reescrita serem regeneradas.

Numa instalação recém-provisionada o `provision.sh` faz o flush no fim, e por
isso o defeito não aparece em ambiente novo. Numa instalação que já está de pé,
ninguém faz — e o sintoma é mudo do jeito mais caro: **o item aparece no menu
lateral, o link existe, e a tela responde 404**, sem uma linha no log. Quem
investigar vai para os ganchos, que estão corretos.

O lugar do flush é `Reconectar_Migracoes`, que é o mecanismo que existe para
rodar uma vez por instalação. Veja `reescrever_permalinks()` — e a armadilha
seguinte, que é sobre **onde** ele roda.

**Remover a aba exige o mesmo flush**, e é fácil esquecer porque não há tela nova
para conferir: a regra segue gravada apontando para um template que já não
existe. Por isso `VERSAO` subiu duas vezes pela mesma aba — 4 na criação, 5 na
remoção. Uma instalação parada em `4` não rodaria nada.

### `flush_rewrite_rules()` em `init` prioridade 5 apaga as regras de terceiros

`flush_rewrite_rules()` não "atualiza" o conjunto: ele **regenera** a partir do
que estiver registrado naquele instante. As migrações rodam em `init` prioridade
5, e nessa altura nem o Dokan nem o bbPress registraram as deles.

O resultado é um conjunto gravado sem o painel da loja e sem o fórum — as duas
áreas caem de uma vez, e a migração se marca como aplicada, então rodar de novo
não conserta. O reparo manual é `wp rewrite flush`; **qualquer instalação que já
tenha rodado a migração defeituosa precisa dele**.

`wp_loaded` é o primeiro gancho em que todo `init` já passou. Agendar o flush
para lá de dentro da migração — `add_action( 'wp_loaded', 'flush_rewrite_rules' )`
— mantém a garantia de rodar uma vez e regenera com tudo registrado.

### `wp_posts.post_status` é `varchar(20)`, e o `sql_mode` não é estrito

Medido nesta instalação:
`ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION` — sem
`STRICT_TRANS_TABLES`. Um slug de status acima de 20 caracteres é **truncado no
`INSERT`, em silêncio**, e o pedido fica com um valor que não casa com nenhum
status registrado: `wc_get_order_statuses()` não o encontra, e a tela imprime o
slug cru no lugar do rótulo.

O prefixo `wc-` conta. `wc-conferencia` tem 14 e passa; um
`wc-aguardando-analise-do-comprovante`, que era o nome natural, teria 36 e
chegaria ao banco como `wc-aguardando-analis`. Nada falha, nada é registrado, e a
investigação vai para o registro do status — que está correto.

HPOS está desligado nesta instalação (`wp_wc_orders` não existe); ligado, a
coluna equivalente tem folga maior, mas o limite do `wp_posts` volta a valer na
sincronização.

### A tabela de pedidos do painel do Dokan mora dentro de um `<form>`

Medido, na ordem em que sai no HTML:

```
[0]  <!--dokan_order_content_inside_before-->
[5]  <form id="order-filter" method="POST" class="dokan-form-inline">
[6]    <table class="dokan-table dokan-table-striped">   ← as células da lista
[10] </form>
[11] <!--dokan_order_content_inside_after-->
```

Com campos `status`, `security`, `_wp_http_referer` e `bulk_orders[]`. Um
`<form method="post">` impresso numa célula seria **aninhado**: o navegador
descarta o interno, e o botão passa a submeter as **ações em massa** do Dokan —
sem erro, sem aviso, e com um efeito plausível na tela.

`formaction` no botão também não serve: o POST leva todos os campos acima, e no
dia em que o Dokan trocar `security` por `_wpnonce`, `check_admin_referer()` leria
o nonce **dele** e recusaria com a mensagem genérica de link expirado.

O caminho é o **atributo `form` do HTML5**, que define o form owner e sobrepõe o
ancestral: `<button form="rc-confirmar-492">` na célula, e o
`<form id="rc-confirmar-492">` correspondente impresso em
`dokan_order_content_inside_after` — que sai fora do form de terceiro. Medido: o
form do Dokan ocupa os bytes `4..17781`; os nossos começam em `17802` e `18216`,
cada botão acha o seu, e nenhum campo de terceiro é serializado. Veja
`Reconectar_Comprovante::formularios_de_confirmacao()`.

### `dokan_order_content_inside_before`/`_after` são de `orders/orders`, não de `orders/listing`

Armadilha de **medição**, e das caras: a tabela sai de `orders/listing`, então é
ele que se renderiza para medir a lista. Só que os dois hooks de antes e depois da
tabela pertencem ao template **pai**, e uma medição que carregue apenas o
`listing` não os dispara.

O sintoma é o pior possível num script de verificação: os `<form>` do rodapé
**não aparecem**, e a ausência parece defeito do código que acabou de ser
escrito. Dispare os dois à mão em volta do `dokan_get_template_part( 'orders/listing', … )`.

Vale a lição geral: ao medir template de terceiro, os hooks que você espera podem
estar num arquivo acima do que você renderizou.

### `dokan_get_vendor_orders` reordena só a página corrente

O filtro recebe o array **já cortado** pela paginação — 10 por página, medido. Um
`usort` ali reordena a página visível e deixa o pedido prioritário na página 3
exatamente onde estava. Para ordenação que atravesse a paginação, o lugar é
`posts_clauses`.

### A lista de pedidos do painel se identifica por `_dokan_vendor_id`, não por `post_author`

Medido em `posts_clauses`:

```
orderby: wp_posts.post_date DESC
join:    INNER JOIN wp_postmeta ON ( wp_posts.ID = wp_postmeta.post_id )
where:   AND ( wp_postmeta.meta_key = '_dokan_vendor_id'
               AND CAST(wp_postmeta.meta_value AS SIGNED) IN ('23') )
         AND wp_posts.post_type = 'shop_order' AND (…lista de status…)
```

Isso é o que permite reconhecer a consulta **dentro** do próprio `posts_clauses`,
por `post_type` mais a presença daquela meta no `where` já montado. A alternativa
— setar uma flag global em `woocommerce_order_query_args` e consumi-la no
`posts_orderby` — tem janela de corrida: qualquer outra query que rode no meio
herda a ordenação. Veja `Reconectar_Comprovante::priorizar_conferencia()`.

### O Dokan escreve rótulo e cor de status num `switch` fechado

`dokan_get_order_status_translated()` e `dokan_get_order_status_class()`
(`dokan-lite/includes/Order/functions.php`) não leem `wc_order_statuses`:
conhecem os status nativos e devolvem string **vazia** para o resto. Medido no
detalhe do pedido do painel da loja: `<label class="dokan-label dokan-label-"></label>`,
um selo sem texto e sem cor — e não só para os status de serviço, mas para
`conferencia`, `preparacao` e `enviado`, que estavam assim desde que nasceram.

O `/wp-admin` e a conta do cliente mostram o rótulo certo, porque leem a lista
do WooCommerce, e é por isso que ninguém viu: quem confere o status confere lá.
Os dois filtros de mesmo nome devolvem o rótulo e a cor — veja
`Reconectar_Status_Pedido::rotulo_no_painel_da_loja()`. Status novo entra no
mapa de cores no mesmo commit.

### `get_saved_products_category()` do Dokan é um leitor que **grava**

O Dokan guarda a categoria escolhida no formulário em `chosen_product_cat`, à
parte dos termos. Quando a meta falta, `Dokan\ProductCategory\Helper::get_saved_products_category()`
a deriva dos termos **e chama `set_object_terms_from_chosen_categories()`** — e
nesta instalação `dokan_selling.product_category_style` é `single`, então só a
primeira árvore sobrevive. Quem a chama são vários caminhos do próprio Dokan —
o formulário da loja, os ganchos de salvamento do `/wp-admin`, os controllers
REST e funções de produto —, então não há um ponto único a vigiar.

Medido: o serviço de demonstração gravado com `[artesanato, servicos]` virou
`[artesanato]` na primeira exibição, e o pedido dele nasceu como compra comum,
com PIX — sem erro, com o produto aparentemente intacto na listagem.

Produto criado por código vai numa categoria só. E quem trocar os termos de um
produto existente apaga `chosen_product_cat` junto, ou o formulário mostra e
regrava a escolha antiga no próximo salvamento. Veja
`reconectar_demo_criar_produto()`.

### `get_terms()` com `meta_key` em `product_cat` devolve vazio

`wc_change_pre_get_terms()` reescreve a consulta de termo das taxonomias do
WooCommerce para ordenar pela meta `order`, e nisso troca o `meta_key` pedido
pelo dela. A busca por `meta_key => '_reconectar_categoria_chave'` volta **vazia**,
sem aviso — a mesma forma do `wc_get_orders()`, só que pelo lado do vazio em vez
do banco inteiro. `meta_query` com `orderby` explícito escapa da troca; veja
`Reconectar_Servicos::termos()`.

### O e-mail de pedido novo do Dokan não dispara `woocommerce_email_order_details`

`emails/vendor-new-order.php` do Dokan monta a tabela à mão e só dispara
`woocommerce_email_after_order_table`, `_order_meta` e `_customer_details`. Um
bloco pendurado em `woocommerce_email_order_details` ou em
`before_order_table` aparece no e-mail do cliente e do administrador e **some**
do da loja — que costuma ser o único que importava. O container não envia
e-mail; para medir, capture com `pre_wp_mail` num `wp eval`.

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

E o caminho inverso também engana: um nonce gerado por `wp --user=… eval
'wp_create_nonce( … )'` **não vale no navegador**. `wp_create_nonce()` mistura o
token de sessão (`wp_get_session_token()`), que no CLI é vazio e no navegador vem
do cookie de login. O nonce sai com aparência perfeita e é recusado — e a
mensagem é a genérica de link expirado, que não diz que a causa é essa.

### `form.action` em JS é sombreado por um `<input name="action">`

Armadilha de **verificação**, não de produção, e capaz de dar por testada uma
trava que nunca foi exercitada. Num `HTMLFormElement`, um campo filho com
`name="action"` — que todo formulário de `admin-post.php` tem — sombreia a
propriedade `action` do elemento: `f.action` devolve o `HTMLInputElement`, não a
URL.

Usado como destino de `fetch`, ele vira a string `[object HTMLInputElement]` no
caminho. Medido: POST para
`/checkout/order-received/493/[object%20HTMLInputElement]`, **status 200**, página
plausível, nenhuma recusa disparada — quatro testes de segurança "passando" sem
ter tocado no servidor. `f.getAttribute( 'action' )` é o caminho.

### Nonce por pedido recusa antes da trava de propriedade

Corolário da anterior, e a razão de um teste de "pedido de outro comprador" nunca
medir o que pensa medir. Quando a ação do nonce inclui o id
(`check_admin_referer( ACAO . '_' . $pedido_id )`), trocar o `pedido=` numa URL
montada à mão invalida o nonce — e a recusa vem da **primeira** guarda, com a
mensagem genérica de link expirado, idêntica à de um id inexistente.

Isso é a propriedade desejada: por HTTP a trava de propriedade não é alcançável,
porque o servidor só emite o nonce a quem já tem o direito. Mas significa que
verificá-la exige outro instrumento — `ReflectionClass` sobre o método privado,
com `wp_set_current_user()` variando o ator. Sem isso, a matriz de permissões
fica medida só na primeira camada.

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

### A grade do Bootstrap colide com o `.col2-set` do WooCommerce

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

### O slug das páginas do WooCommerce sai no idioma ativo na ativação

O plugin cria Loja, Carrinho, Finalizar compra e Minha conta no instante em que é
**ativado** — antes de o pacote de idioma estar de pé. O slug fica gravado na
língua daquele momento e não migra depois. Medido:

| | desenvolvimento (nasceu em inglês) | produção (nasceu em pt-BR) |
| --- | --- | --- |
| Loja | `/shop/` 200 | `/loja/` 200, `/shop/` **404** |
| Minha conta | `/my-account/` 200 | `/minha-conta/` 200, `/my-account/` **404** |

Um `wp post list --post_type=page --name=shop --field=ID` não falha nem avisa:
devolve **vazio**, a guarda `[ -n "$id" ] &&` engole o vazio, e o menu de produção
sai com "Loja" e "Minha Conta" a menos — sem uma linha de erro no log do deploy,
que termina em verde. Foi assim que o menu da EC2 ficou com dois itens a menos
por meses.

A forma correta é a **opção que guarda o ID**, nunca o slug:
`woocommerce_shop_page_id`, `woocommerce_cart_page_id`,
`woocommerce_checkout_page_id` e `woocommerce_myaccount_page_id` — esta última
**sem hífen e sem sublinhado**, ao contrário do slug. Veja
`reconectar_id_de_pagina_do_woo()` em `scripts/provision.sh`, que ainda confere
`post_status` por causa da armadilha logo acima.

As páginas do **Dokan** não sofrem disso: ele grava slug em inglês nos dois
ambientes (`store-listing`, `dashboard`, `my-orders`), e buscá-las pelo slug está
correto.

### Página criada à mão não atravessa o deploy — e o consumidor dela cala

Corolário da armadilha do `custom_logo`, e a Transparência caiu nela. A página
`transparencia` era só **consumida** pelo `provision.sh` — item de menu e link do
rodapé —, e os dois são tolerantes à ausência de propósito ("um rodapé com um
link a menos é melhor que um link para o 404"). Ninguém a **criava**. Na máquina
de desenvolvimento ela existia porque alguém a fez pelo painel; em produção,
`/transparencia/` respondia 404.

O sintoma não é a página faltando: é a **entrega inteira ficando invisível**.
Todo consumidor de `Reconectar_Painel_Transparencia::url()` confere o retorno
antes de imprimir e desiste em silêncio quando ele volta vazio — o comportamento
certo, com o efeito de sumir com o cartão de enquete inteiro de uma vez só. A
investigação vai para o componente, que está correto. (Os dois atalhos que
caíram nisto — o aviso do cabeçalho e o item "Votar" da barra inferior — foram
removidos depois; a armadilha é do padrão, não deles.)

E existir não basta: `enfileirar_assets()` só carrega `transparencia.css` se
achar `[reconectar_painel_transparencia]` no `post_content`. Página sem o
shortcode responde 200 e sai sem estilo nenhum.

Ao criar página nova, escreva o bloco no `provision.sh` **antes** das seções de
menu e de rodapé, que a consultam.

### As guardas de idempotência protegem a instalação errada

Corolário da anterior, e o que faz uma correção parecer entregue sem chegar a
lugar nenhum. O bloco do menu pula tudo quando já há menu no local `primary`; as
colunas do rodapé pulam quando já têm widget. Isso é o que os torna idempotentes
e o que preserva a edição do administrador — e é também o que garante que uma
instalação provisionada **antes** de a página existir nunca receberá o link dela.
Criar a página agora não a leva a lugar nenhum.

O reparo é um bloco à parte, aditivo, idempotente por um identificador estável:

- **Menu** — `reconectar_reparar_item_de_menu()`, por `object_id`. Nunca pelo
  rótulo (o administrador renomeia no painel) nem pela URL (o permalink muda se
  alguém trocar o slug — que é justamente o que divergiu entre os ambientes).
- **Rodapé** — `scripts/reparar-rodape.php`, pelo caminho do href, inserindo o
  `<li>` antes do `</ul>`. Vai em PHP porque o conteúdo é HTML dentro de array
  serializado: **`wp widget get` não existe**, `wp widget list --format=json` é a
  única leitura e devolve o texto com escapes Unicode.

Os dois acrescentam no **fim** da lista, sem `--position`: reordenar é um arrasto
no painel, e o script não desfaz escolha de quem administra.

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

### A soma de referência de uma diferença não é a soma da lista impressa

Quando uma tela mostra parcelas e declara "a diferença até o total é X", a
tentação é somar o que está na tela — e é errado sempre que a tela **filtra**
alguma parcela.

Medido na prévia de pagamento do checkout, com três lojas e uma delas sem chave
PIX: os cartões impressos somavam R$ 38,00 + R$ 118,00, o total do carrinho era
R$ 248,00, e a frase saía "a diferença de R$ 92,00 é de frete e outros
acréscimos". Não havia linha de frete naquele carrinho: os R$ 92,00 eram os
produtos da loja que a prévia tinha deixado de fora. Número plausível, causa
inventada, numa tela de pagamento — exatamente o que a regra de honestidade de
dados proíbe.

A soma de referência tem de ser a do **conjunto inteiro** (`array_sum( $valores )`,
todas as lojas do carrinho), não a do subconjunto exibido; quem explica a parcela
ausente é o alerta que nomeia a loja, não a aritmética. E o defeito só apareceu
**na medição**: lido, o código parecia correto, porque a variável que acumulava
era a mesma que desenhava os cartões.

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

### Configuração que aponta para anexo não atravessa o deploy

Corolário do recorte acima, e o primeiro deploy caiu nele. `custom_logo` é uma
theme mod que guarda o **ID de um anexo**, e anexo mora em `uploads/`, que não é
versionado nem sincronizado. A logo definida no Customizer da máquina de
desenvolvimento não existe do outro lado — nem o ID, nem o arquivo.

O sintoma não é imagem quebrada: `header.php:47` tem fallback, e o cabeçalho cai
no nome do site em **Thoge**, uma fonte de display que numa linha de cabeçalho
sai ilegível. A página inteira parece correta, com CSS e tudo no lugar, e só a
marca fica errada — o que manda a investigação para a tipografia, que está
funcionando: medido, `thoge.otf` respondia 200 no servidor.

O arquivo nunca foi o problema: as três logos estão em
`themes/reconectar/assets/img/`, versionadas, e o tema sobe inteiro. Faltava
importar para a biblioteca e apontar a mod, o que o `provision.sh` passa a fazer
na seção "Logo do cabeçalho". A guarda confere o **anexo**, não só a mod: um
banco restaurado sem a mídia deixaria o ID apontando para o nada, e aí o
provisionamento diria "já definida" sobre um cabeçalho em fallback.

Vale para qualquer opção que guarde ID de anexo — ícone do site, imagem de
cabeçalho, capa de página. Configuração feita pelo Customizer local não chega ao
servidor por nenhum caminho: ou entra no `provision.sh`, ou é refeita à mão lá.

### Arquivo em `uploads/` é público — e `uploads/` não atravessa o deploy

Duas consequências do mesmo fato, e as duas mordem quem grava arquivo enviado por
usuário.

**Público.** `/wp-content/uploads/` é servido direto pelo Apache, sem passar pelo
WordPress: nenhuma capacidade é consultada, e o nome do arquivo costuma ser
adivinhável (`comprovante-1.pdf`). A Media Library ainda o exibe na listagem de
mídia para todo perfil com `upload_files`. Para dado pessoal — comprovante
bancário traz nome, valor e conta — isso é o oposto do que a LGPD pede, e o
edital cobra.

O arranjo que este projeto usa está em `Reconectar_Comprovante::diretorio()`:
subdiretório próprio, `.htaccess` com `Require all denied` **e** `deny from all`,
`index.php` vazio contra listagem, nome gerado por `wp_generate_password( 32,
false )`, e entrega por rota autoral que confere direito antes do `readfile()`.
O nome imprevisível é a camada que sobrevive a uma troca para nginx, em que o
`.htaccess` vira inerte.

**Não sincronizado.** `git ls-files wp-content/` devolve só os dois diretórios
autorais, e o `rsync` do deploy segue esse recorte. Arquivo enviado por usuário
existe **apenas** no servidor que o recebeu: não está no Git, não vem do
`provision.sh` e não tem origem nenhuma para ser restaurado. Backup de `uploads/`
é responsabilidade da infraestrutura, e nada no repositório o substitui.

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

### `required: true` num input de ação composta não barra valor vazio

Ele exige que o `with:` **traga a chave**, não que o valor chegue preenchido. Os
workflows sempre trazem as cinco; o que falta é o secret ou a variable por trás
delas, e a ação recebia string vazia sem reclamar.

O sintoma é o `usage` do `ssh`. Medido:

```
ssh "@"                  → usage
ssh "@exemplo.invalido"  → usage
ssh "ec2-user@"          → Could not resolve hostname
ssh ""                   → Could not resolve hostname
```

Com `SSH_USUARIO` ausente, `ALVO` vira `@` e o `ssh` imprime o próprio modo de
uso — o que manda a investigação para a sintaxe da linha, que está correta, e
não para o cadastro que falta. `SSH_HOST` vazio nem chega a ser olhado.

Pior que o erro ilegível: `CAMINHO_REMOTO` vazio faria o passo seguinte
sincronizar para `/wp-content/` e `/scripts/` na **raiz do servidor** — com
`--rsync-path="sudo rsync"`, portanto como root e com `--delete`. Quem barra é
o passo "Conferir que os secrets e variables existem", em
`.github/actions/preparar-ssh/action.yml`.

### Post type novo: o nome cabe em 20 caracteres, e o `query_var` fica ligado

`wp_posts.post_type` é `varchar(20)`, com o mesmo `sql_mode` não estrito da
armadilha do `post_status`: `reconectar_incubadora` (21) seria truncado no
`INSERT`, em silêncio. Daí `incubadora_pagina`, com 18.

Num post type hierárquico com `query_var => false`, a regra de reescrita passa a
usar `pagename` — e `WP::parse_request()` confere `pagename` por
`get_page_by_path()` **só no tipo `page`**. Medido: `/incubadora/<slug>/` caía na
regra de anexo e respondia 404, com a regra do CPT bem ali em `wp rewrite list`.
O `query_var` é o próprio `POST_TYPE`.

E o `capabilities` do CPT declara só as **primitivas**. Mapear ali as meta caps
(`edit_post`, `read_post`, `delete_post`) derruba o redirecionamento do
`/wp-admin` que o resto do RBAC monta em cima delas.

### Acima de `post_max_size` o PHP esvazia `$_POST` inteiro — inclusive o `action`

Corpo maior que `post_max_size` chega com `$_POST` e `$_FILES` **vazios**. Não é
só o arquivo que some: somem o nonce e o `action`. Com o `action` no corpo, o
`admin-post.php` nem acha o handler; com ele lá e o nonce perdido, a resposta é
"o formulário expirou", o usuário recarrega e perde o que não tinha salvo.

O envio da Incubadora põe o `action` na **URL** e confere `CONTENT_LENGTH`
contra `post_max_size` **antes** do nonce, respondendo 413 com o limite legível.
Veja `Reconectar_Incubadora_Acoes::exigir_requisicao()`.

E o limite que vale é o do PHP, não o da classe. O container tem
`upload_max_filesize=2M`: os 5 MB de imagem e 10 MB de PDF de
`Reconectar_Incubadora_Arquivos` são um teto, aplicado como `min()` com
`wp_max_upload_size()`. Elevar é decisão de infraestrutura.

### Rascunho novo tem `post_modified_gmt` zerado

`0000-00-00 00:00:00` até a primeira gravação. `get_post_modified_time( 'U',
true )` devolve `false`, que vira 0 na conta, e a tela dizia **"Editado há 57
anos"** — um número absurdo o bastante para ninguém confiar no resto. Use
`get_post_timestamp( $post, 'modified' )`, que cai na data local quando a GMT
está zerada.

### `wp_get_post_revision()` recebe o argumento por referência

Uma expressão ali — `wp_get_post_revision( (int) $id )` — é **erro fatal**, não
aviso. Converta numa variável antes.

### `post_status => 'any'` não alcança a lixeira

Nem o `auto-draft`: `any` exclui todo status registrado com
`exclude_from_search`, e `trash` é um deles. Uma remoção que busque por `any`
deixa para trás o que já foi excluído — e a Incubadora manda para a lixeira. A
lista explícita está em `reconectar_demo_paginas_da_incubadora()`.

### O bbPress regrava `bbp_participant` em `user_register`, depois do papel

`wp_insert_user()` grava o papel com `set_role()` — que dispara `set_user_role` e
a sincronização do fórum — e só **depois** dispara `user_register`. Ali
`bbp_user_register` (prioridade 10) chama `bbp_set_user_role()` com o papel
padrão e põe `bbp_participant` por cima do `bbp_moderator` recém-dado.

O sintoma é um Moderador ou Administrador **novo** sem moderar o fórum
(`edit.php?post_type=topic` em 403), enquanto os antigos funcionam. Foi a carga
de demonstração, ao recriar os usuários, que revelou.
`sincronizar_papel_no_forum_ao_cadastrar()` repete a sincronização em
`user_register` prioridade 20.

### TinyMCE `inline`: o navegador carrega o que o servidor ainda vai recusar

Três defeitos do mesmo fato — no modo `inline` o conteúdo é DOM vivo:

- **`<iframe>` colado é carregado na hora.** O sanitizador do servidor o tira
  ao salvar, mas o navegador já chamou o host dele. O filtro fica no cliente
  também, em `editor.parser.addNodeFilter( 'iframe' )`, com a mesma allowlist.
- **`editor.remove()` devolve o conteúdo serializado ao elemento.** Os iframes
  renascem por um instante, o bastante para chamar o provedor. Esvazie o
  elemento **antes** do `remove()`.
- **O player do vídeo é vivo na edição.** Abrir uma página em edição contata o
  provedor; na leitura, a capa estática não. Está documentado, não é defeito.

E duas da configuração. Uma imagem inserida com `alt=""` abre a janela do
TinyMCE com **"decorativa" já marcada** e o campo de descrição desabilitado —
medido —, e a decisão chega tomada para quem envia; por isso a imagem enviada
entra **sem** `alt`, e quem garante o `alt=""` ao salvar é o sanitizador. E a
base `emojiimages` do plugin `emoticons` busca as imagens numa CDN — a
configurada é `emojis`, que é texto.

O TinyMCE é carregado só no clique em Editar. Para conferir, a agulha é
`tinymce.min.js`: o caminho `vendor/tinymce` aparece nos dados localizados da
página mesmo sem o editor carregado.

### Sanitizar o que o Moderador escreve é reconstruir, não filtrar

Moderador e Administrador **não** têm `unfiltered_html`, e a Incubadora aceita
vídeo, tabela e imagem. `Reconectar_Incubadora_Conteudo` remonta o HTML por
`DOMDocument` a partir de uma lista fechada e passa por `wp_kses` depois, como
segunda camada. A gravação roda entre `kses_remove_filters()` e `kses_init()`,
em `try/finally`: sem isso o núcleo passaria o conteúdo pela allowlist genérica
de post, que não é a da Incubadora e só poderia divergir dela; e sem o
`finally` uma exceção deixaria o `kses` desligado para o resto da requisição.

A leitura **sanitiza de novo** e tem render próprio, sem `the_content`: conteúdo
gravado direto no banco — por restauração de dump, por migração — não passou
pela gravação. `<img>` só da rota autoral de arquivos: imagem externa é
rastreador, e a LGPD conta. A bateria de 70 casos de XSS vive fora do
repositório; ao mexer no sanitizador, cada caso precisa de segunda passada
idêntica, ou ele não é idempotente.

### `wp_set_comment_status()` só conhece os status do núcleo

O comentário oculto da Incubadora vive em `comment_approved = 'rc-oculto'`, e
`wp_set_comment_status( $id, 'rc-oculto' )` parece o caminho natural. Ele aceita
apenas `hold`, `approve`, `spam` e `trash`: com qualquer outro valor devolve
`false` e **não grava nada**. Medido: um teste de "resposta em fio oculto" falhou
com o código correto, porque o fio nunca chegou a ser ocultado. Quem grava é
`wp_update_comment()` com `comment_approved`, como faz
`Reconectar_Incubadora_Interacao::moderar()`.

O oculto fica de fora de `comments_open`, das consultas do núcleo e do feed por
filtros da mesma classe — o tipo `rc_incubadora` não aparece num `get_comments()`
que não o peça. Comentário de tipo novo herda esse cuidado, ou vaza para o
`/wp-admin` e para o RSS.

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
