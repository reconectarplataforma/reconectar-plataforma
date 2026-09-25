# Roteiro de demonstração por perfil

Passo a passo para demonstrar a plataforma nos quatro perfis de acesso:
Administrador, Administrador de Empresas, Vendedor e Usuário Comum. Cada
roteiro mostra o que o perfil **faz** e, logo em seguida, o que ele **não
consegue fazer** — porque num marketplace multi-vendedor o limite é tão parte
da entrega quanto a função.

Tempo total: cerca de 35 minutos. Cada roteiro é independente.

> Os IDs numéricos de pedido **mudam a cada recarga** da demonstração. Este
> roteiro identifica os pedidos pelo cliente, pelo status e pelo valor, que são
> estáveis. Se precisar do ID, leia-o na tela.

## Preparação

```bash
./scripts/demo-completa.sh
```

Sobe o ambiente, provisiona e popula. Idempotente — pode rodar de novo sem
duplicar nada. Ao terminar, imprime os acessos.

| Endereço | O quê |
| --- | --- |
| http://localhost:8090 | loja |
| http://localhost:8090/wp-admin/ | painel administrativo |
| http://localhost:8090/dashboard/ | painel do vendedor (Dokan) |
| http://localhost:8090/painel-empresas/ | painel do Administrador de Empresas |
| http://localhost:8081 | phpMyAdmin |

**Use uma janela anônima por perfil**, ou faça logout entre os roteiros. Trocar
de usuário sem sair é a causa mais comum de "a permissão não está funcionando"
numa demonstração.

## Credenciais

| Perfil | Login | Senha |
| --- | --- | --- |
| Administrador | `admin` | `reconectar-admin` |
| Admin. de Empresas — Nosso Chão | `demo-admin-nosso-chao` | `reconectar-demo` |
| Admin. de Empresas — Bem Viver | `demo-admin-bem-viver` | `reconectar-demo` |
| Admin. de Empresas — as duas | `demo-admin-rede` | `reconectar-demo` |
| Vendedor — Sabor da Terra | `demo-sabor-da-terra` | `reconectar-demo` |
| Vendedor — Ateliê Raízes | `demo-atelie-raizes` | `reconectar-demo` |
| Vendedor — Moda Reconecta | `demo-moda-reconecta` | `reconectar-demo` |
| Vendedor — Casa Viva | `demo-casa-viva` | `reconectar-demo` |
| Vendedor — Bem Viver Natural | `demo-bem-viver` | `reconectar-demo` |
| Cliente — Ana | `demo-cliente-ana` | `reconectar-demo` |
| Cliente — João | `demo-cliente-joao` | `reconectar-demo` |
| Cliente — Marina | `demo-cliente-marina` | `reconectar-demo` |

Cinco lojas, três produtos cada. Três clientes, seis pedidos cobrindo o fluxo
inteiro. As cinco lojas estão distribuídas em duas empresas:

| Empresa | Município | Lojas |
| --- | --- | --- |
| Cooperativa Nosso Chão | Maceió/AL | Sabor da Terra, Ateliê Raízes, Moda Reconecta |
| Rede Bem Viver | Arapiraca/AL | Casa Viva, Bem Viver Natural |

Teresa administra só a primeira, Otávio só a segunda, e Clara administra as
duas — ela tem `reconectar_gerir_todas_as_empresas`, e é o caso que prova que o
alcance múltiplo é uma capacidade, não um privilégio embutido no papel.

---

# Roteiro 1 — Usuário Comum

**Perfil:** `demo-cliente-ana` · `reconectar-demo`
**Duração:** ~8 min

A jornada de compra completa, e o fato de não haver nada além dela.

### 1.1 A vitrine, deslogado

Abra http://localhost:8090 sem entrar em conta nenhuma.

Observe, de cima para baixo:

- **cabeçalho** com logo, busca ("Busque por item ou loja"), seletor de
  localização, login e carrinho com total e contagem;
- **categorias** em carrossel horizontal, com setas de navegação;
- **lojas em destaque**, ordenadas por avaliação;
- **produtos em destaque**;
- **vitrine de lojas** em grade, com cards completos: logo, nome, nota em
  estrela, categoria, distância, faixa de tempo de entrega e taxa;
- **barra de filtros** em pílulas.

> **Não** haverá botão "Ver mais lojas", e é assim que deve ser. Ele só aparece
> quando há mais lojas do que cabem na página — 12 — e a demonstração tem 5.
> Também não adianta forçar `?lojas=3`: o parâmetro tem piso de 12, para que uma
> URL pública não consiga estreitar a vitrine. Quando o botão aparece, é um link
> com `?lojas=N`, não uma busca por AJAX: o estado expandido fica na URL,
> compartilhável e desfeito pelo botão "voltar".

> Os números de tempo, taxa e distância vêm de metas gravadas em cada loja —
> não são calculados na hora nem inventados para preencher a tela. Um valor
> plausível e falso é pior que um campo vazio, porque ninguém o confere.

Redimensione a janela para largura de celular: a grade recompõe e o menu
colapsa fechado.

### 1.2 Busca e filtros

Busque por um termo do catálogo (por exemplo, "mel"). Aplique um filtro de
ordenação na barra de pílulas e confira que a URL reflete o estado — o filtro é
compartilhável e sobrevive ao recarregamento.

### 1.3 Entrar e comprar

Entre como `demo-cliente-ana`. Note que **a barra administrativa do WordPress
não aparece** — está oculta para quem não entra no painel.

Adicione ao carrinho **produtos de duas lojas diferentes**. Abra o carrinho:
está tudo junto, num carrinho só.

Vá ao checkout, preencha os dados e conclua. Os meios previstos são PIX, cartão
de crédito e boleto — veja [PAGAMENTOS.md](PAGAMENTOS.md).

### 1.4 Acompanhar

Em **Minha conta → Pedidos**, Ana tem dois pedidos além do que você acabou de
criar:

| Status na tela | Total | Significado no fluxo |
| --- | --- | --- |
| Concluído | R$ 79,80 | Entregue |
| Processando | R$ 359,00 | Pagamento aprovado |

Abra um deles. O cliente vê o próprio pedido, com itens, valores e histórico.

### 1.5 Os limites — o que Ana **não** consegue

Ainda logada como Ana, tente cada URL:

| URL | Resultado medido |
| --- | --- |
| `/wp-admin/` | `302` → `/my-account/` |
| `/dashboard/` | `302` → home (o Dokan barra quem não é vendedor) |
| `/comunidade/` | `403` — "Acesso restrito" |
| `/forums/` | `403` — mesma página |

A página de 403 diz, literalmente:

> Esta área é reservada a vendedores e à administração da plataforma. Sua conta
> de cliente não tem acesso aos fóruns e à comunidade.

E olhe o menu do site: **não há link para a comunidade nem para o fórum**. O
bloqueio já bastaria para a segurança; esconder o link é usabilidade — oferecer
um caminho que devolve 403 é defeito de interface.

O item "Fórum" é o caso menos óbvio dos dois: ele é um item `custom`, e não um
post type, porque a listagem de perguntas é o **arquivo** de `forum` e não tem
post a que apontar. O filtro que esconde os dois reconhece o item custom pelo
caminho da URL — assim ele continua escondido mesmo que alguém renomeie o item
pelo painel.

> Digite a URL da comunidade à mão. É esse o teste que importa: a trava está no
> backend, não na ausência do link.

---

# Roteiro 2 — Vendedor

**Perfil:** `demo-sabor-da-terra` · `reconectar-demo`
**Duração:** ~10 min

Uma loja própria, completa e isolada.

### 2.1 O painel

Entre e vá para http://localhost:8090/dashboard/ — o painel do Dokan, no
front-end. O vendedor trabalha aqui, nunca no `/wp-admin`.

Percorra: **Produtos**, **Pedidos**, **Retiradas**, **Relatórios**,
**Configurações**.

### 2.2 Os produtos são só os dele

Em **Produtos**, a Sabor da Terra tem **3**. O catálogo da plataforma tem 15.

Isso não é filtro de tela. `map_meta_cap` protege o acesso a um registro, mas
não filtra consulta nenhuma — sem o filtro de listagem, o vendedor veria nomes,
preços e estoque dos concorrentes ainda que não conseguisse abrir nenhum. Para
informação comercial, ver a lista já é o vazamento.

Crie um produto novo, com nome, preço, estoque e imagem. Publique. Abra a loja
pública e encontre-o na vitrine.

### 2.3 Os pedidos são só os dele

Em **Pedidos**, aparecem apenas os que contêm produtos da Sabor da Terra.

Abra um e mova o status ao longo do fluxo do edital:

```
Pedido realizado → Pagamento aprovado → Em preparação → Enviado → Entregue
```

**"Em preparação" e "Enviado" são status autorais**, registrados por
`class-reconectar-status-pedido.php`. O WooCommerce vai de "Processando" direto
a "Concluído" — os dois estados do meio não existiam e foram criados, e são
declarados como pagos para que o faturamento não suma dos relatórios enquanto o
pedido está a caminho.

### 2.4 Faturamento

Em **Relatórios**, confira as vendas da loja.

> Esses números vêm das tabelas próprias do Dokan (`wp_dokan_orders`,
> `wp_dokan_vendor_balance`), não dos pedidos do WooCommerce. Elas não são
> limpas quando um pedido é apagado, e linhas órfãs viram venda fantasma. A
> carga de demonstração as reconcilia — veja
> `reconectar_demo_limpar_tabelas_dokan()`.

### 2.5 O isolamento — o que ele **não** consegue

Em outra aba, entre como `demo-bem-viver` e copie a URL de edição de um produto
dele. Volte à sessão da Sabor da Terra e cole.

**Acesso negado.** Não há sequer o registro na listagem, e a URL direta também
não abre.

Pela linha de comando, nas duas direções:

```bash
docker compose run --rm wpcli wp eval '$a=get_user_by("login","demo-sabor-da-terra")->ID; $b=get_user_by("login","demo-bem-viver")->ID; $p=get_posts(array("post_type"=>"product","author"=>$b,"numberposts"=>1)); $meu=get_posts(array("post_type"=>"product","author"=>$a,"numberposts"=>1)); wp_set_current_user($a); printf("produto de outro: %s\nproduto proprio:  %s\n", current_user_can("edit_post",$p[0]->ID)?"PERMITIDO (FALHA)":"negado (ok)", current_user_can("edit_post",$meu[0]->ID)?"permitido (ok)":"NEGADO (FALHA)");'
```

Saída esperada:

```
produto de outro: negado (ok)
produto proprio:  permitido (ok)
```

As duas linhas importam. Uma trava que negasse tudo também passaria na
primeira.

### 2.6 A comunidade — o vendedor entra

Abra `/comunidade/`. **O vendedor tem acesso**, ao contrário do cliente: ele
participa dos fóruns, mas não os administra.

### 2.7 O fórum de perguntas e respostas

Clique em **Fórum** no menu — o item que Ana não via — ou abra `/forums/`.

A tela lista as 6 perguntas com título, trecho, tags, autor com o selo do papel
e três contadores: visualizações, respostas e saldo de votos. Percorra as três
abas e repare que **a ordem muda de verdade**:

- **Recentes** — pela última atividade, que é o padrão do bbPress;
- **Votos** — a pergunta de precificação, com saldo 5, sobe ao topo;
- **Sem resposta** — sobram as duas que ninguém respondeu.

Abra a pergunta sobre embalagem de cerâmica: a **melhor resposta** aparece no
topo, marcada com selo e não só com cor. Vote em alguma resposta e note que o
número muda sem JavaScript nenhum — o formulário posta e a página volta ao
tópico. Clique de novo no mesmo botão: o voto é **desfeito**, não somado. E não
há botão de voto no conteúdo do próprio vendedor; o endpoint recusa também,
para quem tentar pela URL.

> A escrita tem trava própria, além do acesso à tela. O bbPress processa o POST
> de criação antes do bloqueio de leitura, então quem não participa da
> comunidade tem `publish_topics` negada na origem, em `map_meta_cap`.

### 2.8 O painel administrativo — o vendedor não entra

| URL | Resultado medido |
| --- | --- |
| `/wp-admin/` | `302` → `/dashboard/` |
| `/wp-admin/plugins.php` | `403` |
| `/dashboard/` | `200` |
| `/comunidade/` | `200` |
| `/forums/` | `200` |

As duas primeiras linhas vêm de travas diferentes, e é por isso que os códigos
diferem. `/wp-admin/` é redirecionamento — o vendedor tem para onde ir, e
mandá-lo ao painel dele é mais útil que negar. Já `plugins.php` **nega**: a
capacidade foi revogada do papel e verificada de novo na hora da decisão, e a
requisição morre antes de qualquer redirecionamento. Só o Administrador instala
plugins.

---

# Roteiro 3 — Administrador de Empresas

**Perfil:** `demo-admin-nosso-chao` · `reconectar-demo`
**Duração:** ~10 min

Quem administra a operação não necessariamente administra a tecnologia. Este
perfil cadastra empresas e vendedores, acompanha a operação das lojas sob sua
gestão — e **não tem acesso ao `/wp-admin`**.

### 3.1 O painel

Entre e vá para http://localhost:8090/painel-empresas/. O atalho **Painel de
Empresas** também aparece no cabeçalho do site, no lugar onde o vendedor vê o
link para o painel dele.

Não é o Dokan. É uma rota própria da aplicação, no visual do tema Reconectar,
com as mesmas cores e a mesma tipografia do resto da plataforma. O painel do
Dokan é intransponível sem a capacidade `dokandar`, que para o plugin **define**
quem é vendedor: concedê-la ao Administrador de Empresas o transformaria em
lojista aos olhos do Dokan e o faria herdar em silêncio tudo que o plugin
liberar no futuro. Por isso a rota é nossa.

Na listagem, Teresa vê **uma empresa**: a Cooperativa Nosso Chão. A Rede Bem
Viver existe, tem lojas e pedidos, e não aparece.

### 3.2 A ficha da empresa

Abra a Cooperativa Nosso Chão. A tela reúne:

- os dados cadastrais — CNPJ, razão social, contato, município, responsável;
- os **três vendedores** da empresa, com produtos publicados, ganhos liberados
  e situação;
- o botão de desativar a empresa inteira.

> A coluna chama-se **"Ganhos liberados"**, e não "Faturamento", porque é isso
> que o número é: o Dokan só soma os pedidos concluídos, descontadas as
> devoluções. Pedido em preparação ou a caminho ainda não entra na conta. É o
> mesmo número que o vendedor vê no painel dele — os dois painéis leem a mesma
> fonte, e um rótulo que prometesse o total vendido estaria mentindo sobre um
> dado correto.

Na carga de demonstração, só a Sabor da Terra tem pedido concluído: **R$
79,80**. Os outros dois mostram R$ 0,00, com pedidos em andamento visíveis logo
abaixo. A diferença entre as duas colunas é a demonstração.

### 3.3 A ficha do vendedor

Clique em **Sabor da Terra**. A ficha mostra dados de contato, os produtos com
preço e estoque, os pedidos recentes e os ganhos liberados.

Tente editar um produto: **não há como**. Não é botão escondido — o alcance
decidido para este ator é **consulta**. Ele acompanha a operação, o vendedor a
conduz.

### 3.4 Cadastrar uma empresa

**Empresas → Nova empresa.** Preencha nome, CNPJ, razão social, contato,
município e responsável. Salve.

A empresa nova aparece na listagem **dele** — quem cria entra no próprio
escopo. Sem isso, o administrador cadastraria uma empresa e perderia o acesso a
ela no mesmo clique.

### 3.5 Cadastrar um vendedor

Na ficha da empresa, **Novo vendedor**. Preencha nome da loja, login, e-mail e
contato. Salve.

A tela devolve um **link de definição de senha**, com o aviso de que ele aparece
uma única vez:

> Repasse este link ao vendedor. Ele aparece uma única vez e expira como
> qualquer link de redefinição de senha do WordPress.

Nenhum e-mail é enviado — os endereços da demonstração são `@exemplo.invalid` e
não há SMTP nesta instalação. A interface diz o que de fato aconteceu; anunciar
"e-mail enviado" quando nada saiu é o tipo de mentira que só se descobre quando
o vendedor liga perguntando pela senha.

O vendedor criado é um `seller` comum, com loja Dokan própria. O papel é
**literal no código**, nunca lido do formulário: é essa linha que impede um POST
forjado de criar um administrador.

### 3.6 A cascata da desativação

O teste que separa uma trava correta de uma plausível. Na ficha de um vendedor
— digamos o Ateliê Raízes — clique em **desativar**. Ele sai de operação; os
outros dois continuam vendendo.

Agora desative a **empresa inteira**. Os três saem de operação.

Reative a empresa. E confira:

| Vendedor | Estado individual | Depois de reativar a empresa |
| --- | --- | --- |
| Sabor da Terra | ativo | **voltou a vender** |
| Ateliê Raízes | desativado no passo anterior | **continua fora de operação** |
| Moda Reconecta | ativo | **voltou a vender** |

Reativar a empresa **não liga todo mundo**: cada vendedor volta ao estado que
era dele. O estado individual e o estado da empresa são duas informações
distintas, e o que vale na loja é a conjunção das duas.

### 3.7 O isolamento entre empresas

Na barra de endereços, troque o ID da empresa pelo da Rede Bem Viver:

```bash
docker compose run --rm wpcli wp eval '$p=get_page_by_path("bem-viver",OBJECT,"reconectar_empresa"); echo home_url("/painel-empresas/empresa/{$p->ID}/"), "\n";'
```

**403 — "Você não tem permissão para acessar esta área."** A verificação vem
antes de qualquer consulta ao banco: não há listagem parcial, nem contagem
vazando pelo título da página.

Agora saia e entre como `demo-admin-rede` (Clara). A mesma URL abre
normalmente, e a listagem mostra **as duas empresas**. A diferença entre as duas
sessões é uma capacidade, não uma tela.

### 3.8 Os limites — o que Teresa **não** consegue

| URL | Resultado medido |
| --- | --- |
| `/painel-empresas/` | `200` |
| `/painel-empresas/empresa/<Nosso Chão>/` | `200` |
| `/painel-empresas/empresa/<Bem Viver>/` | `403` |
| `/comunidade/` | `200` |
| `/wp-admin/` | `302` → `/painel-empresas/` |
| `/wp-admin/plugins.php` | `403` |
| `/wp-admin/users.php` | `302` |
| `/dashboard/` | `302` — não é vendedor |

E o `/painel-empresas/` responde **403** para o cliente e para o vendedor:
vender não é administrar a empresa.

As capacidades por trás disso, sem passar por URL nenhuma:

```bash
docker compose run --rm wpcli wp eval '$u=get_user_by("login","demo-admin-nosso-chao"); wp_set_current_user($u->ID); foreach(array("manage_options","manage_woocommerce","edit_users","create_users","promote_users","list_users","dokandar","activate_plugins","edit_themes") as $c){printf("%-20s %s\n",$c,current_user_can($c)?"CONCEDIDA (FALHA)":"negada (ok)");}'
```

Todas negadas. A distinção importa: o redirecionamento de `/wp-admin/` é
conveniência de interface — quem de fato barra é a capacidade ausente. Uma
regressão que concedesse `manage_woocommerce` ao papel abriria o painel técnico
sem que uma única URL mudasse de código, e é por isso que o
`verificar-acessos.sh` confere as duas coisas.

---

# Roteiro 4 — Administrador

**Perfil:** `admin` · `reconectar-admin`
**Duração:** ~7 min

Visão e controle sobre a plataforma inteira.

### 4.1 O painel

Entre em http://localhost:8090/wp-admin/.

### 4.2 Visão total

| Onde | O que confirmar |
| --- | --- |
| **Produtos** | os 15 da plataforma, de todas as lojas |
| **WooCommerce → Pedidos** | todos os pedidos, de todos os vendedores |
| **Dokan → Vendedores** | as 5 lojas |
| **Empresas** | as 2 empresas, com seus vendedores vinculados |
| **Usuários** | admin, 3 administradores de empresa, 5 vendedores, 3 clientes |

O contraste com os roteiros 2 e 3 é o ponto: o vendedor via 3 produtos, Teresa
via as 3 lojas de uma empresa, o administrador vê os 15 produtos e as duas
empresas. E só ele chega aqui — os outros três perfis nem abrem esta tela.

### 4.3 O fluxo de status

Abra um pedido. No seletor de status aparecem **"Em preparação"** e
**"Enviado"**, entre os nativos do WooCommerce. Confira também nas **ações em
massa** da listagem.

### 4.4 Gestão de plugins — exclusiva

Vá em **Plugins**. Ativos: WooCommerce, Dokan Lite, BuddyPress, bbPress e
Reconectar Core.

Esta tela é inacessível a qualquer outro perfil, o Administrador de Empresas
incluído — e é ela que dá nome ao princípio: quem administra a operação não
administra a tecnologia. A revogação é dupla: as capacidades são removidas do
papel **e** verificadas de novo no momento da decisão — porque uma capacidade
pode ser concedida direto a um usuário, ou por outro plugin.

### 4.5 Comunidade

Em **Fóruns** e nas telas do BuddyPress, o administrador modera e administra. O
vendedor e o Administrador de Empresas participam; só o administrador
administra.

### 4.6 Conferir as capacidades

```bash
docker compose run --rm wpcli wp eval 'foreach(array("seller","customer","company_admin","administrator") as $p){$o=wp_roles()->get_role($p); $c=array_keys(array_filter($o->capabilities)); printf("%-14s manage_options=%s manage_woocommerce=%s comunidade=%s total=%d\n",$p,in_array("manage_options",$c,true)?"SIM":"nao",in_array("manage_woocommerce",$c,true)?"SIM":"nao",in_array("reconectar_participar_comunidade",$c,true)?"SIM":"nao",count($c));}'
```

Esperado:

```
seller         manage_options=nao manage_woocommerce=nao comunidade=SIM total=67
customer       manage_options=nao manage_woocommerce=nao comunidade=nao total=1
company_admin  manage_options=nao manage_woocommerce=nao comunidade=SIM total=6
administrator  manage_options=SIM manage_woocommerce=SIM comunidade=SIM total=164
```

As duas primeiras colunas do `seller` são o que sustenta o isolamento inteiro:
as travas liberam quem tem `manage_woocommerce`, e ele não tem. Se um plugin
novo conceder essa capacidade ao vendedor, tudo cai — sem erro e sem aviso.

O `company_admin` tem **seis** capacidades: `read`, as quatro do painel de
empresas e a da comunidade. O número pequeno é a demonstração — ele administra
empresas e vendedores sem uma única capacidade nativa de administração do
WordPress. `reconectar_gerir_todas_as_empresas` fica **fora** do papel de
propósito: é concedida usuário a usuário, como acontece com a Clara. Alcance
múltiplo é decisão de quem administra, não característica do cargo.

---

# Roteiro 5 — O carrinho multi-vendedor

**Duração:** ~3 min

O requisito mais específico da arquitetura, e o mais fácil de demonstrar.

A demonstração já traz o caso pronto: o pedido de **Marina**, no valor de
**R$ 157,70**, com **3 itens de vendedores diferentes**.

### 5.1 Como o cliente vê

Entre como `demo-cliente-marina` → **Minha conta → Pedidos**. Um pedido só, de
R$ 157,70. Foi uma compra, um checkout, um pagamento.

### 5.2 Como o vendedor vê

Entre como qualquer um dos vendedores envolvidos. No painel, aparece **apenas a
parte dele** — com o valor da sua fatia, não os R$ 157,70.

### 5.3 O que aconteceu por baixo

```bash
docker compose run --rm wpcli wp eval 'foreach(wc_get_orders(array("limit"=>-1,"status"=>"any")) as $o){printf("#%-5d pai=%-5d %-11s %8s itens=%d vendedor=%s\n",$o->get_id(),$o->get_parent_id(),$o->get_status(),$o->get_total(),count($o->get_items()),$o->get_meta("_dokan_vendor_id")?:"-");}'
```

Um pedido-pai com 3 itens e três sub-pedidos apontando para ele, cada um com o
seu `_dokan_vendor_id`. O split é feito por
`dokan()->order->maybe_split_orders()` no fechamento da compra.

**Um carrinho, um pagamento, um pedido para o cliente; um pedido por vendedor no
backend, cada qual com a sua associação item ↔ vendedor.**

---

## Checklist

As travas de acesso têm verificação automática:

```bash
./scripts/verificar-acessos.sh -v
```

Faz login de verdade nos quatro perfis, bate em cada URL restrita e compara o
código HTTP com o esperado — incluindo o isolamento entre vendedores e entre
empresas, nas duas direções, e a escrita no fórum. São 54 casos; o script sai
com status 1 se algum falhar. Rode antes de apresentar.

Isso cobre os itens 3, 4, 7, 8, 9, 12, 15, 16, 17 e 20 da tabela abaixo. O
restante é visual e precisa de olho humano:

| # | O que demonstrar | Evidência |
| --- | --- | --- |
| 1 | Vitrine com busca, filtros e cards completos | home em 8090 |
| 2 | Compra de ponta a ponta | pedido novo em Minha conta |
| 3 | Cliente sem acesso à comunidade | 403 em `/comunidade/` |
| 4 | Cliente sem acesso ao painel | redirect de `/wp-admin/` |
| 5 | Vendedor vê só os produtos dele | 3 de 15 no painel Dokan |
| 6 | Vendedor vê só os pedidos dele | listagem de Pedidos |
| 7 | Vendedor não acessa dado de outro | URL direta negada + comando 2.5 |
| 8 | Vendedor entra na comunidade | `/comunidade/` abre |
| 9 | Vendedor não entra no painel | redirect de `/wp-admin/` |
| 10 | Fluxo de 5 estados do pedido | seletor com "Em preparação" e "Enviado" |
| 11 | Admin vê tudo | 15 produtos, todos os pedidos, 5 lojas |
| 12 | Só o admin gere plugins | tela de Plugins |
| 13 | Carrinho multi-vendedor com split | pedido de R$ 157,70 e seus 3 filhos |
| 14 | Admin de Empresas opera fora do `/wp-admin` | `/painel-empresas/` no visual do tema |
| 15 | Admin de Empresas isolado por empresa | 403 na empresa alheia |
| 16 | Alcance múltiplo é capacidade, não papel | `demo-admin-rede` vê as duas |
| 17 | Vendedor e cliente fora do painel de empresas | 403 em `/painel-empresas/` |
| 18 | Cadastro de vendedor com link de senha | link exibido uma única vez |
| 19 | Cascata de desativação preserva o individual | seção 3.6 |
| 20 | Cliente não escreve no fórum | `publish_topics` negada, seção 2.7 |
| 21 | Fórum ordena de verdade pelas três abas | Votos põe a de saldo 5 no topo |
| 22 | Voto sem JavaScript, e sem votar em si | seção 2.7 |

## Recomeçar

Apagar só os dados de demonstração, preservando a instalação:

```bash
./scripts/seed-demo.sh remover
```

Recriar:

```bash
./scripts/seed-demo.sh instalar
```

Do zero, apagando banco e uploads (pede confirmação digitada):

```bash
./scripts/demo-completa.sh --recomecar
```

## Se algo não bater

**Uma permissão parece não funcionar.** Quase sempre é sessão: troque de perfil
em janela anônima, ou faça logout de verdade.

**Um pedido ou produto some.** Os IDs mudam a cada recarga. Identifique pelo
cliente, status e valor.

**`/painel-empresas/empresa/<id>/` dá 404.** As subrotas são endpoints de
rewrite e só existem depois que as regras são regravadas. O provisionamento faz
isso no fim; fora dele:

```bash
docker compose run --rm wpcli wp rewrite flush
```

**Ruído de SQL no log com `wp_bp_activity`.** BuddyPress está ativo sem as
tabelas criadas; o erro aparece ao remover usuários e **não** interrompe nada.

**Detalhes do conteúdo:** [DADOS_DEMONSTRACAO.md](DADOS_DEMONSTRACAO.md).
**Regras de permissão:** [PERFIS_E_PERMISSOES.md](PERFIS_E_PERMISSOES.md).
**Arquitetura:** [STACKS.md](STACKS.md).
