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
`woocommerce_after_checkout_validation`, onde `validar_checkout()` recusa um
pedido que nenhuma loja conseguiria receber por inteiro. No bloco, os dois
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

### A regra da interseção

O carrinho pode ter produtos de mais de uma loja, e cada loja declara os meios
que aceita. O meio oferecido tem de ser um que **todas** aceitem.

| Método | Quando |
| --- | --- |
| `is_available()` → `false` | nenhuma loja do carrinho tem o dado daquele método |
| `validar_checkout()` → erro `reconectar_pagamento_indisponivel` | alguma loja do carrinho não aceita o meio escolhido |

Quando um meio não é oferecido, a tela diz **qual** loja não pode receber por
ali. Uma lista de meios vazia, sem explicação, mandaria o comprador embora sem
saber o que fazer.

Uma loja que não cadastrou meio nenhum **não vende**. Não é um estado de erro: é
a consequência verdadeira de não dizer como receber.

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
com o nome da loja, os dados do meio escolhido e o **valor do sub-pedido**
correspondente. Os blocos somam o total do pedido-pai.

Um bloco só, com a soma, mandaria o comprador pagar tudo para uma das lojas.

Os dados saem de `dokan_get_store_info( $vendor_id )`, na chave `payment` do
`dokan_profile_settings`. Só o necessário é exibido, e **nada disso vai para
URL** — a chave PIX de uma pessoa física costuma ser o CPF dela.

## BR Code copia-e-cola

`reconectar_pix_br_code()` monta o payload estático EMV MPM a partir de chave,
nome, cidade e valor: montagem de TLV mais CRC16-CCITT/FALSE. É cálculo puro —
sem dependência externa, sem build e sem chamada de rede, o que o mantém dentro
das regras do projeto.

**A conferência que vale é colar o código no app de um banco real.** Um código
que o banco recusa é pior que nenhum código: se não passar, a entrega sai com os
dados da chave em texto e sem o copia-e-cola.

QR Code em imagem está **fora de escopo**: exigiria biblioteca nova, e o
copia-e-cola resolve o caso no celular.

## O que este cenário não resolve

| Não faz | Por quê | O que exigiria |
| --- | --- | --- |
| Confirmação automática de pagamento | sem API de banco não há como saber que o PIX caiu | integração bancária ou provedor com webhook |
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
