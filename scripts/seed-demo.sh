#!/usr/bin/env bash
# Carga e remoção dos dados de demonstração da plataforma Reconectar.
#
#   ./scripts/seed-demo.sh              # cria categorias, lojas, produtos e avaliações
#   ./scripts/seed-demo.sh remover      # apaga tudo o que a carga criou
#   ./scripts/seed-demo.sh remover -y   # idem, sem perguntar
#
# Este script NÃO é chamado por `provision.sh`, e isso é deliberado: um
# ambiente provisionado do zero precisa poder ser entregue vazio. Dado
# fictício só entra quando alguém pede explicitamente, rodando este comando.
#
# Tudo o que é criado leva a meta `_reconectar_demo = 1`, e a remoção se
# baseia exclusivamente nessa marca — conteúdo cadastrado por pessoas reais
# nunca é tocado. Enquanto a carga estiver ativa, o site exibe uma faixa de
# aviso no topo de todas as páginas (ver `reconectar-core`).
#
# Não use em produção. Ver docs/DADOS_DEMONSTRACAO.md.

set -euo pipefail

# Caminho do seed *dentro* do container: `docker-compose.yml` monta ./scripts
# em /var/www/scripts no serviço wpcli.
SEED_NO_CONTAINER="/var/www/scripts/seed/demo.php"

RAIZ_PROJETO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

modo="instalar"
confirmado="nao"

for argumento in "$@"; do
  case "$argumento" in
    remover)
      modo="remover"
      ;;
    instalar)
      modo="instalar"
      ;;
    -y|--sim)
      confirmado="sim"
      ;;
    -h|--help)
      # Linhas 2 a 17: o bloco de comentário do topo, sem o shebang.
      sed -n '2,17p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
      exit 0
      ;;
    *)
      echo "Argumento desconhecido: $argumento" >&2
      echo "Use: $(basename "${BASH_SOURCE[0]}") [instalar|remover] [-y]" >&2
      exit 2
      ;;
  esac
done

# A remoção apaga produtos, usuários e arquivos de mídia. É restrita ao que
# tem a marca `_reconectar_demo`, mas ainda assim é irreversível — daí a
# confirmação. Sem terminal interativo (CI, `docker compose run` sem -it), o
# -y passa a ser obrigatório em vez de a pergunta travar o script.
if [ "$modo" = "remover" ] && [ "$confirmado" != "sim" ]; then
  if [ -t 0 ]; then
    printf 'Apagar os dados de demonstração (produtos, lojas, imagens e avaliações)? [s/N] '
    read -r resposta
    case "$resposta" in
      s|S|sim|SIM) ;;
      *) echo "Cancelado."; exit 0 ;;
    esac
  else
    echo "Sem terminal interativo: repita o comando com -y para confirmar a remoção." >&2
    exit 1
  fi
fi

# O script serve aos dois contextos. Dentro do container wpcli o `wp` já está
# no PATH e o seed é executado diretamente; a partir do host, delega ao
# `docker compose run`, que é como a equipe roda no dia a dia.
if command -v wp >/dev/null 2>&1; then
  wp eval-file "$SEED_NO_CONTAINER" "$modo"
else
  if ! docker compose version >/dev/null 2>&1; then
    echo "Nem o WP-CLI nem o Docker Compose foram encontrados." >&2
    echo "Rode a partir da raiz do projeto com o ambiente no ar (docker compose up -d)." >&2
    exit 1
  fi
  cd "$RAIZ_PROJETO"
  docker compose run --rm wpcli wp eval-file "$SEED_NO_CONTAINER" "$modo"
fi
