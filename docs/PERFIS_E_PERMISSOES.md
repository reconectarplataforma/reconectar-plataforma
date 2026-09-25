# Perfis e permissões

Os três atores da plataforma, o que cada um pode fazer e **onde exatamente no
código a regra é aplicada**. Implementação em
`wp-content/plugins/reconectar-core/includes/class-reconectar-permissoes.php`.

## Os atores

| Ator | Papel WP | Onde trabalha |
| --- | --- | --- |
| **Administrador** | `administrator` | `/wp-admin` |
| **Administrador de Empresas** | `company_admin` | `/painel-empresas/` |
| **Vendedor** | `seller` | painel do Dokan, no front-end |
| **Usuário Comum** | `customer` | loja e "Minha conta" |

**Duas palavras para a mesma conta.** No painel de empresas a plataforma chama
essas contas de **Loja** — a empresa é a Reconectar Incubadora Digital, e cada
loja é um negócio cadastrado por ela. Este documento continua dizendo
"Vendedor" porque descreve o papel `seller`, que é do **Dokan**: o nome vem de
fora e mudá-lo quebraria o plugin. Onde se lê "vendedor" aqui, entenda "a conta
que o painel de empresas lista como loja".

**Administrador.** Gestão técnica e operacional da plataforma inteira. É o único
que instala, ativa, atualiza, configura ou remove plugins, e o único que
administra a comunidade e os fóruns.

**Vendedor.** Uma loja própria e isolada: produtos, estoque, pedidos,
pagamentos, entrega. Participa dos fóruns, mas não os administra. **Nunca**
acessa dado de outro vendedor.

**Usuário Comum.** A jornada de compra, e só ela: navegar → escolher → comprar →
pagar → acompanhar a entrega. Sem fóruns, sem painel administrativo, sem painel
de vendedor.

## Como a plataforma decide

A autorização é por **capacidade**, não por papel. A distinção parece sutil e
não é: papel é rótulo, capacidade é permissão. Código que pergunta "esta pessoa
é vendedora?" para decidir o que ela pode fazer confunde identidade com
autorização, e quebra no dia em que aparece um quarto papel ou em que um usuário
recebe uma permissão avulsa.

Por isso o acesso à comunidade tem capacidade própria — 
`reconectar_participar_comunidade` — concedida aos papéis `administrator` e
`seller` em `sincronizar_capacidades()`. Quem checa acesso pergunta pela
capacidade; quem concede sabe dos papéis.

`sincronizar_capacidades()` roda em `init`, não na ativação do plugin: o papel
`seller` é criado pelo **Dokan**, e se o `reconectar-core` for ativado antes
dele o papel ainda não existe — a concessão se perderia em silêncio. Uma
constante de versão (`VERSAO_CAPACIDADES`) evita reescrever as capacidades a
cada carregamento; para forçar a ressincronização, incremente-a.

### O portão

Duas capacidades funcionam como portão em várias travas: `manage_options` e
`manage_woocommerce`. Quem as tem passa. Isso só é seguro porque **o papel
`seller` não tem nenhuma das duas** — verificado na instalação: 67 capacidades,
nenhuma delas. O `customer` tem exatamente uma (`read`).

Se um dia o Dokan ou outro plugin conceder `manage_woocommerce` ao vendedor, o
isolamento cai inteiro, sem erro e sem aviso. Confira com o comando na seção
"Como verificar".

## Matriz de permissões

| Funcionalidade | Admin | Vendedor | Cliente |
| --- | :---: | :---: | :---: |
| **Catálogo e compra** | | | |
| Navegar pela vitrine, buscar, filtrar | ✅ | ✅ | ✅ |
| Adicionar ao carrinho e comprar | ✅ | ✅ | ✅ |
| Acompanhar os próprios pedidos | ✅ | ✅ | ✅ |
| Avaliar produto comprado | ✅ | ✅ | ✅ |
| **Loja do vendedor** | | | |
| Criar e editar produtos da própria loja | ✅ | ✅ | ❌ |
| Gerir estoque da própria loja | ✅ | ✅ | ❌ |
| Ver e processar pedidos da própria loja | ✅ | ✅ | ❌ |
| Configurar pagamento e entrega da própria loja | ✅ | ✅ | ❌ |
| Ver faturamento da própria loja | ✅ | ✅ | ❌ |
| **Dados de outro vendedor** | | | |
| Ver produtos, pedidos, estoque ou receita de outro vendedor | ✅ | ❌ | ❌ |
| **Comunidade** | | | |
| Ler e participar de fóruns | ✅ | ✅ | ❌ |
| Perguntar, responder e marcar tags no fórum | ✅ | ✅ | ❌ |
| Votar em pergunta ou resposta | ✅ | ✅ | ❌ |
| Marcar a melhor resposta | ✅ | só nas próprias perguntas | ❌ |
| Moderar e administrar a comunidade | ✅ | ❌ | ❌ |
| **Plataforma** | | | |
| Acessar `/wp-admin` | ✅ | ❌ | ❌ |
| Instalar, ativar, atualizar ou remover plugins | ✅ | ❌ | ❌ |
| Gerir usuários e papéis | ✅ | ❌ | ❌ |
| Configurações técnicas da plataforma | ✅ | ❌ | ❌ |
| Ver todos os pedidos e todas as lojas | ✅ | ❌ | ❌ |

## Onde cada regra é aplicada

| Regra | Método | Hook |
| --- | --- | --- |
| Concede/revoga capacidades por papel | `sincronizar_capacidades()` | `init` |
| Nega gestão de plugins a não-admin | `restringir_gestao_de_plugins()` | `map_meta_cap` |
| Isola registro de outro vendedor | `restringir_por_vendedor()` | `map_meta_cap` |
| Filtra listagens de produto do vendedor | `restringir_listagens_do_vendedor()` | `pre_get_posts` |
| Bloqueia comunidade para o cliente | `bloquear_comunidade()` | `template_redirect` |
| Nega escrita no fórum a quem não participa | `negar_escrita_no_forum()` | `map_meta_cap` |
| Esconde links da comunidade | `ocultar_itens_da_comunidade()` | `wp_nav_menu_objects` |
| Mantém não-admin fora do `/wp-admin` | `bloquear_area_administrativa()` | `admin_init` |
| Esconde a barra administrativa | `ocultar_barra_administrativa()` | `show_admin_bar` |

**Interface e backend são camadas distintas, e ambas existem.** Esconder o link
da comunidade no menu é usabilidade — oferecer um link que devolve 403 é defeito
de interface. Quem barra o acesso é o `template_redirect`, e ele barra mesmo que
a URL seja digitada à mão. O edital exige as duas; nenhuma substitui a outra.

**Ler e escrever são travas separadas, e por um motivo medido.** O bbPress
processa o POST de criação em `template_redirect` **prioridade 8**, antes da
prioridade 10 onde `bloquear_comunidade()` está — e consulta apenas a capacidade
primitiva `publish_topics`, que todo usuário tem por causa do papel
`bbp_participant` atribuído no registro. Sem `negar_escrita_no_forum()`, o gate
de leitura não alcançaria a escrita. O nonce do formulário ainda barraria, mas
uma única barreira não é uma trava: é a última que sobrou.

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

**A revogação de plugins é dupla.** `sincronizar_capacidades()` remove as
capacidades do papel, e `restringir_gestao_de_plugins()` checa de novo na hora
da decisão. A primeira age sobre o papel; a segunda fecha a porta de uma
capacidade concedida direto ao usuário ou por outro plugin via `user_has_cap`.

## Como verificar

```bash
./scripts/verificar-acessos.sh -v
```

Verifica 59 casos por HTTP: faz login como cliente, vendedor, administrador e
administrador de empresas e bate em cada URL restrita, conferindo o código de
resposta. Sai com status 1 se algum falhar.

É por HTTP de propósito. A autorização precisa valer para a URL digitada à mão,
que é o caminho que uma auditoria vai tentar; um teste que apenas consulta
`current_user_can()` em PHP prova que a função responde o esperado quando
alguém pergunta, não que a requisição foi barrada.

Respostas medidas nesta instalação:

| Perfil | `/wp-admin/` | `plugins.php` | `/dashboard/` | `/comunidade/` | `/forums/` | `/painel-empresas/` |
| --- | --- | --- | --- | --- | --- | --- |
| Deslogado | — | — | — | `302` → login | `302` → login | `302` → login |
| Cliente | `302` → `/my-account/` | — | `302` → home | `403` | `403` | `403` |
| Vendedor | `302` → `/dashboard/` | `403` | `200` | `200` | `200` | `403` |
| Admin de Empresas | `302` → `/painel-empresas/` | — | — | `200` | `200` | `200` |
| Administrador | `200` | `200` | — | `200` | `200` | `200` |

Note a diferença entre as duas colunas do vendedor: `/wp-admin/` redireciona —
ele tem para onde ir — e `plugins.php` nega. São travas distintas, e o código
de resposta mostra qual delas agiu.

Capacidades efetivas de cada papel:

```bash
docker compose run --rm wpcli wp eval 'foreach(array("seller","customer","administrator") as $p){$o=wp_roles()->get_role($p); $c=array_keys(array_filter($o->capabilities)); printf("%-14s manage_options=%s manage_woocommerce=%s comunidade=%s total=%d\n",$p,in_array("manage_options",$c,true)?"SIM":"nao",in_array("manage_woocommerce",$c,true)?"SIM":"nao",in_array("reconectar_participar_comunidade",$c,true)?"SIM":"nao",count($c));}'
```

Resultado esperado: `SIM` nas três colunas só para `administrator`; `seller` com
`SIM` apenas em comunidade; `customer` com `nao` em tudo e `total=1`.

Um vendedor consegue editar produto de outro?

```bash
docker compose run --rm wpcli wp eval '$a=get_user_by("login","demo-sabor-da-terra")->ID; $b=get_user_by("login","demo-bem-viver")->ID; $p=get_posts(array("post_type"=>"product","author"=>$b,"numberposts"=>1)); if(!$p){echo "sem produto para testar\n";exit;} $id=$p[0]->ID; wp_set_current_user($a); printf("vendedor A editar produto de B: %s\n", current_user_can("edit_post",$id)?"PERMITIDO (FALHA)":"negado (ok)");'
```

O teste de mesa não substitui o navegador. O roteiro de
[ROTEIRO_PERFIS.md](ROTEIRO_PERFIS.md) exercita os mesmos limites pela
interface, que é onde eles serão avaliados.

## Limites conhecidos

**A verificação é um script, não uma suíte de testes.**
`verificar-acessos.sh` cobre as travas de acesso por URL e o isolamento de
produto entre dois vendedores, e depende de a carga de demonstração estar
instalada — não roda em CI nem antecede um commit. Cobre o que quebrou até
hoje; não cobre o que ainda não foi imaginado.

Em especial, nada dispara alarme se um plugin novo conceder
`manage_woocommerce` ao papel `seller`. O script detectaria a consequência
(`/wp-admin/` deixaria de redirecionar), mas só quando alguém o rodasse.

**`restringir_listagens_do_vendedor()` filtra apenas `product`.** Listagem de
pedidos não passa por ela: hoje o vendedor não entra no `/wp-admin`, e a coleção
de pedidos da REST API exige `manage_woocommerce`, que ele não tem. A proteção
existe, mas vem de outra camada — se qualquer uma das duas condições mudar, esta
lacuna se abre.

**O painel do Dokan aplica as próprias verificações.** Elas não foram
substituídas, e sim reforçadas. Uma mudança de comportamento do plugin pode
alterar o resultado sem tocar em nada deste repositório.
