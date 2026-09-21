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
for plugin in woocommerce dokan-lite buddypress bbpress; do
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

echo "== Fórum inicial (bbPress) =="
if ! wp post list --post_type=forum --field=ID | grep -q .; then
  wp post create \
    --post_type=forum \
    --post_title="Fórum Geral" \
    --post_status=publish
else
  echo "Já existe pelo menos um fórum."
fi

echo "== Resumo =="
wp theme list
wp plugin list
echo "Páginas do WooCommerce (Loja, Carrinho, Checkout, Minha Conta) são criadas automaticamente na ativação do plugin."
echo "Provisionamento concluído. Acesse: $WP_URL"
