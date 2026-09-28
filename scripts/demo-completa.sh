#!/usr/bin/env bash
# Monta a demonstração completa da plataforma Reconectar em um comando.
#
#   ./scripts/demo-completa.sh              # sobe o ambiente, provisiona e popula
#   ./scripts/demo-completa.sh --recomecar  # apaga TUDO antes (banco e uploads)
#   ./scripts/demo-completa.sh --help
#
# Encadeia três etapas que antes precisavam ser disparadas à mão, na ordem certa:
#
#   1. sobe os serviços do Docker Compose e espera o banco e o WordPress;
#   2. roda "provision.sh" (núcleo, idioma, tema, plugins, páginas, permalinks);
#   3. roda o seed "seed/demo.php" (lojas, produtos, avaliações, clientes, pedidos).
#
# As três etapas são idempotentes: rodar de novo não duplica nada. É isso que
# permite usar este script tanto no primeiro dia quanto depois de um "git pull"
# que trouxe lojas novas ao catálogo de demonstração.
#
# NÃO existe uma imagem Docker própria para a demonstração, e a ausência é
# deliberada: a carga não precisa de nada além do WP-CLI e do PHP que a imagem
# "wordpress:cli-php8.2" já traz (o GD, usado para gerar as imagens dos
# produtos, vem compilado nela). Uma imagem autoral seria essa mesma imagem com
# um script copiado para dentro — e o script já chega pelo bind-mount, sem
# rebuild a cada alteração. O que existe é um SERVIÇO dedicado, "demo", isolado
# atrás do profile de mesmo nome para não subir junto no "docker compose up".

set -euo pipefail

SEED_NO_CONTAINER="/var/www/scripts/seed/demo.php"
PROVISION_NO_CONTAINER="/var/www/scripts/provision.sh"
RAIZ_PROJETO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Quanto esperar, em segundos, o serviço "wordpress" terminar de copiar o núcleo
# para o volume compartilhado. Na primeira subida isso leva alguns segundos, e o
# WP-CLI que chegue antes falha com "This does not seem to be a WordPress
# installation" — um erro que descreve o instante, não o problema.
ESPERA_MAXIMA=120

recomecar=0

while [ $# -gt 0 ]; do
  case "$1" in
    --recomecar|--do-zero)
      recomecar=1
      ;;
    -h|--help)
      sed -n '2,24p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
      exit 0
      ;;
    *)
      echo "Argumento desconhecido: $1" >&2
      echo "Use --help para ver as opções." >&2
      exit 1
      ;;
  esac
  shift
done

# ---------------------------------------------------------------------------
# Execução dentro do container
# ---------------------------------------------------------------------------

if command -v wp >/dev/null 2>&1; then
  echo "== Aguardando o WordPress =="

  decorrido=0
  while [ ! -f /var/www/html/wp-config.php ]; do
    if [ "$decorrido" -ge "$ESPERA_MAXIMA" ]; then
      echo "wp-config.php não apareceu em ${ESPERA_MAXIMA}s." >&2
      echo "O serviço 'wordpress' subiu? Verifique com: docker compose ps" >&2
      exit 1
    fi
    sleep 2
    decorrido=$(( decorrido + 2 ))
  done

  echo "Núcleo disponível após ${decorrido}s."

  bash "$PROVISION_NO_CONTAINER"

  echo
  echo "== Dados de demonstração =="
  wp eval-file "$SEED_NO_CONTAINER" instalar

  echo
  echo "== Acesso =="
  echo "Site:       ${WP_URL:-http://localhost:8090}"
  echo "Painel:     ${WP_URL:-http://localhost:8090}/wp-admin/"
  echo "phpMyAdmin: http://localhost:${PHPMYADMIN_PORT:-8081}"
  echo
  echo "Administrador: ${WP_ADMIN_USER:-admin} / ${WP_ADMIN_PASSWORD:-reconectar-admin}"
  echo

  # Os logins são lidos do banco, e não impressos de uma lista fixa aqui: uma
  # lista fixa continuaria correta no papel depois de o catálogo mudar, e é
  # exatamente no primeiro login que a divergência apareceria.
  senha_demo="${RECONECTAR_DEMO_SENHA:-reconectar-demo}"

  for papel in seller customer; do
    logins=$(wp user list \
      --role="$papel" \
      --meta_key=_reconectar_demo \
      --meta_value=1 \
      --field=user_login \
      --format=csv 2>/dev/null | tr '\n' ' ')

    if [ -n "${logins// /}" ]; then
      if [ "$papel" = "seller" ]; then
        echo "Lojas (senha: $senha_demo):"
      else
        echo "Clientes (senha: $senha_demo):"
      fi

      for login in $logins; do
        echo "  - $login"
      done
      echo
    fi
  done

  echo "Para remover só os dados de demonstração: ./scripts/seed-demo.sh remover"
  exit 0
fi

# ---------------------------------------------------------------------------
# Execução a partir do host
# ---------------------------------------------------------------------------

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker não encontrado, e não há WP-CLI neste ambiente." >&2
  echo "Rode este script no host (com Docker) ou dentro do container wpcli." >&2
  exit 1
fi

cd "$RAIZ_PROJETO"

if [ "$recomecar" -eq 1 ]; then
  # Destrutivo de verdade: "-v" apaga os volumes nomeados, e com eles o banco
  # inteiro e os uploads. Só o que está versionado em wp-content/ sobrevive.
  echo "ATENÇÃO: --recomecar apaga o banco de dados e os uploads deste ambiente."
  echo "Tudo que não estiver no repositório será perdido."

  if [ -t 0 ]; then
    read -r -p "Digite 'apagar' para confirmar: " resposta
    if [ "$resposta" != "apagar" ]; then
      echo "Cancelado."
      exit 1
    fi
  else
    echo "Sem terminal interativo: confirmação impossível. Cancelado." >&2
    exit 1
  fi

  docker compose down -v
fi

echo "== Subindo os serviços =="
docker compose up -d db wordpress phpmyadmin

echo
docker compose run --rm demo
