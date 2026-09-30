# Pagamentos — pagamento direto à loja

A plataforma **não toca no dinheiro**. O comprador paga a loja por **PIX** ou
**transferência bancária**, usando os dados que a própria loja cadastrou na
dashboard do Dokan. Não há credencial de provedor, não há split e não há
confirmação automática de recebimento.

Este documento registra o cenário escolhido, o que ele resolve, o que ele
deliberadamente **não** resolve e o que precisaria mudar para resolver.

> **Este arquivo substitui a versão anterior**, que dizia não haver gateway
> nenhum e listava a escolha de provedor como pendência. A pendência foi
> decidida: o cenário abaixo é a entrega, não um estágio intermediário dela.

## Por que este cenário

O modelo de negócio do edital é uma incubadora digital ligando negócios locais a
compradores. A plataforma intermedeia a **vitrine**, não o pagamento.

As consequências práticas:

- **Nenhum provedor precisa ser escolhido.** Não há taxa, não há prazo de
  repasse, não há homologação e não há CNPJ da plataforma recebendo por conta de
  terceiro — que é uma operação regulada.
- **Nenhuma credencial existe para vazar.** Não há chave de API em código, em
  banco ou em `.env`.
- **Nada trava se o orçamento não cobrir o Dokan Pro.** O split automático, que
  é o que exigiria a versão paga, deixa de ser necessário: o dinheiro nunca é
  agregado, então não precisa ser dividido.

O preço está na seção "O que este cenário não resolve".

## Onde a loja cadastra os dados

**Configurações → Pagamento** na dashboard do Dokan
(`/dashboard/settings/payment`). O Dokan chama essas entradas de *withdraw
methods* internamente; a tela, para quem usa, se chama Pagamento. O descompasso
de vocabulário é do plugin — construir uma aba nova só para renomear um conceito
interno não valeria o custo de manutenção.

| Método | Campos |
| --- | --- |
| PIX | tipo de chave, chave, nome do beneficiário, cidade |
| Conta bancária | titular, banco, agência, conta, tipo, CPF/CNPJ |

Beneficiário e cidade não são enfeite no PIX: são o que falta para montar um BR
Code válido.

A opção `dokan_withdraw['withdraw_methods']` é ajustada pelo `provision.sh` para
`{"paypal":"","bank":"bank","pix":"pix"}`. Sem isso ela fica no padrão de
fábrica, com só o PayPal ligado, e **toda loja vê "No withdraw method is
available"** — a tela existe, está vazia, e nada indica o porquê.

Repare no formato: é um **mapa**, não uma lista. Chave desligada guarda string
vazia; ligada repete o próprio nome. Gravar `["pix","bank"]` ali não liga nada e
ainda apaga o `bank` que já estava de pé — com o mesmo sintoma mudo.

O menu **Withdraw** da dashboard é ocultado por `dokan_get_dashboard_nav`: com o
comprador pagando a loja direto, a plataforma não retém saldo, e um menu de saque
prometeria um repasse que não existe.

> **O Dokan descarta em silêncio método que ele não conhece.**
> `Dashboard\Templates\Settings::insert_settings_info()` tem `bank` e `paypal`
> escritos à mão no ramo do nonce `dokan_payment_settings_nonce`; um
> `$_POST['settings']['pix']` simplesmente não é lido. O único gancho que alcança
> é `dokan_store_profile_settings_args`, e ele dispara em **todos** os caminhos de
> salvamento do perfil — por isso a injeção é guardada por
> `wp_verify_nonce( …, 'dokan_payment_settings_nonce' )`. Sem essa guarda, salvar
> a loja em outra aba apagaria os dados de pagamento, sem erro e sem aviso.

`Reconectar_Lojas::preparar_loja()` semeia a chave `'pix' => array()` junto de
`'bank'`, para que loja nova nasça com a estrutura esperada.

## Como o comprador paga

Dois gateways autorais em
`wp-content/plugins/reconectar-core/includes/pagamento/`, estendendo
`WC_Payment_Gateway` e registrados em `woocommerce_payment_gateways`:

| Classe | ID | Título |
| --- | --- | --- |
| `Reconectar_Gateway_Pix` | `reconectar_pix` | PIX |
| `Reconectar_Gateway_Transferencia` | `reconectar_transferencia` | Transferência bancária |

Ambas estendem `Reconectar_Gateway_Direto`, que concentra o comportamento comum.

### O checkout tem de ser o clássico

As páginas **Carrinho** e **Finalizar compra** nascem, no WooCommerce atual, com
os blocos `woocommerce/cart` e `woocommerce/checkout`. O bloco de checkout
desenha **apenas** os métodos registrados em
`woocommerce_blocks_payment_method_type_registration` — um ponto de extensão que
exige uma classe `AbstractPaymentMethodType` mais um script chamando
`registerPaymentMethod` no navegador. Um `WC_Payment_Gateway` clássico que não
faça isso some da tela, e some **sem erro**.

Medido no HTML da página, com o carrinho montado e a loja com chave PIX
cadastrada:

```
paymentMethodSortOrder: ["reconectar_pix","reconectar_transferencia"]
paymentMethodData:      []
payment_methods:        ["reconectar_pix"]   (Store API do carrinho)
```

O servidor sabia que o PIX estava disponível; o bloco não tinha como desenhá-lo.
O comprador via "Não há métodos de pagamento disponíveis. Entre em contato
conosco" — a pior forma do defeito, porque a tela está correta, o gateway está
ligado e nada na interface indica a causa.

Por isso o `provision.sh` converte as duas páginas aos shortcodes
`[woocommerce_cart]` e `[woocommerce_checkout]`. Além de o gateway voltar a
aparecer, é o caminho clássico que chama `payment_fields()` — onde o aviso de
qual loja não recebe por aquele meio é impresso — e
`woocommerce_after_checkout_validation`, onde `validar_meios_por_loja()` recusa
um pedido cuja escolha de meio alguma loja não aceita. No bloco, os dois
simplesmente não rodam.

A divisão em sub-pedidos do Dokan **não** é o motivo da escolha: medido em
`$wp_filter`, ele pendura `split_vendor_orders` e `dokan_sync_insert_order`
também em `woocommerce_store_api_checkout_order_processed`, então ela
funcionaria nos dois caminhos. (Olhar só para
`woocommerce_store_api_checkout_update_order_meta`, que está vazio, leva à
conclusão contrária.)

Escrever a integração de blocos continua possível, e seria o caminho se o
checkout em blocos virasse requisito. O preço é uma classe por gateway, um
script sem etapa de compilação — o projeto não tem build — e um equivalente da
validação no Store API; e ainda esbarra em `paymentMethodData` ser resolvido uma
vez por carregamento, enquanto a lista de lojas sem chave muda com o carrinho.

**Nenhum dos dois processa transação.** `process_payment()` marca o pedido como
aguardando pagamento, esvazia o carrinho e devolve a URL de agradecimento. A
confirmação é manual, feita pela loja ao mudar o status do pedido — que é o que
acontece de fato quando alguém paga numa chave PIX pessoal. Inventar confirmação
automática seria fabricar um dado.

### Cada loja escolhe o seu meio

O carrinho pode ter produtos de mais de uma loja, e cada loja declara os meios
que aceita. Como o dinheiro vai **direto** para cada uma, não há razão para que
a escolha seja única: a loja que só recebe por transferência não deve impedir
que a vizinha receba por PIX.

Por isso a escolha mora **dentro do colapse de cada loja**, no checkout — um
`<fieldset>` de rádios `rc_pagamento[<loja_id>]` com os meios daquela loja, e
nenhum se a loja não cadastrou nenhum. O rádio global do WooCommerce, em
`#payment`, continua no formulário e escondido por CSS: ele é o que leva um
`payment_method` válido no POST, sem o qual o núcleo recusa a submissão.

| Método | Papel |
| --- | --- |
| `meios_da_loja( $loja_id )` | os gateways que **aquela** loja aceita, via `loja_recebe()` |
| `meio_escolhido( $loja_id )` | POST → `post_data` → sessão → primeiro meio aceito; fonte única da renderização e da validação |
| `meios_escolhidos()` | mapa `loja_id => gateway_id` de todo o carrinho |
| `guardar_escolha( $post_data )` | grava na sessão e ajusta `chosen_payment_method` |
| `validar_meios_por_loja( $campos, $erros )` | recusa escolha que a loja não aceita |
| `gravar_meios_no_pedido( $pedido_id )` | grava `META_MEIOS` no pai e `_payment_method` em cada sub-pedido |

`is_available()` passou a ser **união**, não interseção: basta uma loja do
carrinho aceitar o meio para o gateway existir. A recusa deixou de ser do
gateway inteiro e passou a ser por loja, onde o comprador pode fazer algo a
respeito.

O que o WooCommerce **não** comporta é mais de um gateway por submissão — ele
processa um. O pedido pai fica com o meio da primeira loja em `_payment_method`
(id técnico, que só o código lê, e cujo `process_payment()` é idêntico nos dois
gateways) e com a **lista dos meios realmente usados** em
`_payment_method_title`, que é o que um humano lê no painel. A verdade por loja
vive em `META_MEIOS` (`_reconectar_meios_por_loja`) no pai e no
`_payment_method` de cada sub-pedido do Dokan.

Medido num pedido de três lojas com dois meios:

```
PAI 483   _payment_method: reconectar_pix
          _payment_method_title: PIX e Transferência bancária
          _reconectar_meios_por_loja: {"26":"reconectar_pix","25":"reconectar_transferencia","23":"reconectar_transferencia"}
FILHO 484 (loja 26) reconectar_pix              PIX
FILHO 485 (loja 25) reconectar_transferencia    Transferência bancária
FILHO 486 (loja 23) reconectar_transferencia    Transferência bancária
```

A gravação vai em **prioridade 30** de `woocommerce_checkout_update_order_meta`:
o Dokan divide o pedido na 10 e sincroniza as tabelas dele na 20 — antes disso
os sub-pedidos ainda não existem.

Uma loja que não cadastrou meio nenhum **não vende**. Não é um estado de erro: é
a consequência verdadeira de não dizer como receber. No colapse dela, no lugar
dos rádios, sai o alerta que a nomeia.

Nesse caso a lista fica vazia, e a frase de fábrica do WooCommerce descreve o
efeito escondendo a causa: quem lê não tem como saber que o problema é de uma
loja específica do carrinho, nem que remover os produtos dela resolveria — o
caminho plausível, e errado, é concluir que a plataforma está quebrada.
`Reconectar_Pagamento_Direto::explicar_ausencia_de_meios()`, no filtro
`woocommerce_no_available_payment_methods_message`, nomeia as lojas. A relação
sai dos gateways instanciados (`instanceof Reconectar_Gateway_Direto`), não de
uma lista de meios escrita à mão: um meio novo passa a contar sozinho, e não há
duas listas para divergirem.

### Uma instrução por loja

O Dokan Lite divide o pedido em sub-pedidos em
`woocommerce_checkout_update_order_meta`, e `dokan_get_sellers_by( $order )`
devolve os vendedores agrupados por item.

A tela de agradecimento (`woocommerce_thankyou`) e o e-mail do pedido
(`woocommerce_email_before_order_table`) imprimem **um bloco por loja**, cada um
com o nome da loja, os dados do **meio daquela loja** e o **valor do sub-pedido**
correspondente. Os blocos somam o total do pedido-pai.

Um bloco só, com a soma, mandaria o comprador pagar tudo para uma das lojas.

O meio de cada bloco sai de `META_MEIOS`, com **recuo obrigatório**: pedido sem a
meta — todos os anteriores a esta entrega — cai em
`$pedido->get_payment_method()` para todas as lojas, que é o comportamento de
antes. Sem o recuo, um pedido antigo passaria a imprimir bloco sem instrução
nenhuma. Medido no pedido 236, que não tem a meta: os três blocos saem em PIX,
idênticos aos de antes.

Os dados saem de `dokan_get_store_info( $vendor_id )`, na chave `payment` do
`dokan_profile_settings`. Só o necessário é exibido, e **nada disso vai para
URL** — a chave PIX de uma pessoa física costuma ser o CPF dela.

**No checkout não sai dado de pagamento nenhum.** O cartão do meio escolhido diz
apenas a frase curta — "Ao finalizar, daremos instruções para pagar." no PIX —, e
chave, beneficiário, banco, agência e conta aparecem só depois de o pedido
existir: na tela de agradecimento e no e-mail. É pedido do cliente, e tem a
vantagem de manter o dado sensível fora de uma página que o comprador pode
abandonar com a aba aberta. O que continua no checkout é o **alerta** que nomeia
a loja sem meio cadastrado — ali não há dado a proteger, e sem ele o comprador
descobriria o problema depois de pagar. Ele sai em dois lugares, de propósito:
dentro do colapse da loja (`.rc-checkout__sem-meio`), onde o comprador está
olhando, e em `payment_fields()`, que serve a lista global e ao caso em que
nenhuma loja do carrinho recebe.

## BR Code copia-e-cola

`reconectar_pix_br_code()` monta o payload estático EMV MPM a partir de chave,
nome, cidade e valor: montagem de TLV mais CRC16-CCITT/FALSE. É cálculo puro —
sem dependência externa, sem build e sem chamada de rede, o que o mantém dentro
das regras do projeto.

**A conferência que vale é colar o código no app de um banco real.** Um código
que o banco recusa é pior que nenhum código: se não passar, a entrega sai com os
dados da chave em texto e sem o copia-e-cola.

## QR Code

O mesmo payload sai também em **QR Code**, desenhado em SVG por
`includes/pagamento/funcoes-qrcode.php` — um codificador do ISO/IEC 18004 escrito
aqui, pelas mesmas três ausências do BR Code: sem dependência externa, sem etapa
de compilação e sem chamada de rede. O payload carrega a chave PIX, que costuma
ser o CPF de uma pessoa, e não pode sair pela rede nem por query string para um
gerador de terceiro.

O BR Code de uma loja real sai em **V8**, 49×49 módulos. A armadilha de verificar
codec autoral — por que a ida e volta com o próprio código não prova nada — está
registrada no `CLAUDE.md`.

**A conferência que ainda falta é apontar o app de um banco real para a imagem.**
Só o cliente pode fazê-la.

## Comprovante: o comprador envia, a loja confere e confirma

A instrução fecha metade do ciclo. Sem a outra metade, o comprador paga e não tem
como dizer que pagou, e a loja só descobre olhando o próprio extrato — sem saber
a qual pedido aquele crédito pertence.

`Reconectar_Comprovante` (`includes/pagamento/class-reconectar-comprovante.php`)
fecha o ciclo em três passos:

| Passo | Quem | Onde |
| --- | --- | --- |
| anexa o comprovante | o comprador | logo abaixo da instrução de pagamento, na tela de agradecimento e em *Meus pedidos → ver pedido* |
| confere e confirma | a loja | coluna **Comprovante** da lista de pedidos do painel (`/dashboard/orders/`) |
| muda de status | o sistema | duas transições: o envio leva o sub-pedido a **Pagamento em conferência**, a confirmação leva a **Em preparação** |

### O ciclo de status, e por que ele tem três degraus

```
Aguardando pagamento  →  Pagamento em conferência  →  Em preparação
   (on-hold/pending)         (wc-conferencia)          (wc-preparacao)
     o comprador               ele enviou o             a loja conferiu
     ainda não pagou           comprovante              e deu por recebido
```

O degrau do meio existe porque sem ele o pedido pago e o pedido não pago ficam
indistinguíveis na lista da loja — e é a loja quem precisa saber em qual agir.
`Reconectar_Status_Pedido::CONFERENCIA` o registra, e `rotulos()` o põe antes de
`PREPARACAO`: é a ordem do array que ordena o seletor do painel.

O status **não** conta como pago. `considerar_pagos()` tem `preparacao` e
`enviado` escritos à mão, e `conferencia` fica de fora de propósito: o
comprovante chegou, o dinheiro ainda não foi conferido, e entrar ali
contaminaria os relatórios do WooCommerce com receita não verificada.

`STATUS_AGUARDANDO` recebeu `conferencia` junto com a mudança, e essa linha é
crítica: `confirmado()` é `! has_status( STATUS_AGUARDANDO )`, então sem ela um
pedido que **acabou de receber** comprovante passaria a contar como confirmado —
o campo sumiria da tela do comprador e o botão sumiria da lista da loja, os dois
em silêncio. Medido depois da mudança: pedido em `conferencia` com comprovante
devolve `confirmado = false`.

### A coluna, e por que ela não mora numa aba própria

A primeira versão desta entrega punha a conferência numa aba separada do painel
(`/dashboard/comprovantes/`). Ela saiu: o comprovante é um dado *do pedido*, e
separá-lo obrigava a loja a cruzar duas telas para agir sobre a mesma linha.

Hoje são dois hooks públicos do Dokan, simétricos —
`dokan_order_listing_header_before_action_column` para o `<th>` e
`dokan_order_listing_row_before_action_field` para o `<td>`, que recebe o
**sub-pedido** daquela loja. Nenhum override de template.

A célula tem quatro estados, e o primeiro é o que a impede de mentir:

| Estado | O que sai |
| --- | --- |
| pedido pago por outro meio | um traço neutro, com a razão no `.screen-reader-text` |
| pagamento direto, sem comprovante | *Não enviado* |
| com comprovante, não confirmado | *Baixar* mais o botão *Confirmar* |
| confirmado | só *Baixar* |

Dizer "não enviado" num pedido pago por cartão seria cobrar da loja um documento
que ninguém pediu ao comprador.

O `<td>` sai em **todos** os casos, mesmo vazio: a contagem de células tem de
casar com a de cabeçalhos, ou a linha inteira desalinha e a tabela passa a
mostrar o valor de uma coluna sob o título de outra. Medido: 9 `<th>` e 9 células
em cada linha.

### O botão de confirmar não pode ser um `<form>`

A tabela de pedidos do Dokan mora **dentro** de um `<form>` dele, com campos
`status`, `security`, `_wp_http_referer` e `bulk_orders[]`. Um `<form>` impresso
na célula seria aninhado — o navegador descarta o interno e o botão passa a
submeter as ações em massa, sem erro.

O caminho é o atributo `form` do HTML5: `<button form="rc-confirmar-492">` na
célula, e o `<form id="rc-confirmar-492">` correspondente impresso em
`dokan_order_content_inside_after`, que sai **fora** do form de terceiro. Medido:
o form do Dokan ocupa os bytes `4..17781`; os nossos começam em `17802` e `18216`,
cada botão acha o seu, e os nonces são distintos — um por pedido.

### Os pedidos em conferência sobem ao topo

"Cronológica e prioritária", nas palavras do pedido: quem enviou comprovante em
tese já pagou, e esperar atrás de um pedido que ninguém pagou é a fila errada.

`priorizar_conferencia()` age em `posts_clauses`, não sobre o resultado:
`dokan_get_vendor_orders` é filtro sobre a **página corrente**, cortada em 10, e
reordenar ali deixaria o pedido em conferência na página 3 exatamente onde
estava.

A consulta se reconhece por `post_type` mais a presença de `_dokan_vendor_id` no
`where` já montado — o Dokan filtra por essa meta, nunca por `post_author`, e é
isso que dispensa uma flag global com janela de corrida.

Dentro do grupo prioritário o mais **antigo** vem primeiro; fora dele o `CASE`
devolve `NULL` para todas as linhas, que empatam e caem no `post_date DESC`
original. Medido na loja 24:

```
492 (conferência, 12:57) → 493 (conferência, 13:07) → 491 → 238 → 234
```

### Um comprovante por loja, não por pedido

A meta `_reconectar_comprovante` é gravada no **sub-pedido do Dokan**, o mesmo que
`lojas_do_pedido()` já usa para imprimir a instrução. Num carrinho de três lojas
há três pagamentos, três meios possíveis e três comprovantes.

Um arquivo único para o pedido inteiro faria o vendedor A enxergar o comprovante
do pagamento feito ao vendedor B — nome, valor e dados de conta de terceiro, o
oposto do que a LGPD pede.

O dono do sub-pedido é `dokan_get_seller_id_by_order()`, **nunca** `post_author`:
sob HPOS aquele campo não é a fonte da verdade.

### O arquivo mora fora da Media Library

Comprovante bancário é dado pessoal. Uma URL em `/wp-content/uploads/` é pública
e enumerável, e a Media Library ainda o exporia na listagem de mídia do painel,
para todo perfil com `upload_files`.

Por isso o arquivo vai para `wp-content/uploads/reconectar-comprovantes/`, com
três camadas:

1. `.htaccess` com `Require all denied` **e** `deny from all` — a imagem deste
   projeto é Apache, e as duas diretivas cobrem 2.2 e 2.4;
2. `index.php` vazio, contra listagem de diretório caso o `.htaccess` seja
   ignorado;
3. nome gerado por `wp_generate_password( 32, false )` mais a extensão que
   `wp_check_filetype_and_ext()` confirmou. É a camada que sobrevive a uma troca
   para nginx, em que o `.htaccess` vira inerte. O nome que o comprador deu ao
   arquivo fica só na meta, para exibição — nunca no disco.

Medido: as quatro URLs públicas do diretório (o arquivo, a listagem, o
`index.php` e o próprio `.htaccess`) respondem **403**.

A entrega é por rota autoral — `admin-post.php?action=reconectar_baixar_comprovante`
—, que confere nonce e direito antes de `readfile()`. Podem baixar o comprador do
pedido, a loja dona daquele sub-pedido e o `administrator`; **outra loja não**.

O nonce é emitido **por pedido** (`reconectar_baixar_comprovante_<id>`), e isso
tem uma consequência que vale registrar: trocar o `pedido=` numa URL montada à
mão nunca chega à trava de propriedade — a recusa vem antes, do nonce. A trava de
propriedade é a segunda camada, e por HTTP ela não é alcançável justamente porque
o servidor só emite o nonce a quem já tem o direito.

### O comprovante some quando não cabe mais

O campo só aparece enquanto o sub-pedido está em
`Reconectar_Pagamento_Direto::STATUS_AGUARDANDO` e a loja ainda não confirmou.
Um campo de upload num pedido já liberado é um convite a pagar de novo, na tela
em que o comprador vai justamente conferir se pagou.

Enquanto a loja não confirma, **substituir é permitido**: quem manda a foto
errada não tem outro caminho.

### A confirmação não move o pedido pai

`confirmar()` muda o status do **sub-pedido**. Numa compra multi-loja, o pai
continua aguardando até a última loja confirmar.

Sincronizar o pai exigiria decidir o que significa "metade pago", e inventar essa
semântica seria pior que declará-la. Quem quiser a visão do todo tem os
sub-pedidos, cada um com a própria nota de confirmação — autor e data.

O botão só existe **com comprovante em mãos**. Confirmar sem ele continua
possível pelo seletor de status do próprio Dokan, na mesma linha; o que a coluna
não faz é oferecer o atalho de liberar um pedido sem ter o que conferir.

Pela mesma razão, nada impede a loja de mover um pedido a *Pagamento em
conferência* à mão, sem comprovante. O efeito é ele subir ao topo sem arquivo —
inofensivo, e barrar isso exigiria interceptar a transição de status de terceiro
para ganho nenhum.

### O campo não sai no e-mail

`campo()` só é chamado no contexto `tela`. Formulário em cliente de e-mail não
funciona, e ainda espalharia a rota de upload por caixas de entrada.

Medido nos 27 templates de e-mail da instalação, nos dois estados de pedido:
nenhum traz `<form`, `enctype`, `type="file"` nem a rota. As instruções de
pagamento continuam saindo, que é o comportamento desejado.

## O que este cenário não resolve

| Não faz | Por quê | O que exigiria |
| --- | --- | --- |
| Confirmação **automática** de pagamento | sem API de banco não há como saber que o PIX caiu; quem confirma é a loja, a partir do comprovante e do próprio extrato | integração bancária ou provedor com webhook |
| Split de comissão | o dinheiro não passa pela plataforma | Dokan Pro ou provedor com split (ex.: Mercado Pago Connect) |
| Conciliação | mesma razão | idem |
| Estorno pela plataforma | idem | idem |
| Saque pela dashboard | não há saldo retido | idem |

## A comissão fica em zero

O Dokan calcula comissão sobre todo pedido e mostra o resultado na dashboard da
loja, como desconto sobre o que ela recebeu. Neste arranjo a plataforma não
retém nada: o dinheiro nunca passa por ela.

Por isso o `provision.sh` grava `dokan_selling` com `admin_commission` em zero —
tanto o percentual quanto a taxa fixa. Não é omissão nem valor provisório: é a
única forma de a tela da loja dizer a verdade. O assistente de configuração do
Dokan propõe 10% mais R$10 como padrão, e aceitá-lo faria cada loja ver um
desconto que ninguém cobra — número plausível e falso, que é o pior tipo de dado
para se ter numa interface.

O mesmo bloco marca o assistente como concluído (as quatro etapas e a opção de
conclusão, que é separada delas). Sem isso o painel abre com a faixa "Complete
your marketplace setup in minutes" por cima das Configurações — e segui-la é
justamente o que introduziria a comissão.

Se um dia a plataforma passar a reter parte do valor, o número volta aqui e
deixa de ser zero. Enquanto não retém, ele é zero.

## Se um provedor for adotado no futuro

Nada do que está aqui precisa ser desfeito: os dois gateways autorais convivem
com qualquer plugin de gateway, porque a API do WooCommerce é uma lista.

Quando isso acontecer, as credenciais (chaves de API, tokens) devem ser
inseridas **apenas** por `WooCommerce → Configurações → Pagamentos`, nunca em
código versionado. Credenciais de sandbox para desenvolvimento local seguem o
padrão do projeto: `.env`, que é ignorado pelo Git.

As decisões que continuariam pendentes, e que são de negócio e não técnicas:
escolha do provedor considerando taxa e prazo de repasse; qual CNPJ receberia os
repasses de cada loja; e se o orçamento cobre o Dokan Pro.
