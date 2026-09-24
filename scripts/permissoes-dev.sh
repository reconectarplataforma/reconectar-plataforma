#!/usr/bin/env bash
# Acerta o dono e as permissões de wp-content/ no ambiente de desenvolvimento.
#
#   ./scripts/permissoes-dev.sh              # aplica
#   ./scripts/permissoes-dev.sh --conferir   # só relata, não muda nada
#
# Por que este script existe: `wp-content/` é um bind-mount. Do lado de fora
# quem escreve é você (UID 1000, tipicamente); de dentro é o `www-data` da
# imagem Apache (UID 33). Cada um cria arquivos que pertencem a si e que o
# outro não consegue alterar — o WordPress não atualiza um plugin que você
# baixou, e você não apaga um upload que ele gerou. O sintoma costuma aparecer
# como "não foi possível criar o diretório" no painel ou como `Permission
# denied` no editor, e é fácil confundir com defeito do plugin.
#
# A solução é dar aos dois o mesmo acesso, por grupo:
#
#   * código autoral (tema `reconectar` e plugin `reconectar-core`) fica do
#     host, com o grupo do WordPress — quem edita é você; ele só precisa ler;
#   * o resto de `wp-content/` fica do WordPress, com o seu grupo — quem
#     escreve é ele (uploads, atualizações, traduções); você precisa poder
#     apagar e versionar.
#
# Em ambos os casos o grupo recebe escrita e os diretórios ganham o bit
# setgid, para que **arquivos novos já nasçam com o grupo certo**. Sem o
# setgid, o script conserta o passado e o problema volta no dia seguinte.
#
# O setgid governa o grupo, não o modo: o WordPress usa umask 022, então um
# arquivo que ele criar depois nasce 644 — do grupo certo, mas sem escrita
# para ele. Você continua podendo apagar e versionar esse arquivo (isso
# depende da permissão do diretório, que tem g+w); para *editá-lo*, rode o
# script de novo. Na prática isso só aparece ao mexer à mão em algo que o
# painel baixou.
#
# Nada aqui vale para produção: lá o dono é um só, e conceder escrita de grupo
# ao código seria abrir mão de uma barreira de segurança real.

set -euo pipefail

RAIZ_PROJETO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# UID do www-data da imagem Debian (serviço `wordpress`), que é quem cria os
# arquivos no volume. A imagem CLI é Alpine e usa 82 — por isso `wpcli` e
# `demo` declaram `user: "33:33"` no Compose, e por isso o número aqui é 33.
UID_WORDPRESS=33

UID_HOST="$(id -u)"
GID_HOST="$(id -g)"

# Caminho de `wp-content` *dentro* do container. É o mesmo inode do host: o
# `chown` feito lá aparece aqui, e é por isso que o script não precisa de sudo.
CONTEUDO="/var/www/html/wp-content"

# Os dois diretórios de código autoral, relativos a `wp-content`.
AUTORAIS=(
  "themes/reconectar"
  "plugins/reconectar-core"
)

modo="aplicar"

for argumento in "$@"; do
  case "$argumento" in
    --conferir|-c)
      modo="conferir"
      ;;
    -h|--help)
      # Linhas 2 a 35: o bloco de comentário do topo, sem o shebang.
      sed -n '2,35p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
      exit 0
      ;;
    *)
      echo "Argumento desconhecido: $argumento" >&2
      echo "Use: $(basename "${BASH_SOURCE[0]}") [--conferir]" >&2
      exit 2
      ;;
  esac
done

cd "$RAIZ_PROJETO"

if ! docker compose version >/dev/null 2>&1; then
  echo "Docker Compose não encontrado." >&2
  echo "Este script precisa rodar a partir do host, na raiz do projeto." >&2
  exit 1
fi

# `exec` quando o ambiente já está no ar — é o caso comum e não sobe nada.
# Senão, `run --no-deps`, que dispensa o banco: o serviço aqui é usado só como
# um root com o bind-mount montado, e esperar o healthcheck do MariaDB para um
# `chown` seria cobrar meio minuto por nada.
if docker compose ps --status running --services 2>/dev/null | grep -qx wordpress; then
  executar() { docker compose exec -T -u root wordpress bash -c "$1"; }
else
  echo "O serviço wordpress não está no ar; usando um container avulso."
  executar() { docker compose run --rm --no-deps --user root --entrypoint bash wordpress -c "$1"; }
fi

if [ "$modo" = "conferir" ]; then
  echo "Dono e permissões atuais (esperado: código autoral ${UID_HOST}:${UID_WORDPRESS}, o resto ${UID_WORDPRESS}:${GID_HOST}):"
  echo
  executar "ls -ldn ${CONTEUDO} ${CONTEUDO}/* ${CONTEUDO}/themes/reconectar ${CONTEUDO}/plugins/reconectar-core 2>/dev/null"
  echo
  echo "Arquivos fora do padrão, se houver:"
  executar "
    find ${CONTEUDO} \
      -path ${CONTEUDO}/themes/reconectar -prune -o \
      -path ${CONTEUDO}/plugins/reconectar-core -prune -o \
      \( ! -uid ${UID_WORDPRESS} -o ! -gid ${GID_HOST} \) -print 2>/dev/null | head -20
    for dir in ${AUTORAIS[*]}; do
      find ${CONTEUDO}/\$dir \( ! -uid ${UID_HOST} -o ! -gid ${UID_WORDPRESS} \) -print 2>/dev/null | head -20
    done
  "
  echo
  echo "Nenhuma linha acima significa que está tudo no padrão."
  exit 0
fi

echo "Ajustando ${CONTEUDO} ..."

# A ordem importa: primeiro a regra geral em `wp-content` inteiro, depois a
# exceção nos dois diretórios autorais, que a sobrescreve.
executar "
  set -e

  chown -R ${UID_WORDPRESS}:${GID_HOST} ${CONTEUDO}
  chmod -R g+rwX ${CONTEUDO}

  for dir in ${AUTORAIS[*]}; do
    if [ -d ${CONTEUDO}/\$dir ]; then
      chown -R ${UID_HOST}:${UID_WORDPRESS} ${CONTEUDO}/\$dir
      chmod -R g+rwX ${CONTEUDO}/\$dir
    fi
  done

  find ${CONTEUDO} -type d -exec chmod g+s {} +
"

echo "Pronto."
echo
echo "  código autoral   -> ${UID_HOST}:${UID_WORDPRESS} (você edita, o WordPress lê)"
echo "  resto de wp-content -> ${UID_WORDPRESS}:${GID_HOST} (o WordPress escreve, você apaga)"
echo
echo "Confira quando quiser com: ./scripts/permissoes-dev.sh --conferir"
