# Dados de demonstração

Este documento descreve a **carga de dados fictícios** da plataforma
Reconectar: o que ela cria, como identificá-la, e como removê-la por
completo.

> **Nada do que a carga cria representa pessoa, empresa ou produto real.**
> As lojas, os produtos, os preços, os endereços, os telefones e as
> avaliações são inventados e existem apenas para que as telas do sistema
> possam ser vistas e avaliadas com conteúdo na tela.

## Por que a carga existe

O ambiente provisionado do zero por `scripts/provision.sh` entrega a
plataforma **vazia**: nenhuma categoria de produto, nenhuma loja, nenhum
produto. Isso é correto para uma entrega — ninguém quer receber um sistema
com dado inventado dentro —, mas torna impossível avaliar o layout: a home
tem uma vitrine de lojas, um carrossel de categorias e uma faixa de produtos
em destaque, e as três renderizam em branco num banco vazio.

A carga resolve esse problema sem comprometer a entrega, porque é
**estritamente opcional**: `provision.sh` **não** a executa, e o serviço `demo`
do Compose fica atrás de um profile, de modo que um `docker compose up` comum
não o levanta. Dado fictício só entra no banco quando alguém pede — por
`seed-demo.sh`, por `demo-completa.sh` ou por `docker compose run --rm demo`.

## Como usar

```bash
./scripts/demo-completa.sh
```

Do zero: sobe os contêineres, provisiona a instalação e popula. É o comando
para quem vai apresentar a plataforma e não tem ambiente de pé. O mesmo
trabalho, com os serviços já rodando, cabe em `docker compose run --rm demo` —
o serviço `demo` do `docker-compose.yml` fica atrás do profile de mesmo nome,
justamente para não subir junto com o resto.

```bash
./scripts/seed-demo.sh
```

Só a carga, na instalação que já existe. Cria 6 categorias de produto, 5 lojas
(vendedores Dokan), 15 produtos, as imagens de todos eles, 16 avaliações, 3
clientes e 6 pedidos. A operação é **idempotente**: rodar
duas vezes não duplica nada — cada registro é procurado antes de ser criado,
pelo slug (categorias, produtos), pelo login (vendedores e clientes) ou por uma
chave determinística gravada na meta `_reconectar_demo_chave`: avaliações usam
`{login-da-loja}-{posição}`; pedidos usam a chave declarada (`ped-001` e
seguintes).

As avaliações precisaram dessa chave própria porque, ao contrário dos demais
registros, não têm identificador natural: para o WordPress, dois comentários
com o mesmo autor, o mesmo texto e a mesma nota são registros legitimamente
distintos, e sem a chave a segunda execução inseria tudo de novo.

```bash
./scripts/seed-demo.sh remover
```

Apaga tudo o que a carga criou. Pede confirmação; use `-y` para pular a
pergunta (necessário em ambientes sem terminal interativo, como CI).

```bash
./scripts/seed-demo.sh --help
```

O script funciona tanto a partir do host (delega ao `docker compose run`)
quanto de dentro do container `wpcli` (onde o WP-CLI já está no PATH).

## As quatro camadas de marcação

Numa entrega de licitação, o pior resultado possível seria alguém avaliar
dado fictício acreditando ser dado real — ou, pior ainda, tentar entrar em
contato com uma "loja" que não existe. Por isso a carga se identifica em
quatro lugares independentes, e qualquer um deles basta para reconhecê-la:

### 1. Meta `_reconectar_demo` em cada registro

Todo registro criado pela carga recebe a meta `_reconectar_demo = 1`: os
produtos, os usuários (vendedores e clientes), os termos de categoria, os
arquivos de mídia, as avaliações e os pedidos. É essa marca que a remoção usa como critério — ela
procura por ela em cada um desses tipos e não toca em mais nada. Conteúdo
cadastrado por pessoas reais nunca tem essa meta e, portanto, nunca é
removido, mesmo que tenha nome parecido.

As avaliações são o único caso em que a meta não é consultada diretamente na
remoção: elas desaparecem junto com os produtos que as receberam, porque
apagar um post permanentemente apaga seus comentários. O resultado é o mesmo
— toda avaliação da carga vive em um produto da carga —, e a meta continua
servindo para identificá-las no banco a qualquer momento.

Para listar o que está marcado:

```bash
docker compose run --rm wpcli wp post list --post_type=product --meta_key=_reconectar_demo --format=count
```

### 2. Faixa de aviso no site e no painel

Enquanto a carga estiver ativa, a opção `reconectar_demo_ativo` fica gravada
no banco, e o plugin `reconectar-core` exibe:

- uma **faixa listrada de advertência** no topo de todas as páginas do site
  (via `wp_body_open`), sem botão de fechar;
- um **aviso equivalente** no painel administrativo (via `admin_notices`),
  porque quem administra o site vê o catálogo pelas listas de produtos e de
  usuários, onde a faixa do front-end não aparece.

O aviso está no plugin, e não no tema, de propósito: ele precisa continuar de
pé independentemente de qual tema esteja ativo.

A classe correspondente é
`wp-content/plugins/reconectar-core/includes/class-reconectar-aviso-demo.php`.

### 3. E-mails no domínio `exemplo.invalid`

Todos os e-mails da carga — dos vendedores e dos autores das avaliações —
usam o domínio `exemplo.invalid`.

O TLD `.invalid` é **reservado pela RFC 2606, seção 2**, o que significa que
ele nunca será registrado por ninguém. Nenhuma mensagem enviada a esses
endereços pode alcançar uma pessoa real, nem hoje nem no futuro. Domínios
como `.local` ou `.test` não oferecem a mesma garantia em todos os contextos,
e por isso não foram usados.

Para listar os usuários da carga:

```bash
docker compose run --rm wpcli wp user list --search='*@exemplo.invalid' --fields=ID,user_login,user_email,roles
```

### 4. Este documento

A quarta camada é a documentação: qualquer pessoa que encontre lojas com
nomes plausíveis no ambiente tem, aqui, a lista do que foi inventado e o
comando que remove tudo.

## O que exatamente é criado

**6 categorias de produto** (taxonomia `product_cat`): Alimentos e Bebidas,
Artesanato, Moda e Acessórios, Casa e Decoração, Beleza e Cuidados, e
Cultura e Educação. A última fica propositalmente **sem produtos**, para
exercitar o comportamento de `hide_empty` nas consultas da home.

**5 lojas** (usuários com papel `seller` do Dokan), todas em municípios de
Alagoas, cada uma com 3 produtos:

| Loja | Categoria | Município |
|---|---|---|
| Sabor da Terra | Alimentos e Bebidas | Maceió |
| Ateliê Raízes | Artesanato | Maceió |
| Moda Reconecta | Moda e Acessórios | Arapiraca |
| Casa Viva | Casa e Decoração | Penedo |
| Bem Viver Natural | Beleza e Cuidados | Marechal Deodoro |

**15 produtos**, 4 deles com preço promocional, e **16 avaliações**
distribuídas entre os produtos de cada loja em rodízio, com notas de 4 e 5
estrelas e textos genéricos assinados por "Cliente de demonstração". As
avaliações existem para que a nota da loja apareça nos cards da vitrine —
por isso a distribuição é por loja, não por produto.

Cada loja declara **tempo de entrega, taxa e distância** — `30-45 min`, `0`,
`2,4 km` para a Sabor da Terra, e assim por diante. Os três valores são
literais no catálogo, nunca calculados: a plataforma não tem integração de
logística, e um prazo plausível gerado na hora é uma promessa que ninguém
assumiu. `taxa` igual a `0` faz a loja entrar no filtro de entrega grátis.

**3 clientes** (papel `customer`): Ana Lima (Maceió), João Ferreira (Arapiraca)
e Marina Costa (Penedo), com telefone e endereço de cobrança preenchidos.
Existem para que o roteiro percorra a jornada de compra de verdade — entrar,
ver o histórico, acompanhar um pedido — em vez de descrevê-la.

**6 pedidos**, cobrindo de propósito os cinco status do fluxo e os três meios
de pagamento:

| Chave | Cliente | Status | Pagamento | Idade |
|---|---|---|---|---|
| `ped-001` | Ana | Entregue (`wc-completed`) | PIX | 24 dias |
| `ped-002` | João | Enviado (`wc-enviado`) | Cartão | 6 dias |
| `ped-003` | Marina | Em preparação (`wc-preparacao`) | Boleto | 3 dias |
| `ped-004` | Ana | Pagamento aprovado (`wc-processing`) | PIX | 2 dias |
| `ped-005` | João | Pedido realizado (`wc-pending`) | Boleto | 1 dia |
| `ped-006` | Marina | Pagamento aprovado (`wc-processing`) | Cartão | 1 dia |

`wc-preparacao` e `wc-enviado` são status autorais, registrados por
`class-reconectar-status-pedido.php`. O `ped-006` tem itens de **três lojas
diferentes**, e é o caso que demonstra o carrinho multi-vendedor: o Dokan o
divide em um sub-pedido por vendedor, cada qual visível apenas para o seu dono.

As idades em dias existem para que a coluna de data do painel sirva para algo.
Um histórico em que tudo aconteceu hoje não se parece com uma loja em operação.

**Vendedores e clientes entram com a mesma senha**, `reconectar-demo`, definida
em `demo.php` e substituível pela variável de ambiente
`RECONECTAR_DEMO_SENHA`. São contas de ambiente local, não credenciais de
sistema — antes elas recebiam senha aleatória, o que tornava impossível
demonstrar o painel do vendedor sem redefinir cada uma à mão. Os logins estão
em [ROTEIRO_PERFIS.md](ROTEIRO_PERFIS.md).

## As imagens

Todas as imagens — banners de loja, avatares, ícones de categoria e fotos de
produto — são **geradas por código** no momento da carga, com a extensão GD
do PHP: um gradiente na cor da categoria, o nome do item centralizado em
Poppins e a palavra "DEMO" no canto.

A alternativa seria versionar fotografias no repositório, o que traria um
problema de licenciamento para um repositório público (a Atividade 2.9 do
edital) e um problema de peso. Imagens geradas não têm dono, não têm licença
a respeitar e deixam claro, à primeira vista, que não são fotos de produtos
reais.

## Sobre a remoção

A remoção apaga pedidos, produtos, usuários, arquivos de mídia, termos de
categoria e avaliações — permanentemente, sem passar pela lixeira. Ela é
restrita ao que carrega a meta `_reconectar_demo`, mas ainda assim é
irreversível, e por isso pede confirmação.

A ordem das operações importa e está implementada assim em
`scripts/seed/demo.php`: os pedidos saem primeiro, com `force delete` — um
pedido na lixeira continua contando nos relatórios do painel. Em seguida vem a
limpeza das tabelas paralelas do Dokan, **depois** dos pedidos e não antes,
porque ela decide pelo que sobrou: uma linha só é reconhecida como órfã quando
o pedido dela já não existe. Depois os produtos (cujos comentários e metas de
nota vão junto); então as referências de banner e avatar são limpas do
`dokan_profile_settings` de cada vendedor **antes** de os arquivos de mídia
serem apagados (caso contrário o perfil ficaria apontando para anexos
inexistentes); depois os anexos, os usuários — vendedores e clientes saem
juntos, porque recebem a mesma meta —, os termos e, por fim, a opção
`reconectar_demo_ativo`, o que faz a faixa de aviso desaparecer.

### As tabelas paralelas do Dokan

`wp_dokan_orders`, `wp_dokan_vendor_balance` e companhia guardam as vendas, e é
delas — não dos pedidos do WooCommerce — que o painel do vendedor lê o
faturamento. Apagar o pedido **não** limpa essas linhas: o plugin as remove a
partir dos seus próprios hooks de estorno e cancelamento, não da exclusão
definitiva que esta carga usa.

A consequência foi observada aqui: numa instalação em que a carga rodou duas
vezes, o painel somava vendas de pedidos que já não existiam em lugar nenhum. O
relatório não estava errado por cálculo — estava certo sobre uma base suja. Daí
`reconectar_demo_limpar_tabelas_dokan()`, cujo critério é a existência do
pedido, não a marcação de demonstração: uma linha que aponta para um pedido
inexistente não é utilizável por ninguém, qualquer que tenha sido a sua origem.

## Não use em produção

A carga existe para desenvolvimento, homologação e demonstração. Em um
ambiente de produção com lojistas reais, rodá-la significaria exibir lojas
inventadas ao lado de lojas de verdade.

## Arquivos relacionados

| Arquivo | Papel |
|---|---|
| `scripts/demo-completa.sh` | Ambiente + provisionamento + carga, em um comando |
| `scripts/seed-demo.sh` | Ponto de entrada; trata argumentos e confirmação |
| `scripts/seed/demo.php` | Lógica de criação e de remoção (roda via `wp eval-file`) |
| `scripts/seed/dados-demo.php` | Catálogo declarativo: nomes, preços, endereços, textos |
| `wp-content/plugins/reconectar-core/includes/class-reconectar-aviso-demo.php` | Faixa de aviso no site e no painel |
| `docs/ROTEIRO_PERFIS.md` | Credenciais e roteiro de demonstração por perfil |
