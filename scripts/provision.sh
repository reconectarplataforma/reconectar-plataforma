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

echo "== Autocadastro de vendedor (desligado) =="
# Quem cadastra loja nesta plataforma é o Administrador ou o Administrador de
# Empresas, pelo painel de empresas. As duas linhas abaixo NÃO são a trava — a
# trava é `Reconectar_Cadastro_De_Lojas`, no plugin autoral, e ela existe
# justamente porque esta opção do Dokan fecha só o checkbox do formulário de
# registro, deixando abertos os dois shortcodes dedicados e o "Become a vendor".
# O que se ganha aqui é coerência de tela: sem isto o site continuaria
# oferecendo um caminho que o servidor recusa.
if [ "$(wp option pluck dokan_appearance show_register_as_vendor 2>/dev/null || true)" = "off" ]; then
  echo "Autocadastro de vendedor já desligado."
else
  wp option patch update dokan_appearance show_register_as_vendor off
fi

# A página de onboarding ficaria em branco depois que o shortcode dela sai do ar.
pagina_onboarding="$(wp post list --post_type=page --name=vendor-onboarding --post_status=publish --field=ID)"
if [ -n "$pagina_onboarding" ]; then
  wp post update "$pagina_onboarding" --post_status=draft
  echo "Página '/vendor-onboarding/' passada para rascunho."
else
  echo "Página '/vendor-onboarding/' já não está publicada."
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
if ! wp post list --post_type=page --title="Comunidade" --field=ID | grep -q .; then
  wp post create \
    --post_type=page \
    --post_title="Comunidade" \
    --post_status=publish \
    --post_content="[buddypress]"
else
  echo "Página 'Comunidade' já existe."
fi

echo "== Página do Painel de Empresas =="
# A página é a âncora da rota do Administrador de Empresas: a URL do painel é
# resolvida a partir do slug dela, e os endpoints `empresa` e `vendedor` só
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

echo "== Fórum inicial (bbPress) =="
if ! wp post list --post_type=forum --field=ID | grep -q .; then
  wp post create \
    --post_type=forum \
    --post_title="Fórum Geral" \
    --post_status=publish
else
  echo "Já existe pelo menos um fórum."
fi

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

echo "== Menu principal (navegação) =="
if wp menu list --fields=locations --format=csv | grep -q "primary"; then
  echo "Já existe um menu atribuído ao local 'primary', pulando."
else
  wp menu create "Menu Principal"
  wp menu item add-custom "menu-principal" "Início" "$WP_URL/" --position=1

  loja_id=$(wp post list --post_type=page --name=shop --post_status=publish --field=ID)
  [ -n "$loja_id" ] && wp menu item add-post "menu-principal" "$loja_id" --title="Loja" --position=2

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
  wp menu item add-custom "menu-principal" "Fórum" "$WP_URL/forums/" --position=5

  transparencia_id=$(wp post list --post_type=page --name=transparencia --post_status=publish --field=ID)
  [ -n "$transparencia_id" ] && wp menu item add-post "menu-principal" "$transparencia_id" --title="Transparência" --position=6

  conta_id=$(wp post list --post_type=page --name=my-account --post_status=publish --field=ID)
  [ -n "$conta_id" ] && wp menu item add-post "menu-principal" "$conta_id" --title="Minha Conta" --position=7

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
  wp menu item add-custom "menu-principal" "Fórum" "$WP_URL/forums/"
  echo "Item 'Fórum' acrescentado ao menu principal."
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
reconectar_url_da_pagina() {
  local id
  id=$(wp post list --post_type=page --name="$1" --post_status=publish --field=ID)

  if [ -n "$id" ]; then
    wp post url "$id"
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
  navegacao="$(reconectar_item_de_rodape shop 'Loja')"
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
  vendedor="$(reconectar_item_de_rodape vendor-onboarding 'Quero vender')"
  vendedor+="$(reconectar_item_de_rodape dashboard 'Painel do vendedor')"
  vendedor+="$(reconectar_item_de_rodape my-account 'Minha conta')"
  vendedor+="$(reconectar_item_de_rodape my-orders 'Meus pedidos')"

  if [ -n "$vendedor" ]; then
    wp widget add custom_html reconectar-rodape-3 \
      --title="Sua conta" \
      --content="<ul>$vendedor</ul>"
  else
    echo "Nenhuma página de conta encontrada, coluna 3 fica vazia."
  fi
fi

echo "== Permalinks =="
# O WordPress instala com a estrutura "plain" (?p=123), em que as URLs por
# slug não resolvem. Isso não é cosmético: o dashboard do vendedor (Dokan) e
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
