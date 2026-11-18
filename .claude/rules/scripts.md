---
paths:
  - "scripts/**"
  - ".github/**"
  - "docker-compose.yml"
---

# Armadilhas — provisionamento, WP-CLI, carga e URLs

Continuação da seção **Armadilhas** do `CLAUDE.md` da raiz, carregada só quando
a sessão mexe nos caminhos acima. Quando um item fala em armadilha "registrada
acima" ou "adiante", ela pode estar na raiz ou em outro arquivo de `.claude/rules/`.

## Os passos do assistente do Dokan escutam `updated_option`, não `added_option`

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

## `wp option patch update` exige a chave; `patch insert` exige a opção

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

## `get_saved_products_category()` do Dokan é um leitor que **grava**

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

## `reconectar_demo_remover()` lista post types explicitamente

Um tipo novo na carga **não sai sozinho**. E o filtro é sempre
`RECONECTAR_DEMO_META`: `get_posts()` por `post_type => 'topic'` sem a meta
devolve o fórum inteiro da instalação — a forma bbPress do defeito do
`wc_get_orders()`.

## `wp_delete_term()` com a taxonomia errada devolve `false` em silêncio

A carga cria termos em `product_cat` e em `topic-tag`. A remoção busca a
taxonomia no `term_taxonomy` em vez de assumir uma; sem isso, sobrevivem tags
que levam a listas vazias.

## `get_page_by_path()` devolve rascunho e lixeira

Sem conferir `post_status`, um helper de URL entrega o permalink de uma página
despublicada — link válido que leva ao 404 para quem não está logado.

## O slug das páginas do WooCommerce sai no idioma ativo na ativação

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

## Página criada à mão não atravessa o deploy — e o consumidor dela cala

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

## As guardas de idempotência protegem a instalação errada

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

## `--ssl=0` só vale em `wp db *`

Qualquer outro comando do WP-CLI rejeita a flag.

## `wp config set` não enxerga constante cujo valor é expressão

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

## URL gravada no banco não acompanha `WP_HOME` dinâmico

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

## O filtro de `Host` é uma allowlist, não o cabeçalho cru

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

## Configuração que aponta para anexo não atravessa o deploy

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
