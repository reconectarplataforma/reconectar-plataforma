# Roteiro de cadastro manual

Como popular à mão uma instalação recém-provisionada — perfis, categorias e
lojas —, usando apenas o acesso de **Super Administrador**.

Este é o caminho para um ambiente **real**. A carga de
[dados de demonstração](DADOS_DEMONSTRACAO.md) faz o mesmo trabalho em segundos,
mas tudo que ela cria é fictício e existe para avaliar telas: não use em
produção.

## Por que este documento existe

`scripts/provision.sh` entrega a plataforma **vazia** de propósito: ele instala
o núcleo, o tema, os plugins e as páginas, e não cria nenhuma categoria, nenhuma
empresa, nenhuma loja e nenhum produto. Isso é correto para uma entrega —
ninguém quer receber um sistema com dado inventado dentro —, e é também o motivo
de a home de uma instalação nova mostrar *"Ainda não há lojas publicadas na
plataforma."*

O que se segue é a sequência que tira o ambiente desse estado.

## Antes de começar

Entre como Super Administrador em `/wp-admin/`. O papel `administrator` aparece
na tela com esse nome — foi renomeado pelo plugin autoral, e é ele que acumula
os dois lados: administra a tecnologia e, por consequência, também a operação.

O trabalho acontece em **duas áreas distintas**, e confundir uma com a outra é o
erro mais comum deste roteiro:

| Área | URL | O que se faz ali |
| --- | --- | --- |
| Administração do WordPress | `/wp-admin/` | perfis (usuários) e categorias de produto |
| Painel de Empresas | `/painel-empresas/` | empresas e lojas |

O Painel de Empresas **não tem item de menu no `/wp-admin/`** — ele é uma rota
de front-end, fora da administração. O caminho pela interface é o botão
**"Painel de Empresas"** no canto direito do cabeçalho do site, que aparece para
quem está logado com permissão de acessá-lo. Digitar `/painel-empresas/` na
barra de endereços dá no mesmo.

## A ordem importa

As dependências são reais, não burocráticas — cada uma delas faz algo sumir da
tela quando invertida:

```
Administrador (company_admin)  ─┐
                                ├─→  Empresa  ─→  Loja  ─→  Produto
Categoria de produto  ──────────┴──────────────────────────────┘
```

- **Loja não existe fora de empresa cadastrada.** O formulário de loja só é
  alcançável a partir da ficha de uma empresa.
- **Categoria sem produto publicado não aparece no site.** Tanto o carrossel da
  home quanto a página `/categorias/` consultam com `hide_empty`.
- **O Administrador precisa nascer antes das empresas dele.** Ver a seção do
  escopo, logo abaixo: é a armadilha desta entrega.

---

# 1. Perfis

## 1.1 Os papéis, e o nome com que aparecem na tela

O seletor de papel do WordPress lista muito mais do que os cinco atores da
plataforma — há os do WooCommerce, os do Dokan e os cinco do bbPress. Só estes
importam:

| Rótulo na tela | Papel | O que administra | Onde trabalha |
| --- | --- | --- | --- |
| Super Administrador | `administrator` | tudo | `/wp-admin/` e `/painel-empresas/` |
| Administrador | `company_admin` | as empresas do escopo dele, e as lojas delas | `/painel-empresas/` |
| Moderador de Conteúdo | `content_moderator` | posts, páginas, comentários e o fórum | `/wp-admin/` |
| Vendor | `seller` | a própria loja | `/dashboard/` (painel do Dokan) |
| Customer | `customer` | a própria conta | `/my-account/` |

**Nunca crie uma loja escolhendo "Vendor" aqui.** O papel é o mesmo, mas uma
conta criada pelo `/wp-admin/` nasce sem o perfil de loja do Dokan — sem
`store_name`, sem `dokan_publishing`, sem `dokan_enable_selling`. O efeito não é
erro: a conta existe, entra no sistema, e **some da vitrine**, porque a consulta
que monta a lista de lojas descarta quem não tem nome de loja. Lojas se cadastram
pelo Painel de Empresas, e só por ele — ver a seção 3.

**Usuário Comum** (`customer`) também não precisa de cadastro manual: quem compra
se registra sozinho em `/my-account/`. O autocadastro de **loja**, ao contrário,
é desligado pelo provisionamento de propósito.

## 1.2 Cadastrar um Administrador (`company_admin`)

`/wp-admin/user-new.php` — **Usuários → Adicionar novo**.

| Campo | O que preencher |
| --- | --- |
| Nome de usuário | obrigatório, não muda depois |
| E-mail | obrigatório, único na instalação |
| Nome / Sobrenome | opcional |
| Senha | **defina uma aqui** — ver a nota abaixo |
| Enviar notificação | deixe desmarcado |
| Função | **Administrador** |

> **A instalação não envia e-mail.** Não há SMTP configurado, e nada no
> provisionamento configura um. A caixa "Enviar ao novo usuário um e-mail sobre
> sua conta" não produz erro na tela — a mensagem simplesmente não chega. Defina
> a senha no próprio formulário e repasse por um canal combinado, ou peça à
> pessoa que use "Perdi minha senha" só depois que o envio de e-mail existir.

## 1.3 O escopo: o passo que falta

Um Administrador recém-criado **não vê empresa nenhuma**. O painel dele abre
dizendo *"Nenhuma empresa sob sua gestão ainda"*, mesmo que a plataforma já tenha
dezenas cadastradas.

Isso é intencional: o vínculo entre Administrador e empresa é N:N e explícito,
gravado na user meta `_reconectar_empresas_geridas`. Só o Super Administrador
enxerga todas, porque só ele recebe a capacidade
`reconectar_gerir_todas_as_empresas` — e ela fica fora do papel `company_admin`
justamente para que "administrar uma empresa" não vire, por descuido,
"administrar todas".

O escopo é preenchido **automaticamente numa única situação**: quando o próprio
Administrador cadastra a empresa, ela entra no escopo dele no mesmo instante.

Daí a recomendação prática:

> **Crie o Administrador primeiro e deixe que ele cadastre as empresas dele.**
> É o caminho sem nenhum passo escondido.

Quando isso não for possível — a empresa já existe, ou foi o Super Administrador
que a cadastrou —, não há tela para corrigir: o vínculo se grava por WP-CLI.
Primeiro os IDs:

```bash
docker compose run --rm wpcli wp post list --post_type=reconectar_empresa --fields=ID,post_title
```

```bash
docker compose run --rm wpcli wp user list --role=company_admin --fields=ID,user_login,display_name
```

E então o escopo, com a lista **completa** de empresas daquele Administrador — a
gravação substitui o valor anterior, não acrescenta a ele:

```bash
docker compose run --rm wpcli wp user meta update 7 _reconectar_empresas_geridas --format=json '[274,275]'
```

Conferindo:

```bash
docker compose run --rm wpcli wp user meta get 7 _reconectar_empresas_geridas --format=json
```

## 1.4 Cadastrar um Moderador de Conteúdo (`content_moderator`)

Mesmo formulário da seção 1.2, com **Função: Moderador de Conteúdo**.

Ele trabalha dentro do `/wp-admin/`, mas com uma administração podada: publica e
edita posts e páginas, modera comentários e administra o fórum. Não alcança
produtos, pedidos, plugins, temas nem configurações — quem administra o produto
é a loja dona dele. A matriz completa está em
[PERFIS_E_PERMISSOES.md](PERFIS_E_PERMISSOES.md).

Ao contrário do Administrador, este perfil **não tem escopo a definir**: está
pronto assim que a conta é criada.

---

# 2. Categorias de produto

`/wp-admin/edit-tags.php?taxonomy=product_cat&post_type=product` —
**Produtos → Categorias**.

## 2.1 Os campos

| Campo | Efeito |
| --- | --- |
| Nome | o que aparece no card e no título da listagem |
| Slug | entra na URL; deixe em branco para derivar do nome |
| Categoria-mãe | **nenhuma**, para as categorias principais — ver 2.3 |
| Descrição | aparece no topo da listagem da categoria |
| Tipo de exibição | padrão |
| Miniatura | a imagem do card — ver 2.2 |

## 2.2 A miniatura, e o que acontece sem ela

O card de categoria da home usa a miniatura do termo. Quando ela não existe, o
card desenha um círculo com a **inicial** do nome — não fica quebrado, mas a
faixa inteira de categorias sem imagem vira uma fileira de letras.

A imagem é enviada pela própria tela da categoria, no campo Miniatura. É um
anexo da biblioteca de mídia, e vale lembrar de uma assimetria do deploy: anexo
mora em `wp-content/uploads/`, que **não** é sincronizado entre ambientes. Uma
miniatura enviada em desenvolvimento não existe no servidor, e vice-versa. Cada
ambiente recebe as suas.

## 2.3 Duas regras que fazem categoria sumir da tela

**Categoria sem produto publicado não aparece em lugar nenhum do site público.**
Tanto o carrossel da home quanto a página `/categorias/` consultam com
`hide_empty`, e é por isso que a página de categorias de uma instalação
recém-cadastrada continua vazia até o primeiro produto entrar. Não é defeito:
uma categoria listada que leva a uma prateleira vazia é pior do que uma
categoria ainda invisível.

**A home só mostra categorias de primeiro nível**, e no máximo **14**, ordenadas
pela quantidade de produtos. Subcategorias aparecem na página `/categorias/`,
agrupadas sob a mãe. Se a intenção é que uma categoria apareça na home, ela não
pode ter mãe.

## 2.4 Um conjunto de partida

Estas seis vêm da carga de demonstração e cobrem o recorte da economia solidária
do edital. Servem como ponto de partida, não como obrigação:

| Nome | Slug |
| --- | --- |
| Alimentos e Bebidas | `alimentos-e-bebidas` |
| Artesanato | `artesanato` |
| Moda e Acessórios | `moda-e-acessorios` |
| Casa e Decoração | `casa-e-decoracao` |
| Beleza e Cuidados | `beleza-e-cuidados` |

---

# 3. Empresas e lojas

Tudo nesta seção acontece em `/painel-empresas/`.

A **empresa** é a pessoa jurídica — a cooperativa, a associação, o coletivo. A
**loja** é o negócio que vende na plataforma, e tem uma conta de acesso própria.
Uma empresa pode ter várias lojas; uma loja pertence a no máximo uma empresa.

## 3.1 Cadastrar a empresa

Painel de Empresas → **Cadastrar empresa**.

| Campo | Obrigatório | Observação |
| --- | --- | --- |
| Nome da empresa | **sim** | é o único obrigatório |
| Razão social | não | |
| CNPJ | não | digite só números; a pontuação entra sozinha, e os dígitos verificadores são conferidos |
| Pessoa responsável | não | |
| E-mail de contato | não | validado como e-mail |
| Telefone | não | com DDD, fixo ou celular; máscara automática |
| Município | não | aparece na listagem de empresas |
| UF | não | duas letras |

Só o nome é exigido porque o cadastro pode ser completado depois — exigir CNPJ de
uma cooperativa em formalização seria inventar um requisito que o edital não faz.

Um campo recusado (CNPJ com dígito trocado, e-mail malformado) volta com a
mensagem e **preserva o que já foi digitado**: nenhuma empresa é gravada pela
metade.

## 3.2 Cadastrar a loja

Na ficha da empresa, botão **Cadastrar loja**. O formulário anuncia a qual
empresa a loja será vinculada — se o nome ali não for o esperado, volte: a loja
nasce presa a essa empresa e o vínculo não é editável depois.

| Campo | Obrigatório | Observação |
| --- | --- | --- |
| Nome da loja | **sim** | vira o `store_name` do Dokan, que é o que a vitrine exibe |
| Nome de usuário | **sim** | o login; não muda depois |
| E-mail | **sim** | único na instalação |
| Nome / Sobrenome | não | da pessoa responsável |
| Telefone | não | |
| Descrição da loja | não | |

**Não há campo de papel**, e a ausência é deliberada: o papel é constante no
código, para que nenhuma requisição possa pedir um diferente.

A loja nasce pronta para operar — perfil do Dokan montado, publicação direta de
produto habilitada, venda liberada — desde que a empresa esteja em operação.

## 3.3 O link de definição de senha

Nenhum e-mail é enviado. Depois de cadastrar, a ficha da loja mostra um **link
de definição de senha** para você repassar à pessoa responsável.

> **Ele aparece uma única vez.** Copie antes de sair da tela.

Perdido o link, o caminho é a pessoa usar "Perdi minha senha" na tela de acesso
— o que só funciona quando o envio de e-mail existir — ou o Super Administrador
definir a senha à mão em **Usuários → Editar**.

O link expira como qualquer redefinição de senha do WordPress.

## 3.4 Ativar e desativar

Há **dois** interruptores, e eles não são o mesmo:

| Onde | Botão | Efeito |
| --- | --- | --- |
| Ficha da empresa | Desativar empresa | nenhuma loja da empresa pode vender |
| Ficha da loja | Desativar loja | só aquela loja sai de operação |

Eles se combinam, e a combinação é o que a ficha informa em texto: reativar a
empresa devolve cada loja ao **estado individual em que estava** — uma loja
desativada à mão não volta a vender junto com a empresa. A ficha diz qual dos
dois casos se aplica, em vez de deixar a dedução por conta de quem lê.

Uma loja fora de operação continua cadastrada e visível no painel; o que ela
perde é a permissão de venda.

## 3.5 O que fica para a loja fazer

Do cadastro em diante, o trabalho é da própria loja, no painel do Dokan
(`/dashboard/`), com a conta dela:

- **Logo e banner** da loja. Sem eles nada quebra — o card cai na inicial do
  nome, como o de categoria —, mas uma vitrine inteira assim vira uma fileira de
  letras.
- **Endereço**, incluindo a cidade: é dela que sai o filtro por município da
  listagem de lojas. Endereço em branco tira a loja desse filtro.
- **Chave PIX**, em Configurações → Pagamento. É o único meio de recebimento que
  a plataforma oferece por padrão, e sem ela a loja aparece no checkout como
  "não recebe por aqui". O fluxo completo está em [PAGAMENTOS.md](PAGAMENTOS.md).
- **Produtos**, com preço, estoque, imagem e **categoria** — é o que faz a
  categoria da seção 2 deixar de ser invisível.

O produto ainda decide uma coisa que parece configuração de loja: **a categoria
exibida no card da loja não é um campo**, é a categoria mais frequente entre os
produtos publicados dela. O Dokan tem um campo próprio para isso, opcional, que
fica vazio na maioria dos cadastros; deduzir do que a loja de fato vende dá um
resultado correto sem exigir preenchimento de ninguém. Uma loja sem produto
aparece sem categoria, e não há onde corrigir isso a não ser publicando.

O Super Administrador e o Administrador **veem** produtos, pedidos e ganhos de
cada loja na ficha dela, mas não os editam: quem administra o produto é a loja
dona dele.

---

# 4. Conferir o resultado

| Página | O que tem de aparecer |
| --- | --- |
| `/` (home) | carrossel de categorias, vitrine de lojas, faixa de produtos |
| `/categorias/` | as categorias que já têm produto, com as subcategorias sob a mãe |
| Lojas parceiras | todas as lojas em operação, com filtro por categoria e município |
| `/painel-empresas/` | as empresas, com a contagem de lojas e a situação de cada uma |

A listagem de lojas é a página que o Dokan registra como `store_listing` —
`/store-listing/` por padrão, renomeável no painel dele. Chegue a ela pelo botão
"Ver todas" da vitrine da home, e não por um caminho digitado: o endereço é
resolvido pelo registro do plugin, não pelo slug.

Três comportamentos que parecem defeito e não são:

- **A faixa "Lojas em destaque" só aparece a partir de quatro lojas.** Com menos,
  a vitrine completa logo abaixo já mostra todas, e o destaque seria a repetição
  do que o cliente vê dois blocos adiante.
- **A vitrine leva até um minuto para refletir uma mudança.** A lista de lojas
  fica em cache curto. Se acabou de ativar uma loja e ela não apareceu, espere.
- **Categoria sem produto não aparece.** Ver 2.3.

## O conjunto mínimo

Para a home não exibir nenhum bloco vazio:

- 1 Administrador (ou o próprio Super Administrador cadastrando)
- 1 empresa em operação
- **4 lojas** em operação, com logo e banner — abaixo disso, some a faixa de
  destaque
- 3 a 6 categorias de primeiro nível, com miniatura
- pelo menos 1 produto publicado **em cada** categoria que deva aparecer

---

# O que este roteiro não cobre

- **Produtos.** O cadastro é da loja, no painel do Dokan, e está fora do acesso
  de Super Administrador por desenho.
- **Configuração de pagamento.** É por loja, e tem documento próprio:
  [PAGAMENTOS.md](PAGAMENTOS.md).
- **Conteúdo editorial** — posts, páginas, fórum, campanhas da home e as
  páginas da Incubadora. É o território do Moderador de Conteúdo, e a
  Incubadora se escreve pelo próprio site, em `/incubadora/`, sem o
  `/wp-admin`: veja a seção 4.6 de [ROTEIRO_PERFIS.md](ROTEIRO_PERFIS.md). A
  página âncora e o item de menu nascem do `provision.sh`; as páginas, não —
  uma instalação nova abre a Incubadora com o botão "Criar a primeira página".
- **Envio de e-mail.** A instalação não tem SMTP, e todo passo deste roteiro foi
  escrito para funcionar sem ele.
- **Importação em lote.** Não há. Para volume, o caminho é WP-CLI, no molde de
  `scripts/seed/demo.php`.

## Documentos relacionados

| Documento | Para quê |
| --- | --- |
| [PERFIS_E_PERMISSOES.md](PERFIS_E_PERMISSOES.md) | a matriz completa dos cinco atores |
| [ROTEIRO_PERFIS.md](ROTEIRO_PERFIS.md) | percorrer a plataforma com cada perfil |
| [DADOS_DEMONSTRACAO.md](DADOS_DEMONSTRACAO.md) | o mesmo conteúdo, criado por carga automática |
| [PAGAMENTOS.md](PAGAMENTOS.md) | PIX, transferência e o checkout multi-loja |
| [DEPLOY.md](DEPLOY.md) | publicar no servidor |
