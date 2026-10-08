#!/usr/bin/env bash
# Script de provisionamento do ambiente local da plataforma Reconectar.
# Executa via WP-CLI dentro do container "wpcli":
#   docker compose run --rm wpcli bash /var/www/scripts/provision.sh
#
# Idempotente: pode ser rodado novamente com segurança (ex.: após criar o
# tema "reconectar" na Task 5, ou após adicionar novos plugins no futuro).

set -euo pipefail

WP_ADMIN_USER="${WP_ADMIN_USER:-admin}"
WP_ADMIN_PASSWORD="${WP_ADMIN_PASSWORD:-reconectar-admin}"
WP_ADMIN_EMAIL="${WP_ADMIN_EMAIL:-admin@reconectar.local}"
WP_TITLE="${WP_TITLE:-Reconectar - Incubadora Digital}"
WP_URL="${WP_URL:-http://localhost:8090}"

echo "== Aguardando o banco de dados =="
# "--ssl=0" é necessário porque a imagem wordpress:cli-php8.2 traz um cliente
# MariaDB Connector/C que exige TLS por padrão, enquanto o serviço "db"
# (MariaDB local, sem TLS habilitado) não o suporta. Sem essa flag, o
# comando externo "mariadb-check" (chamado com --no-defaults, que ignora
# qualquer my.cnf) falha com "SSL is required, but the server does not
# support it". A conexão ocorre inteiramente na rede interna do Docker
# Compose, então desabilitar TLS aqui é seguro.
wp db check --skip-plugins --skip-themes --ssl=0 || {
  echo "Banco de dados ainda não disponível. Verifique se o serviço 'db' está saudável (docker compose ps)."
  exit 1
}

echo "== Núcleo do WordPress =="
if wp core is-installed 2>/dev/null; then
  echo "WordPress já instalado, pulando."
else
  wp core install \
    --url="$WP_URL" \
    --title="$WP_TITLE" \
    --admin_user="$WP_ADMIN_USER" \
    --admin_password="$WP_ADMIN_PASSWORD" \
    --admin_email="$WP_ADMIN_EMAIL" \
    --skip-email
fi

echo "== Depuração: avisos no log, nunca na tela =="
# `WORDPRESS_DEBUG=1` no compose liga `WP_DEBUG`, e o padrão de
# `WP_DEBUG_DISPLAY` é imprimir na resposta. Um único aviso impresso antes de
# `wp_redirect()` derruba os dois `header()` de `pluggable.php` com "headers
# already sent" — e o efeito não é uma mensagem feia numa tela que funciona: é
# a ação não acontecer. Foi assim que salvar uma pergunta no fórum passou a
# terminar numa tela de erros, com o tópico gravado e o usuário sem nunca
# chegar nele.
#
# Desligar `WP_DEBUG` inteiro esconderia o problema; mandar para
# `wp-content/debug.log` mantém o aviso onde ele serve, e fora do corpo da
# resposta. Vale para qualquer aviso futuro, não só para o que originou isto.
# Cada entrada é `CONSTANTE:literal:avaliado`. Os dois valores são necessários
# porque `wp config set --raw` grava o literal (`true`, `false`) enquanto
# `wp config get` devolve o resultado da avaliação em PHP — `1` e string vazia.
# Comparar com o literal nunca casa, e o bloco reescrevia as constantes a cada
# execução dizendo "definida" para o que já estava lá.
#
# E `wp config get` de constante inexistente também devolve vazio, que é o
# mesmo de `false`: sem o `wp config has`, `WP_DEBUG_DISPLAY` pareceria já
# configurada numa instalação em que ela nunca foi escrita.
for par in WP_DEBUG_LOG:true:1 WP_DEBUG_DISPLAY:false:; do
  constante="${par%%:*}"
  resto="${par#*:}"
  literal="${resto%%:*}"
  avaliado="${resto#*:}"
  if wp config has "$constante" --type=constant >/dev/null 2>&1 &&
    [ "$(wp config get "$constante" --type=constant 2>/dev/null)" = "$avaliado" ]; then
    echo "$constante já estava em $literal."
  else
    wp config set "$constante" "$literal" --raw --type=constant
    echo "$constante definida como $literal."
  fi
done

echo "== URL do site: o host de quem pede =="
# `wp core install --url` grava `home` e `siteurl` com um endereço fixo. O HTML
# continua saindo do servidor quando alguém abre pelo IP da máquina, mas todo
# asset, link e miniatura vem carimbado com `localhost:8090` — e `localhost`, no
# celular, é o próprio celular. O resultado é a página crua, sem CSS nenhum,
# com as imagens quebradas. Parece defeito do tema e é a URL gravada no banco.
#
# Trocar as opções para o IP só inverte quem fica de fora, e ainda obrigaria a
# reprovisionar a cada troca de rede, já que o endereço vem de DHCP. O bloco
# escrito abaixo atende os dois acessos ao mesmo tempo. A razão de cada decisão
# dele — precedência sobre as opções, constante em vez de filtro, allowlist em
# vez de `Host` cru — está comentada no próprio bloco, que é onde alguém lendo
# o `wp-config.php` vai precisar dela.
#
# O passo é um script PHP, e não três `wp config set`, porque o comando não
# serve aqui: o `wp-config-transformer` não enxerga definição cujo valor é
# expressão, nunca encontra a anterior e acrescenta uma linha nova a cada
# execução. Veja o cabeçalho de `configurar-url-dinamica.php`.
reconectar_host_padrao="${WP_URL#*://}"
php /var/www/scripts/configurar-url-dinamica.php "$(wp config path)" "$reconectar_host_padrao"

echo "== Idioma (pt_BR) =="
wp language core is-installed pt_BR || wp language core install pt_BR
wp site switch-language pt_BR || true

echo "== Tema =="
wp theme is-installed storefront || wp theme install storefront

if [ -d "/var/www/html/wp-content/themes/reconectar" ]; then
  echo "Tema autoral 'reconectar' encontrado, ativando."
  wp theme activate reconectar
else
  echo "Tema autoral 'reconectar' ainda não existe (será criado na Task 5). Ativando 'storefront' por enquanto."
  wp theme activate storefront
fi

echo "== Logo do cabeçalho =="
# `header.php:47` desenha a logo personalizada e, sem ela, cai no nome do site em
# Thoge — uma fonte de display, que numa linha de cabeçalho sai ilegível. O
# primeiro deploy na EC2 subiu assim: a logo estava definida em desenvolvimento,
# mas `custom_logo` guarda o ID de um anexo, e anexo mora em `wp-content/uploads/`,
# que não é versionado nem entra no recorte do `rsync`. Nada no servidor tinha
# como saber qual imagem é a marca.
#
# O arquivo em si sempre esteve lá — `assets/img/` é do tema, e o tema sobe
# inteiro. O que faltava era importá-lo para a biblioteca e apontar a mod.
#
# A guarda confere o ANEXO, e não só a mod: um banco restaurado sem a mídia
# deixaria `custom_logo` apontando para um ID que não existe mais, e aí o
# cabeçalho volta ao fallback enquanto o provisionamento diria "já definida".
if [ -d "/var/www/html/wp-content/themes/reconectar" ]; then
  id_logo="$(wp theme mod get custom_logo --field=value 2>/dev/null || true)"

  if [ -n "$id_logo" ] && wp post get "$id_logo" --field=ID >/dev/null 2>&1; then
    echo "Logo do cabeçalho já definida (anexo $id_logo)."
  else
    id_logo="$(wp media import wp-content/themes/reconectar/assets/img/logo-apoio-cor.png \
      --title="Reconectar - Incubadora Digital" --porcelain)"
    wp theme mod set custom_logo "$id_logo"
    echo "Logo do cabeçalho importada (anexo $id_logo)."
  fi
fi

echo "== Plugins do marketplace e comunidade =="
# `nextend-facebook-connect` é o Nextend Social Login: apesar do slug, a versão
# livre entrega Facebook, Google e X. Ele fica instalado e ativo mesmo sem
# credencial OAuth configurada, e essa é a intenção — sem Client ID e Secret o
# plugin simplesmente não imprime o provedor, e a tela de login degrada para
# e-mail e senha em vez de exibir um botão que erraria ao ser clicado. As
# credenciais são criadas no Google Cloud Console e no Meta for Developers e
# coladas no `wp-admin`; não entram no repositório.
for plugin in woocommerce dokan-lite buddypress bbpress nextend-facebook-connect; do
  if wp plugin is-installed "$plugin"; then
    echo "Plugin '$plugin' já instalado."
  else
    wp plugin install "$plugin"
  fi

  if wp plugin is-active "$plugin"; then
    echo "Plugin '$plugin' já ativo."
  else
    wp plugin activate "$plugin"
  fi
done

echo "== Plugin autoral 'reconectar-core' =="
if [ -d "/var/www/html/wp-content/plugins/reconectar-core" ]; then
  wp plugin is-active reconectar-core || wp plugin activate reconectar-core
else
  echo "Plugin autoral 'reconectar-core' ainda não existe (será criado na Task 6). Pulando ativação."
fi

echo "== Idioma dos plugins e temas (pt_BR) =="
# `wp language core install` traduz só o núcleo. Sem este bloco, o painel
# administrativo aparecia em português enquanto a loja inteira — carrinho,
# checkout, painel do vendedor, fóruns — continuava em inglês: "Cart",
# "Proceed to Checkout", "Place Order", "Vendor:". Não era falta de arquivo
# `.mo` autoral nem caso para um filtro `gettext`; era um passo de instalação
# que nunca havia sido dado.
#
# O pacote do WordPress.org traz junto os `.json` que o `wp_set_script_translations()`
# usa, então os blocos React do WooCommerce (carrinho e checkout) também
# passam a falar português — é o que torna estas duas linhas a menor
# alteração possível para o maior ganho.
#
# Precisa rodar DEPOIS da instalação dos plugins: o WP-CLI só busca pacote
# para o que já está no disco. Roda mais de uma vez sem duplicar, e sai com
# status 0 mesmo quando um pacote não existe no repositório — hoje é o caso
# do Dokan Lite, que não tem tradução oficial em pt_BR. Por isso nenhum `||
# true` aqui: um erro real ainda deve derrubar o script.
wp language plugin install --all pt_BR
wp language theme install --all pt_BR

echo "== Tradução autoral do Dokan Lite (pt_BR) =="
# O Dokan Lite não tem pacote pt_BR no WordPress.org, então a tradução mora
# no repositório, em `scripts/dokan-lite-pt_BR.po`, e é compilada aqui.
# `wp-content/languages/` não é versionado nem sincronizado pelo deploy: os
# arquivos que existiam na máquina de desenvolvimento tinham sido copiados à
# mão e nunca chegariam ao servidor.
#
# São três saídas, e nenhuma substitui a outra:
#   - `.l10n.php`: desde o WordPress 6.5 é o que o núcleo lê **antes** do
#     `.mo`. Gerar só o `.mo` deixaria valendo um `.l10n.php` antigo, e a
#     tradução nova não apareceria, sem erro nenhum.
#   - `.mo`: o caminho de leitura para quem não tem o `.l10n.php`.
#   - `.json`: as telas em React do painel do vendedor e do admin, que leem
#     por `wp_set_script_translations()`. `--no-purge` porque o padrão do
#     `make-json` apaga do `.po` as strings de JavaScript que ele extrai.
#
# Os `.json` antigos saem antes: o nome deles é o md5 do caminho do script,
# e um script que o Dokan removeu deixaria para trás um arquivo que nada lê.
#
# Se um dia o WordPress.org publicar o pacote oficial, `wp language plugin
# install` acima e as atualizações automáticas de tradução gravariam nestes
# mesmos nomes. Este bloco roda depois e prevalece no provisionamento; entre
# um provisionamento e outro, vale o que a atualização automática gravou.
DOKAN_PO_ORIGEM="/var/www/scripts/dokan-lite-pt_BR.po"
DIR_IDIOMAS_PLUGINS="/var/www/html/wp-content/languages/plugins"
if [ -f "$DOKAN_PO_ORIGEM" ] && wp plugin is-installed dokan-lite; then
  mkdir -p "$DIR_IDIOMAS_PLUGINS"
  rm -f "$DIR_IDIOMAS_PLUGINS"/dokan-lite-pt_BR-*.json
  cp "$DOKAN_PO_ORIGEM" "$DIR_IDIOMAS_PLUGINS/dokan-lite-pt_BR.po"
  wp i18n make-mo "$DIR_IDIOMAS_PLUGINS/dokan-lite-pt_BR.po" "$DIR_IDIOMAS_PLUGINS/dokan-lite-pt_BR.mo"
  wp i18n make-php "$DIR_IDIOMAS_PLUGINS/dokan-lite-pt_BR.po" "$DIR_IDIOMAS_PLUGINS"
  wp i18n make-json "$DIR_IDIOMAS_PLUGINS/dokan-lite-pt_BR.po" "$DIR_IDIOMAS_PLUGINS" --no-purge
else
  echo "Tradução do Dokan Lite ou o próprio plugin ausente. Pulando."
fi

echo "== Vitrine pública (cortina 'Em breve' do WooCommerce) =="
# O WooCommerce instala com "coming_soon" ligado desde a versão 9.1: loja,
# produto, carrinho e checkout ficam atrás de uma cortina que só o
# administrador atravessa. Num ambiente de demonstração isso é indistinguível
# de um defeito — o visitante deslogado vê uma página de espera no lugar da
# loja inteira, e o menu principal parece quebrado.
#
# A cortina existe para quem monta uma loja de verdade em produção, não para
# um ambiente que o provisionamento acaba de montar do zero. Desligá-la aqui
# não é decisão de negócio: é terminar a instalação.
if [ "$(wp option get woocommerce_coming_soon 2>/dev/null || true)" = "no" ]; then
  echo "Vitrine já pública."
else
  wp option update woocommerce_coming_soon no
fi

echo "== Localização da loja (moeda, país e unidades) =="
# O WooCommerce instala com os padrões dos Estados Unidos e nunca pergunta:
# moeda USD, país "US:CA", peso em libras, comprimento em polegadas, ponto
# como separador decimal. Num marketplace de edital brasileiro isso aparecia
# como "$115.00" na vitrine e um checkout abrindo no estado da Califórnia.
#
# Trocar a moeda não converte nenhum valor, e é por isso que cabe aqui: os
# preços estão gravados como números puros (115.00), sem moeda intrínseca —
# declarar BRL nomeia a moeda que o projeto sempre teve na prática, não
# reprecifica nada. O mesmo vale para as unidades: nenhum produto tem `_weight`
# ou `_length` gravado, então "lbs" → "kg" não reinterpreta medida alguma,
# apenas define a unidade dos próximos cadastros. Se algum dia houver peso
# gravado em libras, esta linha passa a exigir conversão junto — não a mova
# para cá sem checar.
#
# `woocommerce_allowed_countries` fica de fora de propósito: restringir a
# venda ao Brasil é decisão de negócio do edital, não resíduo de instalação.
for par in \
  "woocommerce_currency=BRL" \
  "woocommerce_currency_pos=left_space" \
  "woocommerce_price_decimal_sep=," \
  "woocommerce_price_thousand_sep=." \
  "woocommerce_default_country=BR" \
  "woocommerce_weight_unit=kg" \
  "woocommerce_dimension_unit=cm"
do
  opcao="${par%%=*}"
  valor="${par#*=}"

  if [ "$(wp option get "$opcao" 2>/dev/null || true)" = "$valor" ]; then
    echo "Opção '$opcao' já é '$valor'."
  else
    wp option update "$opcao" "$valor"
  fi
done

# Grava uma chave dentro de uma opção-mapa, exista ela ou não.
#
# `wp option patch update` exige que a CHAVE já esteja lá e sai com erro quando
# falta — sob `set -e`, isso aborta o provisionamento inteiro. E numa instalação
# nova ela falta: medido na EC2, `dokan_appearance` nasce sem
# `show_register_as_vendor` e o deploy morreu em `No data exists for key`. Aqui a
# chave existia porque a tela do Dokan já tinha sido salva alguma vez, e foi por
# isso que o defeito nunca apareceu em desenvolvimento.
#
# Quem cria a chave é `patch insert`, que também atualiza a já existente. Ele
# exige, por sua vez, que a OPÇÃO exista: sem ela sai em `Cannot create key …
# on data type boolean`, porque `get_option()` devolveu `false`. Criá-la como
# objeto vazio é inócuo — o Dokan lê cada chave com o default interno dela.
#
# Argumentos: <opção> <chave> [--format=json] <valor>
reconectar_gravar_chave_de_opcao() {
  local opcao="$1"; shift

  if ! wp option get "$opcao" >/dev/null 2>&1; then
    wp option add "$opcao" --format=json '{}'
  fi

  wp option patch insert "$opcao" "$@"
}

echo "== Autocadastro de loja (desligado) =="
# Quem cadastra loja nesta plataforma é o Administrador ou o Administrador de
# Empresas, pelo painel de empresas. As duas linhas abaixo NÃO são a trava — a
# trava é `Reconectar_Cadastro_De_Lojas`, no plugin autoral, e ela existe
# justamente porque esta opção do Dokan fecha só o checkbox do formulário de
# registro, deixando abertos os dois shortcodes dedicados e o "Become a vendor".
# O que se ganha aqui é coerência de tela: sem isto o site continuaria
# oferecendo um caminho que o servidor recusa.
if [ "$(wp option pluck dokan_appearance show_register_as_vendor 2>/dev/null || true)" = "off" ]; then
  echo "Autocadastro de loja já desligado."
else
  reconectar_gravar_chave_de_opcao dokan_appearance show_register_as_vendor off
fi

# A página de onboarding ficaria em branco depois que o shortcode dela sai do ar.
pagina_onboarding="$(wp post list --post_type=page --name=vendor-onboarding --post_status=publish --field=ID)"
if [ -n "$pagina_onboarding" ]; then
  wp post update "$pagina_onboarding" --post_status=draft
  echo "Página '/vendor-onboarding/' passada para rascunho."
else
  echo "Página '/vendor-onboarding/' já não está publicada."
fi

echo "== Meios de pagamento =="
# O comprador paga cada loja direto, por PIX ou transferência, com os dados que
# a própria loja cadastrou. A loja cadastra isso em Configurações → Pagamento da
# dashboard do Dokan, e essa tela só lista os métodos que estiverem nesta opção.
#
# `withdraw_methods` é um **mapa**, não uma lista: medido, o valor de fábrica é
# `{"paypal":"","bank":"bank"}` — chave desligada guarda string vazia, ligada
# repete o próprio nome. Gravar `["pix","bank"]` aqui não liga nada e ainda
# apaga o `bank` que já estava de pé, e o sintoma é a tela existir vazia com
# "No withdraw method is available", sem dizer por quê.
if [ "$(wp option pluck dokan_withdraw withdraw_methods --format=json 2>/dev/null || true)" = '{"paypal":"","bank":"bank","pix":"pix"}' ]; then
  echo "Métodos de recebimento da loja já configurados."
else
  reconectar_gravar_chave_de_opcao dokan_withdraw withdraw_methods --format=json '{"paypal":"","bank":"bank","pix":"pix"}'
fi

# Os gateways de fábrica do WooCommerce competem com os autorais e mandariam o
# dinheiro para o lugar errado: `bacs` é transferência para uma conta da
# **plataforma** — que não existe neste arranjo e nunca foi preenchida — e tem o
# mesmo rótulo do gateway autoral, de modo que o comprador veria duas
# "Transferência bancária" e não teria como distinguir. `cod` promete pagamento
# na entrega, que nenhuma loja se comprometeu a aceitar.
#
# A chave `enabled` só existe depois que o gateway é salvo ao menos uma vez:
# medido, `woocommerce_cheque_settings` não a tem. Aqui a ausência dela é
# informação, e não um caso a consertar — por isso este bloco NÃO usa
# `reconectar_gravar_chave_de_opcao`: sem a chave, o gateway está no padrão de
# fábrica dele, que já é desligado, e gravar `no` só criaria configuração para
# repetir o que já vale. O `[ -z "$estado" ]` abaixo é essa leitura.
#
# O `patch update` do ramo `else` é seguro porque só se chega nele com a chave
# lida e diferente de `no`.
for gateway in bacs cheque cod; do
  estado="$(wp option pluck "woocommerce_${gateway}_settings" enabled 2>/dev/null || true)"

  if [ -z "$estado" ] || [ "$estado" = "no" ]; then
    echo "Gateway padrão '$gateway' já desligado."
  else
    wp option patch update "woocommerce_${gateway}_settings" enabled no
  fi
done

echo "== Carrinho e checkout clássicos =="
# O WooCommerce cria estas duas páginas com os blocos `woocommerce/cart` e
# `woocommerce/checkout`, e o bloco de checkout **não enxerga gateway clássico**.
# Ele desenha apenas o que vier registrado em
# `woocommerce_blocks_payment_method_type_registration`, um ponto de extensão que
# exige uma classe `AbstractPaymentMethodType` mais um script chamando
# `registerPaymentMethod` no navegador. Um `WC_Payment_Gateway` que não faça isso
# some da tela — e some sem erro.
#
# Medido no HTML da página, com o carrinho montado e a loja com chave PIX
# cadastrada:
#
#   paymentMethodSortOrder: ["reconectar_pix","reconectar_transferencia"]
#   paymentMethodData:      []
#   payment_methods:        ["reconectar_pix"]   (Store API do carrinho)
#
# O servidor sabia que o PIX estava disponível; o bloco não tinha como desenhá-lo.
# O comprador via "Não há métodos de pagamento disponíveis", que é a pior forma
# do defeito: a tela está correta, o gateway está ligado e nada indica a causa.
#
# O shortcode resolve os três problemas de uma vez. Além do gateway aparecer, é
# o caminho clássico que chama `payment_fields()` — onde o aviso de qual loja não
# recebe por aquele meio é impresso, requisito registrado em `docs/PAGAMENTOS.md`
# — e `woocommerce_after_checkout_validation`, onde `validar_checkout()` recusa
# um pedido que nenhuma loja conseguiria receber por inteiro. No bloco, os dois
# simplesmente não rodam.
#
# A divisão em sub-pedidos do Dokan **não** é o motivo: medido, ele pendura
# `split_vendor_orders` e `dokan_sync_insert_order` também em
# `woocommerce_store_api_checkout_order_processed`, então ela funcionaria nos dois
# caminhos.
#
# Escrever a integração de blocos continua possível, e seria o caminho se o
# checkout em blocos virasse requisito. Ele custa uma classe por gateway, um
# script sem etapa de compilação (o projeto não tem build) e um equivalente da
# validação no Store API — e ainda esbarra em `paymentMethodData` ser resolvido
# uma vez por carregamento, enquanto a lista de lojas sem chave muda com o
# carrinho.
#
# O título em português vem junto porque as duas páginas nascem com o nome em
# inglês: o WooCommerce as cria no ativar, antes de o pacote de idioma estar de
# pé, e o texto fica gravado como dado — nenhuma tradução posterior o alcança. O
# sintoma aparece longe da causa, na trilha de navegação ("Início › Cart") e na
# aba do navegador, num site inteiramente em português.
#
# Trocar `post_title` é seguro: o `wp_update_post` só gera `post_name` quando ele
# está vazio, então `/cart/` e `/checkout/` continuam valendo — e é por isso que
# a rota não é traduzida junto. Mudá-la quebraria todo link já publicado, e o
# `woocommerce_cart_page_id` aponta para o ID, não para o caminho.
for par in \
  "cart=woocommerce_cart=Carrinho" \
  "checkout=woocommerce_checkout=Finalizar compra"
do
  chave="${par%%=*}"
  resto="${par#*=}"
  shortcode="${resto%%=*}"
  titulo="${resto#*=}"
  pagina="$(wp option get "woocommerce_${chave}_page_id" 2>/dev/null || true)"

  if [ -z "$pagina" ] || [ "$pagina" -lt 1 ]; then
    echo "Página de '$chave' ainda não existe; nada a converter."
    continue
  fi

  if wp post get "$pagina" --field=post_content | grep -q "\[${shortcode}\]"; then
    echo "Página de '$chave' já usa o shortcode clássico."
  else
    wp post update "$pagina" --post_content="<!-- wp:shortcode -->[${shortcode}]<!-- /wp:shortcode -->"
  fi

  if [ "$(wp post get "$pagina" --field=post_title)" = "$titulo" ]; then
    echo "Página de '$chave' já se chama '$titulo'."
  else
    wp post update "$pagina" --post_title="$titulo"
  fi
done

echo "== Compra e avaliação só com login =="
# Requisito: só quem tem conta compra e avalia. O WooCommerce nasce com o
# checkout de visitante ligado e o WordPress com comentário anônimo aberto —
# medido nos dois ambientes, um visitante finalizava pedido e deixava avaliação
# com nota. Quem recusa no servidor é o núcleo: `WC_Checkout` sem conta, e
# `wp_handle_comment_submission()` com 403. A avaliação de loja não precisa de
# linha própria: o Dokan Lite tira a nota da loja das avaliações dos produtos.
#
# `comment_registration` vale para todo comentário enviado por formulário, não só
# avaliação de produto — post de blog incluído. Notas de pedido, que também são
# comentários, o sistema grava sem passar por `wp-comments-post.php`, e o fórum é
# bbPress: nenhum dos dois é alcançado.
#
# O lembrete de login do checkout fica DESLIGADO de propósito: ele imprime um
# formulário oculto, com um texto que manda o visitante "para a seção de
# cobrança", que ele não tem. O tema põe no lugar um bloco próprio, visível e com
# link de cadastro — `inc/marketplace/so-com-login.php`.
for par in \
  "woocommerce_enable_guest_checkout=no" \
  "woocommerce_enable_checkout_login_reminder=no" \
  "comment_registration=1"
do
  opcao="${par%%=*}"
  valor="${par#*=}"

  if [ "$(wp option get "$opcao" 2>/dev/null || true)" = "$valor" ]; then
    echo "Opção '$opcao' já é '$valor'."
  else
    wp option update "$opcao" "$valor"
  fi
done

echo "== Assistente de configuração do Dokan =="
# Sem este bloco o painel abre com a faixa "Complete your marketplace setup in
# minutes" por cima das Configurações do Dokan, e ela não some sozinha: o
# assistente considera pendentes as etapas `basic` e `commission`, que são as
# duas que dependem da opção `dokan_selling`.
#
# A opção nasce ausente, e é por aí que a armadilha entra: os passos do
# assistente escutam **`updated_option`**, nunca `added_option`. Uma opção que
# ainda não existe é criada por `add_option`, que dispara o segundo — então
# gravar `dokan_selling` pela primeira vez NÃO marca etapa nenhuma. (As outras
# duas já apareciam marcadas nesta instalação por efeito colateral: o
# provisionamento regrava `dokan_withdraw` e `dokan_appearance`, que já
# existiam.) Depender desse efeito colateral seria pior ainda numa segunda
# execução, quando o valor é igual ao gravado e `update_option` não dispara
# nada. Por isso as opções de conclusão são escritas à mão, abaixo.
#
# A comissão vai a ZERO de propósito, e essa é a parte que não pode ser copiada
# dos padrões da tela. O assistente propõe 10% mais R$10 fixos; medido em
# `wp_dokan_orders`, hoje `net_amount` é igual ao `order_total` em todos os
# pedidos — a plataforma não retém nada, porque o comprador paga a loja direto
# (veja `docs/PAGAMENTOS.md`). Aceitar o padrão faria a dashboard de cada loja
# passar a exibir um desconto que ninguém cobra: número plausível e falso, que é
# exatamente o que a regra de honestidade de dados deste projeto proíbe.
if wp option get dokan_selling --format=json >/dev/null 2>&1; then
  echo "Regras de venda do Dokan já gravadas."
else
  wp option add dokan_selling --format=json '{"shipping_fee_recipient":"seller","tax_fee_recipient":"seller","shipping_tax_fee_recipient":"seller","order_status_change":"on","new_seller_enable_selling":"on","commission_type":"fixed","admin_commission":{"additional_fee":"0","admin_percentage":"0"},"commission_category_based_values":{"items":[],"all":{"flat":"","percentage":""}}}'
fi

# Cada etapa guarda a própria conclusão, e o assistente inteiro guarda a dele em
# opção separada — marcar as quatro não marca o assistente. Medido: com as quatro
# em `1`, `AdminSetupGuide::is_setup_complete()` continuava devolvendo `false` e
# a faixa seguia na tela.
for etapa in basic commission withdraw appearance; do
  opcao="dokan_admin_onboarding_setup_step_${etapa}_completed"

  if [ "$(wp option get "$opcao" 2>/dev/null || true)" = "1" ]; then
    echo "Etapa '$etapa' do assistente já concluída."
  else
    wp option update "$opcao" 1
  fi
done

if [ "$(wp option get dokan_admin_setup_guide_steps_completed 2>/dev/null || true)" = "1" ]; then
  echo "Assistente de configuração do Dokan já concluído."
else
  wp option update dokan_admin_setup_guide_steps_completed 1
fi

echo "== Resíduos da instalação padrão do WordPress =="
# O WordPress nasce com um post "Hello world!", um comentário de "A WordPress
# Commenter" e uma sidebar de blog. Nada disso é invisível: o post e o
# comentário apareciam nos widgets "Posts recentes" e "Comentários recentes"
# em cinco páginas públicas — loja, carrinho, vitrine de lojas, transparência
# e a página de produto. A vitrine do edital exibia "Hello world!" ao lado do
# preço do artesanato.
#
# A sidebar custa mais do que o constrangimento: o Storefront decide a largura
# do conteúdo pela existência de widget na `sidebar-1` e reservava 26% da linha
# para uma coluna de blog num site que não tem blog. Esvaziá-la devolve essa
# largura à loja.
#
# Post e comentário vão para a LIXEIRA, não para o `--force`: são reversíveis
# pelo painel se alguém quiser conferir o que saiu.
post_exemplo="$(wp post list --post_type=post --name=hello-world --post_status=publish --field=ID)"
if [ -n "$post_exemplo" ]; then
  wp post delete "$post_exemplo"
else
  echo "Post 'Hello world!' já removido."
fi

comentario_exemplo="$(wp comment list --status=approve --search='A WordPress Commenter' --field=ID --number=1)"
if [ -n "$comentario_exemplo" ]; then
  wp comment delete "$comentario_exemplo"
else
  echo "Comentário de exemplo já removido."
fi

# A remoção dos widgets é condicionada à lista ser EXATAMENTE a de fábrica.
# Não é preciosismo: é o que impede o provisionamento de apagar, na segunda
# execução, a sidebar que o administrador tiver montado no meio-tempo. Mexeu
# em qualquer um dos cinco, a lista difere e este bloco passa direto.
if [ "$(wp widget list sidebar-1 --format=ids)" = "block-2 block-3 block-4 block-5 block-6" ]; then
  wp widget delete block-2 block-3 block-4 block-5 block-6
  echo "Sidebar de blog padrão esvaziada."
else
  echo "Sidebar-1 já foi ajustada, preservando como está."
fi

echo "== Página da Comunidade (BuddyPress) =="
# A página nasce vazia: `Reconectar_Comunidade` a redireciona ao diretório de
# atividade. O conteúdo antigo, `[buddypress]`, é um shortcode que o BuddyPress
# 14 não registra, e saía cru na tela. Ela existe porque menu e rodapé apontam
# para ela pelo ID.
comunidade_id="$(wp post list --post_type=page --title="Comunidade" --field=ID | head -n1)"
if [ -z "$comunidade_id" ]; then
  wp post create \
    --post_type=page \
    --post_title="Comunidade" \
    --post_status=publish \
    --post_content=""
elif [ "$(wp post get "$comunidade_id" --field=post_content)" = "[buddypress]" ]; then
  # Reparo das instalações provisionadas antes: só toca o conteúdo que o
  # próprio script gravou, nunca o que o administrador tiver escrito ali.
  wp post update "$comunidade_id" --post_content=""
  echo "Página 'Comunidade' sem o shortcode inexistente."
else
  echo "Página 'Comunidade' já existe."
fi

echo "== Títulos dos diretórios do BuddyPress =="
# O BuddyPress cria as páginas dos diretórios no idioma da ativação, como o
# WooCommerce (veja a armadilha no CLAUDE.md), e o título delas é o <h1> da
# tela. Só traduz o título de fábrica: renomeado à mão, fica como está. O slug
# não muda — é a URL do diretório.
for par in "activity:Activity:Comunidade" "members:Members:Membros"; do
  componente="${par%%:*}"; resto="${par#*:}"; de="${resto%%:*}"; para="${resto#*:}"
  pagina_id="$(wp option pluck bp-pages "$componente" 2>/dev/null || true)"
  [ -n "$pagina_id" ] || continue
  if [ "$(wp post get "$pagina_id" --field=post_title 2>/dev/null || true)" = "$de" ]; then
    wp post update "$pagina_id" --post_title="$para"
  else
    echo "Diretório '$componente' já tem título próprio."
  fi
done

echo "== Página do Painel de Empresas =="
# A página é a âncora da rota do Administrador de Empresas: a URL do painel é
# resolvida a partir do slug dela, e os endpoints `empresa` e `loja` só
# funcionam sobre uma página existente. Sem esta página, o papel existe e não
# tem para onde ir — o redirecionamento de saída do `/wp-admin` cai na conta.
if ! wp post list --post_type=page --name=painel-empresas --field=ID | grep -q .; then
  wp post create \
    --post_type=page \
    --post_title="Painel de Empresas" \
    --post_name=painel-empresas \
    --post_status=publish \
    --post_content="[reconectar_painel_empresas]"
else
  echo "Página 'Painel de Empresas' já existe."
fi

echo "== Página de Categorias =="
# Destino do "Ver todos" do carrossel de categorias da home, que antes apontava
# para o catálogo de produtos. `reconectar_url_das_categorias()` procura a página
# por este slug; sem ela o link volta a cair em /shop/.
if ! wp post list --post_type=page --name=categorias --field=ID | grep -q .; then
  wp post create \
    --post_type=page \
    --post_title="Categorias" \
    --post_name=categorias \
    --post_status=publish \
    --post_content="[reconectar_categorias]"
else
  echo "Página 'Categorias' já existe."
fi

echo "== Página de Transparência =="
# Âncora do painel de transparência, e ela precisa existir **aqui** — antes do
# menu e do rodapé, que consultam a página e a omitem em silêncio quando não a
# acham. Enquanto este bloco não existiu, a página era criada à mão na máquina de
# desenvolvimento e não atravessava o deploy por nenhum caminho: em produção o
# painel respondia 404, e com ele sumiam o atalho de enquete do cabeçalho e o
# item "Votar" da barra inferior, que conferem
# `Reconectar_Painel_Transparencia::url()` antes de imprimir. É a mesma forma da
# armadilha do `custom_logo` — configuração local que o servidor nunca vê.
#
# O shortcode no conteúdo não é enfeite: `url()` acha a página pelo slug, mas
# `enfileirar_assets()` só carrega `transparencia.css` se encontrar
# `[reconectar_painel_transparencia]` no `post_content`. Página sem ele responde
# 200 e sai sem estilo nenhum.
if ! wp post list --post_type=page --name=transparencia --field=ID | grep -q .; then
  wp post create \
    --post_type=page \
    --post_title="Transparência" \
    --post_name=transparencia \
    --post_status=publish \
    --post_content="[reconectar_painel_transparencia]"
else
  echo "Página 'Transparência' já existe."
fi

echo "== Página da Incubadora =="
# Âncora da wiki da Incubadora, criada aqui pela mesma razão da Transparência:
# o menu, logo abaixo, a consulta e a omite em silêncio quando não a acha, e uma
# página feita à mão no painel não atravessa o deploy.
#
# O shortcode no conteúdo é o que faz a tela existir: sem ele a página responde
# 200, não carrega `incubadora.css` e não desenha a árvore. A âncora é `page`,
# e não o post type `incubadora_pagina`, porque a regra de reescrita do post type
# exige um segmento depois de `/incubadora/` — a URL sozinha cai em `pagename`.
#
# Só `publish`: `get_page_by_path()` acharia um rascunho com o mesmo slug, e o
# plugin descarta a âncora que não esteja publicada.
if ! wp post list --post_type=page --name=incubadora --post_status=publish --field=ID | grep -q .; then
  wp post create \
    --post_type=page \
    --post_title="Incubadora" \
    --post_name=incubadora \
    --post_status=publish \
    --post_content="[reconectar_incubadora]"
else
  echo "Página 'Incubadora' já existe."
fi

echo "== Fórum inicial (bbPress) =="
if ! wp post list --post_type=forum --field=ID | grep -q .; then
  wp post create \
    --post_type=forum \
    --post_title="Fórum Geral" \
    --post_status=publish
else
  echo "Já existe pelo menos um fórum."
fi

echo "== Fórum de cooperação (bbPress) =="
# O módulo Cooperação do ERS passa pelo fórum: RF23 (cooperação e parcerias),
# RF25 (indicação de profissionais e iniciativas) e RF26 (eventos comunitários)
# pedem discussão "por meio do fórum". Os fóruns temáticos da carga de
# demonstração somem no `seed-demo.sh remover`, e o "Fórum Geral" acima só nasce
# numa instalação sem fórum nenhum — então este precisa de bloco próprio, ou não
# atravessa o deploy.
#
# A guarda é a meta `_reconectar_forum_chave`, nunca o título nem o slug: o
# administrador renomeia pelo painel, e o bloco recriaria o fórum ao lado do
# original. A lixeira entra na busca de propósito: fórum que o administrador
# mandou para lá foi decisão dele, e o provisionamento não o desfaz. Os status
# `hidden` e `closed` são os de fórum do bbPress, que `any` não cobre.
#
# `bbp_insert_forum()`, e não `wp post create`: é ela que grava o tipo, o status
# e os contadores do bbPress.
if ! wp post list --post_type=forum --post_status=publish,private,hidden,closed,draft,pending,trash \
    --meta_key=_reconectar_forum_chave --meta_value=cooperacao --field=ID | grep -q .; then
  wp eval '
    $id = bbp_insert_forum(
      array(
        "post_title"   => "Cooperação e parcerias",
        "post_content" => "Parcerias entre lojas e iniciativas, troca de serviços, indicação de profissionais e divulgação de eventos da comunidade.",
        "post_parent"  => 0,
      ),
      array(
        "forum_type" => "forum",
        "status"     => "open",
      )
    );
    if ( ! $id ) {
      WP_CLI::error( "Falha ao criar o fórum de cooperação." );
    }
    update_post_meta( $id, "_reconectar_forum_chave", "cooperacao" );
    WP_CLI::success( "Fórum de cooperação criado: ID $id." );
  '
else
  echo "Fórum de cooperação já existe."
fi

echo "== Categoria Serviços (WooCommerce) =="
# RF24 (divulgação e busca de serviços): produto nesta categoria, ou numa filha
# dela, é serviço — sai a R$ 0, com "A combinar" na vitrine, e vira solicitação
# que a loja responde pela plataforma. Ver `Reconectar_Servicos`.
#
# A guarda é a meta de termo `_reconectar_categoria_chave`, pela mesma razão do
# fórum de cooperação acima: o administrador renomeia a categoria pelo painel.
# Um termo já existente com o slug `servicos` — criado à mão antes deste bloco —
# é **adotado**, não duplicado: `wp_insert_term()` recusaria o nome repetido, e
# o fluxo de serviço nunca reconheceria o termo que a loja está usando.
#
# `meta_query`, nunca `meta_key`/`meta_value`: em `product_cat` o WooCommerce
# reescreve `meta_key` para a meta `order` da ordenação, e a busca devolve vazio
# em silêncio — medido, este bloco "adotava" a categoria de novo a cada rodada.
wp eval '
  $chave = array(
    "taxonomy"   => "product_cat",
    "hide_empty" => false,
    "fields"     => "ids",
    "orderby"    => "term_id",
    "meta_query" => array(
      array(
        "key"   => "_reconectar_categoria_chave",
        "value" => "servicos",
      ),
    ),
  );
  if ( get_terms( $chave ) ) {
    echo "Categoria Serviços já existe.\n";
    return;
  }
  $termo = get_term_by( "slug", "servicos", "product_cat" );
  if ( $termo ) {
    $id = (int) $termo->term_id;
    WP_CLI::log( "Categoria com slug servicos adotada: ID $id." );
  } else {
    $novo = wp_insert_term(
      "Serviços",
      "product_cat",
      array(
        "slug"        => "servicos",
        "description" => "Serviços oferecidos pelas lojas da plataforma. O valor é combinado com o prestador: descreva o que precisa ao solicitar, e ele responde pela plataforma.",
      )
    );
    if ( is_wp_error( $novo ) ) {
      WP_CLI::error( "Falha ao criar a categoria Serviços: " . $novo->get_error_message() );
    }
    $id = (int) $novo["term_id"];
    WP_CLI::success( "Categoria Serviços criada: ID $id." );
  }
  update_term_meta( $id, "_reconectar_categoria_chave", "servicos" );
'

echo "== Ícones das categorias =="
# Cada categoria conhecida recebe um SVG de `themes/reconectar/assets/icones/`,
# versionado com o tema e portanto presente nos dois ambientes — ao contrário
# da miniatura, que é anexo em `uploads/` e não atravessa o deploy. O termo
# guarda só o nome do ícone; ver o cabeçalho do script para as guardas.
#
# Vem depois de "Categoria Serviços", que grava a chave pela qual o script a
# encontra. As categorias da carga de demonstração ainda não existem aqui numa
# instalação nova: a carga chama o mesmo script ao terminar.
wp eval-file /var/www/scripts/icones-de-categoria.php

echo "== Título da listagem de lojas =="
# A página é criada pelo Dokan com o título "Store List", em inglês, e é ela que
# o tema usa como vitrine de lojas. O renome só acontece enquanto o título ainda
# for o padrão do plugin: assim o script continua idempotente e não desfaz um
# título que o administrador tenha escolhido — a mesma promessa do bloco do
# rodapé logo abaixo.
lista_id=$(wp post list --post_type=page --name=store-listing --post_status=publish --field=ID)
if [ -n "$lista_id" ] && [ "$(wp post get "$lista_id" --field=post_title)" = "Store List" ]; then
  wp post update "$lista_id" --post_title="Lojas parceiras"
else
  echo "Título da listagem já foi definido."
fi

# Devolve o ID de uma página do WooCommerce pela **opção** que o aponta, nunca
# pelo slug. O slug é gravado no idioma ativo no instante em que o plugin cria a
# página — no ativar, antes de o pacote de idioma estar de pé —, então ele varia
# por instalação: a máquina de desenvolvimento nasceu em inglês e tem `shop` e
# `my-account`; a de produção nasceu em pt-BR e tem `loja` e `minha-conta`.
#
# O sintoma de buscar por slug é o pior que este script sabe produzir: o
# `wp post list --name=shop` não falha, devolve vazio, a guarda `[ -n … ]` engole
# o vazio, e o menu de produção fica com "Loja" e "Minha Conta" a menos sem uma
# linha de erro no log do deploy. O bloco do carrinho e do checkout lá acima já
# resolvia pela opção; faltava aplicar o mesmo aqui.
#
# A chave da conta é `woocommerce_myaccount_page_id` — sem hífen e sem
# sublinhado, ao contrário do slug. E a conferência de `post_status` repete o
# `--post_status=publish` que as buscas antigas faziam: sem ela, uma página na
# lixeira entregaria link válido para o 404 de quem não está logado.
reconectar_id_de_pagina_do_woo() {
  local id status
  id="$(wp option get "woocommerce_$1_page_id" 2>/dev/null || true)"

  case "$id" in
    '' | *[!0-9]* ) return 0 ;;
  esac

  [ "$id" -ge 1 ] || return 0

  status="$(wp post get "$id" --field=post_status 2>/dev/null || true)"

  if [ "$status" = "publish" ]; then
    printf '%s' "$id"
  fi
}

echo "== Título da página de produtos =="
# A página da loja do WooCommerce é o catálogo inteiro, com filtros, e se chama
# "Produtos": "Loja" passou a ser o atalho de cada loja para a própria vitrine,
# um item que o plugin acrescenta ao menu só para ela. O título nasce "Shop" ou
# "Loja", conforme o idioma no instante da ativação, e só é trocado enquanto for
# um desses — a mesma promessa do bloco da listagem de lojas logo acima. O slug
# fica: está em links já compartilhados.
#
# A migração 8 de `Reconectar_Migracoes` faz o mesmo nas instalações já de pé.
# Este bloco existe porque, numa instalação nova, ela pode rodar antes de o
# WooCommerce criar a página, e se marcar como aplicada sem ter o que renomear.
produtos_id="$(reconectar_id_de_pagina_do_woo shop)"
if [ -n "$produtos_id" ] && printf '%s\n' "Shop" "Loja" | grep -qxF "$(wp post get "$produtos_id" --field=post_title)"; then
  wp post update "$produtos_id" --post_title="Produtos"
else
  echo "Título da página de produtos já foi definido."
fi

echo "== Menu principal (navegação) =="
if wp menu list --fields=locations --format=csv | grep -q "primary"; then
  echo "Já existe um menu atribuído ao local 'primary', pulando."
else
  wp menu create "Menu Principal"
  # Caminho, não URL absoluta. `WP_HOME` varia por requisição para atender
  # `localhost` e o IP da máquina, mas o que vai para `_menu_item_url` fica
  # gravado como texto e não acompanha: um item nascido com `http://localhost:8090/`
  # continuaria mandando o celular para o próprio celular. Os itens `add-post`
  # não têm esse problema — guardam o ID e resolvem o permalink na hora.
  #
  # `Reconectar_Permissoes::ocultar_itens_da_comunidade()` compara só o
  # `PHP_URL_PATH` do item, então continua reconhecendo o do fórum.
  wp menu item add-custom "menu-principal" "Início" "/" --position=1

  loja_id="$(reconectar_id_de_pagina_do_woo shop)"
  [ -n "$loja_id" ] && wp menu item add-post "menu-principal" "$loja_id" --title="Produtos" --position=2

  # A listagem de lojas é o destino do breadcrumb do Dokan e do "Ver todos" dos
  # carrosséis da home. Sem item no menu, o único caminho até ela era o rodapé —
  # e um marketplace sem link para "lojas" na navegação principal esconde
  # justamente o que ele tem de diferente de uma loja comum.
  lojas_id=$(wp post list --post_type=page --name=store-listing --post_status=publish --field=ID)
  [ -n "$lojas_id" ] && wp menu item add-post "menu-principal" "$lojas_id" --title="Lojas" --position=3

  comunidade_id=$(wp post list --post_type=page --name=comunidade --post_status=publish --field=ID)
  [ -n "$comunidade_id" ] && wp menu item add-post "menu-principal" "$comunidade_id" --title="Comunidade" --position=4

  # O fórum entra como item custom, e não `add-post`: a listagem "Todas as
  # perguntas" é o arquivo do post type `forum`, que não tem post próprio a que
  # apontar. O "Fórum Geral" é uma categoria dentro dela, não a tela.
  #
  # Quem não participa da comunidade não vê este item: o filtro
  # `Reconectar_Permissoes::ocultar_itens_da_comunidade()` reconhece itens custom
  # pelo caminho da URL, além dos post types de bbPress.
  wp menu item add-custom "menu-principal" "Fórum" "/forums/" --position=5

  transparencia_id=$(wp post list --post_type=page --name=transparencia --post_status=publish --field=ID)
  [ -n "$transparencia_id" ] && wp menu item add-post "menu-principal" "$transparencia_id" --title="Transparência" --position=6

  # Visitante e cliente não veem este item — a Incubadora não é área deles —, e
  # `Reconectar_Incubadora_Leitura::ocultar_item_de_quem_nao_usa()` o tira pelo ID
  # da página.
  incubadora_id=$(wp post list --post_type=page --name=incubadora --post_status=publish --field=ID)
  [ -n "$incubadora_id" ] && wp menu item add-post "menu-principal" "$incubadora_id" --title="Incubadora" --position=7

  conta_id="$(reconectar_id_de_pagina_do_woo myaccount)"
  [ -n "$conta_id" ] && wp menu item add-post "menu-principal" "$conta_id" --title="Minha Conta" --position=8

  wp menu location assign menu-principal primary
fi

# Uma instalação provisionada antes desta entrega já tem menu atribuído a
# `primary` e cai no "pulando" acima — ficaria para sempre sem o link do fórum,
# que é justamente a tela nova. O reparo abaixo roda em toda execução e é
# idempotente pela URL do item, não pelo rótulo: renomear "Fórum" no painel não
# pode fazer o script criar um segundo.
if wp menu list --fields=slug --format=csv | grep -q "^menu-principal$" \
  && ! wp menu item list menu-principal --fields=url --format=csv 2>/dev/null | grep -q "/forums/"; then
  # Sem `--position`: o menu já tem ordem definida, e repetir a 5 empataria com
  # o item que a ocupa, deixando a ordem dos dois a cargo do banco.
  wp menu item add-custom "menu-principal" "Fórum" "/forums/"
  echo "Item 'Fórum' acrescentado ao menu principal."
fi

# Acrescenta ao menu já criado um item `add-post` que falta. Irmão do reparo do
# fórum acima, e pela mesma razão: uma instalação provisionada antes desta entrega
# cai no "pulando" e ficaria para sempre sem os itens.
#
# A idempotência é pelo **`object_id`**, não pelo rótulo nem pela URL. O rótulo é
# editável no painel, e a URL do item `add-post` é o permalink — que muda se
# alguém renomear o slug da página. O ID do post é o único dado que sobrevive às
# duas coisas, e é justamente o slug que divergiu entre os ambientes.
#
# Sem `--position`, pela razão já escrita acima: o menu tem ordem definida, e
# repetir uma posição ocupada deixa o empate a cargo do banco. O item entra no fim
# da lista — reordenar é um arrasto no painel, e este script não desfaz escolha do
# administrador.
#
# A lista é colhida numa variável antes de ir ao `grep`, e não canalizada direto:
# sob `pipefail`, o `grep -q` fecha o cano ao achar a primeira linha e o `wp` do
# outro lado pode morrer de SIGPIPE, fazendo o pipeline reportar falha justamente
# no caso em que o item EXISTE — a condição se inverteria e o script criaria uma
# duplicata por execução. É a mesma armadilha já registrada no bloco do rodapé.
reconectar_reparar_item_de_menu() {
  local pagina_id="$1" rotulo="$2" existentes

  [ -n "$pagina_id" ] || return 0

  existentes="$(wp menu item list menu-principal --fields=object_id --format=csv 2>/dev/null || true)"

  if printf '%s\n' "$existentes" | grep -qx "$pagina_id"; then
    return 0
  fi

  wp menu item add-post "menu-principal" "$pagina_id" --title="$rotulo"
  echo "Item '$rotulo' acrescentado ao menu principal."
}

reconectar_menus_existentes="$(wp menu list --fields=slug --format=csv 2>/dev/null || true)"

if printf '%s\n' "$reconectar_menus_existentes" | grep -qx "menu-principal"; then
  reconectar_reparar_item_de_menu "$(reconectar_id_de_pagina_do_woo shop)" "Produtos"

  transparencia_reparo_id=$(wp post list --post_type=page --name=transparencia --post_status=publish --field=ID)
  reconectar_reparar_item_de_menu "$transparencia_reparo_id" "Transparência"

  incubadora_reparo_id=$(wp post list --post_type=page --name=incubadora --post_status=publish --field=ID)
  reconectar_reparar_item_de_menu "$incubadora_reparo_id" "Incubadora"

  reconectar_reparar_item_de_menu "$(reconectar_id_de_pagina_do_woo myaccount)" "Minha Conta"
fi

echo "== Rodapé (widgets das três colunas) =="
# O tema registra as áreas "reconectar-rodape-1..3" e imprime apenas as que
# tiverem widget — sem isso o rodapé nasceria com buracos na grade. O efeito
# colateral é que um provisionamento que não popula nenhuma delas produz um
# rodapé só com a faixa legal, o que parecia um defeito do tema e era omissão
# daqui: as chaves sequer apareciam em "sidebars_widgets".
#
# Cada coluna só é preenchida se estiver vazia. Isso mantém o script idempotente
# e, mais importante, preserva o que o administrador editar depois: rodar de
# novo não desfaz o trabalho dele.

# Devolve a URL pública de uma página pelo slug, ou nada se ela não existir.
# Consultar em vez de concatenar o slug ao domínio importa porque as páginas do
# WooCommerce são criadas com slugs em inglês e podem ser renomeadas no painel —
# um link chumbado sobreviveria à renomeação apontando para lugar nenhum.
#
# O retorno é o caminho, não a URL absoluta, pela mesma razão do menu: o HTML do
# widget fica gravado como texto no banco e não acompanha o `WP_HOME` que varia
# por requisição. `wp post url` devolve absoluto, então a base sai fora — colhida
# uma vez só, porque `wp eval` sobe o WordPress inteiro a cada chamada.
reconectar_base_do_site="$(wp eval 'echo home_url();')"

reconectar_url_da_pagina() {
  local id url
  id=$(wp post list --post_type=page --name="$1" --post_status=publish --field=ID)

  if [ -n "$id" ]; then
    url=$(wp post url "$id")
    printf '%s' "${url#"$reconectar_base_do_site"}"
  fi
}

# Irmã da de cima para as páginas do WooCommerce, que não podem ser procuradas
# pelo slug: ele sai no idioma ativo quando o plugin cria a página, e diverge
# entre a máquina de desenvolvimento e a de produção. Veja
# `reconectar_id_de_pagina_do_woo()`, lá em cima.
reconectar_url_da_pagina_do_woo() {
  local id url
  id="$(reconectar_id_de_pagina_do_woo "$1")"

  if [ -n "$id" ]; then
    url=$(wp post url "$id")
    printf '%s' "${url#"$reconectar_base_do_site"}"
  fi
}

# Monta um <li> de link, ou nada quando a página não existe. Omitir o item é
# deliberado: um rodapé com um link a menos é melhor que um link para o 404.
reconectar_item_de_rodape() {
  local url
  url=$(reconectar_url_da_pagina "$1")

  if [ -n "$url" ]; then
    printf '<li><a href="%s">%s</a></li>' "$url" "$2"
  fi
}

# O mesmo <li>, para página do WooCommerce resolvida pela opção de ID.
reconectar_item_de_rodape_do_woo() {
  local url
  url=$(reconectar_url_da_pagina_do_woo "$1")

  if [ -n "$url" ]; then
    printf '<li><a href="%s">%s</a></li>' "$url" "$2"
  fi
}

# A checagem não passa por `| grep -q .` porque o script roda sob `pipefail`:
# o grep fecha o cano assim que acha a primeira linha, o `wp` do outro lado
# morre de SIGPIPE, e o pipeline inteiro passa a reportar falha justamente no
# caso em que a coluna TEM widget — invertendo a condição e duplicando tudo a
# cada execução.
if [ -n "$(wp widget list reconectar-rodape-1 --format=ids)" ]; then
  echo "Coluna 1 do rodapé já tem conteúdo."
else
  wp widget add custom_html reconectar-rodape-1 \
    --title="Reconectar" \
    --content="<p>Incubadora Digital para Vínculos e Negócios.</p><p>Plataforma do projeto <em>Nosso Chão, Nossa História</em>, uma iniciativa FUNDEPES/UNOPS.</p>"
fi

if [ -n "$(wp widget list reconectar-rodape-2 --format=ids)" ]; then
  echo "Coluna 2 do rodapé já tem conteúdo."
else
  navegacao="$(reconectar_item_de_rodape_do_woo shop 'Produtos')"
  navegacao+="$(reconectar_item_de_rodape store-listing 'Lojas parceiras')"
  navegacao+="$(reconectar_item_de_rodape comunidade 'Comunidade')"
  navegacao+="$(reconectar_item_de_rodape transparencia 'Transparência')"

  if [ -n "$navegacao" ]; then
    wp widget add custom_html reconectar-rodape-2 \
      --title="Navegação" \
      --content="<ul>$navegacao</ul>"
  else
    echo "Nenhuma página de navegação encontrada, coluna 2 fica vazia."
  fi
fi

if [ -n "$(wp widget list reconectar-rodape-3 --format=ids)" ]; then
  echo "Coluna 3 do rodapé já tem conteúdo."
else
  loja="$(reconectar_item_de_rodape vendor-onboarding 'Quero vender')"
  loja+="$(reconectar_item_de_rodape dashboard 'Painel da loja')"
  loja+="$(reconectar_item_de_rodape_do_woo myaccount 'Minha conta')"
  loja+="$(reconectar_item_de_rodape my-orders 'Meus pedidos')"

  if [ -n "$loja" ]; then
    wp widget add custom_html reconectar-rodape-3 \
      --title="Sua conta" \
      --content="<ul>$loja</ul>"
  else
    echo "Nenhuma página de conta encontrada, coluna 3 fica vazia."
  fi
fi

echo "== Links que faltam no rodapé =="
# Irmão do reparo do menu, lá em cima, e pela mesma razão: as colunas acima só são
# escritas quando estão vazias, então uma instalação provisionada antes de uma
# página existir nunca recebe o link dela. Foi assim que a Transparência ficou de
# fora do rodapé de produção, junto com "Loja" e "Minha conta" — estas duas porque
# eram procuradas pelo slug em inglês, que não existe na instalação nascida em
# pt-BR.
#
# O reparo vai em PHP e não aqui porque o conteúdo do widget é HTML dentro de um
# array serializado: `wp widget list --format=json` é a única leitura disponível
# (não existe `wp widget get`), e ela devolve o texto com escapes Unicode. Montar
# a substituição em bash a partir disso seria frágil de um jeito que só apareceria
# em produção.
wp eval-file /var/www/scripts/reparar-rodape.php

echo "== URLs gravadas no banco =="
# Os blocos de menu e de rodapé acima só escrevem quando encontram o lugar vazio,
# e é isso que os torna idempotentes. O efeito colateral é que uma instalação
# provisionada antes desta entrega nunca recebe a correção: ela já tem menu
# atribuído e colunas preenchidas, com as URLs absolutas de `localhost:8090` que
# o script gravava na época. Este passo alcança essas.
wp eval-file /var/www/scripts/normalizar-urls.php

echo "== Permalinks =="
# O WordPress instala com a estrutura "plain" (?p=123), em que as URLs por
# slug não resolvem. Isso não é cosmético: o dashboard da loja (Dokan) e
# as telas do BuddyPress são servidos por rewrite rules, e sem elas caem na
# home. O flush precisa rodar DEPOIS da ativação dos plugins e da criação das
# páginas/fóruns, para que os CPTs de Dokan/BuddyPress/bbPress já estejam
# registrados e entrem nas regras geradas.
if [ "$(wp option get permalink_structure)" = "/%postname%/" ]; then
  echo "Permalinks já configurados como /%postname%/."
else
  wp rewrite structure '/%postname%/'
fi
wp rewrite flush

echo "== Resumo =="
wp theme list
wp plugin list
echo "Páginas do WooCommerce (Loja, Carrinho, Checkout, Minha Conta) são criadas automaticamente na ativação do plugin."
echo "Provisionamento concluído. Acesse: $WP_URL"
