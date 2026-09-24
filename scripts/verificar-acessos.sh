#!/usr/bin/env bash
# Verificação das travas de acesso da plataforma Reconectar.
#
#   ./scripts/verificar-acessos.sh              # roda todos os casos
#   ./scripts/verificar-acessos.sh -v           # mostra também os que passam
#   BASE=http://outro:porta ./scripts/verificar-acessos.sh
#
# Faz login de verdade como cliente, vendedor e administrador e bate em cada
# URL restrita, comparando o código HTTP com o esperado. Sai com status 1 se
# qualquer caso falhar — serve para conferir a demonstração antes de apresentá-la.
#
# Por que HTTP e não `current_user_can()`: a autorização precisa valer para a
# URL digitada à mão, que é o caminho que uma auditoria vai tentar. Um teste
# que só pergunta a capacidade em PHP não prova que a requisição foi barrada;
# prova apenas que a função responde o esperado quando alguém a consulta.
#
# Complementa, não substitui, o roteiro manual em docs/ROTEIRO_PERFIS.md: o
# isolamento entre vendedores (produto de um não abre para o outro) depende de
# IDs do banco e é verificado à parte, no fim deste script.

set -uo pipefail

BASE="${BASE:-http://localhost:8090}"
SENHA_DEMO="${RECONECTAR_DEMO_SENHA:-reconectar-demo}"
SENHA_ADMIN="${RECONECTAR_ADMIN_SENHA:-reconectar-admin}"

RAIZ_PROJETO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

verboso="nao"
for argumento in "$@"; do
  case "$argumento" in
    -v|--verboso) verboso="sim" ;;
    -h|--help)
      # Linhas 2 a 20: o bloco de comentário do topo, sem o shebang.
      sed -n '2,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
      exit 0
      ;;
    *)
      echo "Argumento desconhecido: $argumento" >&2
      echo "Use: $(basename "${BASH_SOURCE[0]}") [-v]" >&2
      exit 2
      ;;
  esac
done

if ! command -v curl >/dev/null 2>&1; then
  echo "curl não encontrado." >&2
  exit 1
fi

falhas=0
total=0

# Cada perfil ganha seu próprio jar de cookies. Reaproveitar um só entre
# perfis é a forma mais fácil de produzir um falso positivo: a sessão antiga
# continua valendo e o teste mede o acesso da pessoa errada.
autenticar() {
  local login="$1" senha="$2" jar="$3"
  rm -f "$jar"
  # A primeira requisição existe para o WordPress plantar o cookie de teste
  # que o wp-login.php exige antes de aceitar o formulário.
  curl -s -c "$jar" -o /dev/null "$BASE/my-account/"
  curl -s -c "$jar" -b "$jar" -o /dev/null \
    --data-urlencode "log=$login" \
    --data-urlencode "pwd=$senha" \
    --data-urlencode "wp-submit=Acessar" \
    --data-urlencode "testcookie=1" \
    "$BASE/wp-login.php"
  if ! grep -q wordpress_logged_in "$jar" 2>/dev/null; then
    echo "  !! não foi possível entrar como $login" >&2
    return 1
  fi
  return 0
}

# esperado: o código HTTP, opcionalmente seguido de um trecho que precisa
# aparecer no destino do redirecionamento ("302 /dashboard/").
conferir() {
  local jar="$1" caminho="$2" esperado="$3" descricao="$4"
  local codigo_esperado="${esperado%% *}"
  local destino_esperado=""
  [ "$esperado" != "$codigo_esperado" ] && destino_esperado="${esperado#* }"

  local resposta codigo destino
  if [ -z "$jar" ]; then
    resposta="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$BASE$caminho")"
  else
    resposta="$(curl -s -b "$jar" -o /dev/null -w '%{http_code} %{redirect_url}' "$BASE$caminho")"
  fi
  codigo="${resposta%% *}"
  destino="${resposta#* }"

  total=$((total + 1))

  local ok="sim"
  [ "$codigo" != "$codigo_esperado" ] && ok="nao"
  if [ -n "$destino_esperado" ] && [[ "$destino" != *"$destino_esperado"* ]]; then
    ok="nao"
  fi

  if [ "$ok" = "sim" ]; then
    [ "$verboso" = "sim" ] && printf '  ok    %-24s %s\n' "$caminho" "$descricao"
    return 0
  fi

  printf '  FALHA %-24s esperado %s, veio %s %s\n' "$caminho" "$esperado" "$codigo" "$destino"
  falhas=$((falhas + 1))
  return 1
}

echo "Verificando $BASE"
echo

echo "Visitante deslogado"
conferir "" "/comunidade/" "302 wp-login.php" "vai ao login, com retorno"
conferir "" "/" "200" "a vitrine é pública"

echo "Cliente (demo-cliente-marina)"
JAR_CLIENTE=/tmp/reconectar-acessos-cliente.txt
if autenticar "demo-cliente-marina" "$SENHA_DEMO" "$JAR_CLIENTE"; then
  conferir "$JAR_CLIENTE" "/my-account/"   "200"                "a própria conta"
  conferir "$JAR_CLIENTE" "/wp-admin/"     "302 /my-account/"   "sem painel administrativo"
  conferir "$JAR_CLIENTE" "/dashboard/"    "302"                "sem painel de vendedor"
  conferir "$JAR_CLIENTE" "/comunidade/"   "403"                "sem comunidade"
  conferir "$JAR_CLIENTE" "/forums/"       "403"                "sem fóruns"
else
  falhas=$((falhas + 1))
fi

echo "Vendedor (demo-sabor-da-terra)"
JAR_VENDEDOR=/tmp/reconectar-acessos-vendedor.txt
if autenticar "demo-sabor-da-terra" "$SENHA_DEMO" "$JAR_VENDEDOR"; then
  conferir "$JAR_VENDEDOR" "/dashboard/"            "200"              "o painel é dele"
  conferir "$JAR_VENDEDOR" "/comunidade/"           "200"              "participa da comunidade"
  conferir "$JAR_VENDEDOR" "/wp-admin/"             "302 /dashboard/"  "volta ao painel dele"
  conferir "$JAR_VENDEDOR" "/wp-admin/plugins.php"  "403"              "não gere plugins"
else
  falhas=$((falhas + 1))
fi

echo "Administrador"
JAR_ADMIN=/tmp/reconectar-acessos-admin.txt
if autenticar "admin" "$SENHA_ADMIN" "$JAR_ADMIN"; then
  conferir "$JAR_ADMIN" "/wp-admin/"            "200" "painel completo"
  conferir "$JAR_ADMIN" "/wp-admin/plugins.php" "200" "gere plugins"
  conferir "$JAR_ADMIN" "/comunidade/"          "200" "administra a comunidade"
else
  falhas=$((falhas + 1))
fi

rm -f "$JAR_CLIENTE" "$JAR_VENDEDOR" "$JAR_ADMIN"

# O isolamento entre vendedores não tem URL fixa: depende de qual produto
# pertence a quem. Vai por WP-CLI, e nas duas direções — uma trava que negasse
# tudo passaria num teste que só verifica a negação.
echo "Isolamento entre vendedores"
if docker compose version >/dev/null 2>&1; then
  saida="$(cd "$RAIZ_PROJETO" && docker compose run --rm wpcli wp eval '
    $a = get_user_by( "login", "demo-sabor-da-terra" );
    $b = get_user_by( "login", "demo-bem-viver" );
    if ( ! $a || ! $b ) { echo "sem-dados\n"; exit; }
    $alheio = get_posts( array( "post_type" => "product", "author" => $b->ID, "numberposts" => 1 ) );
    $proprio = get_posts( array( "post_type" => "product", "author" => $a->ID, "numberposts" => 1 ) );
    if ( ! $alheio || ! $proprio ) { echo "sem-dados\n"; exit; }
    wp_set_current_user( $a->ID );
    printf( "%s %s\n",
      current_user_can( "edit_post", $alheio[0]->ID ) ? "FALHA" : "ok",
      current_user_can( "edit_post", $proprio[0]->ID ) ? "ok" : "FALHA"
    );' 2>/dev/null | tail -1)"

  case "$saida" in
    "ok ok")
      total=$((total + 2))
      [ "$verboso" = "sim" ] && {
        echo "  ok    produto de outro         negado"
        echo "  ok    produto próprio          permitido"
      }
      ;;
    sem-dados)
      echo "  --    carga de demonstração ausente; isolamento não verificado"
      ;;
    *)
      total=$((total + 2))
      echo "  FALHA isolamento: '$saida' (esperado 'ok ok' = nega o alheio, permite o próprio)"
      falhas=$((falhas + 1))
      ;;
  esac
else
  echo "  --    Docker Compose ausente; isolamento não verificado"
fi

echo
if [ "$falhas" -eq 0 ]; then
  echo "$total casos, nenhuma falha."
  exit 0
fi

echo "$total casos, $falhas falha(s)." >&2
exit 1
