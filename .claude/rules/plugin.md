---
paths:
  - "wp-content/plugins/reconectar-core/**"
  - "scripts/verificar-acessos.sh"
  - "scripts/verificar-incubadora.php"
---

# Armadilhas — plugin: RBAC, fórum, Incubadora, privacidade e e-mail

Continuação da seção **Armadilhas** do `CLAUDE.md` da raiz, carregada só quando
a sessão mexe nos caminhos acima. Quando um item fala em armadilha "registrada
acima" ou "adiante", ela pode estar na raiz ou em outro arquivo de `.claude/rules/`.

## O topo e a barra React do Dokan decidem por URL, não por papel

Dois defeitos mudos de quem abre a moldura do painel sem ser loja — hoje, o
painel de empresas.

**Item ativo é prefixo.** A barra lateral acende item sem submenu por
`location.href.startsWith( item.url )`. Um item cuja URL é prefixo de outro —
`/painel-empresas/` e `/painel-empresas/loja/` — acende junto nas telas do
outro, e nada no PHP acusa: o `<li class="active">` da barra **clássica**
sai certo, e não é ela que aparece com o layout React. Veja o `#content` em
`Reconectar_Navegacao_Da_Loja::registrar_menu()`.

**"Visitar loja" não está na configuração do layout.** O filtro
`dokan_vendor_dashboard_layout_config` corrige nome, `editUrl` e "Minha conta",
e o botão segue apontando para `/store/<login>/` — uma loja que não existe. O
React lê `window.dokan.urls.storeUrl`, montado em `Assets.php` com
`dokan_get_store_url()` do usuário corrente; o filtro que o alcança é
`dokan_frontend_localize_script`.

E o `style.css` do Dokan zera o padding de
`.dokan-dashboard .dokan-dashboard-content ul li`, com (0,3,2): todo `<ul>`
autoral dentro da moldura perde o recuo dos itens.

## Aba nova no painel do Dokan exige flush de reescrita

Os três ganchos que criam a aba — `dokan_query_var_filter`,
`dokan_get_dashboard_nav` e `dokan_load_custom_template` — não bastam. A query
var nova só passa a valer depois de as regras de reescrita serem regeneradas.

Numa instalação recém-provisionada o `provision.sh` faz o flush no fim, e por
isso o defeito não aparece em ambiente novo. Numa instalação que já está de pé,
ninguém faz — e o sintoma é mudo do jeito mais caro: **o item aparece no menu
lateral, o link existe, e a tela responde 404**, sem uma linha no log. Quem
investigar vai para os ganchos, que estão corretos.

O lugar do flush é `Reconectar_Migracoes`, que é o mecanismo que existe para
rodar uma vez por instalação. Veja `reescrever_permalinks()` — e a armadilha
seguinte, que é sobre **onde** ele roda.

**Remover a aba exige o mesmo flush**, e é fácil esquecer porque não há tela nova
para conferir: a regra segue gravada apontando para um template que já não
existe. Por isso `VERSAO` subiu duas vezes pela mesma aba — 4 na criação, 5 na
remoção. Uma instalação parada em `4` não rodaria nada.

## `flush_rewrite_rules()` em `init` prioridade 5 apaga as regras de terceiros

`flush_rewrite_rules()` não "atualiza" o conjunto: ele **regenera** a partir do
que estiver registrado naquele instante. As migrações rodam em `init` prioridade
5, e nessa altura nem o Dokan nem o bbPress registraram as deles.

O resultado é um conjunto gravado sem o painel da loja e sem o fórum — as duas
áreas caem de uma vez, e a migração se marca como aplicada, então rodar de novo
não conserta. O reparo manual é `wp rewrite flush`; **qualquer instalação que já
tenha rodado a migração defeituosa precisa dele**.

`wp_loaded` é o primeiro gancho em que todo `init` já passou. Agendar o flush
para lá de dentro da migração — `add_action( 'wp_loaded', 'flush_rewrite_rules' )`
— mantém a garantia de rodar uma vez e regenera com tudo registrado.

## `wp_posts.post_status` é `varchar(20)`, e o `sql_mode` não é estrito

Medido nesta instalação:
`ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION` — sem
`STRICT_TRANS_TABLES`. Um slug de status acima de 20 caracteres é **truncado no
`INSERT`, em silêncio**, e o pedido fica com um valor que não casa com nenhum
status registrado: `wc_get_order_statuses()` não o encontra, e a tela imprime o
slug cru no lugar do rótulo.

O prefixo `wc-` conta. `wc-conferencia` tem 14 e passa; um
`wc-aguardando-analise-do-comprovante`, que era o nome natural, teria 36 e
chegaria ao banco como `wc-aguardando-analis`. Nada falha, nada é registrado, e a
investigação vai para o registro do status — que está correto.

HPOS está desligado nesta instalação (`wp_wc_orders` não existe); ligado, a
coluna equivalente tem folga maior, mas o limite do `wp_posts` volta a valer na
sincronização.

## `get_terms()` com `meta_key` em `product_cat` devolve vazio

`wc_change_pre_get_terms()` reescreve a consulta de termo das taxonomias do
WooCommerce para ordenar pela meta `order`, e nisso troca o `meta_key` pedido
pelo dela. A busca por `meta_key => '_reconectar_categoria_chave'` volta **vazia**,
sem aviso — a mesma forma do `wc_get_orders()`, só que pelo lado do vazio em vez
do banco inteiro. `meta_query` com `orderby` explícito escapa da troca; veja
`Reconectar_Servicos::termos()`.

## O bbPress processa POST em `template_redirect` **prioridade 8**

`bbp_template_redirect` (`bbpress/includes/core/actions.php:50`) roda **antes**
da prioridade 10, onde está `bloquear_comunidade()`. E o handler de criação só
consulta a capacidade primitiva (`publish_topics`), que todo usuário tem por
causa do papel `bbp_participant` dado no registro. Quem barra a escrita é
`Reconectar_Permissoes::negar_escrita_no_forum()`, em `map_meta_cap` — não o
gate de leitura.

## `admin-post.php` mora dentro de `/wp-admin`

É a rota padrão de `<form method="post">` de front-end, e o portão
`bloquear_area_administrativa()` a interceptava. O sintoma não é erro: é 302
para `/my-account/` ou `/dashboard/` e a ação simplesmente não acontecer, numa
página plausível. Há exceção explícita para ela, irmã da de `wp_doing_ajax()`.
**Todo endpoint novo de front-end precisa dessa exceção ou de outra rota.**

## A integração bbPress↔BuddyPress chama função removida na 12.0

`BBP_BuddyPress_Members::get_profile_url()`
(`bbpress/includes/extend/buddypress/members.php:232`) testa
`function_exists( 'bp_core_get_user_domain' )` **antes** de
`bp_members_get_user_url()`. No BuddyPress 12+ a primeira continua existindo
como casca depreciada, então o teste passa, o ramo antigo roda e o aviso sai —
junto com o defeito acima, já que `bbp_get_user_profile_url()` é chamado no
fluxo de criação de tópico.

`Reconectar_Forum::substituir_urls_de_perfil()` troca os seis filtros
`bbp_pre_get_user_*` por versões que usam o substituto oficial. Silenciar o
aviso resolveria a tela e deixaria a chamada obsoleta de pé, para quebrar de
novo quando o BuddyPress remover a casca.

## Ordenar por meta no `WP_Query`: as duas rotas óbvias falham

`meta_key` + `orderby => 'meta_value'` monta INNER JOIN e some com quem não tem
a meta. E `meta_query` com `relation => 'OR'` e ramo `NOT EXISTS` traz todos de
volta **estragando a ordem** — a condição sai do `ON` para o `WHERE`, o join
casa todas as metas do post e o `GROUP BY` ordena por uma qualquer. Medido: um
tópico com saldo 7 atrás de um sem voto. Use `posts_clauses` com `LEFT JOIN`
próprio e `COALESCE( …, 0 )`, como em `Reconectar_Forum::ordenar_por_votos()`.

## `bbp_new_topic`/`bbp_new_reply` só disparam pelo formulário do frontend

`bbp_insert_topic()` e `bbp_insert_reply()` não os acionam. Criação programática
precisa gravar as metas à mão — e recontar: `bbp_update_reply_walker()` só refaz
as contagens sob `bbp_deleted_reply` ou `save_post`, e em WP-CLI o resultado
seria `_bbp_reply_count` em zero com respostas na tela.

## O bbPress tem uma segunda camada de papéis, e ela **vence** a do WordPress

`bbp_keymaster`, `bbp_moderator`, `bbp_participant`, `bbp_spectator`,
`bbp_blocked`. São gravados como papel **adicional** do usuário e se sobrepõem às
capacidades de fórum declaradas no papel do WordPress.

Todo cadastro recebe `bbp_participant`. Foi por isso que um papel novo declarando
`edit_topics` e `delete_others_topics` respondia "não" às duas: a capacidade era
escrita no papel, aplicada pelo `sincronizar_capacidades()`, e perdida adiante —
o sintoma mais caro deste repositório. Quem resolve é
`Reconectar_Permissoes::aplicar_moderacao_no_forum()`.

O gancho é **`set_user_role`**, nunca `add_user_role`: `bbp_set_user_role()` troca
o papel com `WP_User::remove_role()` e `add_role()`, que disparam
`add_user_role` — o método chamaria a si mesmo.

## `bbp_map_forum_meta_caps()` reserva `edit_forums` ao `keep_gate`

Sob `bbp_map_meta_caps`, em `map_meta_cap` prioridade 10, o bbPress troca
`edit_forums` e `edit_others_forums` por `do_not_allow` para quem não é keymaster,
**independentemente do papel**. Medido, com o papel declarando as duas:

```
allcaps[edit_forums] = true
map_meta_cap( 'edit_forums' ) = do_not_allow
```

`publish_forums` **não** sofre isso — essa o bbPress mapeia para `moderate`. A
assimetria é dele. `Reconectar_Permissoes::restaurar_gestao_de_foruns()` devolve
as duas em **prioridade 11**, e só para quem o papel já tinha autorizado; a
leitura é em `$usuario->allcaps[$cap]` e não com `user_can()`, que reentraria em
`map_meta_cap` com a mesma capacidade e entraria em recursão infinita.

`keep_gate` continua fora de alcance de propósito: ele abre as Configurações do
bbPress e a ferramenta de **redefinição**, que apaga fóruns, tópicos e respostas
da instalação inteira.

## `edit_comment` numa avaliação vira `edit_others_products`

O núcleo mapeia `edit_comment` para a capacidade de edição do **post** ao qual o
comentário pertence. Numa avaliação de produto, isso é `edit_others_products`,
que o Moderador não tem e não deve ter. Conferir a moderação por
`current_user_can( 'edit_comment', $id )` recusa o Moderador em toda avaliação,
com a tela aparentemente certa e o botão devolvendo erro. A fila de
`Reconectar_Painel_Moderacao` confere `moderate_comments` e o tipo do
comentário. `rc_incubadora` fica de fora, porque já é moderado na Incubadora.

## `.rc-botao` existe no tema **e** no plugin

O `marketplace.css` do tema declara `.rc-botao` laranja, em (0,1,0), e carrega
**depois** do `painel-empresas.css` do plugin. Com a mesma especificidade, o
fundo do tema vence `.rc-botao--primario` e `.rc-botao--discreto`. Nas telas de
`/gestao/`, todo botão saía laranja: "Desaprovar", "Spam" e "Lixeira" tinham o
mesmo peso visual de "Aprovar". É de novo colisão de nome, e não há regra
autoral errada no caminho. `painel-gestao.css` redeclara as variantes sob
`.rc-painel-gestao`. Tela nova fora dessa classe, com o tema ativo, precisa do
mesmo escopo.

## `promote_user` sem o papel de destino devolve `true`

`current_user_can( 'promote_user', $id )` responde "sim" sozinha. O filtro que
impede a escalada — `negar_gestao_de_usuarios_superiores()` — compara o papel de
**destino** com o do ator, e o destino é o **terceiro** argumento. Conferir sem
ele inverte a resposta e dá por segura uma trava que não foi consultada.

## `wp eval` com `wp_set_current_user()` lê capacidade otimista

O código do `eval` roda depois do `init`, então o papel dinâmico que o bbPress
aplica naquele gancho não está no usuário: capacidades de fórum respondem "SIM"
onde o navegador responde 403. Para medir de verdade, `wp --user=<login> eval …`.

E o caminho inverso também engana: um nonce gerado por `wp --user=… eval
'wp_create_nonce( … )'` **não vale no navegador**. `wp_create_nonce()` mistura o
token de sessão (`wp_get_session_token()`), que no CLI é vazio e no navegador vem
do cookie de login. O nonce sai com aparência perfeita e é recusado — e a
mensagem é a genérica de link expirado, que não diz que a causa é essa.

## `form.action` em JS é sombreado por um `<input name="action">`

Armadilha de **verificação**, não de produção, e capaz de dar por testada uma
trava que nunca foi exercitada. Num `HTMLFormElement`, um campo filho com
`name="action"` — que todo formulário de `admin-post.php` tem — sombreia a
propriedade `action` do elemento: `f.action` devolve o `HTMLInputElement`, não a
URL.

Usado como destino de `fetch`, ele vira a string `[object HTMLInputElement]` no
caminho. Medido: POST para
`/checkout/order-received/493/[object%20HTMLInputElement]`, **status 200**, página
plausível, nenhuma recusa disparada — quatro testes de segurança "passando" sem
ter tocado no servidor. `f.getAttribute( 'action' )` é o caminho.

## Nonce por pedido recusa antes da trava de propriedade

Corolário da anterior, e a razão de um teste de "pedido de outro comprador" nunca
medir o que pensa medir. Quando a ação do nonce inclui o id
(`check_admin_referer( ACAO . '_' . $pedido_id )`), trocar o `pedido=` numa URL
montada à mão invalida o nonce — e a recusa vem da **primeira** guarda, com a
mensagem genérica de link expirado, idêntica à de um id inexistente.

Isso é a propriedade desejada: por HTTP a trava de propriedade não é alcançável,
porque o servidor só emite o nonce a quem já tem o direito. Mas significa que
verificá-la exige outro instrumento — `ReflectionClass` sobre o método privado,
com `wp_set_current_user()` variando o ator. Sem isso, a matriz de permissões
fica medida só na primeira camada.

## Nem toda negação do `/wp-admin` sai com 403

`plugins.php` morre em `wp-admin/includes/menu.php:384`, num `wp_die( …, 403 )`
explícito, porque `user_can_access_admin_page()` reprova a página inteira. Já
`theme-install.php` **passa** por esse portão — ele pendura em `themes.php` — e só
então bate na verificação do próprio arquivo (`theme-install.php:16`), um
`wp_die()` **sem argumento de status**; o padrão de `_default_wp_die_handler()` é
**500**. A tela diz a mesma coisa nos dois casos.

E `admin.php?page=wc-orders` devolve **301** para `edit.php?post_type=shop_order`
quando o HPOS está desligado — é ali que o 403 acontece. Um verificador que
espere 403 em toda negação acusa falha onde não há, e um que leia só o primeiro
código lê 301 como sucesso.

Para o Administrador e o Moderador, que desde a versão 10 das capacidades não
entram no `/wp-admin`, as negações saem em **dois** códigos, e os dois são
certos. Tela que o papel não alcança por capacidade — `edit.php`, `nav-menus.php`,
`widgets.php`, `edit-comments.php` — morre no `menu.php` com 403, **antes** do
portão. Tela que o papel ainda alcançaria — `users.php`, `upload.php`, o fórum,
as campanhas — passa por ali e sai em 302 para `/modulos/`, pelo portão. O 403
prova que a capacidade saiu; só o 302 prova que o portão está de pé.

## O menu "Produtos" pode apontar para as avaliações

Para um papel com `moderate_comments` e sem `edit_products`, o item de menu
**Produtos** existe e resolve para `admin.php?page=product-reviews` — as
avaliações, que o WooCommerce registra sob aquele menu. O catálogo
(`edit.php?post_type=product`) responde 403 e nenhum item leva até lá. Quem vir o
rótulo na lateral e concluir que o perfil administra o catálogo terá lido o
rótulo, não o destino.

## Em single-site, `edit_users` alcança o `administrator`

Não existe "editar usuário abaixo de mim" no núcleo. Quem tem `edit_users` pode
abrir a ficha de um `administrator`, **trocar a senha dele** e entrar com a
conta; com `promote_users`, pode promover a si mesmo. As duas juntas transformam
o Administrador restrito em Super Administrador com dois cliques, e a proibição
de instalar plugin vira decoração.

Desde a versão 10 das capacidades as primitivas de usuário saíram do papel — a
senha da loja se manda pela ficha dela, no `/painel-empresas/` —, e
`Reconectar_Permissoes::negar_gestao_de_usuarios_superiores()` ficou como
segunda camada, em `map_meta_cap`, para o dia em que alguém as devolver. Ao
conferir, lembre que `promote_user` **exige o papel de destino como terceiro
argumento** — a armadilha registrada acima.

## Post type com capacidade própria mapeia também as de **publicado**

`map_meta_cap()` pede `edit_published_posts` e `delete_published_posts` para o
post publicado, e `edit_private_posts`/`delete_private_posts` para o privado.
Fora do array `capabilities` do `register_post_type()`, essas quatro caem na
primitiva de **post** homônima — e funcionavam por acidente enquanto o papel
tinha capacidade de post.

Quando o Administrador e o Moderador perderam as de post, mandar uma campanha
no ar para a lixeira passou a responder 403, com rascunho funcionando
normalmente: o defeito só aparece no conteúdo publicado. Campanha e enquete
mapeiam as onze; ao criar post type com capacidade própria, declare as onze
também, como faz `Reconectar_Incubadora`.

## `negar_escrita_ao_admin_de_empresas()` monitora `edit_post` genérico

O filtro vigia `edit_post`, `delete_post` e `publish_post` para qualquer post, e
a exceção é uma **allowlist de post types**. Quando o papel ganhou capacidade de
conteúdo — post e página, até a versão 9 das capacidades —, o filtro passou a
negar a edição deles sem uma linha de mudança: o código estava no lugar certo, correto para o que fora escrito, e
impedia em silêncio o que a especificação nova pedia.

Ao dar capacidade de escrita nova a um desses papéis, acrescente o post type à
allowlist — ou a capacidade é concedida, sincronizada e perdida adiante. Produto
e pedido ficam de fora de propósito: quem administra o produto é a loja dona
dele.

## Renomear meta ou capacidade exige migração versionada

Uma chave de meta ou capacidade renomeada **não migra sozinha**: o valor
continua gravado no banco sob o nome velho, e o código novo lê vazio. O sintoma
não é erro — é dado que some. `user_can()` com capacidade inexistente devolve
`false` em silêncio, e a loja desativada volta a parecer ativa.

`Reconectar_Migracoes` (`init`, prioridade **5** — antes das capacidades, que
rodam em 10) guarda esses passos, com opção própria `reconectar_migracoes_versao`
no molde de `Reconectar_Permissoes::VERSAO_CAPACIDADES`. Duas assimetrias que
custam tempo se descobertas depois:

- **`remove_role()`/`add_role()` cobre o `company_admin`, não o `administrator`.**
  `sincronizar_papel_do_admin_de_empresas()` recria o papel do zero, então a
  capacidade velha sai junto. O `administrator` recebe as dele por `add_cap()` e
  **só perde o que for explicitamente removido** — daí a lista `CAPS_LEGADAS` e
  o `remove_cap` sobre todos os papéis, que fica lá permanentemente: é o que faz
  uma instalação provisionada há meses migrar sozinha.
- **`UPDATE` direto em `meta_key` não invalida o cache.** O WordPress guarda a
  usermeta em cache de objeto, e a leitura seguinte entrega o estado antigo por
  toda a requisição. Colha os `user_id` **antes** do `UPDATE` — depois dele a
  consulta pela chave velha volta vazia — e chame `clean_user_cache()` em cada
  um. Antes do `UPDATE`, apague as linhas legadas de quem já tem a chave nova:
  sem isso a renomeação cria duplicata, e `get_user_meta( …, true )` passa a
  devolver uma das duas arbitrariamente.

## Arquivo em `uploads/` é público — e `uploads/` não atravessa o deploy

Duas consequências do mesmo fato, e as duas mordem quem grava arquivo enviado por
usuário.

**Público.** `/wp-content/uploads/` é servido direto pelo Apache, sem passar pelo
WordPress: nenhuma capacidade é consultada, e o nome do arquivo costuma ser
adivinhável (`comprovante-1.pdf`). A Media Library ainda o exibe na listagem de
mídia para todo perfil com `upload_files`. Para dado pessoal — comprovante
bancário traz nome, valor e conta — isso é o oposto do que a LGPD pede, e o
edital cobra.

O arranjo que este projeto usa está em `Reconectar_Comprovante::diretorio()`:
subdiretório próprio, `.htaccess` com `Require all denied` **e** `deny from all`,
`index.php` vazio contra listagem, nome gerado por `wp_generate_password( 32,
false )`, e entrega por rota autoral que confere direito antes do `readfile()`.
O nome imprevisível é a camada que sobrevive a uma troca para nginx, em que o
`.htaccess` vira inerte.

**Não sincronizado.** `git ls-files wp-content/` devolve só os dois diretórios
autorais, e o `rsync` do deploy segue esse recorte. Arquivo enviado por usuário
existe **apenas** no servidor que o recebeu: não está no Git, não vem do
`provision.sh` e não tem origem nenhuma para ser restaurado. Backup de `uploads/`
é responsabilidade da infraestrutura, e nada no repositório o substitui.

## Post type novo: o nome cabe em 20 caracteres, e o `query_var` fica ligado

`wp_posts.post_type` é `varchar(20)`, com o mesmo `sql_mode` não estrito da
armadilha do `post_status`: `reconectar_incubadora` (21) seria truncado no
`INSERT`, em silêncio. Daí `incubadora_pagina`, com 18.

Num post type hierárquico com `query_var => false`, a regra de reescrita passa a
usar `pagename` — e `WP::parse_request()` confere `pagename` por
`get_page_by_path()` **só no tipo `page`**. Medido: `/incubadora/<slug>/` caía na
regra de anexo e respondia 404, com a regra do CPT bem ali em `wp rewrite list`.
O `query_var` é o próprio `POST_TYPE`.

E o `capabilities` do CPT declara só as **primitivas**. Mapear ali as meta caps
(`edit_post`, `read_post`, `delete_post`) derruba o redirecionamento do
`/wp-admin` que o resto do RBAC monta em cima delas.

## Acima de `post_max_size` o PHP esvazia `$_POST` inteiro — inclusive o `action`

Corpo maior que `post_max_size` chega com `$_POST` e `$_FILES` **vazios**. Não é
só o arquivo que some: somem o nonce e o `action`. Com o `action` no corpo, o
`admin-post.php` nem acha o handler; com ele lá e o nonce perdido, a resposta é
"o formulário expirou", o usuário recarrega e perde o que não tinha salvo.

O envio da Incubadora põe o `action` na **URL** e confere `CONTENT_LENGTH`
contra `post_max_size` **antes** do nonce, respondendo 413 com o limite legível.
Veja `Reconectar_Incubadora_Acoes::exigir_requisicao()`.

E o limite que vale é o do PHP, não o da classe. O container tem
`upload_max_filesize=2M`: os 5 MB de imagem e 10 MB de PDF de
`Reconectar_Incubadora_Arquivos` são um teto, aplicado como `min()` com
`wp_max_upload_size()`. Elevar é decisão de infraestrutura.

## Rascunho novo tem `post_modified_gmt` zerado

`0000-00-00 00:00:00` até a primeira gravação. `get_post_modified_time( 'U',
true )` devolve `false`, que vira 0 na conta, e a tela dizia **"Editado há 57
anos"** — um número absurdo o bastante para ninguém confiar no resto. Use
`get_post_timestamp( $post, 'modified' )`, que cai na data local quando a GMT
está zerada.

## `wp_get_post_revision()` recebe o argumento por referência

Uma expressão ali — `wp_get_post_revision( (int) $id )` — é **erro fatal**, não
aviso. Converta numa variável antes.

## `post_status => 'any'` não alcança a lixeira

Nem o `auto-draft`: `any` exclui todo status registrado com
`exclude_from_search`, e `trash` é um deles. Uma remoção que busque por `any`
deixa para trás o que já foi excluído — e a Incubadora manda para a lixeira. A
lista explícita está em `reconectar_demo_paginas_da_incubadora()`.

## O bbPress regrava `bbp_participant` em `user_register`, depois do papel

`wp_insert_user()` grava o papel com `set_role()` — que dispara `set_user_role` e
a sincronização do fórum — e só **depois** dispara `user_register`. Ali
`bbp_user_register` (prioridade 10) chama `bbp_set_user_role()` com o papel
padrão e põe `bbp_participant` por cima do `bbp_moderator` recém-dado.

O sintoma é um Moderador ou Administrador **novo** sem moderar o fórum
(`edit.php?post_type=topic` em 403), enquanto os antigos funcionam. Foi a carga
de demonstração, ao recriar os usuários, que revelou.
`sincronizar_papel_no_forum_ao_cadastrar()` repete a sincronização em
`user_register` prioridade 20.

## TinyMCE `inline`: o navegador carrega o que o servidor ainda vai recusar

Três defeitos do mesmo fato — no modo `inline` o conteúdo é DOM vivo:

- **`<iframe>` colado é carregado na hora.** O sanitizador do servidor o tira
  ao salvar, mas o navegador já chamou o host dele. O filtro fica no cliente
  também, em `editor.parser.addNodeFilter( 'iframe' )`, com a mesma allowlist.
- **`editor.remove()` devolve o conteúdo serializado ao elemento.** Os iframes
  renascem por um instante, o bastante para chamar o provedor. Esvazie o
  elemento **antes** do `remove()`.
- **O player do vídeo é vivo na edição.** Abrir uma página em edição contata o
  provedor; na leitura, a capa estática não. Está documentado, não é defeito.

E duas da configuração. Uma imagem inserida com `alt=""` abre a janela do
TinyMCE com **"decorativa" já marcada** e o campo de descrição desabilitado —
medido —, e a decisão chega tomada para quem envia; por isso a imagem enviada
entra **sem** `alt`, e quem garante o `alt=""` ao salvar é o sanitizador. E a
base `emojiimages` do plugin `emoticons` busca as imagens numa CDN — a
configurada é `emojis`, que é texto.

O TinyMCE é carregado só no clique em Editar. Para conferir, a agulha é
`tinymce.min.js`: o caminho `vendor/tinymce` aparece nos dados localizados da
página mesmo sem o editor carregado.

## Sanitizar o que o Moderador escreve é reconstruir, não filtrar

Moderador e Administrador **não** têm `unfiltered_html`, e a Incubadora aceita
vídeo, tabela e imagem. `Reconectar_Incubadora_Conteudo` remonta o HTML por
`DOMDocument` a partir de uma lista fechada e passa por `wp_kses` depois, como
segunda camada. A gravação roda entre `kses_remove_filters()` e `kses_init()`,
em `try/finally`: sem isso o núcleo passaria o conteúdo pela allowlist genérica
de post, que não é a da Incubadora e só poderia divergir dela; e sem o
`finally` uma exceção deixaria o `kses` desligado para o resto da requisição.

A leitura **sanitiza de novo** e tem render próprio, sem `the_content`: conteúdo
gravado direto no banco — por restauração de dump, por migração — não passou
pela gravação. `<img>` só da rota autoral de arquivos: imagem externa é
rastreador, e a LGPD conta. A bateria de 70 casos de XSS vive fora do
repositório; ao mexer no sanitizador, cada caso precisa de segunda passada
idêntica, ou ele não é idempotente.

## `wp_set_comment_status()` só conhece os status do núcleo

O comentário oculto da Incubadora vive em `comment_approved = 'rc-oculto'`, e
`wp_set_comment_status( $id, 'rc-oculto' )` parece o caminho natural. Ele aceita
apenas `hold`, `approve`, `spam` e `trash`: com qualquer outro valor devolve
`false` e **não grava nada**. Medido: um teste de "resposta em fio oculto" falhou
com o código correto, porque o fio nunca chegou a ser ocultado. Quem grava é
`wp_update_comment()` com `comment_approved`, como faz
`Reconectar_Incubadora_Interacao::moderar()`.

O oculto fica de fora de `comments_open`, das consultas do núcleo e do feed por
filtros da mesma classe — o tipo `rc_incubadora` não aparece num `get_comments()`
que não o peça. Comentário de tipo novo herda esse cuidado, ou vaza para o
`/wp-admin` e para o RSS.

## Os apagadores de privacidade acham o titular pelo e-mail da conta

Excluir a conta **antes** de rodar **Ferramentas → Apagar dados pessoais** não dá
erro: cada apagador — núcleo, WooCommerce, Dokan, `Reconectar_Privacidade` —
procura `get_user_by( 'email' )`, não acha ninguém e responde "nada a remover".
O relatório sai limpo e os votos, o IP do fórum e o vínculo social ficam. Por
isso o apagador anonimiza e **não** exclui a conta; a ordem está em
`docs/EXCLUSAO_DE_DADOS.md`.

Ao gravar dado pessoal novo que nenhum apagador alcança — mapa `user_id => …`
em meta, comentário sem e-mail, meta de terceiro —, ele entra em
`Reconectar_Privacidade` no mesmo commit, no apagador **e** no exportador.

## `NextendSocialLogin::$providers` traz provedores que não existem

A lista mistura os reais (Google, Facebook, Twitter) com as vitrines da versão
Pro — Slack, Apple, GitHub e mais dezessete —, que herdam de
`NextendSocialProviderDummy`, **não** de `NextendSocialProvider`, e não têm
`isUserConnected()` nem `removeConnectionByUserID()`. Medido: um laço sobre a
lista inteira é erro fatal no primeiro deles. Filtre por
`instanceof NextendSocialProvider`.

## O Emogrifier do WooCommerce reescreve o atributo `style`

`WC_Email::style_inline()` calcula o estilo de cada elemento a partir do
`<style>` do template e **grava por cima** do `style` que o elemento já tinha.
Medido no link de redefinição de senha: `<a style="word-break: break-all;">`
saiu `<a style="font-weight: normal; text-decoration: underline; color: #663191;">`,
e a URL vazou do cartão de 600px. Em e-mail, quem estiliza é classe com regra
em `woocommerce_email_styles` — veja `a.rc-email-url` em
`Reconectar_Email::estilos()`.

E a cor base não é o teal: o WooCommerce escolhe texto branco ou escuro pelo
brilho da base, e o branco sobre `#31BEB1` fica em 2,3:1. A base é a
institucional; o teal entra só onde não carrega texto.

## As variáveis do SMTP só chegam ao serviço `wordpress`

`Reconectar_Email` lê `RECONECTAR_SMTP_*` do ambiente, e só o `wordpress` as
recebe no `docker-compose.yml`. Um `wp eval 'wp_mail(…)'` pelo `wpcli` sai pelo
`mail()` do PHP e devolve `false` — parece defeito do SMTP e é o container
errado. Para medir o caminho do SMTP pelo CLI, passe as variáveis com `-e` (uma
senha errada basta para ver a conexão e o TLS chegarem à autenticação).

Com o SMTP ligado no desenvolvimento, todo e-mail para os endereços
`exemplo.invalid` da carga volta como devolução para a caixa do Gmail. A carga
corta o envio com `pre_wp_mail`; o que se fizer depois, pelo navegador, não.
