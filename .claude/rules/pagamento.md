---
paths:
  - "wp-content/plugins/reconectar-core/includes/pagamento/**"
  - "wp-content/plugins/reconectar-core/includes/class-reconectar-servicos.php"
  - "wp-content/plugins/reconectar-core/includes/class-reconectar-status-pedido.php"
  - "wp-content/themes/reconectar/inc/marketplace/checkout.php"
  - "wp-content/themes/reconectar/woocommerce/**"
  - "wp-content/themes/reconectar/assets/js/checkout.js"
---

# Armadilhas — pagamento, checkout e pedidos do Dokan

Continuação da seção **Armadilhas** do `CLAUDE.md` da raiz, carregada só quando
a sessão mexe nos caminhos acima. Quando um item fala em armadilha "registrada
acima" ou "adiante", ela pode estar na raiz ou em outro arquivo de `.claude/rules/`.

## O Dokan descarta em silêncio meio de pagamento que ele não conhece

`Dashboard\Templates\Settings::insert_settings_info()` tem `bank` e `paypal`
escritos à mão no ramo do nonce `dokan_payment_settings_nonce`. Um
`$_POST['settings']['pix']` chega, não é lido, e a tela recarrega sem erro e sem
o dado — o sintoma é o campo voltar vazio depois de salvar.

O único gancho que alcança a gravação é `dokan_store_profile_settings_args`, e
ele dispara em **todos** os caminhos de salvamento do perfil. Por isso a injeção
do PIX é guardada por `wp_verify_nonce( …, 'dokan_payment_settings_nonce' )`:
sem essa guarda, salvar a loja em outra aba apagaria os dados de pagamento, sem
erro e sem aviso. Veja `Reconectar_Pagamento_Pix::gravar()`.

E a opção `dokan_withdraw['withdraw_methods']` precisa ligar `pix`, `bank` e
`whatsapp` —
sem isso **toda loja vê "No withdraw method is available"**: a tela existe, está
vazia, e nada indica o porquê. O `provision.sh` a ajusta.

O formato é um **mapa**, não uma lista: medido, o valor de fábrica é
`{"paypal":"","bank":"bank"}` — chave desligada guarda string vazia, ligada
repete o próprio nome. Gravar `["pix","bank"]` não liga nada e ainda apaga o
`bank` que já estava de pé, com o mesmo sintoma mudo. O valor correto é
`{"paypal":"","bank":"bank","pix":"pix","whatsapp":"whatsapp"}`.

## O bloco `woocommerce/checkout` não enxerga gateway clássico

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

## Meta de sub-pedido do Dokan exige prioridade ≥ 30 na gravação

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

## `WC_AJAX::update_order_review()` grava `chosen_payment_method` antes do `do_action`

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

## O Dokan recusa cupom de plataforma em carrinho multi-loja

Medido, a resposta de `?wc-ajax=apply_coupon` com três lojas no carrinho:
`Este cupom é inválido para multiple vendedores.` Não há erro visível na tela
além dessa linha, e o cupom simplesmente não entra. Para exercitar a linha de
cupom do resumo é preciso montar carrinho de **uma loja só** — do contrário a
investigação vai para o código do cupom, que está correto.

## Ida e volta em codec autoral é cega ao erro que os dois lados compartilham

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

## Gerador de Reed-Solomon com as duas linhas trocadas sai recíproco

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

## A tabela de pedidos do painel do Dokan mora dentro de um `<form>`

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

## `dokan_order_content_inside_before`/`_after` são de `orders/orders`, não de `orders/listing`

Armadilha de **medição**, e das caras: a tabela sai de `orders/listing`, então é
ele que se renderiza para medir a lista. Só que os dois hooks de antes e depois da
tabela pertencem ao template **pai**, e uma medição que carregue apenas o
`listing` não os dispara.

O sintoma é o pior possível num script de verificação: os `<form>` do rodapé
**não aparecem**, e a ausência parece defeito do código que acabou de ser
escrito. Dispare os dois à mão em volta do `dokan_get_template_part( 'orders/listing', … )`.

Vale a lição geral: ao medir template de terceiro, os hooks que você espera podem
estar num arquivo acima do que você renderizou.

## `dokan_get_vendor_orders` reordena só a página corrente

O filtro recebe o array **já cortado** pela paginação — 10 por página, medido. Um
`usort` ali reordena a página visível e deixa o pedido prioritário na página 3
exatamente onde estava. Para ordenação que atravesse a paginação, o lugar é
`posts_clauses`.

## A lista de pedidos do painel se identifica por `_dokan_vendor_id`, não por `post_author`

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

## O Dokan escreve rótulo e cor de status num `switch` fechado

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

## O e-mail de pedido novo do Dokan não dispara `woocommerce_email_order_details`

`emails/vendor-new-order.php` do Dokan monta a tabela à mão e só dispara
`woocommerce_email_after_order_table`, `_order_meta` e `_customer_details`. Um
bloco pendurado em `woocommerce_email_order_details` ou em
`before_order_table` aparece no e-mail do cliente e do administrador e **some**
do da loja — que costuma ser o único que importava. O container não envia
e-mail; para medir, capture com `pre_wp_mail` num `wp eval`.

## A soma de referência de uma diferença não é a soma da lista impressa

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
