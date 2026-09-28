# Roteiro de demonstração por perfil

Passo a passo para demonstrar a plataforma nos cinco perfis de acesso: Super
Administrador, Administrador, Moderador de Conteúdo, Vendedor e Usuário Comum.
Cada roteiro mostra o que o perfil **faz** e, logo em seguida, o que ele **não
consegue fazer** — porque num marketplace multi-vendedor o limite é tão parte
da entrega quanto a função.

Tempo total: cerca de 65 minutos. Cada roteiro é independente.

> **Dois nomes mudaram.** O que este documento chamava de "Administrador" é hoje
> o **Super Administrador** (papel `administrator`), e o "Administrador de
> Empresas" virou **Administrador** (papel `company_admin`, o mesmo de antes,
> com competências novas). As chaves gravadas no banco não mudaram: um roteiro
> antigo continua valendo, trocando só o nome na tela.

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
| http://localhost:8090/painel-empresas/ | painel do Administrador |
| http://localhost:8081 | phpMyAdmin |

**Use uma janela anônima por perfil**, ou faça logout entre os roteiros. Trocar
de usuário sem sair é a causa mais comum de "a permissão não está funcionando"
numa demonstração.

## Credenciais

| Perfil | Login | Senha |
| --- | --- | --- |
| Super Administrador | `admin` | `reconectar-admin` |
| Administrador — Nosso Chão | `demo-admin-nosso-chao` | `reconectar-demo` |
| Administrador — Bem Viver | `demo-admin-bem-viver` | `reconectar-demo` |
| Administrador — as duas | `demo-admin-rede` | `reconectar-demo` |
| Moderador de Conteúdo | `demo-moderador` | `reconectar-demo` |
| Loja — Sabor da Terra | `demo-sabor-da-terra` | `reconectar-demo` |
| Loja — Ateliê Raízes | `demo-atelie-raizes` | `reconectar-demo` |
| Loja — Moda Reconecta | `demo-moda-reconecta` | `reconectar-demo` |
| Loja — Casa Viva | `demo-casa-viva` | `reconectar-demo` |
| Loja — Bem Viver Natural | `demo-bem-viver` | `reconectar-demo` |
| Cliente — Ana | `demo-cliente-ana` | `reconectar-demo` |
| Cliente — João | `demo-cliente-joao` | `reconectar-demo` |
| Cliente — Marina | `demo-cliente-marina` | `reconectar-demo` |

As cinco contas de loja têm o papel `seller` do Dokan — é por isso que o
Roteiro 2, que demonstra o painel do plugin, continua se chamando "Vendedor". No
painel de empresas as mesmas contas aparecem como **Lojas**.

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

# Roteiro 3 — Administrador

**Perfil:** `demo-admin-nosso-chao` · `reconectar-demo`
**Duração:** ~12 min

Quem administra a operação não necessariamente administra a tecnologia. Este
perfil cadastra empresas e lojas, configura as contas de loja, modera conteúdo,
cria menus — e **não instala plugin, não troca tema, não edita arquivo e não
atualiza o núcleo**.

> **O que mudou desde a versão anterior deste roteiro.** Ele se chamava
> "Administrador de Empresas" e não entrava no `/wp-admin`; hoje entra, com uma
> capacidade própria (`reconectar_acessar_wp_admin`) e um painel podado. A seção
> 3.9 é nova e demonstra essa parte.

> **Duas palavras para a mesma conta.** Deste roteiro em diante, o painel de
> empresas chama de **Loja** a conta que o WordPress registra com o papel
> `seller`: a empresa é a Reconectar Incubadora Digital, e cada loja é um
> negócio que ela cadastra. O Roteiro 2 continua dizendo "Vendedor" porque
> descreve o painel do **Dokan**, onde o nome vem de fora.

### 3.1 O painel

Entre e vá para http://localhost:8090/painel-empresas/. O atalho **Painel de
Empresas** também aparece no cabeçalho do site, no lugar onde o vendedor vê o
link para o painel dele.

Não é o Dokan. É uma rota própria da aplicação, no visual do tema Reconectar,
com as mesmas cores e a mesma tipografia do resto da plataforma. O painel do
Dokan é intransponível sem a capacidade `dokandar`, que para o plugin **define**
quem é vendedor: concedê-la ao Administrador o transformaria em
lojista aos olhos do Dokan e o faria herdar em silêncio tudo que o plugin
liberar no futuro. Por isso a rota é nossa.

À esquerda há um **menu de duas seções** — Empresas e Lojas —, com o "Sair" no
rodapé dele. O menu marca a seção corrente com `aria-current="page"`, e em tela
estreita vira faixa horizontal rolável acima do conteúdo: não é um `<details>`
que abriria sozinho no celular.

Na listagem, Teresa vê **uma empresa**: a Cooperativa Nosso Chão. A Rede Bem
Viver existe, tem lojas e pedidos, e não aparece.

No topo, três contagens: **Empresas**, **Lojas** e **Lojas em operação**. As
três saem de consulta ao escopo dela, no carregamento. Não há faturamento
agregado ali de propósito — somá-lo exigiria percorrer os ganhos de cada loja a
cada abertura da tela, e um número estimado para preencher o espaço seria pior
que espaço nenhum.

### 3.1.1 A listagem de lojas

**Menu → Lojas**, ou `/painel-empresas/loja/`. Todas as lojas no escopo, de
todas as empresas que Teresa administra, com a empresa de cada uma, os produtos
publicados, os ganhos liberados e a situação. É o mesmo recorte da ficha da
empresa, visto pelo outro eixo: por loja em vez de por empresa.

Não há botão de cadastrar nesta tela, e a ausência é deliberada: uma loja nasce
vinculada a uma empresa, e o caminho de criação passa pela ficha dela — que é
onde o vínculo é conhecido.

### 3.2 A ficha da empresa

Abra a Cooperativa Nosso Chão. A tela reúne:

- os dados cadastrais — CNPJ, razão social, contato, município, responsável;
- as **três lojas** da empresa, com produtos publicados, ganhos liberados
  e situação;
- o botão de desativar a empresa inteira.

> A coluna chama-se **"Ganhos liberados"**, e não "Faturamento", porque é isso
> que o número é: o Dokan só soma os pedidos concluídos, descontadas as
> devoluções. Pedido em preparação ou a caminho ainda não entra na conta. É o
> mesmo número que a loja vê no painel do Dokan — os dois painéis leem a mesma
> fonte, e um rótulo que prometesse o total vendido estaria mentindo sobre um
> dado correto.

Na carga de demonstração, só a Sabor da Terra tem pedido concluído: **R$
79,80**. Os outros dois mostram R$ 0,00, com pedidos em andamento visíveis logo
abaixo. A diferença entre as duas colunas é a demonstração.

### 3.3 A ficha da loja

Clique em **Sabor da Terra**. A ficha mostra dados de contato, os produtos com
preço e estoque, os pedidos recentes e os ganhos liberados.

Tente editar um produto: **não há como**. Não é botão escondido — o alcance
decidido para este ator é **consulta**. Ele acompanha a operação, quem conduz a
loja é ela mesma, pelo painel do Dokan.

### 3.4 Cadastrar uma empresa

**Empresas → Nova empresa.** Preencha nome, CNPJ, razão social, contato,
município e responsável. Salve.

A empresa nova aparece na listagem **dele** — quem cria entra no próprio
escopo. Sem isso, o administrador cadastraria uma empresa e perderia o acesso a
ela no mesmo clique.

### 3.5 Cadastrar uma loja

Na ficha da empresa, **Nova loja**. Preencha nome da loja, login, e-mail e
contato. Salve.

A tela devolve um **link de definição de senha**, com o aviso de que ele aparece
uma única vez:

> Repasse este link à pessoa responsável pela loja. Ele aparece uma única vez e
> expira como qualquer link de redefinição de senha do WordPress.

O link define a senha de **uma pessoa**, e é por isso que a frase não diz
"repasse à loja": loja não recebe link nenhum.

Nenhum e-mail é enviado — os endereços da demonstração são `@exemplo.invalid` e
não há SMTP nesta instalação. A interface diz o que de fato aconteceu; anunciar
"e-mail enviado" quando nada saiu é o tipo de mentira que só se descobre quando
a pessoa liga perguntando pela senha.

A conta criada é um `seller` comum, com loja Dokan própria. O papel é
**literal no código**, nunca lido do formulário: é essa linha que impede um POST
forjado de criar um administrador.

### 3.6 A cascata da desativação

O teste que separa uma trava correta de uma plausível. Na ficha de uma loja
— digamos o Ateliê Raízes — clique em **desativar**. Ela sai de operação; as
outras duas continuam vendendo.

Agora desative a **empresa inteira**. Os três saem de operação.

Reative a empresa. E confira:

| Loja | Estado individual | Depois de reativar a empresa |
| --- | --- | --- |
| Sabor da Terra | ativo | **voltou a vender** |
| Ateliê Raízes | desativado no passo anterior | **continua fora de operação** |
| Moda Reconecta | ativo | **voltou a vender** |

Reativar a empresa **não liga todo mundo**: cada loja volta ao estado que
era dela. O estado individual e o estado da empresa são duas informações
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
| `/painel-empresas/loja/` | `200` |
| `/painel-empresas/empresa/<Nosso Chão>/` | `200` |
| `/painel-empresas/empresa/<Bem Viver>/` | `403` |
| `/comunidade/` | `200` |
| `/wp-admin/` | `200` — mas veja 3.9 |
| `/wp-admin/users.php` | `200` |
| `/wp-admin/nav-menus.php` | `200` |
| `/wp-admin/plugins.php` | `403` |
| `/wp-admin/theme-editor.php` | `403` |
| `/wp-admin/options-general.php` | `403` |
| `/wp-admin/edit.php?post_type=product` | `403` |
| `/dashboard/` | `302` — não é vendedor |

E o `/painel-empresas/` responde **403** para o cliente e para quem toca uma
loja: vender não é administrar a empresa. Vale também para a listagem de lojas —
`/painel-empresas/loja/` é do painel gerencial, e uma loja não lista as outras.

As capacidades por trás disso, sem passar por URL nenhuma:

```bash
docker compose run --rm wpcli wp eval '$u=get_user_by("login","demo-admin-nosso-chao"); wp_set_current_user($u->ID); foreach(array("manage_options","manage_woocommerce","dokandar","activate_plugins","install_plugins","switch_themes","install_themes","edit_themes","edit_files","update_core") as $c){printf("%-20s %s\n",$c,current_user_can($c)?"CONCEDIDA (FALHA)":"negada (ok)");}'
```

Todas negadas. `edit_users`, `create_users` e `promote_users` ficaram **fora**
desta lista de propósito: elas são concedidas — é assim que ele configura as
contas de loja — e quem as limita é uma meta capacidade, com alvo, demonstrada
em 3.9.

A distinção entre as duas colunas da tabela importa: `plugins.php` responde 403
porque a capacidade não existe no papel; `/wp-admin/` responde 200 porque uma
capacidade autoral o autoriza, sem lhe dar nada do WooCommerce. Uma regressão
que concedesse `manage_woocommerce` ao papel abriria o painel técnico inteiro
sem que uma única URL mudasse de código, e é por isso que o
`verificar-acessos.sh` confere as duas coisas.

### 3.9 O painel administrativo — podado

Vá para http://localhost:8090/wp-admin/. O painel abre, e o menu lateral é a
demonstração. Medido, item por item:

| Item | Destino | Resposta |
| --- | --- | --- |
| Painel | `index.php` | `200` |
| Posts | `edit.php` | `200` |
| Mídia | `upload.php` | `200` |
| Páginas | `edit.php?post_type=page` | `200` |
| Campanhas | `edit.php?post_type=reconectar_campanha` | `200` |
| Comentários | `edit-comments.php` | `200` |
| Produtos | `admin.php?page=product-reviews` | `200` |
| Propostas de Votação | `edit.php?post_type=proposta_votacao` | `200` |
| Empresas | `edit.php?post_type=reconectar_empresa` | `200` |
| Fóruns | `edit.php?post_type=forum` | `200` |
| Tópicos | `edit.php?post_type=topic` | `200` |
| Respostas | `edit.php?post_type=reply` | `200` |
| Aparência | `themes.php` | `200` |
| Usuários | `users.php` | `200` |
| Ferramentas | `tools.php` | `200` |

Não há **Plugins**, não há **Configurações**, não há **WooCommerce** e não há
**Dokan** — as quatro entradas que carregariam a plataforma inteira.

> **"Produtos" não é o catálogo.** O item existe, mas aponta para
> `admin.php?page=product-reviews`: são as **avaliações** de produto, que o
> WooCommerce registra sob o menu Produtos e libera por `moderate_comments`.
> Moderar avaliação é moderação de conteúdo, e é por isso que o item fica. O
> catálogo em si — `edit.php?post_type=product` — responde **403**, e nenhum item
> de menu leva até lá. Quem vir "Produtos" na lateral e concluir que o perfil
> administra o catálogo terá lido o rótulo, não o destino.

**Ferramentas** abre em `200` porque `tools.php` é uma casca: a tela lista o que
o usuário pode usar, e para este perfil não sobra nada acionável. As telas de
verdade por trás dela — `export.php`, `import.php`, `site-health.php`,
`erase-personal-data.php`, `export-personal-data.php` — respondem todas **403**.

Em **Usuários**, Teresa lista e edita as contas de loja. Agora tente a escalada,
que é o caso que não se confere por leitura de código:

1. Abra a ficha do usuário `admin` — `/wp-admin/users.php`, clique no nome.
   A tela recusa: quem tem `edit_users` em single-site poderia trocar a senha de
   um `administrator` e entrar com a conta dele.
2. Na própria ficha dela, tente mudar o papel para **Super Administrador**. O
   seletor não oferece a opção; forçada pelo formulário, a ação é negada.
3. Abra a ficha de `demo-sabor-da-terra` e mude alguma coisa. **Funciona** — é
   o contorno que prova que o filtro não fechou o perfil inteiro.

Em **Aparência**, o submenu tem **Menus** (`nav-menus.php`, `200`) e não tem
Editor de Temas (`theme-editor.php`, `403`). A tela **Temas** abre, e abre de
propósito: `edit_theme_options` é a única capacidade que o WordPress oferece para
editar menus, e ela arrasta Temas, Personalizar, Widgets e Fontes junto — não há
granularidade menor no núcleo. Repare que os temas não têm botão de ativar nem de
excluir: sem `switch_themes` e sem `delete_themes`, a tela é de leitura.

> **`theme-install.php` responde `500`, não `403`, e a diferença não é defeito
> nosso.** As duas negações são do núcleo, em lugares diferentes. `plugins.php`
> morre em `wp-admin/includes/menu.php:384`, num `wp_die( …, 403 )` explícito,
> porque `user_can_access_admin_page()` reprova a página inteira. Já
> `theme-install.php` **passa** por esse portão — ele pendura em `themes.php`, que
> o perfil pode abrir — e só então bate na própria verificação do arquivo
> (`theme-install.php:16`), um `wp_die()` **sem argumento de status**; o padrão de
> `_default_wp_die_handler()` é 500. A tela diz "Sem permissão para instalar temas
> neste site" nos dois casos. Um script de verificação que espere 403 em toda
> negação vai acusar falha onde não há.

Em **Produtos** ou **Pedidos** digitados à mão
(`/wp-admin/edit.php?post_type=product`), a resposta é 403: quem administra o
produto é a loja dona dele.

Em **Fóruns**, **Tópicos** e **Respostas** o perfil administra: as três
listagens abrem em `200`, e `post-new.php?post_type=forum` também — "criar
fóruns" é literal na especificação. Os três links do widget **Em resumo** da
tela inicial levam a essas listagens.

> **Isso não veio de graça, e o defeito era do tipo mais caro deste
> repositório.** O bbPress mantém uma segunda camada de papéis — `bbp_keymaster`,
> `bbp_moderator`, `bbp_participant` — gravada como papel **adicional** do
> usuário, e ela se sobrepõe ao papel do WordPress. Os dois perfis novos nasciam
> com o `bbp_participant` que todo cadastro recebe, então as capacidades de
> moderação declaradas no papel eram escritas, aplicadas e perdidas adiante.
>
> E uma delas não se resolvia nem com o papel certo. Medido, com o papel
> declarando a capacidade:
>
> ```
> allcaps[edit_forums] = true
> map_meta_cap( 'edit_forums' ) = do_not_allow
> ```
>
> `bbp_map_forum_meta_caps()` reserva `edit_forums` e `edit_others_forums` a quem
> tem `keep_gate` — isto é, ao keymaster. `publish_forums`, essa o bbPress mapeia
> para `moderate` e passa; a assimetria é dele. Quem devolve as duas é
> `Reconectar_Permissoes::restaurar_gestao_de_foruns()`, em `map_meta_cap`
> **prioridade 11**, e só para quem o papel do WordPress já tinha autorizado.
>
> `keep_gate` continua fora de alcance, de propósito: ele abre as Configurações
> do bbPress e a ferramenta de **redefinição**, a que apaga fóruns, tópicos e
> respostas da instalação inteira. Confira —
> `options-general.php?page=bbpress` e `tools.php?page=bbp-repair` respondem
> **403** para os dois perfis.

---

# Roteiro 4 — Moderador de Conteúdo

**Perfil:** `demo-moderador` · `reconectar-demo`
**Duração:** ~6 min

O perfil mais novo da plataforma, e o de desenho mais delicado: ele entra no
`/wp-admin` — coisa que nem o Vendedor nem o Usuário Comum fazem — e ainda
assim não toca em nada da operação comercial nem da tecnologia. Conteúdo,
comunidade e campanha; nada além.

### 4.1 O painel — o que existe e o que não existe

Entre em http://localhost:8090/wp-admin/. Medido, item por item:

| Item | Destino | Resposta |
| --- | --- | --- |
| Painel | `index.php` | `200` |
| Posts | `edit.php` | `200` |
| Mídia | `upload.php` | `200` |
| Páginas | `edit.php?post_type=page` | `200` |
| Campanhas | `edit.php?post_type=reconectar_campanha` | `200` |
| Comentários | `edit-comments.php` | `200` |
| Produtos | `admin.php?page=product-reviews` | `200` |
| Propostas de Votação | `edit.php?post_type=proposta_votacao` | `200` |
| Fóruns | `edit.php?post_type=forum` | `200` |
| Tópicos | `edit.php?post_type=topic` | `200` |
| Respostas | `edit.php?post_type=reply` | `200` |
| Aparência | `themes.php` | `200` |
| Perfil | `profile.php` | `200` |
| Ferramentas | `tools.php` | `200` |

A comparação com a tabela do Roteiro 3 é a demonstração inteira: são as mesmas
entradas, **menos duas**. Onde o Administrador tem **Empresas**, o Moderador não
tem nada (`edit.php?post_type=reconectar_empresa` responde **403**); e onde o
Administrador tem **Usuários**, o Moderador tem **Perfil** — o item existe, mas
aponta para `profile.php`, a ficha dele mesmo. `users.php` e `user-new.php`
respondem **403**: moderar conteúdo não é gerir contas.

Vale aqui a mesma advertência do Roteiro 3: **"Produtos" não é o catálogo.** O
destino é `admin.php?page=product-reviews`, as avaliações, que entram por
`moderate_comments`. `edit.php?post_type=product` responde **403**.

### 4.2 Campanhas na tela inicial

Vá em **Campanhas**. Há duas, e o par é proposital:

| Campanha | Vigência | Na home |
| --- | --- | --- |
| Feira da Safra | 13/09/2026 a 12/11/2026 | **aparece** |
| Mutirão de Inverno | 31/05/2026 a 30/07/2026 | não aparece |

Abra a Feira da Safra. Os campos são imagem destacada, link de destino, início,
fim, ordem e **texto alternativo** — este último obrigatório, porque banner é
imagem com função e WCAG 2.1 é requisito do edital, não recomendação.

Agora abra http://localhost:8090/ numa aba anônima: a faixa de campanhas traz a
Feira da Safra e só ela. **É a vigência que justifica o post type** em vez de um
widget de HTML: a campanha encerrada sai da home sozinha, sem ninguém lembrar de
apagá-la.

Para ver a outra metade da regra, despublique a Feira da Safra e recarregue a
home: a seção **some inteira** — nem título, nem moldura vazia. Campo vazio é
melhor que placeholder; é a regra de honestidade de dados do projeto.

### 4.3 Conteúdo e comunidade

Em **Posts**, **Páginas** e **Comentários** o perfil publica, edita o que é dos
outros e modera — é o núcleo da função. Em **Fóruns**, **Tópicos** e
**Respostas** ele administra o Q&A: as três listagens abrem em `200` e
`post-new.php?post_type=forum` também.

A trava do bbPress que precisou ser contornada para isso está explicada em
detalhe na seção 3.9, e vale igual aqui. O limite também: `keep_gate` não é
concedido, então `options-general.php?page=bbpress` (Configurações do bbPress) e
`tools.php?page=bbp-repair` (a ferramenta de **redefinição**, que apagaria o
fórum inteiro da instalação) respondem **403**.

`/comunidade/` e `/forums/` abrem em `200` no front-end.

### 4.4 Menus

Em **Aparência → Menus** (`nav-menus.php`, `200`) o perfil cria e reordena menus
— pedido explícito da especificação.

> **Por que Aparência abre inteira.** `edit_theme_options` é a **única**
> capacidade que o WordPress oferece para editar menus, e ela vem grudada ao
> Customizer (`customize.php`, `200`) e aos Widgets (`widgets.php`, `200`). Não
> há granularidade menor no núcleo: ou o Moderador cria menus e alcança essas
> telas, ou não cria menus. A amplitude é do WordPress, não uma escolha nossa.
>
> O que **não** vem junto: **Editor de Temas** (`theme-editor.php`, `403`) e
> instalar tema (`theme-install.php`, `500` — ver a nota da seção 3.9 sobre por
> que esta negação sai com 500 e não 403). A tela **Temas** abre em leitura: sem
> `switch_themes` e sem `delete_themes`, não há botão de ativar nem de excluir.

### 4.5 Os limites — o que o Moderador **não** consegue

| Tentativa | URL | Resposta |
| --- | --- | --- |
| Instalar ou ativar plugin | `/wp-admin/plugins.php` | `403` |
| Editar arquivo de tema | `/wp-admin/theme-editor.php` | `403` |
| Instalar tema | `/wp-admin/theme-install.php` | `500` (ver 3.9) |
| Configurações do WordPress | `/wp-admin/options-general.php` | `403` |
| Catálogo de produtos | `/wp-admin/edit.php?post_type=product` | `403` |
| Pedidos do WooCommerce | `/wp-admin/admin.php?page=wc-orders` | `301` → `403` |
| Painel do Dokan | `/wp-admin/admin.php?page=dokan` | `403` |
| Gerir contas | `/wp-admin/users.php` | `403` |
| Criar conta | `/wp-admin/user-new.php` | `403` |
| Empresas | `/wp-admin/edit.php?post_type=reconectar_empresa` | `403` |
| Painel de empresas | `/painel-empresas/` | `403` |
| Painel do vendedor | `/dashboard/` | `302` para a home |
| Exportar conteúdo | `/wp-admin/export.php` | `403` |
| Saúde do site | `/wp-admin/site-health.php` | `403` |

> **O `301` dos pedidos não é exceção.** `admin.php?page=wc-orders` é o endereço
> do armazenamento em tabelas próprias (HPOS); com ele desligado nesta
> instalação, o WooCommerce redireciona para a tela clássica —
> `edit.php?post_type=shop_order` —, e é ali que a negação acontece. Seguindo o
> redirecionamento, a resposta final é **403**. Conferir só o primeiro código
> leria `301` como sucesso.

O princípio em uma frase: **o Moderador entra no painel para cuidar do que se
lê, nunca do que se vende nem do que se instala.**

---

# Roteiro 5 — Super Administrador

**Perfil:** `admin` · `reconectar-admin`
**Duração:** ~7 min

Visão e controle sobre a plataforma inteira.

### 5.1 O painel

Entre em http://localhost:8090/wp-admin/.

### 5.2 Visão total

| Onde | O que confirmar |
| --- | --- |
| **Produtos** | os 15 da plataforma, de todas as lojas |
| **WooCommerce → Pedidos** | todos os pedidos, de todas as lojas |
| **Dokan → Vendedores** | as 5 lojas — o menu é do plugin, e o nome vem com ele |
| **Empresas** | as 2 empresas, com suas lojas vinculadas |
| **Usuários** | admin, 3 Administradores, 1 Moderador, 5 contas de loja, 3 clientes |

O contraste com os roteiros 2 e 3 é o ponto: o vendedor via 3 produtos, Teresa
via as 3 lojas de uma empresa, o Super Administrador vê os 15 produtos e as duas
empresas. E só ele chega a **estas** telas — o Administrador e o Moderador entram
no painel, mas nenhum dos dois abre Produtos, Pedidos ou Dokan.

### 5.3 O fluxo de status

Abra um pedido. No seletor de status aparecem **"Em preparação"** e
**"Enviado"**, entre os nativos do WooCommerce. Confira também nas **ações em
massa** da listagem.

### 5.4 Gestão de plugins — exclusiva

Vá em **Plugins**. Ativos: WooCommerce, Dokan Lite, BuddyPress, bbPress e
Reconectar Core.

Esta tela é inacessível a qualquer outro perfil, o Administrador e o Moderador
de Conteúdo incluídos — os dois entram no `/wp-admin` e mesmo assim recebem
`403` aqui. É ela que dá nome ao princípio: quem administra a operação não
administra a tecnologia. A revogação é dupla: as capacidades são removidas do
papel **e** verificadas de novo no momento da decisão — porque uma capacidade
pode ser concedida direto a um usuário, ou por outro plugin.

### 5.5 Comunidade

Em **Fóruns** e nas telas do BuddyPress, o Super Administrador modera e
administra sem restrição — é o único com `keep_gate`, e por isso o único que
abre as Configurações do bbPress e a ferramenta de redefinição. O Administrador e
o Moderador administram fórum, tópico e resposta, mas não alcançam essas duas
telas; o Vendedor e o Usuário Comum apenas participam.

### 5.6 Conferir as capacidades

```bash
docker compose run --rm wpcli wp eval 'foreach(array("customer","seller","content_moderator","company_admin","administrator") as $p){$o=wp_roles()->get_role($p); $c=array_keys(array_filter($o->capabilities)); printf("%-18s manage_options=%s manage_woocommerce=%s wp_admin=%s comunidade=%s total=%d\n",$p,in_array("manage_options",$c,true)?"SIM":"nao",in_array("manage_woocommerce",$c,true)?"SIM":"nao",in_array("reconectar_acessar_wp_admin",$c,true)?"SIM":"nao",in_array("reconectar_participar_comunidade",$c,true)?"SIM":"nao",count($c));}'
```

Esperado:

```
customer           manage_options=nao manage_woocommerce=nao wp_admin=nao comunidade=nao total=1
seller             manage_options=nao manage_woocommerce=nao wp_admin=nao comunidade=SIM total=67
content_moderator  manage_options=nao manage_woocommerce=nao wp_admin=SIM comunidade=SIM total=31
company_admin      manage_options=nao manage_woocommerce=nao wp_admin=SIM comunidade=SIM total=40
administrator      manage_options=SIM manage_woocommerce=SIM wp_admin=SIM comunidade=SIM total=166
```

As duas primeiras colunas do `seller` são o que sustenta o isolamento inteiro:
as travas liberam quem tem `manage_woocommerce`, e ele não tem. Se um plugin
novo conceder essa capacidade ao vendedor, tudo cai — sem erro e sem aviso.

A coluna `wp_admin` é a leitura mais importante da tabela. Os dois perfis novos
entram no painel por `reconectar_acessar_wp_admin`, uma capacidade **autoral**,
e não por `manage_options`. A diferença não é cosmética: `manage_options` é
exatamente o que `restringir_gestao_da_tecnologia()` acrescenta ao conjunto
exigido para instalar plugin e editar tema. Dar `manage_options` a eles para que
o painel abrisse devolveria, pela mesma linha, a instalação de plugins — o
oposto do que a especificação pede. O alcance da capacidade nova é mínimo de
propósito: dois pontos do código, o portão do `/wp-admin` e a barra
administrativa.

`reconectar_gerir_todas_as_empresas` fica **fora** do papel `company_admin` de
propósito: é concedida usuário a usuário, como acontece com a Clara. Alcance
múltiplo é decisão de quem administra, não característica do cargo — e é por
isso que Teresa e Otávio, com o mesmo papel, veem empresas diferentes.

---

# Roteiro 6 — O carrinho multi-vendedor

**Duração:** ~3 min

O requisito mais específico da arquitetura, e o mais fácil de demonstrar.

A demonstração já traz o caso pronto: o pedido de **Marina**, no valor de
**R$ 157,70**, com **3 itens de vendedores diferentes**.

### 6.1 Como o cliente vê

Entre como `demo-cliente-marina` → **Minha conta → Pedidos**. Um pedido só, de
R$ 157,70. Foi uma compra, um checkout, um pagamento.

### 6.2 Como o vendedor vê

Entre como qualquer um dos vendedores envolvidos. No painel, aparece **apenas a
parte dele** — com o valor da sua fatia, não os R$ 157,70.

### 6.3 O que aconteceu por baixo

```bash
docker compose run --rm wpcli wp eval 'foreach(wc_get_orders(array("limit"=>-1,"status"=>"any")) as $o){printf("#%-5d pai=%-5d %-11s %8s itens=%d vendedor=%s\n",$o->get_id(),$o->get_parent_id(),$o->get_status(),$o->get_total(),count($o->get_items()),$o->get_meta("_dokan_vendor_id")?:"-");}'
```

Um pedido-pai com 3 itens e três sub-pedidos apontando para ele, cada um com o
seu `_dokan_vendor_id`. O split é feito por
`dokan()->order->maybe_split_orders()` no fechamento da compra.

**Um carrinho, um pagamento, um pedido para o cliente; um pedido por vendedor no
backend, cada qual com a sua associação item ↔ vendedor.**

---

# Roteiro 7 — Pagamento direto à loja

**Duração:** ~8 min

A plataforma **não toca no dinheiro**. O comprador paga a loja por PIX ou
transferência, com os dados que a própria loja cadastrou. Não há credencial de
provedor, não há split e não há confirmação automática — a loja confere o
recebimento e muda o status do pedido à mão, que é o que acontece de fato quando
alguém paga numa chave PIX pessoal. O que a plataforma faz é garantir que o meio
oferecido no checkout seja um que **todas** as lojas do carrinho aceitem, e
imprimir uma instrução por loja.

### 7.1 Onde a loja cadastra

Entre como `demo-sabor-da-terra` e vá em **Configurações → Pagamento** na
dashboard do Dokan (http://localhost:8090/dashboard/settings/payment). Há dois
métodos: **PIX** e **Conta bancária**.

O PIX pede tipo de chave, chave, nome do beneficiário e cidade. Os dois últimos
não são enfeite: são o que falta para montar o BR Code copia-e-cola.

> **O teste que importa aqui é o do nonce.** Salve **outra** aba de configurações
> — Loja, por exemplo — e volte para Pagamento. A chave PIX tem de continuar lá.
> O Dokan escreve `bank` e `paypal` à mão no salvamento e **descarta em silêncio**
> qualquer método que ele não conheça; o único gancho que alcança
> (`dokan_store_profile_settings_args`) dispara em *todos* os caminhos de
> salvamento do perfil. Sem a guarda de `wp_verify_nonce( …,
> 'dokan_payment_settings_nonce' )`, salvar a loja em outra aba apagaria os dados
> de pagamento — e o sintoma seria o pior deste repositório: o dado some, sem
> erro, numa tela que funcionou.

### 7.2 Quem aceita o quê

A demonstração distribui os meios de propósito, para que cada combinação
apareça pelo menos uma vez:

| Loja | PIX | Transferência |
| --- | --- | --- |
| Sabor da Terra | sim | sim |
| Ateliê Raízes | **sim** | não |
| Moda Reconecta | não | **sim** |
| Casa Viva | sim | sim |
| Bem Viver Natural | **não** | **não** |

**Bem Viver Natural não cadastrou meio nenhum, e isso não é esquecimento.** É a
loja que demonstra a consequência verdadeira de não dizer como receber: ela não
aparece em pedido algum na demonstração, porque o checkout teria recusado a
compra. Não vender é o resultado honesto — inventar um pedido para ela ensinaria
um fluxo que a plataforma não permite.

### 7.3 O checkout com uma loja só

Entre como `demo-cliente-ana`, ponha no carrinho um produto do **Ateliê Raízes**
e vá ao checkout. Aparece **só PIX** — a loja não declarou conta bancária.

Repita com um produto da **Moda Reconecta**: aparece **só Transferência
bancária**.

Agora ponha no carrinho um produto da **Bem Viver Natural**: nenhum dos dois é
oferecido, e a tela diz **qual** loja não pode receber. Não é uma lista vazia
sem explicação.

### 7.4 O checkout com duas lojas — a regra da interseção

Monte um carrinho com **Ateliê Raízes** (só PIX) e **Moda Reconecta** (só
transferência). A interseção é vazia, e é isso que o checkout informa.

Agora troque para **Sabor da Terra** + **Casa Viva**: as duas aceitam os dois
meios, e os dois aparecem.

`Reconectar_Gateway_Direto::is_available()` devolve `false` quando **nenhuma**
loja do carrinho tem o dado daquele método, e
`Reconectar_Gateway_Direto::validar_checkout()` acrescenta o erro
`reconectar_pagamento_indisponivel` quando alguma loja do carrinho não aceita o
meio escolhido. Oferecer um meio que metade do carrinho não recebe seria pedir
ao comprador que pagasse para quem não pode receber.

### 7.5 A tela de agradecimento — uma instrução por loja

Feche uma compra com produtos de **duas lojas diferentes** por PIX. A tela de
agradecimento imprime **um bloco por loja**, cada um com:

- o nome da loja;
- a chave PIX, o beneficiário e a cidade;
- o **valor do sub-pedido** daquela loja, não o total do carrinho;
- o BR Code copia-e-cola, quando disponível.

Os valores dos blocos somam o total do pedido-pai. **Um bloco só, com a soma,
reprova** — mandaria o comprador pagar tudo para uma das lojas. O mesmo conteúdo
vai no e-mail do pedido (`woocommerce_email_before_order_table`).

Confira também que a linha **"Método de pagamento"** do resumo concorda com o
bloco de instruções logo abaixo: um pedido gravado como PIX e instruções de
transferência é a contradição mais fácil de deixar passar.

### 7.6 O BR Code

O copia-e-cola é um payload EMV MPM estático montado em
`reconectar_pix_br_code()` — TLV mais CRC16-CCITT/FALSE, cálculo puro, sem
biblioteca, sem chamada de rede e sem etapa de compilação.

**A conferência que vale é colar o código no app de um banco real** e ver chave,
beneficiário e valor corretos. Um código que o banco recusa é pior que nenhum
código: se não passar, a entrega sai com os dados da chave em texto e sem o
copia-e-cola.

QR Code em imagem ficou fora de escopo de propósito — exigiria biblioteca nova,
e o copia-e-cola resolve o caso no celular.

### 7.7 O que este cenário **não** resolve

| Não faz | Por quê |
| --- | --- |
| Confirmação automática do pagamento | sem API de banco não há como; a loja confirma à mão |
| Split de comissão | o dinheiro não passa pela plataforma; a comissão do Dokan segue sendo cálculo contábil |
| Conciliação | mesma razão |
| Saque pela dashboard | o menu **Withdraw** do Dokan é ocultado: não há saldo retido, e o menu prometeria repasse inexistente |

Resolver os três primeiros de verdade exigiria Dokan Pro ou um provedor com
split. Está registrado em `docs/PAGAMENTOS.md` como decisão, não como pendência
esquecida.

---

## Checklist

As travas de acesso têm verificação automática:

```bash
./scripts/verificar-acessos.sh -v
```

Faz login de verdade nos cinco perfis, bate em cada URL restrita e compara o
código HTTP com o esperado — incluindo o isolamento entre lojas e entre
empresas, nas duas direções, a escrita no fórum, a administração do fórum pelos
dois perfis novos e as telas que continuam fora do alcance deles. São **127
casos**; o script sai com status 1 se algum falhar. Rode antes de apresentar.

Isso cobre os itens 3, 4, 7, 8, 9, 12, 15, 16, 17, 20, 23, 24 e 25 da tabela
abaixo. O restante é visual e precisa de olho humano:

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
| 11 | Super Admin vê tudo | 15 produtos, todos os pedidos, 5 lojas |
| 12 | Só o Super Admin gere plugins | tela de Plugins |
| 13 | Carrinho multi-vendedor com split | pedido de R$ 157,70 e seus 3 filhos |
| 14 | Administrador opera fora do `/wp-admin` | `/painel-empresas/` no visual do tema |
| 15 | Administrador isolado por empresa | 403 na empresa alheia |
| 16 | Alcance múltiplo é capacidade, não papel | `demo-admin-rede` vê as duas |
| 17 | Vendedor e cliente fora do painel de empresas | 403 em `/painel-empresas/` e em `/painel-empresas/loja/` |
| 18 | Cadastro de loja com link de senha | link exibido uma única vez |
| 19 | Cascata de desativação preserva o individual | seção 3.6 |
| 20 | Cliente não escreve no fórum | `publish_topics` negada, seção 2.7 |
| 21 | Fórum ordena de verdade pelas três abas | Votos põe a de saldo 5 no topo |
| 22 | Voto sem JavaScript, e sem votar em si | seção 2.7 |
| 23 | Moderador entra no painel sem `manage_options` | seções 4.1 e 5.6 |
| 24 | Moderador cria menus e não instala nada | `nav-menus.php` 200, `plugins.php` 403, seção 4.4 |
| 25 | Administrador e Moderador administram o fórum, sem `keep_gate` | seção 3.9 |
| 26 | Escalada de privilégio negada | seção 3.9, os três passos |
| 27 | Campanha vigente aparece, expirada não | seção 4.2 |
| 28 | Sem campanha vigente a seção some inteira | seção 4.2 |
| 29 | Meio de pagamento é a interseção do carrinho | seção 7.4 |
| 30 | Loja sem meio cadastrado não vende, e o motivo aparece | seção 7.3 |
| 31 | Uma instrução de pagamento por loja, com o valor do sub-pedido | seção 7.5 |
| 32 | BR Code reconhecido por um app de banco real | seção 7.6 |

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

**`/painel-empresas/empresa/<id>/` ou `/painel-empresas/loja/` dá 404.** As
subrotas são endpoints de rewrite e só existem depois que as regras são
regravadas. Vale também para quem atualizou o código de uma versão anterior: o
endpoint chamava-se `vendedor` e passou a ser `loja`. O provisionamento regrava
no fim; fora dele:

```bash
docker compose run --rm wpcli wp rewrite flush
```

**Ruído de SQL no log com `wp_bp_activity`.** BuddyPress está ativo sem as
tabelas criadas; o erro aparece ao remover usuários e **não** interrompe nada.

**Detalhes do conteúdo:** [DADOS_DEMONSTRACAO.md](DADOS_DEMONSTRACAO.md).
**Regras de permissão:** [PERFIS_E_PERMISSOES.md](PERFIS_E_PERMISSOES.md).
**Arquitetura:** [STACKS.md](STACKS.md).
