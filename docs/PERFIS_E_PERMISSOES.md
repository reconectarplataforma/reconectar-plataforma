# Perfis e permissões

Os atores da plataforma, o que cada um pode fazer e **onde exatamente no código
a regra é aplicada**. Implementação em
`wp-content/plugins/reconectar-core/includes/class-reconectar-permissoes.php`.

## Os atores

| Ator | Papel WP | Onde trabalha |
| --- | --- | --- |
| **Super Administrador** | `administrator` | `/wp-admin` |
| **Administrador** | `company_admin` | `/wp-admin` e `/painel-empresas/` |
| **Moderador de Conteúdo** | `content_moderator` | moldura do painel do Dokan (Incubadora, Fórum, Comunidade) e `/wp-admin` |
| **Vendedor** | `seller` | painel do Dokan, no front-end |
| **Usuário Comum** | `customer` | loja e "Minha conta" |

**Duas palavras para a mesma conta.** No painel de empresas a plataforma chama
essas contas de **Loja** — a empresa é a Reconectar Incubadora Digital, e cada
loja é um negócio cadastrado por ela. Este documento continua dizendo
"Vendedor" porque descreve o papel `seller`, que é do **Dokan**: o nome vem de
fora e mudá-lo quebraria o plugin. Onde se lê "vendedor" aqui, entenda "a conta
que o painel de empresas lista como loja".

**Super Administrador.** Gestão técnica da instalação. É o único que instala,
ativa, atualiza, configura ou remove **plugins e temas**, o único que edita
arquivo e o único que atualiza o núcleo. A chave do papel continua sendo
`administrator`: em WordPress single-site não existe nada acima dele, e "Super
Admin" no sentido literal é vocabulário de Multisite. O que mudou foi só o
rótulo, por `renomear_papel()` — remover e recriar o `administrator` seria
irreversível se algo falhasse no meio.

**Administrador.** Cadastra empresas e lojas, configura as contas das lojas,
modera o conteúdo da plataforma e cria menus. Não tem nenhuma capacidade de
desenvolvimento: **não instala plugin nem tema, não edita arquivo, não atualiza
o núcleo**. É a evolução do antigo Administrador de Empresas — mesmo papel,
mesma chave `company_admin`, competências novas.

**Moderador de Conteúdo.** Publica e modera post, página e comentário, gere as
campanhas da home, cria menus e participa do fórum. Não administra empresa, não
configura conta de usuário e não toca em produto nem em pedido.

A Incubadora, o Fórum e a Comunidade abrem para ele na moldura do painel do
Dokan, sem cabeçalho nem menu do mercado, com "Moderação" no topo da barra
lateral. A barra traz Incubadora, Comunidade, Fórum e "Painel do WordPress", a
saída para campanhas, enquetes, publicações e comentários. No topo, "Ir ao mercado"
leva de volta ao catálogo. O login dele — por
"Minha conta" ou por `wp-login.php` — leva à Incubadora, a menos que um destino
explícito tenha sido pedido, e `/dashboard/` também. A razão é de orientação: na
casca da vitrine ele não tinha como saber que tinha saído do mercado. Não há
capacidade nova — a moldura é só a casca; quem decide é
`Reconectar_Navegacao_Da_Loja::modera_no_painel()`, pelo papel.

**Vendedor.** Uma loja própria e isolada: produtos, estoque, pedidos,
pagamentos, entrega. Participa dos fóruns, mas não os administra. **Nunca**
acessa dado de outro vendedor.

**Usuário Comum.** A jornada de compra, e só ela: navegar → escolher → comprar →
pagar → acompanhar a entrega. Sem fóruns, sem painel administrativo, sem painel
de vendedor.

As chaves `company_admin` e `content_moderator` ficam em inglês por serem
gravadas no banco, na convenção dos papéis nativos do WordPress. Os rótulos, em
português como todo o resto.

## Como a plataforma decide

A autorização é por **capacidade**, não por papel. A distinção parece sutil e
não é: papel é rótulo, capacidade é permissão. Código que pergunta "esta pessoa
é vendedora?" para decidir o que ela pode fazer confunde identidade com
autorização, e quebra no dia em que aparece um papel novo ou em que um usuário
recebe uma permissão avulsa.

Por isso o acesso à comunidade tem capacidade própria —
`reconectar_participar_comunidade` — concedida em `sincronizar_capacidades()`
aos papéis listados em `PAPEIS_DA_COMUNIDADE`. Quem checa acesso pergunta pela
capacidade; quem concede sabe dos papéis.

`sincronizar_capacidades()` roda em `init`, não na ativação do plugin: o papel
`seller` é criado pelo **Dokan**, e se o `reconectar-core` for ativado antes
dele o papel ainda não existe — a concessão se perderia em silêncio. Uma
constante de versão (`VERSAO_CAPACIDADES`, hoje em **4**) evita reescrever as
capacidades a cada carregamento; para forçar a ressincronização, incremente-a.
É ela que faz uma instalação provisionada meses atrás receber os papéis novos
sozinha, no primeiro `init` depois da atualização.

### Por que o acesso ao `/wp-admin` não passa por `manage_options`

Este é o ponto que decide o desenho inteiro, e é contraintuitivo.

`restringir_gestao_da_tecnologia()` barra as capacidades de plugin e tema
**acrescentando `manage_options` ao conjunto exigido**. E
`eh_administracao_tecnica()` é exatamente `manage_options || manage_woocommerce`.
Dar `manage_options` ao Administrador para que ele entrasse no painel devolveria
a ele, pela mesma linha, a instalação de plugins — precisamente o que a
especificação proíbe.

`manage_woocommerce` abriria a porta sem quebrar a trava de plugin, mas tem
outro preço: os menus de WooCommerce e Dokan aparecem, e **as listagens do
`/wp-admin` não têm escopo por empresa**. O Administrador alcança todas as
empresas e lojas no `/painel-empresas/`, mas lá vê cadastro e situação — não
produto nem pedido. No painel técnico veria produtos e pedidos de todas as
lojas, com edição, desfazendo em silêncio a regra de que quem administra o
produto é a loja dona dele.

A porta é uma capacidade própria, `CAP_ADMIN_WP`
(`reconectar_acessar_wp_admin`), consultada **só** em
`bloquear_area_administrativa()` e `ocultar_barra_administrativa()`, como
terceira alternativa ao lado de `eh_administracao_tecnica()`.
`eh_administracao_tecnica()` **não mudou**: os outros dois lugares que a
consultam — `restringir_por_vendedor()` e `restringir_listagens_do_vendedor()` —
falam de isolamento entre vendedores, e ali a resposta certa para os papéis
novos continua sendo "não é administração técnica".

### O portão do isolamento

Duas capacidades funcionam como portão nas travas de isolamento:
`manage_options` e `manage_woocommerce`. Quem as tem passa. Isso só é seguro
porque **nenhum dos papéis restritos tem qualquer uma das duas** — verificado no
script de acessos, e é o caso mais importante dele. O `customer` tem exatamente
uma capacidade (`read`).

Se um dia o Dokan ou outro plugin conceder `manage_woocommerce` ao vendedor, o
isolamento cai inteiro, sem erro e sem aviso. Confira com o comando na seção
"Como verificar".

## Matriz de permissões

Super Adm. = `administrator`; Adm. = `company_admin`; Moder. = `content_moderator`.

| Funcionalidade | Super Adm. | Adm. | Moder. | Vendedor | Cliente |
| --- | :---: | :---: | :---: | :---: | :---: |
| **Catálogo e compra** | | | | | |
| Navegar pela vitrine, buscar, filtrar | ✅ | ✅ | ✅ | ✅ | ✅ |
| Adicionar ao carrinho e comprar | ✅ | ✅ | ✅ | ✅ | ✅ |
| Acompanhar os próprios pedidos | ✅ | ✅ | ✅ | ✅ | ✅ |
| **Loja do vendedor** | | | | | |
| Criar e editar produtos da própria loja | ✅ | ❌ | ❌ | ✅ | ❌ |
| Ver e processar pedidos da própria loja | ✅ | ❌ | ❌ | ✅ | ❌ |
| Configurar pagamento e entrega da própria loja | ✅ | ❌ | ❌ | ✅ | ❌ |
| Responder solicitação de serviço feita à própria loja | ✅ | ❌ | ❌ | ✅ | ❌ |
| Ver produtos ou pedidos de outro vendedor | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Empresas e lojas** | | | | | |
| Acessar `/painel-empresas/` | ✅ | ✅ | ❌ | ❌ | ❌ |
| Cadastrar empresa e loja | ✅ | ✅ | ❌ | ❌ | ❌ |
| Ver todas as empresas e lojas, com ou sem vínculo | ✅ | ✅ | ❌ | ❌ | ❌ |
| **Conteúdo** | | | | | |
| Publicar e editar post e página | ✅ | ✅ | ✅ | ❌ | ❌ |
| Moderar comentários | ✅ | ✅ | ✅ | ❌ | ❌ |
| Publicar e programar campanhas da home | ✅ | ✅ | ✅ | ❌ | ❌ |
| Criar e editar menus de navegação | ✅ | ✅ | ✅ | ❌ | ❌ |
| Enviar arquivos para a biblioteca de mídia | ✅ | ✅ | ✅ | ❌ | ❌ |
| **Comunidade** | | | | | |
| Ler e participar de fóruns | ✅ | ✅ | ✅ | ✅ | ❌ |
| Votar em pergunta ou resposta | ✅ | ✅ | ✅ | ✅ | ❌ |
| Marcar a melhor resposta | ✅ | ✅ | ✅ | só nas próprias perguntas | ❌ |
| **Incubadora** | | | | | |
| Ler as páginas publicadas | ✅ | ✅ | ✅ | ✅ | ❌ |
| Ver rascunhos, histórico e versões antigas | ✅ | ✅ | ✅ | ❌ | ❌ |
| Criar, editar, publicar, mover e excluir páginas | ✅ | ✅ | ✅ | ❌ | ❌ |
| Restaurar versão antiga | ✅ | ✅ | ✅ | ❌ | ❌ |
| Enviar imagem e PDF para uma página | ✅ | ✅ | ✅ | ❌ | ❌ |
| **Contas** | | | | | |
| Listar, criar e editar contas de loja | ✅ | ✅ | ❌ | ❌ | ❌ |
| Editar a conta de um Super Administrador | ✅ | ❌ | ❌ | ❌ | ❌ |
| Promover alguém a um papel acima do seu | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Plataforma** | | | | | |
| Acessar `/wp-admin` | ✅ | ✅ | ✅ | ❌ | ❌ |
| Instalar, ativar, atualizar ou remover plugins | ✅ | ❌ | ❌ | ❌ | ❌ |
| Instalar, trocar, atualizar ou editar temas | ✅ | ❌ | ❌ | ❌ | ❌ |
| Editar arquivo, atualizar o núcleo | ✅ | ❌ | ❌ | ❌ | ❌ |
| Configurações técnicas da instalação | ✅ | ❌ | ❌ | ❌ | ❌ |

## Onde cada regra é aplicada

| Regra | Método | Hook |
| --- | --- | --- |
| Concede/revoga capacidades por papel | `sincronizar_capacidades()` | `init` |
| Nega plugin, tema, arquivo e núcleo a não-admin | `restringir_gestao_da_tecnologia()` | `map_meta_cap` |
| Impede escalada por `edit_users`/`promote_users` | `negar_gestao_de_usuarios_superiores()` | `map_meta_cap` |
| Nega produto e pedido a quem administra empresa | `negar_escrita_ao_admin_de_empresas()` | `map_meta_cap` |
| Isola registro de outro vendedor | `restringir_por_vendedor()` | `map_meta_cap` |
| Filtra listagens de produto do vendedor | `restringir_listagens_do_vendedor()` | `pre_get_posts` |
| Bloqueia comunidade para o cliente | `bloquear_comunidade()` | `template_redirect` |
| Nega escrita no fórum a quem não participa | `negar_escrita_no_forum()` | `map_meta_cap` |
| Esconde links da comunidade | `ocultar_itens_da_comunidade()` | `wp_nav_menu_objects` |
| Dá ao Moderador e ao Administrador novos a moderação do fórum | `sincronizar_papel_no_forum_ao_cadastrar()` | `user_register` (20) |
| Fecha a Incubadora ao visitante (login) e ao cliente (home) | `Reconectar_Incubadora::bloquear_leitura()` | `template_redirect` (1) |
| Dá 404 a página da Incubadora sob mãe em rascunho | `Reconectar_Incubadora_Leitura::exigir_caminho_visivel()` | `template_redirect` (2) |
| Esconde o item Incubadora do visitante e do cliente | `Reconectar_Incubadora_Leitura::ocultar_item_de_quem_nao_usa()` | `wp_nav_menu_objects` |
| Mostra o item "Loja" (vitrine própria) só a quem tem loja | `Reconectar_Navegacao_Da_Loja::acrescentar_item_loja_ao_menu()` | `wp_nav_menu_objects` (20) |
| Barra a escrita na Incubadora | `Reconectar_Incubadora_Acoes::processar_*()` | `admin_post_reconectar_incubadora_*` |
| Entrega arquivo da Incubadora só a quem lê a Incubadora | `Reconectar_Incubadora_Arquivos::entregar()` | `admin_post_reconectar_incubadora_arquivo` |
| Só a loja dona responde a solicitação de serviço | `Reconectar_Servicos::responder()`, por `loja_pode()` | `admin_post_reconectar_responder_servico` |
| Mantém quem não tem `CAP_ADMIN_WP` fora do painel | `bloquear_area_administrativa()` | `admin_init` |
| Esconde a barra administrativa do site, para todos os perfis | `ocultar_barra_administrativa()` | `show_admin_bar` |

**Interface e backend são camadas distintas, e ambas existem.** Esconder o link
da comunidade no menu é usabilidade — oferecer um link que devolve 403 é defeito
de interface. Quem barra o acesso é o `template_redirect`, e ele barra mesmo que
a URL seja digitada à mão. O edital exige as duas; nenhuma substitui a outra.

**"Produtos" é de todos; "Loja" é de quem tem loja.** O catálogo inteiro
(`/shop/`, com a coluna de filtros) aparece no menu como "Produtos", para todo
perfil e para quem não está logado. O item "Loja" não existe no menu gravado: é
acrescentado na hora, só para quem mora no painel da loja, e leva à vitrine
dela (`/store/<loja>/`). Os demais perfis nunca o recebem — não há link que
precise ser escondido. A vitrine segue pública, como qualquer loja.

**Ler e escrever são travas separadas, e por um motivo medido.** O bbPress
processa o POST de criação em `template_redirect` **prioridade 8**, antes da
prioridade 10 onde `bloquear_comunidade()` está — e consulta apenas a capacidade
primitiva `publish_topics`, que todo usuário tem por causa do papel
`bbp_participant` atribuído no registro. Sem `negar_escrita_no_forum()`, o gate
de leitura não alcançaria a escrita. O nonce do formulário ainda barraria, mas
uma única barreira não é uma trava: é a última que sobrou.

## A Incubadora

A Incubadora é a wiki interna da plataforma: páginas e subpáginas do post type
`incubadora_pagina`, sob `/incubadora/`. Duas regras, e ambas valem pela URL
digitada à mão:

- **Ler exige ter o módulo.** Loja, Administrador, Moderador e Super
  Administrador leem o que está publicado — a mesma divisão da tela
  `/modulos/`, consultada por `Reconectar_Incubadora::pode_ler()`. O visitante
  vai para o login e o cliente volta à home, inclusive na busca, no histórico
  e nos arquivos (403). O item do menu segue a mesma regra. A
  saída leva `noindex`, e o oEmbed das páginas responde 404 — o conteúdo é
  interno e não pode aparecer num buscador nem embutido em outro site.
- **Escrever exige `reconectar_gerir_incubadora`**, que o Super Administrador,
  o Administrador e o Moderador têm. É uma wiki colaborativa: quem tem a
  capacidade edita qualquer página, e o autor é crédito, não dono.

O que cada perfil vê segue **a árvore, não a consulta**. Uma página publicada
sob mãe em rascunho responde 404 a quem só lê, porque abri-la revelaria o
rascunho pela trilha e pela URL. A mesma regra vale na busca: nem o título de
um rascunho sai na lista de resultados de quem não edita.

Histórico e versões antigas (`?historico=1`, `?versao=<ID>`) não existem para
quem não edita: a resposta é a página atual, com 200. Um 403 confirmaria a quem
só lê que existe uma versão com aquele ID.

Os endpoints de escrita passam por `admin-post.php` e conferem, nesta ordem,
método (405), login (401), **capacidade** (403) e nonce (403). A capacidade vem
antes do nonce de propósito: é o que permite provar a trava por HTTP, porque
cliente e loja nunca recebem um nonce válido. O corpo da resposta traz um
`codigo` — `capacidade` ou `nonce` — que diz qual trava respondeu.

Para o Administrador, a capacidade não basta sozinha:
`negar_escrita_ao_admin_de_empresas()` nega `edit_post` a qualquer post type
fora de uma allowlist, e o `incubadora_pagina` está nela. Sem isso, o papel
teria a capacidade e seria barrado em silêncio.

Os arquivos enviados ficam em `uploads/reconectar-incubadora/`, fora da
biblioteca de mídia, com `.htaccess` negando o acesso direto. A única entrada é
uma rota autoral que exige login.

## A escalada que `edit_users` abre

"Configura os usuários das lojas" exige `edit_users`. Em single-site, quem tem
`edit_users` **pode editar um `administrator`** — trocar a senha dele e entrar
com a conta. E `promote_users` permite promover a si mesmo. As duas juntas
transformariam o Administrador restrito no Super Administrador em dois cliques,
e a proibição de acesso de desenvolvedor viraria decoração. Nada na tela
pareceria quebrado.

`negar_gestao_de_usuarios_superiores()`, em `map_meta_cap`, fecha essa porta com
duas regras:

- `edit_user`, `delete_user` e `promote_user` sobre um alvo que tenha
  `manage_options` recebem `do_not_allow`, salvo se quem age também a tiver.
- Em `promote_user`, o papel de destino não pode conter capacidade que o ator
  não possua. Isso cobre a promoção a `administrator` e qualquer papel futuro
  que alguém crie por cima.

O filtro decide **caso a caso, com alvo**, e é por isso que a lista
`CAPS_DE_USUARIOS` continua concedida: as primitivas estão lá de propósito, e a
meta capacidade é que nega. O contorno também é testado — sobre a própria conta
e sobre uma loja, as mesmas capacidades precisam continuar funcionando, ou o
filtro teria fechado o perfil inteiro.

## Aparência → Temas responde 200, e isso não é trava frouxa

`edit_theme_options` é a **única** capacidade que o WordPress oferece para
editar menus de navegação, e ela vem grudada ao Customizer e aos widgets. Não há
granularidade menor no núcleo: ou o moderador cria menus e alcança essas telas,
ou não cria menus. A amplitude é do WordPress, não uma escolha nossa.

`wp-admin/themes.php` abre para quem tem `switch_themes` **ou**
`edit_theme_options`, então a tela responde 200 para os dois papéis restritos.
Ela fica **só de leitura**, e isso foi medido: o JSON que o núcleo imprime nela
traz `"activate":null`, `"delete":null` e `"autoupdate":null` para cada tema, e
`theme-editor.php` responde 403.

`theme-install.php` também nega — "Sem permissão para instalar temas neste site"
—, mas com status **500**, e vale registrar por quê, porque é a diferença entre
uma negação e um erro de servidor aos olhos de qualquer verificação automatizada.
As duas negações são do núcleo, em pontos distintos:

| Tela | Onde morre | Status |
| --- | --- | --- |
| `plugins.php`, `options-general.php`, `edit.php?post_type=product` | `wp-admin/includes/menu.php:384`, `wp_die( …, 403 )` | `403` |
| `theme-install.php` | `wp-admin/theme-install.php:16`, `wp_die()` **sem status** | `500` |

`user_can_access_admin_page()` reprova a página inteira no primeiro grupo. O
`theme-install.php` **passa** por esse portão, porque pendura em `themes.php`, que
o perfil pode abrir — e só então encontra a verificação de `install_themes` do
próprio arquivo. Como essa chamada de `wp_die()` não passa argumento de status, o
padrão de `_default_wp_die_handler()` entra em cena e é **500**. Esperar 403 em
toda negação faria um verificador acusar falha onde a trava está funcionando.

Fechá-la à força custaria mais do que resolve — seria um desvio por tela em
`admin_init`, e `nav-menus.php`, a tela que o perfil existe para usar, depende
exatamente da mesma capacidade. **A trava que vale é a da lista de capacidades,
não a da URL**, e é por isso que o script de acessos confere `switch_themes` e
`install_themes` por WP-CLI em vez de se contentar com o código HTTP.

## O isolamento entre vendedores

É o requisito mais estrito da especificação: o vendedor A **nunca** acessa
produto, pedido, estoque, pagamento ou informação comercial do vendedor B. Está
implementado em duas frentes, porque uma só não basta.

### Acesso a um registro — `map_meta_cap`

`restringir_por_vendedor()` intercepta as capacidades de objeto (`edit_post`,
`read_post`, `delete_post` e as variantes de produto e pedido). Se quem pede é
vendedor e o dono do objeto é outro, acrescenta `do_not_allow`.

Vale para o painel, para a REST API e para qualquer código que consulte
`current_user_can()` antes de agir — que é o contrato do WordPress para
autorização.

`dono_do_objeto()` consulta **pedidos primeiro**: com HPOS ativo eles deixam de
ser posts, e `get_post_type()` devolveria `false` para um ID válido. O dono de
um pedido vem do meta `_dokan_vendor_id`; o de um produto, do `post_author`.

Quando o objeto não é produto nem pedido, a função devolve `null` e nenhuma
regra daqui se aplica — inventar uma quebraria funcionalidade que não é nossa.

### Listagem — `pre_get_posts`

`map_meta_cap` protege o acesso a um registro específico, mas **não filtra uma
consulta**. Sem `restringir_listagens_do_vendedor()`, a lista de produtos
devolveria o catálogo inteiro a um vendedor: nomes, preços e estoque dos
concorrentes à vista, ainda que ele não conseguisse abrir nenhum deles. Para
informação comercial, ver a lista já é o vazamento.

O filtro age **apenas** em área de gestão (`is_admin()` ou `REST_REQUEST`). A
vitrine pública precisa continuar mostrando o catálogo completo, inclusive para
um vendedor logado — ele também é comprador.

### Produto e pedido continuam negados a quem administra

`negar_escrita_ao_admin_de_empresas()` monitora `edit_post`, `delete_post` e
`publish_post` genéricos e libera **só** os tipos que o ator pode escrever —
empresa, post, página, campanha e os do bbPress. Produto e pedido ficam de fora
de propósito: quem administra o produto é a loja dona dele, e a negação se
mantém mesmo que um plugin de terceiro conceda `edit_products` por papel.

Essa allowlist é recente e substituiu a exceção invertida, que liberava apenas o
CPT de empresa. Com o Administrador passando a moderar conteúdo, a versão antiga
teria negado post e página — o sintoma clássico deste repositório: código no
lugar certo que impede em silêncio.

## Detalhes que não são óbvios

**`admin-ajax.php` fica dentro de `/wp-admin`.** Ele atende requisições do
front-end, inclusive as do carrinho e as do painel do Dokan. Por isso
`bloquear_area_administrativa()` volta cedo quando `wp_doing_ajax()`: bloqueá-lo
quebraria a loja para as duas pessoas que o método protege.

**O redirecionamento tem destino útil.** Vendedor barrado no `/wp-admin` vai
para o painel do Dokan; cliente, para "Minha conta". Despejar alguém na home
seria tecnicamente correto e inútil.

**Visitante deslogado na comunidade vai para o login, com retorno** — pode
simplesmente não ter entrado ainda. Só quem está logado *e* sem a capacidade
recebe 403.

**A revogação de tecnologia é dupla.** `sincronizar_capacidades()` remove as
capacidades do papel, e `restringir_gestao_da_tecnologia()` checa de novo na
hora da decisão. A primeira age sobre o papel; a segunda fecha a porta de uma
capacidade concedida direto ao usuário ou por outro plugin via `user_has_cap`.

**Os dois papéis autorais nascem de `remove_role()` + `add_role()`.** Não é
redundância com o `add_role()` sozinho, que é inerte quando o papel já existe:
sem a remoção, uma capacidade retirada da lista continuaria gravada no banco
para sempre. Como a lista é justamente o registro do que o ator *não* pode, ela
precisa ser a verdade, e não o teto histórico. O `administrator` **não** passa
por esse caminho — remover o papel de quem instalou a plataforma é
irreversível se algo falhar no meio. Ele recebe por `add_cap()`, e por isso só
perde o que estiver em `CAPS_LEGADAS`.

**Quem trabalha em mais de um módulo escolhe por onde começar.** Loja,
Administrador e Moderador entram por `/modulos/` (`Reconectar_Modulos`), uma
tela de três cartões — Mercado, Incubadora e Praça, os módulos do edital — a
cada login. O destino de cada cartão depende do perfil: o Mercado é a vitrine
da própria loja (`/store/<loja>/`) para a Loja, o painel de empresas para o
Administrador e a vitrine geral para o Moderador — o painel da loja fica no
ícone da conta e no item "Painel" da barra lateral. A tela não concede nada: cada botão leva a uma rota que tem as suas
próprias travas. O desvio só troca o destino **padrão** do login (vazio,
`/wp-admin`, "Minha conta" ou o painel do Dokan); um `redirect_to` explícito
vence, para que quem entrou por um link não perca o caminho. Cliente e Super
Administrador não passam por ela — o primeiro vai à conta, o segundo ao
`/wp-admin`, e os dois recebem `302` se abrirem a rota à mão.

## Como verificar

```bash
./scripts/verificar-acessos.sh -v
```

Verifica 393 casos, a maior parte por HTTP: faz login como cliente, vendedor, moderador,
administrador e super administrador e bate em cada URL restrita, conferindo o
código de resposta. Sai com status 1 se algum falhar.

É por HTTP de propósito. A autorização precisa valer para a URL digitada à mão,
que é o caminho que uma auditoria vai tentar; um teste que apenas consulta
`current_user_can()` em PHP prova que a função responde o esperado quando
alguém pergunta, não que a requisição foi barrada. Onde só o HTTP não basta —
a escalada de privilégio, as capacidades de tema, o isolamento entre vendedores
— o script complementa com WP-CLI, e o comentário de cada bloco diz por quê.
O caminho feliz da Incubadora — criar, salvar, publicar, mover, excluir, enviar
arquivo, restaurar versão — também vai por WP-CLI, em
`scripts/verificar-incubadora.php`, que o script chama no fim: o nonce que o
WP-CLI gera não vale no navegador.

A resposta a uma solicitação de serviço tem o mesmo desenho em duas camadas. Por
HTTP, todo perfil com nonce forjado para no nonce — que leva o id do pedido e só
é emitido no detalhe que a própria loja abre —, e o visitante recebe 400 sem
alcançar o handler, porque não há `admin_post_nopriv_` registrado. A trava de
propriedade, `loja_pode()`, é privada e vai por `ReflectionMethod` sobre um
pedido provisório, apagado no fim: só a loja dona passa. O Administrador de
empresas fica entre os recusados de propósito — quem responde pelo serviço é a
loja que o oferece, como no produto.

Respostas medidas nesta instalação:

| Perfil | `/wp-admin/` | `plugins.php` | `users.php` | `nav-menus.php` | `/painel-empresas/` | `/dashboard/` | `/comunidade/` | `/modulos/` |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Deslogado | — | — | — | — | `302` → login | — | `302` → login | `302` → login |
| Cliente | `302` → `/my-account/` | — | — | — | `403` | `302` | `403` | `302` |
| Vendedor | `302` → `/dashboard/` | `403` | — | — | `403` | `200` | `200` | `200` |
| Moderador | `200` | `403` | `403` | `200` | `403` | `302` → módulos | `200` | `200` |
| Administrador | `200` | `403` | `200` | `200` | `200` | `302` | `200` | `200` |
| Super Administrador | `200` | `200` | — | — | `200` | — | `200` | `302` → `/wp-admin/` |

Cada célula preenchida é um caso do script, e o travessão marca o que ele não
cobre — não uma permissão indefinida.

Note a diferença entre as duas primeiras colunas do vendedor: `/wp-admin/`
redireciona — ele tem para onde ir — e `plugins.php` nega. São travas distintas,
e o código de resposta mostra qual delas agiu.

Capacidades efetivas de cada papel:

```bash
docker compose run --rm wpcli wp eval 'foreach(array("seller","customer","content_moderator","company_admin","administrator") as $p){$o=wp_roles()->get_role($p); if(!$o){printf("%-18s ausente\n",$p);continue;} $c=array_keys(array_filter($o->capabilities)); printf("%-18s manage_options=%s manage_woocommerce=%s install_plugins=%s wp_admin=%s total=%d\n",$p,in_array("manage_options",$c,true)?"SIM":"nao",in_array("manage_woocommerce",$c,true)?"SIM":"nao",in_array("install_plugins",$c,true)?"SIM":"nao",in_array("reconectar_acessar_wp_admin",$c,true)?"SIM":"nao",count($c));}'
```

Resultado esperado: `SIM` nas três primeiras colunas **só** para
`administrator`; `company_admin` e `content_moderator` com `SIM` apenas em
`wp_admin`; `customer` com `nao` em tudo e `total=1`.

Um vendedor consegue editar produto de outro?

```bash
docker compose run --rm wpcli wp eval '$a=get_user_by("login","demo-sabor-da-terra")->ID; $b=get_user_by("login","demo-bem-viver")->ID; $p=get_posts(array("post_type"=>"product","author"=>$b,"numberposts"=>1)); if(!$p){echo "sem produto para testar\n";exit;} $id=$p[0]->ID; wp_set_current_user($a); printf("vendedor A editar produto de B: %s\n", current_user_can("edit_post",$id)?"PERMITIDO (FALHA)":"negado (ok)");'
```

O Administrador consegue promover a si mesmo?

```bash
docker compose run --rm wpcli wp eval '$a=get_user_by("login","demo-admin-nosso-chao")->ID; $s=get_user_by("login","admin")->ID; wp_set_current_user($a); printf("editar o super admin: %s\npromover a si mesmo a administrator: %s\n", current_user_can("edit_user",$s)?"PERMITIDO (FALHA)":"negado (ok)", current_user_can("promote_user",$a,"administrator")?"PERMITIDO (FALHA)":"negado (ok)");'
```

**O papel de destino é argumento, e omiti-lo inverte a resposta.**
`current_user_can( "promote_user", $id )` sem ele devolve `true` — corretamente,
porque promover *a alguma coisa* é uma capacidade que o perfil tem; o que o
filtro nega é promover **a um papel acima do seu**, e sem o destino não há o que
comparar. Quem só rodar a forma curta vai achar que encontrou um buraco.

Na tela, o destino chega por dois campos diferentes: `role` no `user-edit.php` e
`new_role` na ação em massa do `users.php`. O filtro lê os dois, e o script de
acessos exercita ambos — ler só um deixaria o outro caminho sem regra nenhuma.

O teste de mesa não substitui o navegador. O roteiro de
[ROTEIRO_PERFIS.md](ROTEIRO_PERFIS.md) exercita os mesmos limites pela
interface, que é onde eles serão avaliados.

## Limites conhecidos

**A verificação é um script, não uma suíte de testes.**
`verificar-acessos.sh` cobre as travas de acesso por URL, as capacidades de
tecnologia, a escalada de privilégio e o isolamento entre vendedores, e depende
de a carga de demonstração estar instalada — não roda em CI nem antecede um
commit. Cobre o que quebrou até hoje; não cobre o que ainda não foi imaginado.

Em especial, nada dispara alarme se um plugin novo conceder
`manage_woocommerce` ao papel `seller`. O script detectaria a consequência
(`/wp-admin/` deixaria de redirecionar), mas só quando alguém o rodasse.

**A Incubadora não tem permissão por página.** Quem tem
`reconectar_gerir_incubadora` edita, move e exclui qualquer página, e quem lê a
Incubadora lê qualquer página publicada. Uma área restrita a um grupo exigiria
outro desenho, não um ajuste deste.

**Aparência → Temas continua acessível de leitura** aos dois papéis restritos,
pelo motivo explicado acima. A capacidade de trocar, instalar ou editar tema
está negada; a tela, não.

**`restringir_listagens_do_vendedor()` filtra apenas `product`.** Listagem de
pedidos não passa por ela: hoje o vendedor não entra no `/wp-admin`, e a coleção
de pedidos da REST API exige `manage_woocommerce`, que ele não tem. A proteção
existe, mas vem de outra camada — se qualquer uma das duas condições mudar, esta
lacuna se abre.

**O painel do Dokan aplica as próprias verificações.** Elas não foram
substituídas, e sim reforçadas. Uma mudança de comportamento do plugin pode
alterar o resultado sem tocar em nada deste repositório.
