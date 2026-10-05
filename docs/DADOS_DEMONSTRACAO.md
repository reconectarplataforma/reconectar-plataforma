# Dados de demonstração

Este documento descreve a **carga de dados fictícios** da plataforma
Reconectar: o que ela cria, como identificá-la, e como removê-la por
completo.

> **Nada do que a carga cria representa pessoa, empresa ou produto real.**
> As lojas, os produtos, os preços, os endereços, os telefones, as chaves PIX,
> os dados bancários e as avaliações são inventados e existem apenas para que
> as telas do sistema possam ser vistas e avaliadas com conteúdo na tela.
> **Nenhum BR Code gerado a partir deles cobra de ninguém.**

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
(contas `seller` do Dokan), 15 produtos, as imagens de todos eles, 16
avaliações, 3 clientes e 6 pedidos. A operação é **idempotente**: rodar
duas vezes não duplica nada — cada registro é procurado antes de ser criado,
pelo slug (categorias, produtos), pelo login (lojas e clientes) ou por uma
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
produtos, os usuários (lojas e clientes), os termos de categoria, os
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

Todos os e-mails da carga — das lojas e dos autores das avaliações —
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

**2 empresas** (CPT `reconectar_empresa`): Cooperativa Nosso Chão (Maceió, três
lojas) e Rede Bem Viver (Arapiraca, duas). A distribuição desigual é de
propósito — sem duas empresas povoadas não há como demonstrar o isolamento, que
é a regra de negócio central desse ator. Os CNPJ são fictícios com base zerada e
passam nos dígitos verificadores, porque `Reconectar_Empresa::normalizar_campo()`
os confere: um CNPJ que o formulário recusaria, gravado por baixo pela carga,
seria um dado que só existe na demonstração.

**3 Administradores** (papel `company_admin`) e **1 Moderador de Conteúdo**
(papel `content_moderator`):

| Login | Nome | Alcance |
|---|---|---|
| `demo-admin-nosso-chao` | Teresa Nogueira | Cooperativa Nosso Chão |
| `demo-admin-bem-viver` | Otávio Meireles | Rede Bem Viver |
| `demo-admin-rede` | Clara Viana | todas, por `reconectar_gerir_todas_as_empresas` |
| `demo-moderador` | Rita Albuquerque | sem escopo — conteúdo, comunidade e campanha |

São três administradores e não dois porque o terceiro é o caso de alcance
global previsto na especificação: sem ele a capacidade existiria no código sem
ninguém para exercê-la, e uma regressão nela passaria despercebida. O Moderador
é um só pelo motivo oposto — o perfil **não tem escopo**, então uma segunda
conta mostraria exatamente a mesma tela.

Nenhum bloco deste catálogo declara o papel do usuário: quem é o papel está
escrito em `demo.php`, no código. Um catálogo de dados com `'papel' => '…'`
seria uma escalada de privilégio esperando por um descuido de revisão.

**5 lojas** (usuários com papel `seller` do Dokan), todas em municípios de
Alagoas, cada uma com 3 produtos:

| Loja | Categoria | Município | Aceita |
|---|---|---|---|
| Sabor da Terra | Alimentos e Bebidas | Maceió | PIX (CNPJ) e transferência |
| Ateliê Raízes | Artesanato | Maceió | só PIX (e-mail) |
| Moda Reconecta | Moda e Acessórios | Arapiraca | só transferência |
| Casa Viva | Casa e Decoração | Penedo | PIX (telefone) e transferência |
| Bem Viver Natural | Beleza e Cuidados | Marechal Deodoro | **nenhum** |

A coluna "Aceita" é o catálogo de casos do pagamento direto à loja, e cada linha
existe por um motivo. As três combinações possíveis aparecem — ambos, só PIX, só
transferência —, e é isso que faz a regra da interseção ter o que demonstrar num
carrinho de mais de uma loja. Os tipos de chave também variam, porque o BR Code
monta um payload diferente para cada um.

**Bem Viver Natural não cadastrou meio nenhum, e isso não é esquecimento.** É a
loja que prova que o checkout recusa o pedido dizendo o nome de quem não recebe,
em vez de deixar passar uma compra que ninguém consegue pagar por inteiro.
Preencher os dados dela apagaria o único caso de demonstração desse caminho — e
é também por isso que ela não aparece em pedido algum.

As chaves PIX são fictícias: CNPJ de base zerada, e-mail em `exemplo.invalid`,
telefone em faixa que não existe.

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

**6 pedidos**, cobrindo de propósito os cinco status do fluxo e os dois meios de
pagamento que a plataforma oferece:

| Chave | Cliente | Status | Pagamento | Idade |
|---|---|---|---|---|
| `ped-001` | Ana | Entregue (`wc-completed`) | PIX | 24 dias |
| `ped-002` | João | Enviado (`wc-enviado`) | Transferência | 6 dias |
| `ped-003` | Marina | Em preparação (`wc-preparacao`) | Transferência | 3 dias |
| `ped-004` | Ana | Pagamento aprovado (`wc-processing`) | PIX | 2 dias |
| `ped-005` | João | Pedido realizado (`wc-pending`) | PIX | 1 dia |
| `ped-006` | Marina | Pagamento aprovado (`wc-processing`) | PIX | 1 dia |

O meio de cada pedido **não é escolha estética**: é sempre um que todas as lojas
daquele pedido aceitam, como o checkout exigiria de um comprador de verdade. O
`ped-002` sai em transferência porque a Moda Reconecta só declara conta
bancária; o `ped-006`, em PIX, porque o Ateliê Raízes só declara chave.

> **Cartão e boleto já estiveram nesta tabela**, e é por isso que a observação
> fica registrada: eram rótulos plausíveis de meios que a plataforma nunca
> ofereceu. Os gateways que existem são dois — `reconectar_pix` e
> `reconectar_transferencia` —, e um pedido de demonstração pago por um meio
> inexistente é exatamente o tipo de dado que a regra de honestidade do projeto
> proíbe.

`wc-preparacao` e `wc-enviado` são status autorais, registrados por
`class-reconectar-status-pedido.php`. O `ped-006` tem itens de **três lojas
diferentes**, e é o caso que demonstra o carrinho multi-vendedor: o Dokan o
divide em um sub-pedido por loja, cada qual visível apenas para o seu dono. É
também o pedido que demonstra a tela de agradecimento do pagamento direto, com
**três blocos de instrução** cujos valores são os dos sub-pedidos e somam o
total do pai.

As idades em dias existem para que a coluna de data do painel sirva para algo.
Um histórico em que tudo aconteceu hoje não se parece com uma loja em operação.

**2 campanhas** (CPT `reconectar_campanha`), para a faixa da home:

| Campanha | Vigência | Ordem | Na home |
|---|---|---|---|
| Feira da Safra | de 15 dias atrás a 45 dias à frente | 10 | **aparece** |
| Mutirão de Inverno | de 120 a 60 dias atrás | 20 | não aparece |

São duas de propósito, e a segunda é a que importa: uma campanha **já expirada**
é o que prova que a vigência funciona. Com só a vigente no banco, uma regressão
que ignorasse as datas passaria despercebida — a home continuaria certa, porque
não haveria nada de errado para aparecer.

As datas são **deslocamentos em dias a partir de hoje**, não datas absolutas.
Uma data fixa escrita no catálogo expiraria sozinha com o tempo, e a campanha
"vigente" da demonstração sumiria da home sem ninguém ter mexido em nada.

Ambas têm texto alternativo, que é campo obrigatório: banner é imagem com
função, e o `alt` descreve **o destino do link**, não a arte. E o `link` aponta
para caminho relativo — `/categorias/` e `/lojas/` —, nunca URL absoluta: a
instalação atende `localhost:8090` e o IP da máquina na rede, e um endereço
gravado com host manda o celular de volta para o próprio celular.

Despublicar a Feira da Safra faz a seção **sumir inteira** da home, sem título
nem moldura vazia. É a mesma regra de honestidade dos pedidos: campo vazio é
melhor que placeholder.

**4 categorias de fórum**, **6 perguntas**, **6 respostas** e **13 tags**. As
categorias são post types `forum` do bbPress e ficam ao lado do "Fórum Geral" e
do "Cooperação e parcerias", que o provisionamento cria — esses dois não são da
demonstração e não saem na remoção.

| Categoria | Perguntas |
|---|---|
| Produção e matéria-prima | 1 |
| Vendas e precificação | 2 |
| Entrega e logística | 2 |
| A plataforma | 1 |

**Quatro das seis têm melhor resposta marcada** e **duas ficam sem resposta
nenhuma**, de propósito: a aba "Sem resposta" precisa ter o que mostrar, e uma
listagem em que toda pergunta já foi respondida não se parece com uma
comunidade em atividade. Votos (de 1 a 5) e visualizações (de 23 a 184) são
declarados no catálogo e variam entre as perguntas para que as abas "Votos" e
"Recentes" produzam ordens visivelmente diferentes — com todos os saldos
iguais, não haveria como ver se a ordenação funciona.

O número de visualizações é o único desses valores que **muda depois da
carga**: o contador é real e sobe a cada leitura, uma vez por sessão e nunca
para o próprio autor. Se o banco mostrar 24 onde o catálogo declara 23, alguém
abriu a pergunta — é o contador funcionando, não divergência.

Os autores são **lojas e administradores de empresa**, nunca clientes: o
fórum é fechado a quem não participa da comunidade, e a ausência do cliente no
catálogo é parte da demonstração da regra. Os votos são atribuídos a eleitores
que não são o autor do conteúdo — ninguém vota em si mesmo, no catálogo como no
endpoint.

**7 páginas da Incubadora**, a wiki interna, todas assinadas pelo Moderador
(`demo-moderador`):

| Página | Mãe | Status |
|---|---|---|
| Comece por aqui | — | publicada |
| Guia do vendedor | — | publicada, com vídeo em destaque |
| Cadastrar um produto | Guia do vendedor | publicada |
| Receber por PIX | Guia do vendedor | publicada, com vídeo no texto |
| Governança | — | publicada |
| Como funcionam as enquetes | Governança | publicada |
| Atas e decisões | Governança | **rascunho** |

O rascunho é de propósito: é ele que mostra a regra de leitura. O Moderador o
vê na árvore com o selo "Rascunho"; a loja e o cliente não o veem e recebem 404
no endereço dele. Uma demonstração só com páginas publicadas não teria como
exibir essa diferença.

O texto das páginas descreve **só o que a plataforma faz de fato** — uma
página de ajuda que promete um recurso inexistente ensina errado quem a lê. E
ele passa pelo mesmo sanitizador do editor: o que a allowlist recusaria sai na
saída da carga como aviso, não em silêncio.

O vídeo de "Receber por PIX" é declarado em `dados-demo.php`, na chave `video`
do bloco `incubadora`, e foi escolhido pela equipe do projeto. Ele não se
inventa: um ID plausível de YouTube abriria o vídeo de um terceiro qualquer
dentro da plataforma. Com a chave vazia, a página sai sem vídeo e a carga
avisa. Na leitura, o vídeo é uma capa estática; o player do
`youtube-nocookie.com` só carrega no clique.

A mesma URL é o **vídeo em destaque** do "Guia do vendedor" — o player no topo
da página, acima do texto, que é como a moderação publica vídeo no dia a dia;
"Receber por PIX" mostra o outro formato, o vídeo dentro do texto, pelo editor.
O destaque é marcado com `destaque => true` no catálogo e aplicado **uma vez por
instalação**, inclusive em página que já existia: quem o remover pela interface
não o vê voltar na próxima carga, e página que já tinha vídeo fica com o dela.

**Lojas, clientes, Administradores e Moderador entram com a mesma senha**,
`reconectar-demo`, definida
em `demo.php` e substituível pela variável de ambiente
`RECONECTAR_DEMO_SENHA`. São contas de ambiente local, não credenciais de
sistema — antes elas recebiam senha aleatória, o que tornava impossível
demonstrar o painel do Dokan sem redefinir cada uma à mão. Os logins estão
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

A remoção apaga pedidos, produtos, campanhas, empresas, páginas da Incubadora,
usuários, arquivos de mídia, termos de categoria e avaliações — permanentemente, sem passar pela
lixeira. Ela é
restrita ao que carrega a meta `_reconectar_demo`, mas ainda assim é
irreversível, e por isso pede confirmação.

A ordem das operações importa e está implementada assim em
`scripts/seed/demo.php`: os pedidos saem primeiro, com `force delete` — um
pedido na lixeira continua contando nos relatórios do painel. Em seguida vem a
limpeza das tabelas paralelas do Dokan, **depois** dos pedidos e não antes,
porque ela decide pelo que sobrou: uma linha só é reconhecida como órfã quando
o pedido dela já não existe. Depois os produtos (cujos comentários e metas de
nota vão junto); então as referências de banner e avatar são limpas do
`dokan_profile_settings` de cada loja **antes** de os arquivos de mídia
serem apagados (caso contrário o perfil ficaria apontando para anexos
inexistentes); depois os anexos, os usuários — lojas, clientes, Administradores
e Moderador saem juntos, porque recebem a mesma meta —, as campanhas, as
empresas, os termos e, por fim, a opção `reconectar_demo_ativo`, o que faz a
faixa de aviso desaparecer.

Campanhas e empresas estão nessa lista **porque estão escritas nela**:
`reconectar_demo_remover()` enumera os post types um a um. Um tipo novo na carga
não sai sozinho, e o sintoma seria um registro de demonstração sobrevivendo a
uma remoção que se anunciou completa.

O fórum sai entre os produtos e os usuários, de baixo para cima: respostas,
depois perguntas, depois categorias. **Uma categoria com conteúdo que não é da
demonstração fica de pé**, ainda que ela própria tenha a meta: o bbPress apaga
em cascata o que estiver dentro de um fórum, e uma pergunta feita por alguém de
verdade durante a apresentação sairia junto sem nunca ter recebido a marcação.
Preservar um agrupador vazio de dado fictício custa uma linha no seletor;
apagar a pergunta de outra pessoa não tem desfazer.

As páginas da Incubadora saem **antes dos usuários**, por um motivo próprio: o
post type declara `delete_with_user => false`, e apagar o Moderador deixaria as
páginas no ar, assinadas por uma conta que não existe mais. A busca alcança
também a **lixeira** — é para lá que o botão Excluir da Incubadora manda a
página, e `post_status => any` não a inclui. Sem isso, uma página excluída pela
interface sobreviveria à remoção, e a carga seguinte criaria uma segunda com a
mesma chave. Uma página real criada **sob** uma da demonstração não sai junto:
`wp_delete_post()` sobe as filhas para a avó.

Quando não há terminal — num job, num `ssh` sem `-t` —, a confirmação não tem
como ser respondida, e `./scripts/seed-demo.sh remover` exige `-y`.

As tags do fórum saem pelo mesmo caminho das categorias de produto — a consulta
ao `termmeta` —, mas a taxonomia é lida do banco e não assumida:
`wp_delete_term()` com a taxonomia errada devolve `false` **em silêncio**, e o
sintoma seria uma coluna de tags que sobrevive à remoção e leva a listas vazias.

### As tabelas paralelas do Dokan

`wp_dokan_orders`, `wp_dokan_vendor_balance` e companhia guardam as vendas, e é
delas — não dos pedidos do WooCommerce — que o painel do Dokan lê o
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
