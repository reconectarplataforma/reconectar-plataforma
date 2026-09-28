#!/usr/bin/env bash
# Verificação das travas de acesso da plataforma Reconectar.
#
#   ./scripts/verificar-acessos.sh              # roda todos os casos
#   ./scripts/verificar-acessos.sh -v           # mostra também os que passam
#   BASE=http://outro:porta ./scripts/verificar-acessos.sh
#
# Faz login de verdade como cliente, vendedor, administrador de empresas e
# administrador, e bate em cada URL restrita, comparando o código HTTP com o
# esperado. Sai com status 1 se qualquer caso falhar — serve para conferir a
# demonstração antes de apresentá-la.
#
# Por que HTTP e não `current_user_can()`: a autorização precisa valer para a
# URL digitada à mão, que é o caminho que uma auditoria vai tentar. Um teste
# que só pergunta a capacidade em PHP não prova que a requisição foi barrada;
# prova apenas que a função responde o esperado quando alguém a consulta.
#
# Complementa, não substitui, o roteiro manual em docs/ROTEIRO_PERFIS.md: o
# isolamento entre vendedores (produto de um não abre para o outro) e o
# isolamento entre empresas dependem de IDs do banco e são verificados à parte,
# por WP-CLI, no fim deste script.

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
      # Linhas 2 a 21: o bloco de comentário do topo, sem o shebang.
      sed -n '2,21p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
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

# Confere a presença ou a ausência de um trecho no corpo da resposta.
#
# Os casos de cadastro de loja não cabem em `conferir`: o servidor devolve 200 e
# o formulário certo — o que se verifica é que um campo específico não está mais
# lá. Um 200 sozinho não distingue "o cadastro de cliente funciona" de "o
# formulário de vendedor continua no ar".
#
# esperado: "presente" ou "ausente".
conferir_corpo() {
  local jar="$1" caminho="$2" agulha="$3" esperado="$4" descricao="$5"
  local corpo encontrado="ausente"

  if [ -z "$jar" ]; then
    corpo="$(curl -s "$BASE$caminho")"
  else
    corpo="$(curl -s -b "$jar" "$BASE$caminho")"
  fi

  case "$corpo" in
    *"$agulha"*) encontrado="presente" ;;
  esac

  total=$((total + 1))

  if [ "$encontrado" = "$esperado" ]; then
    [ "$verboso" = "sim" ] && printf '  ok    %-24s %s\n' "$caminho" "$descricao"
    return 0
  fi

  printf '  FALHA %-24s %s: "%s" %s\n' "$caminho" "$descricao" "$agulha" "$encontrado"
  falhas=$((falhas + 1))
  return 1
}

# Consulta pontual ao banco. Existe porque parte dos casos depende de um ID que
# só o banco conhece — o da empresa que o administrador NÃO administra, que é
# justamente o caso mais importante e o único que não cabe numa URL fixa.
#
# `tail -1` porque o `docker compose run` imprime as linhas de criação do
# container antes da saída do comando; `tr -d '\r'` porque elas vêm com CR.
wp_eval() {
  if ! docker compose version >/dev/null 2>&1; then
    return 1
  fi
  ( cd "$RAIZ_PROJETO" && docker compose run --rm wpcli wp eval "$1" 2>/dev/null ) | tail -1 | tr -d '\r'
}

echo "Verificando $BASE"
echo

echo "Visitante deslogado"
conferir "" "/comunidade/"      "302 wp-login.php" "vai ao login, com retorno"
conferir "" "/painel-empresas/" "302 wp-login.php" "o painel de empresas não é público"
conferir "" "/"                 "200"              "a vitrine é pública"

echo "Cadastro de loja (só administrador e administrador de empresas)"
# A opção `show_register_as_vendor` do Dokan fecha apenas o checkbox do
# formulário do WooCommerce. Os casos abaixo cobrem as outras portas, que só
# `Reconectar_Cadastro_De_Lojas` fecha — e a última delas, o POST forjado, é a
# única que prova a trava do servidor: as demais provam que a tela não oferece.
conferir      ""                  "/vendor-onboarding/" "404"      "a página de onboarding do Dokan saiu do ar"
conferir_corpo "" "/my-account/" 'name="shopname"'  "ausente"  "registro sem campos de loja"
conferir_corpo "" "/my-account/" 'name="role"'      "presente" "registro declara o papel que o servidor aceita"

JAR_FORJADO=/tmp/reconectar-acessos-forjado.txt
EMAIL_FORJADO="verificacao-forjada@example.invalid"
rm -f "$JAR_FORJADO"
nonce_registro="$(curl -s -c "$JAR_FORJADO" "$BASE/my-account/" \
  | grep -o 'name="woocommerce-register-nonce" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')"

if [ -z "$nonce_registro" ]; then
  echo "  --    formulário de registro indisponível; POST forjado não verificado"
else
  curl -s -b "$JAR_FORJADO" -c "$JAR_FORJADO" -o /dev/null -X POST "$BASE/my-account/" \
    --data-urlencode "email=$EMAIL_FORJADO" \
    --data-urlencode "password=verificacao-$RANDOM-$RANDOM" \
    --data-urlencode "role=seller" \
    --data-urlencode "fname=Verificacao" \
    --data-urlencode "lname=Forjada" \
    --data-urlencode "phone=82900000000" \
    --data-urlencode "shopname=Loja Forjada" \
    --data-urlencode "shopurl=loja-forjada-verificacao" \
    --data-urlencode "woocommerce-register-nonce=$nonce_registro" \
    --data-urlencode "register=Register"

  # A limpeza vai junto da consulta, e não num passo seguinte: se a trava
  # falhar, o vendedor forjado não pode sobreviver à verificação que o criou.
  resultado_forjado="$(wp_eval '
    $u = get_user_by( "email", "'"$EMAIL_FORJADO"'" );
    if ( ! $u ) { echo "inexistente"; }
    else {
      $papeis = implode( ",", $u->roles );
      require_once ABSPATH . "wp-admin/includes/user.php";
      wp_delete_user( $u->ID );
      echo $papeis;
    }')"

  total=$((total + 1))
  if [ -z "$resultado_forjado" ]; then
    total=$((total - 1))
    echo "  --    Docker Compose ausente; POST forjado não verificado"
  elif [ "$resultado_forjado" = "inexistente" ]; then
    [ "$verboso" = "sim" ] && printf '  ok    %-24s %s\n' "/my-account/" "POST com role=seller recusado"
  else
    printf '  FALHA %-24s POST com role=seller criou usuário (%s)\n' "/my-account/" "$resultado_forjado"
    falhas=$((falhas + 1))
  fi
fi
rm -f "$JAR_FORJADO"

echo "Cliente (demo-cliente-marina)"
JAR_CLIENTE=/tmp/reconectar-acessos-cliente.txt
if autenticar "demo-cliente-marina" "$SENHA_DEMO" "$JAR_CLIENTE"; then
  conferir "$JAR_CLIENTE" "/my-account/"   "200"                "a própria conta"
  conferir "$JAR_CLIENTE" "/wp-admin/"     "302 /my-account/"   "sem painel administrativo"
  conferir "$JAR_CLIENTE" "/dashboard/"    "302"                "sem painel de vendedor"
  conferir "$JAR_CLIENTE" "/comunidade/"   "403"                "sem comunidade"
  conferir "$JAR_CLIENTE" "/forums/"       "403"                "sem fóruns"
  conferir "$JAR_CLIENTE" "/painel-empresas/" "403"             "não administra empresa alguma"
else
  falhas=$((falhas + 1))
fi

echo "Vendedor (demo-sabor-da-terra)"
JAR_VENDEDOR=/tmp/reconectar-acessos-vendedor.txt
if autenticar "demo-sabor-da-terra" "$SENHA_DEMO" "$JAR_VENDEDOR"; then
  conferir "$JAR_VENDEDOR" "/dashboard/"            "200"              "o painel é dele"
  conferir "$JAR_VENDEDOR" "/comunidade/"           "200"              "participa da comunidade"
  conferir "$JAR_VENDEDOR" "/forums/"               "200"              "a listagem de perguntas"
  conferir "$JAR_VENDEDOR" "/wp-admin/"             "302 /dashboard/"  "volta ao painel dele"
  conferir "$JAR_VENDEDOR" "/wp-admin/plugins.php"  "403"              "não gere plugins"
  # `restaurar_gestao_de_foruns()` devolve `edit_forums` a quem o papel já
  # autorizou. O vendedor participa do fórum e não o administra: se um dia a
  # capacidade vazar para o papel `seller`, é esta linha que avisa. O 302 é o
  # portão do `/wp-admin` chegando primeiro — negação mais forte que o 403, e
  # esperar 403 aqui exigiria que a trava fosse *mais fraca* para passar.
  conferir "$JAR_VENDEDOR" "/wp-admin/edit.php?post_type=forum" "302 /dashboard/" "participa do fórum, não o administra"
  conferir "$JAR_VENDEDOR" "/painel-empresas/"      "403"              "vender não é administrar a empresa"
  # A listagem de lojas é rota nova, e rota nova é porta nova: sem este caso, o
  # dia em que `/painel-empresas/loja/` deixasse de passar por `proteger()`
  # abriria a relação de todas as lojas a quem só administra a própria.
  conferir "$JAR_VENDEDOR" "/painel-empresas/loja/" "403"              "a listagem de lojas é do painel gerencial"
else
  falhas=$((falhas + 1))
fi

# Os dois IDs vêm do banco: o painel identifica a empresa por ID na URL, e o
# caso que interessa — abrir a empresa alheia — não tem como ser escrito sem
# saber qual é. Quando a carga de demonstração não está instalada, ficam vazios
# e os dois casos correspondentes são pulados com aviso, em vez de falharem por
# ausência de dado.
ID_NOSSO_CHAO="$(wp_eval '$p = get_page_by_path( "nosso-chao", OBJECT, "reconectar_empresa" ); echo $p ? $p->ID : "";')"
ID_BEM_VIVER="$(wp_eval '$p = get_page_by_path( "bem-viver", OBJECT, "reconectar_empresa" ); echo $p ? $p->ID : "";')"

# Os dois alvos da tela de edição de usuário, pela mesma razão: `user-edit.php`
# identifica a conta por ID, e o caso que interessa — o Administrador tentando
# abrir a conta do Super Administrador — não existe sem saber qual é.
ID_SUPER="$(wp_eval '$u = get_user_by( "login", "admin" ); echo $u ? $u->ID : "";')"
ID_LOJA_SABOR="$(wp_eval '$u = get_user_by( "login", "demo-sabor-da-terra" ); echo $u ? $u->ID : "";')"

# O papel `company_admin` entra no `/wp-admin` desde que ganhou `CAP_ADMIN_WP`, e
# os dois primeiros casos deste bloco são o retrato invertido do que eram: antes
# o painel técnico o devolvia ao `/painel-empresas/`, e a listagem de usuários
# vinha junto na negação. Hoje ele administra as contas das lojas por ali, que é
# o que "configura os usuários das lojas" pede.
#
# `themes.php` responder 200 não é trava frouxa: o núcleo abre a tela a quem tem
# `switch_themes` **ou** `edit_theme_options`, e a segunda é a única capacidade
# que edita menus. A tela fica de leitura — quem prova isso são os casos de
# capacidade mais abaixo, que negam `switch_themes` e `install_themes`. Conferir
# aqui só a URL daria a impressão errada nos dois sentidos.
echo "Administrador (demo-admin-nosso-chao)"
JAR_EMPRESAS=/tmp/reconectar-acessos-empresas.txt
if autenticar "demo-admin-nosso-chao" "$SENHA_DEMO" "$JAR_EMPRESAS"; then
  conferir "$JAR_EMPRESAS" "/painel-empresas/"      "200" "o painel é dele"
  conferir "$JAR_EMPRESAS" "/painel-empresas/loja/" "200" "a listagem das lojas sob sua gestão"
  conferir "$JAR_EMPRESAS" "/comunidade/"           "200" "participa da comunidade"
  conferir "$JAR_EMPRESAS" "/wp-admin/"             "200" "entra no painel técnico"
  conferir "$JAR_EMPRESAS" "/wp-admin/users.php"    "200" "configura as contas das lojas"
  conferir "$JAR_EMPRESAS" "/wp-admin/nav-menus.php" "200" "cria menus"
  conferir "$JAR_EMPRESAS" "/wp-admin/plugins.php"  "403" "não gere plugins"
  conferir "$JAR_EMPRESAS" "/wp-admin/theme-editor.php" "403" "não edita arquivo de tema"
  conferir "$JAR_EMPRESAS" "/wp-admin/options-general.php" "403" "não configura a instalação"
  conferir "$JAR_EMPRESAS" "/wp-admin/edit.php?post_type=product" "403" "produto é da loja, não dele"
  conferir "$JAR_EMPRESAS" "/dashboard/"            "302" "não é vendedor"

  # "Criar fóruns" é literal na especificação, e o bbPress reserva `edit_forums`
  # ao keymaster — a negação não vinha do papel e por isso não aparecia em
  # nenhum caso de capacidade. As duas últimas linhas são o outro lado: o
  # keymaster teria junto as Configurações e a ferramenta de **redefinição**,
  # que apaga o fórum inteiro da instalação.
  conferir "$JAR_EMPRESAS" "/wp-admin/edit.php?post_type=forum"     "200" "administra os fóruns"
  conferir "$JAR_EMPRESAS" "/wp-admin/post-new.php?post_type=forum" "200" "cria fóruns"
  conferir "$JAR_EMPRESAS" "/wp-admin/edit.php?post_type=topic"     "200" "modera os tópicos"
  conferir "$JAR_EMPRESAS" "/wp-admin/edit.php?post_type=reply"     "200" "modera as respostas"
  conferir "$JAR_EMPRESAS" "/wp-admin/options-general.php?page=bbpress" "403" "não configura o bbPress"
  conferir "$JAR_EMPRESAS" "/wp-admin/tools.php?page=bbp-repair"    "403" "não redefine o fórum"

  if [ -n "$ID_NOSSO_CHAO" ] && [ -n "$ID_BEM_VIVER" ]; then
    conferir "$JAR_EMPRESAS" "/painel-empresas/empresa/$ID_NOSSO_CHAO/" "200" "abre a empresa que administra"
    conferir "$JAR_EMPRESAS" "/painel-empresas/empresa/$ID_BEM_VIVER/"  "403" "não abre a empresa alheia"
  else
    echo "  --    carga de demonstração ausente; isolamento entre empresas não verificado por URL"
  fi

  # A escalada de privilégio pela tela, e não só pela capacidade. Confere-se o
  # corpo, não o status: `user-edit.php` nega com um `wp_die()` sem código, que
  # sai como **500** — o mesmo número de um fatal de PHP. Esperar "500" daria um
  # verde quando o arquivo estivesse quebrado, que é exatamente o caso que uma
  # verificação de permissão precisa distinguir.
  if [ -n "$ID_SUPER" ] && [ -n "$ID_LOJA_SABOR" ]; then
    conferir_corpo "$JAR_EMPRESAS" "/wp-admin/user-edit.php?user_id=$ID_SUPER" \
      "Sem permissão para editar este usuário" "presente" "não edita o Super Administrador"
    conferir_corpo "$JAR_EMPRESAS" "/wp-admin/user-edit.php?user_id=$ID_LOJA_SABOR" \
      "Sem permissão para editar este usuário" "ausente" "edita a conta de uma loja"
  else
    echo "  --    contas ausentes; escalada por URL não verificada"
  fi
else
  falhas=$((falhas + 1))
fi

# O Moderador de Conteúdo não tem escopo de empresa — ele modera a plataforma
# inteira —, e é por isso que `/painel-empresas/` lhe é negado: moderar conteúdo
# não é administrar cadastro de loja. Os dois lados precisam estar aqui, porque
# uma regressão que lhe desse o painel gerencial não mudaria nenhuma outra tela.
echo "Moderador de Conteúdo (demo-moderador)"
JAR_MODERADOR=/tmp/reconectar-acessos-moderador.txt
if autenticar "demo-moderador" "$SENHA_DEMO" "$JAR_MODERADOR"; then
  conferir "$JAR_MODERADOR" "/wp-admin/"              "200" "entra no painel técnico"
  conferir "$JAR_MODERADOR" "/wp-admin/nav-menus.php" "200" "cria menus"
  conferir "$JAR_MODERADOR" "/wp-admin/edit.php?post_type=reconectar_campanha" "200" "publica campanhas"
  conferir "$JAR_MODERADOR" "/comunidade/"            "200" "modera a comunidade"
  conferir "$JAR_MODERADOR" "/forums/"                "200" "tem acesso ao fórum"
  conferir "$JAR_MODERADOR" "/wp-admin/edit.php?post_type=forum"     "200" "administra os fóruns"
  conferir "$JAR_MODERADOR" "/wp-admin/post-new.php?post_type=forum" "200" "cria fóruns"
  conferir "$JAR_MODERADOR" "/wp-admin/edit.php?post_type=topic"     "200" "modera os tópicos"
  conferir "$JAR_MODERADOR" "/wp-admin/edit.php?post_type=reply"     "200" "modera as respostas"
  conferir "$JAR_MODERADOR" "/wp-admin/options-general.php?page=bbpress" "403" "não configura o bbPress"
  conferir "$JAR_MODERADOR" "/wp-admin/tools.php?page=bbp-repair"    "403" "não redefine o fórum"
  conferir "$JAR_MODERADOR" "/wp-admin/users.php"     "403" "não configura usuários"
  conferir "$JAR_MODERADOR" "/wp-admin/plugins.php"   "403" "não gere plugins"
  conferir "$JAR_MODERADOR" "/wp-admin/theme-editor.php" "403" "não edita arquivo de tema"
  conferir "$JAR_MODERADOR" "/wp-admin/edit.php?post_type=product" "403" "produto é da loja"
  conferir "$JAR_MODERADOR" "/painel-empresas/"       "403" "moderar não é administrar empresa"
  conferir "$JAR_MODERADOR" "/dashboard/"             "302" "não é vendedor"

  # Pelo corpo, e não pelo status, pela razão registrada no bloco acima: o
  # `wp_die()` de negação sai como 500 e um fatal de PHP sairia igual.
  if [ -n "$ID_LOJA_SABOR" ]; then
    conferir_corpo "$JAR_MODERADOR" "/wp-admin/user-edit.php?user_id=$ID_LOJA_SABOR" \
      "Sem permissão para editar este usuário" "presente" "não edita conta de loja"
  fi
else
  falhas=$((falhas + 1))
fi

echo "Super Administrador (admin)"
JAR_ADMIN=/tmp/reconectar-acessos-admin.txt
if autenticar "admin" "$SENHA_ADMIN" "$JAR_ADMIN"; then
  conferir "$JAR_ADMIN" "/wp-admin/"            "200" "painel completo"
  conferir "$JAR_ADMIN" "/wp-admin/plugins.php" "200" "gere plugins"
  conferir "$JAR_ADMIN" "/comunidade/"          "200" "administra a comunidade"
  conferir "$JAR_ADMIN" "/painel-empresas/"     "200" "também administra empresas"
else
  falhas=$((falhas + 1))
fi

# O endpoint de voto não tem URL de leitura: só responde a POST, e por isso não
# cabe em `conferir`. O caso abaixo prova a primeira das duas travas — o nonce.
# A segunda, a capacidade, vai por WP-CLI mais adiante: um nonce válido não pode
# ser forjado aqui porque `wp_create_nonce()` amarra o valor ao token de sessão
# guardado no cookie, que o WP-CLI não tem. As duas juntas é que fecham o
# endpoint; provar só uma daria a impressão de cobertura que não existe.
echo "Endpoint de voto do fórum"
ID_TOPICO="$(wp_eval '$t = get_posts( array( "post_type" => "topic", "numberposts" => 1, "fields" => "ids" ) ); echo $t ? $t[0] : "";')"
if [ -n "$ID_TOPICO" ] && [ -f "$JAR_CLIENTE" ]; then
  # Os dois perfis, e não só o cliente. Antes da exceção de `admin-post.php` em
  # `bloquear_area_administrativa()`, os dois recebiam 302 para a própria área:
  # o cliente para `/my-account/` e o vendedor para `/dashboard/`. O 302 do
  # cliente parecia prova de que a trava funcionava, e era o contrário — ninguém
  # alcançava o endpoint, nem quem devia. Testar só o lado da negação teria
  # deixado o voto quebrado com o script verde.
  for perfil_jar in "cliente:$JAR_CLIENTE" "vendedor:$JAR_VENDEDOR"; do
    perfil="${perfil_jar%%:*}"
    jar="${perfil_jar#*:}"
    # `curl -b` com caminho inexistente não falha: trata o argumento como uma
    # string de cookies e manda a requisição deslogada, que também devolve 403 —
    # um caso verde sem ter testado ninguém.
    [ -f "$jar" ] || continue
    codigo="$(curl -s -b "$jar" -o /dev/null -w '%{http_code}' \
      --data-urlencode "action=reconectar_votar" \
      --data-urlencode "conteudo=$ID_TOPICO" \
      --data-urlencode "sentido=1" \
      --data-urlencode "_wpnonce=invalido" \
      "$BASE/wp-admin/admin-post.php")"
    total=$((total + 1))
    if [ "$codigo" = "403" ]; then
      [ "$verboso" = "sim" ] && printf '  ok    %-24s %s\n' "admin-post.php" "voto sem nonce recusado ($perfil)"
    else
      printf '  FALHA %-24s %s: esperado 403, veio %s\n' "admin-post.php" "$perfil" "$codigo"
      falhas=$((falhas + 1))
    fi
  done
else
  echo "  --    carga de demonstração ausente; endpoint de voto não verificado"
fi

# Criar pergunta: o caso que faltava, e que custou um bug em produção de
# demonstração. A pergunta era gravada — então qualquer teste que só conferisse
# o banco passaria —, mas o `wp_safe_redirect()` do bbPress falhava com "headers
# already sent" porque um aviso de depreciação do BuddyPress já tinha impresso
# no corpo da resposta. O usuário ficava numa tela de erros, sem nunca ver o
# tópico que acabara de criar.
#
# Por isso o caso mede as duas coisas: o 302, que prova que o redirect
# aconteceu, e a ausência de saída de depuração no corpo, que prova o porquê.
echo "Criação de pergunta no fórum"
ID_CATEGORIA="$(wp_eval '$f = get_posts( array( "post_type" => "forum", "numberposts" => 1, "fields" => "ids" ) ); echo $f ? $f[0] : "";')"
if [ -n "$ID_CATEGORIA" ] && [ -f "$JAR_VENDEDOR" ]; then
  url_categoria="$(wp_eval "echo get_permalink( $ID_CATEGORIA );")"
  titulo="Verificação automática $(date +%s)"
  corpo_form="$(curl -s -b "$JAR_VENDEDOR" "$url_categoria")"
  # O `_wpnonce` do formulário de novo tópico, e não o primeiro da página: a
  # busca do tema e a barra de administração também imprimem um campo com esse
  # nome, e pegar o errado devolveria 200 com "falha de segurança" — um caso
  # vermelho que não seria o defeito que se quer medir.
  nonce="$(printf '%s' "$corpo_form" | tr '>' '>\n' | sed -n '/id="new-post"/,/<\/form/p' |
    grep -o 'name="_wpnonce" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')"

  if [ -z "$nonce" ]; then
    printf '  FALHA %-24s %s\n' "novo tópico" "formulário de pergunta não encontrado em $url_categoria"
    falhas=$((falhas + 1))
    total=$((total + 1))
  else
    resposta="$(curl -s -b "$JAR_VENDEDOR" -w '\n%{http_code}' \
      --data-urlencode "action=bbp-new-topic" \
      --data-urlencode "bbp_topic_title=$titulo" \
      --data-urlencode "bbp_topic_content=Pergunta criada por verificar-acessos.sh; é removida ao fim do teste." \
      --data-urlencode "bbp_forum_id=$ID_CATEGORIA" \
      --data-urlencode "_wpnonce=$nonce" \
      --data-urlencode "_wp_http_referer=${url_categoria#"$BASE"}" \
      "$url_categoria")"
    codigo="$(printf '%s' "$resposta" | tail -1)"
    corpo="$(printf '%s' "$resposta" | sed '$d')"

    total=$((total + 1))
    if [ "$codigo" = "302" ]; then
      [ "$verboso" = "sim" ] && printf '  ok    %-24s %s\n' "novo tópico" "pergunta salva e redirecionada"
    else
      printf '  FALHA %-24s esperado 302, veio %s\n' "novo tópico" "$codigo"
      falhas=$((falhas + 1))
    fi

    total=$((total + 1))
    if printf '%s' "$corpo" | grep -qiE 'Deprecated|headers already sent|Warning:'; then
      printf '  FALHA %-24s %s\n' "novo tópico" "saída de depuração no corpo da resposta"
      falhas=$((falhas + 1))
    else
      [ "$verboso" = "sim" ] && printf '  ok    %-24s %s\n' "novo tópico" "resposta sem saída de depuração"
    fi

    # A limpeza é do próprio teste: sem ela, cada execução deixaria uma pergunta
    # a mais no fórum da demonstração.
    removidos="$(wp_eval "
      \$t = get_posts( array( 'post_type' => 'topic', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 's' => '$titulo' ) );
      \$n = 0;
      foreach ( \$t as \$id ) {
        if ( get_the_title( \$id ) === '$titulo' ) { wp_delete_post( \$id, true ); \$n++; }
      }
      echo \$n;")"
    total=$((total + 1))
    if [ "$removidos" = "1" ]; then
      [ "$verboso" = "sim" ] && printf '  ok    %-24s %s\n' "novo tópico" "pergunta de teste removida"
    else
      printf '  FALHA %-24s esperava remover 1 pergunta de teste, removeu %s\n' "novo tópico" "${removidos:-0}"
      falhas=$((falhas + 1))
    fi
  fi
else
  echo "  --    nenhuma categoria de fórum; criação de pergunta não verificada"
fi

rm -f "$JAR_CLIENTE" "$JAR_VENDEDOR" "$JAR_EMPRESAS" "$JAR_ADMIN"

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

# As travas de URL acima provam que o painel técnico está fechado hoje. Este
# bloco prova o motivo: o papel não tem as capacidades que abririam aquelas
# portas. A diferença importa porque um redirecionamento é conveniência de
# interface — quem de fato barra é a capacidade ausente, e é ela que uma
# regressão em `sincronizar_capacidades()` concederia sem que nenhuma URL mudasse
# de código.
#
# O caso do produto é o alcance decidido para este ator: consulta, não edição.
# Ele administra o vendedor da Sabor da Terra e ainda assim não pode editar um
# produto dele.
#
# `manage_options` e `manage_woocommerce` continuam na lista mesmo agora que os
# dois papéis entram no `/wp-admin`, e é o ponto mais importante deste bloco: a
# porta se abriu por uma capacidade própria, `reconectar_acessar_wp_admin`, e
# não por uma das duas. Conceder `manage_options` devolveria a instalação de
# plugins pela mesma linha que a barra — `restringir_gestao_da_tecnologia()`
# exige exatamente ela —, e `manage_woocommerce` abriria as listagens de produto
# e pedido de **todas** as lojas, desfazendo em silêncio o isolamento por
# empresa que só existe dentro do `/painel-empresas/`.
#
# As capacidades de tema estão aqui por inteiro porque `edit_theme_options` —
# que os dois papéis têm, por ser a única do núcleo que edita menus — abre a
# tela Aparência → Temas. São estes casos que provam que a tela é só de leitura;
# nenhuma URL provaria, já que ela responde 200 de propósito.
echo "Capacidades proibidas ao Administrador e ao Moderador"
if docker compose version >/dev/null 2>&1; then
  saida="$(cd "$RAIZ_PROJETO" && docker compose run --rm wpcli wp eval '
    $atores = array(
      "Administrador" => get_user_by( "login", "demo-admin-nosso-chao" ),
      "Moderador"     => get_user_by( "login", "demo-moderador" ),
    );
    if ( ! $atores["Administrador"] || ! $atores["Moderador"] ) { echo "::sem-dados\n"; exit; }

    $proibidas = array(
      "manage_options",
      "manage_woocommerce",
      "dokandar",
      "install_plugins",
      "activate_plugins",
      "edit_plugins",
      "delete_plugins",
      "switch_themes",
      "install_themes",
      "edit_themes",
      "delete_themes",
      "upload_themes",
      "edit_files",
      "update_core",
    );

    foreach ( $atores as $rotulo => $ator ) {
      foreach ( $proibidas as $cap ) {
        printf( "::%s %s: %s\n", user_can( $ator, $cap ) ? "FALHA" : "ok", $rotulo, $cap );
      }
    }

    // Gestão de contas: concedida ao Administrador por desenho, negada ao
    // Moderador. Os dois lados juntos, porque uma regressão que negasse a
    // todo mundo passaria num teste que só verifica a negação.
    foreach ( array( "list_users", "create_users", "edit_users", "promote_users" ) as $cap ) {
      printf( "::%s Administrador tem: %s\n", user_can( $atores["Administrador"], $cap ) ? "ok" : "FALHA", $cap );
      printf( "::%s Moderador: %s\n", user_can( $atores["Moderador"], $cap ) ? "FALHA" : "ok", $cap );
    }

    wp_set_current_user( $atores["Administrador"]->ID );
    $vendedor = get_user_by( "login", "demo-sabor-da-terra" );
    $produtos = $vendedor ? get_posts( array( "post_type" => "product", "author" => $vendedor->ID, "numberposts" => 1 ) ) : array();
    if ( $produtos ) {
      printf( "::%s %s\n", current_user_can( "edit_post", $produtos[0]->ID ) ? "FALHA" : "ok", "Administrador: edit_post de produto de loja sob sua gestão" );
    }' 2>/dev/null | grep "^::" | sed "s/^:://" | tr -d "\r")"

  if [ "$saida" = "sem-dados" ]; then
    echo "  --    carga de demonstração ausente; capacidades não verificadas"
  elif [ -z "$saida" ]; then
    total=$((total + 1))
    echo "  FALHA não foi possível consultar as capacidades dos papéis administrativos"
    falhas=$((falhas + 1))
  else
    # Heredoc, e não pipe: um `while` do outro lado de um pipe roda em subshell,
    # e os incrementos de `falhas` morreriam com ela — o script terminaria com
    # status 0 anunciando falhas que acabou de imprimir.
    # O rótulo já vem com o nome do ator e diz por si se o caso espera concessão
    # ou negação ("Administrador tem: edit_users"). Escrever "negado" na mensagem,
    # como antes, agora mentiria na metade das linhas.
    while IFS=" " read -r estado rotulo; do
      total=$((total + 1))
      if [ "$estado" = "ok" ]; then
        [ "$verboso" = "sim" ] && echo "  ok    $rotulo"
      else
        echo "  FALHA $rotulo"
        falhas=$((falhas + 1))
      fi
    done <<FIM
$saida
FIM
  fi
else
  echo "  --    Docker Compose ausente; capacidades não verificadas"
fi

# A escalada de privilégio que `edit_users` abre, tentada de verdade.
#
# Em single-site, quem tem `edit_users` alcança **qualquer** conta, inclusive a
# do Super Administrador: trocar a senha dele e entrar com ela. E `promote_users`
# permite promover a si mesmo. As duas juntas transformariam o Administrador no
# topo em dois cliques — e o pedido de não dar acesso de desenvolvedor viraria
# decoração, sem que nenhuma tela parecesse quebrada.
#
# Os casos aqui são meta-capacidades (`edit_user`, `promote_user`), com alvo, e
# é por isso que este bloco não cabe no de cima: as primitivas do Administrador
# estão concedidas de propósito, e é `negar_gestao_de_usuarios_superiores()`, em
# `map_meta_cap`, que decide caso a caso. Os dois últimos casos são o contorno:
# sobre a própria conta e sobre uma loja, as mesmas capacidades precisam
# continuar funcionando, ou o filtro teria fechado o perfil inteiro.
echo "Escalada de privilégio pelo Administrador"
if docker compose version >/dev/null 2>&1; then
  saida="$(cd "$RAIZ_PROJETO" && docker compose run --rm wpcli wp eval '
    $ator  = get_user_by( "login", "demo-admin-nosso-chao" );
    $super = get_user_by( "login", "admin" );
    $loja  = get_user_by( "login", "demo-sabor-da-terra" );
    if ( ! $ator || ! $super || ! $loja ) { echo "::sem-dados\n"; exit; }

    wp_set_current_user( $ator->ID );

    printf( "::%s nao edita o Super Administrador\n", current_user_can( "edit_user", $super->ID ) ? "FALHA" : "ok" );
    printf( "::%s nao apaga o Super Administrador\n", current_user_can( "delete_user", $super->ID ) ? "FALHA" : "ok" );
    printf( "::%s nao promove o Super Administrador\n", current_user_can( "promote_user", $super->ID ) ? "FALHA" : "ok" );
    printf( "::%s nao promove a si mesmo a administrator\n", current_user_can( "promote_user", $ator->ID, "administrator" ) ? "FALHA" : "ok" );

    // Os dois campos por onde o papel de destino chega de verdade, e não só o
    // argumento acima. `users.php` usa `new_role` na ação em massa e
    // `user-edit.php` usa `role`: são chaves diferentes, e ler só uma deixaria
    // o outro caminho sem regra nenhuma.
    foreach ( array( "role", "new_role" ) as $campo ) {
      $_REQUEST = array( $campo => "administrator" );
      printf( "::%s nao promove a administrator via campo %s\n", current_user_can( "promote_user", $ator->ID ) ? "FALHA" : "ok", $campo );

      $_REQUEST = array( $campo => "customer" );
      printf( "::%s ainda promove a customer via campo %s\n", current_user_can( "promote_user", $loja->ID ) ? "ok" : "FALHA", $campo );
    }
    $_REQUEST = array();

    // `wp_update_user()` fica de fora de propósito, e a ausência merece nota: ela
    // é API de baixo nível e **não** consulta `map_meta_cap`, então uma tentativa
    // por ali promoveria o usuário de verdade e reprovaria um filtro que está
    // correto — além de estragar o ambiente no meio da verificação. Quem chama
    // `promote_user` é a tela, e é a tela que o caso HTTP acima exercita.
    printf( "::%s edita a conta de uma loja\n", current_user_can( "edit_user", $loja->ID ) ? "ok" : "FALHA" );
    printf( "::%s edita a propria conta\n", current_user_can( "edit_user", $ator->ID ) ? "ok" : "FALHA" );' 2>/dev/null | grep "^::" | sed "s/^:://" | tr -d "\r")"

  if [ "$saida" = "sem-dados" ]; then
    echo "  --    carga de demonstração ausente; escalada não verificada"
  elif [ -z "$saida" ]; then
    total=$((total + 1))
    echo "  FALHA não foi possível tentar a escalada de privilégio"
    falhas=$((falhas + 1))
  else
    # Heredoc pela mesma razão dos blocos anteriores: um `while` depois de um
    # pipe roda em subshell e os incrementos de `falhas` morreriam com ela.
    while IFS=" " read -r estado rotulo; do
      total=$((total + 1))
      if [ "$estado" = "ok" ]; then
        [ "$verboso" = "sim" ] && echo "  ok    $rotulo"
      else
        echo "  FALHA $rotulo"
        falhas=$((falhas + 1))
      fi
    done <<FIM
$saida
FIM
  fi
else
  echo "  --    Docker Compose ausente; escalada não verificada"
fi

# A trava de URL prova que o cliente não chega à listagem. Este bloco prova a
# camada de baixo, que é a que importa quando a URL muda: o bbPress processa o
# POST de criação em `template_redirect` com prioridade 8, antes da 10 em que
# `bloquear_comunidade()` está, e o handler dele só consulta a capacidade
# primitiva. Sem `negar_escrita_no_forum()`, `demo-cliente-marina` respondia SIM
# a `publish_topics` — o nonce era a única barreira.
#
# Os três casos do vendedor não são simetria decorativa: uma regressão que
# negasse a capacidade a todo mundo passaria num teste que só verifica a
# negação. E `read_forum` fecha o par pelo outro lado — a leitura do fórum
# continua livre para o cliente, que é o que o desenho pede; quem o barra em
# `/forums/` é o gate HTTP, não a capacidade.
echo "Escrita no fórum"
if docker compose version >/dev/null 2>&1; then
  saida="$(cd "$RAIZ_PROJETO" && docker compose run --rm wpcli wp eval '
    $cliente  = get_user_by( "login", "demo-cliente-marina" );
    $vendedor = get_user_by( "login", "demo-sabor-da-terra" );
    $foruns   = get_posts( array( "post_type" => "forum", "numberposts" => 1, "fields" => "ids" ) );
    $topicos  = get_posts( array( "post_type" => "topic", "numberposts" => 1, "fields" => "ids" ) );
    if ( ! $cliente || ! $vendedor || ! $foruns ) { echo "::sem-dados\n"; exit; }

    foreach ( array( "publish_topics", "publish_replies", "assign_topic_tags" ) as $cap ) {
      printf( "::%s cliente-nao-%s\n", user_can( $cliente, $cap ) ? "FALHA" : "ok", $cap );
      printf( "::%s vendedor-%s\n", user_can( $vendedor, $cap ) ? "ok" : "FALHA", $cap );
    }

    printf( "::%s cliente-le-o-forum\n", user_can( $cliente, "read_forum", $foruns[0] ) ? "ok" : "FALHA" );

    if ( $topicos && class_exists( "Reconectar_Forum" ) ) {
      $voto = Reconectar_Forum::votar( $topicos[0], 1, $cliente->ID );
      printf( "::%s cliente-nao-vota\n", is_wp_error( $voto ) ? "ok" : "FALHA" );

      $autor = (int) get_post_field( "post_author", $topicos[0] );
      $proprio = Reconectar_Forum::votar( $topicos[0], 1, $autor );
      printf( "::%s autor-nao-vota-em-si\n", is_wp_error( $proprio ) ? "ok" : "FALHA" );
    }' 2>/dev/null | grep "^::" | sed "s/^:://" | tr -d "\r")"

  if [ "$saida" = "sem-dados" ]; then
    echo "  --    carga de demonstração ausente; escrita no fórum não verificada"
  elif [ -z "$saida" ]; then
    total=$((total + 1))
    echo "  FALHA não foi possível consultar as capacidades de fórum"
    falhas=$((falhas + 1))
  else
    # Heredoc pela mesma razão do bloco anterior: um `while` depois de um pipe
    # roda em subshell e os incrementos de `falhas` morreriam com ela.
    while IFS=" " read -r estado rotulo; do
      total=$((total + 1))
      if [ "$estado" = "ok" ]; then
        [ "$verboso" = "sim" ] && echo "  ok    $rotulo"
      else
        echo "  FALHA $rotulo"
        falhas=$((falhas + 1))
      fi
    done <<FIM
$saida
FIM
  fi
else
  echo "  --    Docker Compose ausente; escrita no fórum não verificada"
fi

echo
if [ "$falhas" -eq 0 ]; then
  echo "$total casos, nenhuma falha."
  exit 0
fi

echo "$total casos, $falhas falha(s)." >&2
exit 1
