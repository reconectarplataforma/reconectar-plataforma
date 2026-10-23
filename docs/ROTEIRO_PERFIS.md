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
| Administrador (vinculado à Nosso Chão) | `demo-admin-nosso-chao` | `reconectar-demo` |
| Administrador (vinculado à Bem Viver) | `demo-admin-bem-viver` | `reconectar-demo` |
| Administrador (sem vínculo próprio) | `demo-admin-rede` | `reconectar-demo` |
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
não aparece** — ela está oculta no site para todos os perfis.

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

### 1.6 A Incubadora — Ana não entra

O menu de Ana não tem **Incubadora**: ela é módulo da Loja, do Administrador e
do Moderador, e o cliente compra. Ainda logada como Ana, digite
http://localhost:8090/incubadora/ à mão: `302` para a home. O mesmo vale para
qualquer página, busca, histórico ou arquivo da Incubadora.

Saia da conta e abra o mesmo endereço: `302` para o login. A Incubadora é
interna — a página sai com `noindex`, e nenhuma URL dela responde a quem não
tem o módulo.

Quem lê sem editar é a Loja: entre como `demo-sabor-da-terra` e abra o mesmo
endereço. A âncora leva à primeira página da árvore, "Comece por aqui". Não há
botão **Editar**, **Nova subpágina**, **Mover…** nem **Histórico**: quem não
tem `reconectar_gerir_incubadora` recebe só a leitura, e o script do editor nem
chega ao navegador.

---

# Roteiro 2 — Vendedor

**Perfil:** `demo-sabor-da-terra` · `reconectar-demo`
**Duração:** ~10 min

Uma loja própria, completa e isolada.

### 2.1 O painel

Entre. O login leva à tela de módulos, http://localhost:8090/modulos/, onde a
loja tem quatro cartões — Mercado (a vitrine dela), Incubadora, Praça e
**Painel**. O Painel abre http://localhost:8090/dashboard/ — o painel do Dokan, no
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
perfil cadastra empresas e lojas, configura as senhas das contas de loja,
modera conteúdo — e **não entra no `/wp-admin`, não instala plugin, não troca
tema, não edita arquivo e não atualiza o núcleo**.

> **O que mudou desde a versão anterior deste roteiro.** Ele se chamava
> "Administrador de Empresas" e não entrava no `/wp-admin`. A partir da versão
> 10 das capacidades, continua sem entrar: o painel técnico ficou só com o
> Super Administrador. O que o Administrador fazia no `/wp-admin` — enquetes,
> fóruns, campanhas, moderação — passa a ter tela própria em `/gestao/<area>/`,
> na moldura do painel do Dokan.

> **Duas palavras para a mesma conta.** Deste roteiro em diante, o painel de
> empresas chama de **Loja** a conta que o WordPress registra com o papel
> `seller`: a empresa é a Reconectar Incubadora Digital, e cada loja é um
> negócio que ela cadastra. O Roteiro 2 continua dizendo "Vendedor" porque
> descreve o painel do **Dokan**, onde o nome vem de fora.

### 3.1 O painel

Entre. O login leva à tela de módulos, http://localhost:8090/modulos/, e o
cartão **Mercado** dela abre http://localhost:8090/painel-empresas/. O atalho **Painel de
Empresas** também aparece no cabeçalho do site, no lugar onde o vendedor vê o
link para o painel dele.

A tela abre **na moldura do painel do Dokan**: a barra lateral à esquerda e a
barra do topo, as mesmas que a loja vê. A barra lateral traz **Módulos**,
**Empresas**, **Lojas**, **Comunidade**, **Fórum**, **Incubadora** e as telas de
gestão da seção 3.9 — nenhum item de venda —, e marca a seção corrente. No topo, "Minha conta" leva à conta dela no site, e "Visitar loja",
que para quem não é loja não teria destino, leva ao catálogo.

A rota, porém, continua nossa: o que vem do Dokan é só a casca. O painel dele é
intransponível sem a capacidade `dokandar`, que para o plugin **define** quem é
vendedor; concedê-la ao Administrador o transformaria em lojista aos olhos do
Dokan e o faria herdar em silêncio tudo que o plugin liberar no futuro. Teresa
não tem `dokandar` — `/dashboard/` continua respondendo 302 para ela. Sem o
Dokan ativo, o painel volta ao menu próprio de duas seções.

Na listagem, Teresa vê **as duas empresas**: todo Administrador alcança todas
as empresas e todas as lojas da plataforma, inclusive loja sem empresa. O
vínculo dela à Nosso Chão continua gravado, mas não restringe mais nada.

No topo, três contagens: **Empresas**, **Lojas** e **Lojas em operação**. As
três saem de consulta ao escopo dela, no carregamento. Não há faturamento
agregado ali de propósito — somá-lo exigiria percorrer os ganhos de cada loja a
cada abertura da tela, e um número estimado para preencher o espaço seria pior
que espaço nenhum.

### 3.1.1 A listagem de lojas

**Barra lateral → Lojas**, ou `/painel-empresas/loja/`. Todas as lojas da
plataforma — inclusive as que não pertencem a empresa nenhuma, que aparecem
como "Sem empresa" —, com a empresa de cada uma, os produtos
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

#### 3.3.1 Enviar link de redefinição de senha

Na ficha, há um botão **Enviar link de redefinição de senha**, com o endereço de
destino escrito embaixo. Clique nele. O que a tela responde depende do servidor
de e-mail, e ela diz qual dos dois casos aconteceu:

- **O servidor aceitou o envio:** "O servidor de e-mail aceitou o envio do link
  de redefinição de senha para o endereço cadastrado da loja." Nenhum link
  aparece na tela — ele está no e-mail.
- **O envio falhou:** "O e-mail com o link de redefinição de senha não pôde ser
  enviado. Repasse o link abaixo à pessoa responsável pela loja." O link aparece
  **uma vez**, e some ao recarregar.

"Aceitou o envio" é o que o servidor sabe dizer, não "e-mail entregue": os
endereços da demonstração são `@exemplo.invalid`, e com SMTP ligado o envio é
aceito e a mensagem volta como devolução. A senha atual continua valendo até a
pessoa definir outra.

O botão só alcança a loja que o Administrador pode gerir
(`Reconectar_Empresa::pode_gerir_loja()`), e essa conferência vem antes do
envio.

#### 3.3.2 Dados de operação

Tente editar um produto: **não há como**. Não é botão escondido — o alcance
decidido para este ator é **consulta**. Ele acompanha a operação, quem conduz a
loja é ela mesma, pelo painel do Dokan.

### 3.4 Cadastrar uma empresa

**Empresas → Nova empresa.** Preencha nome, CNPJ, razão social, contato,
município e responsável. Salve.

A empresa nova aparece na listagem, e quem a cria fica vinculado a ela. O
vínculo não restringe mais o alcance — todo Administrador vê todas as empresas
—, mas continua gravado: é o registro de quem responde por cada uma.

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

### 3.7 O alcance sobre todas as empresas

Na barra de endereços, troque o ID da empresa pelo da Rede Bem Viver — a que
Teresa **não** tem vinculada:

```bash
docker compose run --rm wpcli wp eval '$p=get_page_by_path("bem-viver",OBJECT,"reconectar_empresa"); echo home_url("/painel-empresas/empresa/{$p->ID}/"), "\n";'
```

A ficha abre normalmente. Até a versão 8 das capacidades a resposta era **403**:
o Administrador só enxergava as empresas a que estava vinculado, e o alcance
total era uma capacidade dada a dedo (`reconectar_gerir_todas_as_empresas`, a da
Clara). Hoje ela faz parte do papel `company_admin`, e as três contas de
Administrador veem a mesma tela. A capacidade segue separada do portão do
painel: o Moderador não a tem e continua recebendo 403 em `/painel-empresas/`.

### 3.8 Os limites — o que Teresa **não** consegue

| URL | Resultado medido |
| --- | --- |
| `/painel-empresas/` | `200` |
| `/painel-empresas/loja/` | `200` |
| `/painel-empresas/empresa/<Nosso Chão>/` | `200` |
| `/painel-empresas/empresa/<Bem Viver>/` | `200` — alcança todas, veja 3.7 |
| `/gestao/enquetes/` | `200` |
| `/gestao/foruns/` | `200` |
| `/gestao/campanhas/` | `200` |
| `/gestao/moderacao/` | `200` |
| `/comunidade/` | `302` → `/activity/` |
| `/wp-admin/` | `302` → `/modulos/` |
| `/wp-admin/users.php` | `302` → `/modulos/` |
| `/wp-admin/upload.php` | `302` → `/modulos/` |
| `/wp-admin/edit.php` | `403` |
| `/wp-admin/post-new.php?post_type=page` | `403` |
| `/wp-admin/nav-menus.php` | `403` |
| `/wp-admin/widgets.php` | `403` |
| `/wp-admin/edit-comments.php` | `403` |
| `/wp-admin/plugins.php` | `403` |
| `/wp-admin/theme-editor.php` | `403` |
| `/wp-admin/options-general.php` | `403` |
| `/wp-admin/edit.php?post_type=product` | `403` |
| `/wp-admin/edit.php?post_type=forum` | `302` → `/modulos/` |
| `/wp-admin/edit.php?post_type=topic` | `302` → `/modulos/` |
| `/wp-admin/user-edit.php?user_id=<loja>` | `302` → `/modulos/` |
| `/dashboard/` | `302` — não é vendedor |

E o `/painel-empresas/` responde **403** para o cliente e para quem toca uma
loja: vender não é administrar a empresa. Vale também para a listagem de lojas —
`/painel-empresas/loja/` é do painel gerencial, e uma loja não lista as outras.

**Os dois códigos de negação acima não são incoerência.** As telas que
`menu.php` reprova por capacidade — posts, menus, rodapé, comentários — morrem
num `wp_die( …, 403 )` **antes** de o portão rodar: o 403 prova que a
capacidade saiu do papel (desde a versão 10 das capacidades, o Administrador não
tem nenhuma delas). As que o papel ainda alcançaria — usuários, mídia — passam
por ali e saem em 302 para `/modulos/`: é o que prova que o portão de
admissão está de pé. Esperar um código só nas duas listas daria falha onde não
há, ou um verde que não mede o portão.

As capacidades por trás disso, sem passar por URL nenhuma:

```bash
docker compose run --rm wpcli wp eval '$u=get_user_by("login","demo-admin-nosso-chao"); wp_set_current_user($u->ID); foreach(array("manage_options","manage_woocommerce","dokandar","activate_plugins","install_plugins","switch_themes","install_themes","edit_themes","edit_files","update_core") as $c){printf("%-20s %s\n",$c,current_user_can($c)?"CONCEDIDA (FALHA)":"negada (ok)");}'
```

Todas negadas. `reconectar_acessar_wp_admin` — a capacidade que autoriza a
entrada no `/wp-admin` — também não está aqui de propósito: foi revogada na
versão 10, porque o painel técnico ficou só com o Super Administrador. O que
o Administrador fazia ali cabe agora em telas que a plataforma desenhou para
ele.

### 3.9 As telas de gestão — `/gestao/<area>/`

A operação — enquetes, fóruns, campanhas, moderação — deixou de viver no
`/wp-admin` e ganhou telas próprias em `/gestao/`, na moldura do painel do
Dokan. Teresa acessa cada uma por:

- **Enquetes:** http://localhost:8090/gestao/enquetes/
- **Fóruns:** http://localhost:8090/gestao/foruns/
- **Campanhas:** http://localhost:8090/gestao/campanhas/
- **Moderação:** http://localhost:8090/gestao/moderacao/

Ou pelos itens da barra lateral: Módulos, Empresas, Lojas, Comunidade, Fórum,
Incubadora, Enquetes (com o subitem Categorias do fórum), Campanhas e Moderação.
Cada item declara a capacidade da tela a que leva, e a barra só mostra o que o
perfil alcança.

#### 3.9.1 A moldura

A moldura é a casca visual do painel do Dokan — a barra lateral à esquerda, a
barra de topo com o nome do módulo. O que vem dentro é autoral: a plataforma
desenhou cada tela segundo suas necessidades, e não herda nada do painel do
vendedor além da casca e do portão. É por isso que o painel técnico do
WordPress não aparece em lado nenhum — o portão nunca abre para Teresa.

#### 3.9.2 Por que não no `/wp-admin`

A página do Dokan barra quem não tem `dokandar`, e conceder essa capacidade ao
Administrador o faria herdar, em silêncio, tudo que o plugin liberar por ela
daqui em diante. Teresa não tem `dokandar` — `/dashboard/` continua respondendo
302 para ela. É o mesmo motivo que a barra lateral não traz ícone nem link de
venda: a operação está numa moldura emprestada, o operador não é vendedor, e o
painel técnico da plataforma virou tela nova de propósito.

### 3.10 Conferir as travas

Abra as URLs da tabela 3.8, uma a uma. Duas famílias de resposta:

1. **Tela que o papel não alcança por capacidade** (posts, páginas, menus,
   rodapé, comentários, plugins) → `403`, vindo de `wp-admin/includes/menu.php`.
   A capacidade não existe no papel do Administrador desde a versão 10.
2. **Tela que o papel ainda alcançaria** (usuários, mídia, fórum, a ficha de
   uma conta) → `302` para `/modulos/`. É o portão
   `bloquear_area_administrativa()`, que só deixa passar quem tem
   `reconectar_acessar_wp_admin` — hoje, só o Super Administrador.

A escalada é o caso que não se confere por leitura de código. Abra
`/wp-admin/user-edit.php?user_id=<id do Super Administrador>`: `302` para
`/modulos/`, sem chegar à ficha. Trocar a senha de outra conta não tem tela —
a única que o Administrador alcança é o envio de link da seção 3.3.1, e só para
lojas no escopo dele. O teste de mesa equivalente está em
[PERFIS_E_PERMISSOES.md](PERFIS_E_PERMISSOES.md).

---

# Roteiro 4 — Moderador de Conteúdo

**Perfil:** `demo-moderador` · `reconectar-demo`
**Duração:** ~6 min

O perfil mais novo da plataforma: ele trabalha na plataforma — conteúdo,
comunidade, fórum, campanhas, enquetes — e **não entra no `/wp-admin`, não
toca em loja, em empresa, em produto, em pedido nem em conta de usuário**. E
**não instala plugin, não troca tema, não edita arquivo e não atualiza o
núcleo**.

Desde a versão 10 das capacidades, assim como o Administrador, o Moderador
não entra no `/wp-admin`. O que ele faz tem tela própria em `/gestao/`.

### 4.1 As telas de gestão — `/gestao/<area>/`

O login leva à tela de módulos, http://localhost:8090/modulos/, com três
cartões: Mercado, Incubadora e Praça. As telas de gestão estão na barra lateral
da moldura — Enquetes (com o subitem Categorias do fórum), Campanhas e
Moderação —, ou direto pelo endereço:

- **Enquetes:** http://localhost:8090/gestao/enquetes/
- **Categorias do fórum:** http://localhost:8090/gestao/foruns/
- **Campanhas:** http://localhost:8090/gestao/campanhas/
- **Moderação:** http://localhost:8090/gestao/moderacao/

`/gestao/` sem área não é tela: responde 404.

#### 4.1.1 A moldura

A moldura é a casca visual do painel do Dokan — a barra lateral à esquerda e a
barra de topo. O que vem dentro é autoral. A barra do Moderador traz Módulos,
Comunidade, Fórum, Incubadora, Enquetes, Campanhas e Moderação; não traz
Empresas nem Lojas, que pedem o portão do painel de empresas, nem item de venda.
E não há atalho para o `/wp-admin` em lado nenhum.

### 4.2 Campanhas e enquetes

Clique em **Campanhas** na barra lateral — http://localhost:8090/gestao/campanhas/. Há
duas campanhas, e o par é proposital:

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

As enquetes vivem em **Enquetes**, na mesma barra — http://localhost:8090/gestao/enquetes/.

### 4.3 Fórum — administração e moderação

Em **Enquetes → Categorias do fórum** — http://localhost:8090/gestao/foruns/ —
o Moderador cria, edita, fecha e reabre as categorias. Não há exclusão: uma
categoria com tópicos os levaria junto, e ela é só fechada.

A moderação de tópicos e respostas acontece em dois lugares. No próprio tópico,
o menu **Moderar** traz fechar, fixar, spam, lixeira e editar para quem tem o
direito. Em **Moderação** — http://localhost:8090/gestao/moderacao/ — fica a
fila: avaliações de produto e o que está pendente ou em spam no fórum.

A trava do bbPress que precisou ser contornada — `edit_forums` reservado ao
`keep_gate` — está em [PERFIS_E_PERMISSOES.md](PERFIS_E_PERMISSOES.md). O limite: `keep_gate` não é concedido, então as
**Configurações do bbPress** e a ferramenta de **redefinição** (que apagaria o
fórum inteiro da instalação) respondem **403** na tentativa de URL direta.

### 4.4 Os limites — o que o Moderador **não** consegue

| URL | Resultado medido |
| --- | --- |
| `/wp-admin/` | `302` → `/modulos/` |
| `/wp-admin/nav-menus.php` | `403` |
| `/wp-admin/widgets.php` | `403` |
| `/wp-admin/edit.php` | `403` |
| `/wp-admin/post-new.php?post_type=page` | `403` |
| `/wp-admin/edit-comments.php` | `403` |
| `/wp-admin/edit.php?post_type=reconectar_campanha` | `302` → `/modulos/` |
| `/wp-admin/users.php` | `302` → `/modulos/` |
| `/wp-admin/edit.php?post_type=forum` | `302` → `/modulos/` |
| `/wp-admin/edit.php?post_type=topic` | `302` → `/modulos/` |
| `/wp-admin/edit.php?post_type=product` | `403` |
| `/wp-admin/plugins.php` | `403` |
| `/wp-admin/theme-editor.php` | `403` |
| `/wp-admin/options-general.php` | `403` |
| `/painel-empresas/` | `403` |
| `/dashboard/` | `302` → `/modulos/` |

**Os dois códigos de negação acima não são incoerência.** As telas que
`menu.php` reprova por capacidade — menus, rodapé, comentários — morrem num
`wp_die( …, 403 )` **antes** de o portão rodar: o 403 prova que a capacidade
saiu do papel (desde a versão 10 das capacidades, o Moderador não tem nenhuma
delas). As que o papel ainda alcançaria — campanhas, fórum, usuários — passam
por ali e saem em 302 para `/modulos/`: é o que prova que o portão de
admissão está de pé. Esperar um código só nas duas listas daria falha onde não
há, ou um verde que não mede o portão.

O princípio em uma frase: **o Moderador trabalha nas telas que a plataforma
desenhou para ele — `/gestao/`, Incubadora, Comunidade, Fórum — e nunca no
painel técnico do WordPress.**

### 4.5 A Incubadora — escrever sem entrar no `/wp-admin`

A Incubadora é a wiki interna da plataforma, e tudo nela acontece no próprio
site. Abra http://localhost:8090/incubadora/.

**A árvore.** Na lateral, as sete páginas da demonstração, com "Atas e
decisões" marcada **Rascunho** sob Governança. Ela existe para mostrar a regra
de leitura: copie o endereço dela, entre como Ana (Roteiro 1) e abra — **404**.
O cliente e a loja não veem o rascunho na árvore, nem na busca, nem pelo
endereço.

**Editar.** Em "Receber por PIX", clique em **Editar**. O editor abre sobre a
própria página, sem tela de prévia: o que se vê enquanto se edita é o que será
publicado. Mude uma frase e clique em **Atualizar**; a linha "Editado há…" sobe
para agora. **Fechar** com alteração pendente pede confirmação. `Ctrl+S` também
salva.

**Vídeo.** O botão **Inserir vídeo** aceita link do YouTube ou do Vimeo. Na
leitura, o vídeo é uma capa estática com botão de play: nada é pedido ao
provedor antes do clique, e o player sai de `youtube-nocookie.com`. Dentro do
editor o player é vivo — é a única situação em que abrir a página já contata o
provedor.

**O que o editor recusa.** Cole um `<iframe>` de outro site, um `<script>` ou
uma imagem de outro domínio: ao salvar, o trecho sai e o aviso "Parte do
conteúdo foi ajustada ao salvar" diz o quê. A recusa é do servidor, não do
editor — o Moderador não tem `unfiltered_html`, e o conteúdo é reconstruído a
partir de uma lista fechada de elementos.

**Imagem e PDF.** Arraste ou cole uma imagem no editor, ou use **Anexar imagem
ou PDF**. O PDF entra como link. O arquivo vai para uma pasta fora da biblioteca de mídia, com nome aleatório,
servida só para quem está logado; a imagem perde os metadados (EXIF) e é
reduzida a 2000 px. SVG é recusado, e o limite efetivo é o do servidor — **2 MB**
no container de desenvolvimento.

**Organizar.** **Nova subpágina** cria uma filha da página aberta, como
rascunho. Arraste uma página na árvore para antes, para dentro ou para depois
de outra; pelo teclado, **Mover…** abre um diálogo com a mãe e a posição. As
subpáginas vão junto, e o link copiado por **Copiar link** continua valendo
depois da mudança.

**Histórico.** Em **Histórico** estão as versões gravadas, com autor e data.
Abra uma antiga e use **Restaurar esta versão**: o estado de agora vira uma
versão nova antes de ser substituído, então restaurar nunca perde nada.

**Conflito.** Abra a mesma página em duas abas, edite e salve na primeira, e
depois salve na segunda. A segunda recebe o aviso de que a página mudou e a
opção **Salvar por cima da versão nova** — nada é sobrescrito sem que alguém
escolha.

**Buscar.** A busca da lateral não distingue acento nem maiúscula: "pix" acha
"PIX". O Moderador acha o rascunho; Ana, com a mesma palavra, não acha.

O Administrador e o Super Administrador fazem o mesmo. A loja lê, como Ana.

---

# Roteiro 5 — Super Administrador

**Perfil:** `admin` · `reconectar-admin`
**Duração:** ~7 min

Visão e controle sobre a plataforma inteira.

### 5.1 O painel

Entre. O login leva à tela de módulos, como nos outros perfis de trabalho, e o
quarto cartão, **Painel Admin**, abre http://localhost:8090/wp-admin/. Só este
perfil o recebe.

### 5.2 Visão total

| Onde | O que confirmar |
| --- | --- |
| **Produtos** | os 15 da plataforma, de todas as lojas |
| **WooCommerce → Pedidos** | todos os pedidos, de todas as lojas |
| **Dokan → Vendedores** | as 5 lojas — o menu é do plugin, e o nome vem com ele |
| **Empresas** | as 2 empresas, com suas lojas vinculadas |
| **Usuários** | admin, 3 Administradores, 1 Moderador, 5 contas de loja, 3 clientes |

O contraste com os roteiros 2 e 3 é o ponto: o vendedor via 3 produtos, Teresa
via as empresas e as lojas sem ver produto nem pedido (fora do painel técnico),
o Super Administrador vê os 15 produtos e as duas empresas. E só ele chega a
**estas** telas — o Administrador e o Moderador não entram no painel técnico
(seção 5.4).

### 5.3 O fluxo de status

Abra um pedido. No seletor de status aparecem **"Em preparação"** e
**"Enviado"**, entre os nativos do WooCommerce. Confira também nas **ações em
massa** da listagem.

### 5.4 O `/wp-admin` — exclusivo do Super Administrador

Desde a versão 10 das capacidades, o painel técnico é só do Super Administrador.
Administrador e Moderador não entram — um redirecionamento os devolve a
`/modulos/` (a tela de escolha de módulos).

**Gestão de plugins.** Vá em **Plugins**. Ativos: WooCommerce, Dokan Lite,
BuddyPress, bbPress e Reconectar Core.

Esta tela é inacessível a qualquer outro perfil. É ela que dá nome ao
princípio: quem administra a operação não administra a tecnologia.

**Gestão de temas, usuários e configurações.** Em **Aparência**, o Super
Administrador gerencia todos os temas da instalação — ativa, desativa, edita
arquivos, instala novos. Em **Usuários**, lê todas as contas e edita qualquer
uma, inclusive a sua própria. Em **Configurações**, acessa o painel geral do
WordPress — aqui moram as variáveis da plataforma que o `provision.sh` grava.

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
content_moderator  manage_options=nao manage_woocommerce=nao wp_admin=nao comunidade=SIM total=16
company_admin      manage_options=nao manage_woocommerce=nao wp_admin=nao comunidade=SIM total=21
administrator      manage_options=SIM manage_woocommerce=SIM wp_admin=SIM comunidade=SIM total=169
```

As duas primeiras colunas do `seller` são o que sustenta o isolamento inteiro:
as travas liberam quem tem `manage_woocommerce`, e ele não tem. Se um plugin
novo conceder essa capacidade ao vendedor, tudo cai — sem erro e sem aviso.

A coluna `wp_admin` é a leitura mais importante da tabela, e mudou na versão
10. O Administrador e o Moderador **não têm `reconectar_acessar_wp_admin` mais**
— a capacidade foi revogada porque o painel técnico virou exclusividade do Super
Administrador, e o que eles faziam lá ganhou tela própria em `/gestao/`. O
número total de capacidades caiu para os dois porque saíram as primitivas de
post, página, menu, rodapé, comentário e usuário. A diminuição de sete capacidades
do Moderador (de 31 para 24, com arredondamento na tela) é porque perdeu
`upload_files` junto — a mídia chega pelas telas de gestão.

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
dois perfis novos, o redirecionamento de `/wp-admin` para os dois perfis novos,
as telas que continuam fora do alcance deles e as travas da Incubadora —
leitura, escrita, histórico, busca e arquivos. São **512 casos**; o script sai
com status 1 se algum falhar. Rode antes de apresentar.

Isso cobre os itens 3, 4, 7, 8, 9, 12, 14, 15, 16, 17, 20, 23, 24, 25, 33 e 34 da tabela
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
| 14 | Administrador e Moderador não entram em `/wp-admin` | redirect para `/modulos/`, seções 3.8/3.10 e 4.4 |
| 15 | Administrador alcança todas as empresas | 200 na empresa sem vínculo |
| 16 | A barra lateral não traz venda ao Administrador | Empresas, Lojas, Comunidade e Fórum |
| 17 | Vendedor e cliente fora do painel de empresas | 403 em `/painel-empresas/` e em `/painel-empresas/loja/` |
| 18 | Cadastro de loja com link de senha | link exibido uma única vez, seção 3.5 |
| 19 | Cascata de desativação preserva o individual | seção 3.6 |
| 20 | Cliente não escreve no fórum | `publish_topics` negada, seção 2.7 |
| 21 | Fórum ordena de verdade pelas três abas | Votos põe a de saldo 5 no topo |
| 22 | Voto sem JavaScript, e sem votar em si | seção 2.7 |
| 23 | Administrador e Moderador trabalham em telas de `/gestao/` | enquetes, fóruns, campanhas, moderação, seções 3.9 e 4.1 |
| 24 | Moderador não tem `manage_options` | seções 4.1 e 5.6 |
| 25 | Administrador e Moderador administram o fórum, sem `keep_gate` | seções 3.9 e 4.3 |
| 26 | Escalada de privilégio negada | seção 3.10 |
| 27 | Campanha vigente aparece, expirada não | seção 4.2 |
| 28 | Sem campanha vigente a seção some inteira | seção 4.2 |
| 29 | Meio de pagamento é a interseção do carrinho | seção 7.4 |
| 30 | Loja sem meio cadastrado não vende, e o motivo aparece | seção 7.3 |
| 31 | Uma instrução de pagamento por loja, com o valor do sub-pedido | seção 7.5 |
| 32 | BR Code reconhecido por um app de banco real | seção 7.6 |
| 33 | Incubadora fechada ao visitante e ao cliente, sem edição para a loja | seção 1.6 |
| 34 | Rascunho da Incubadora invisível a quem só lê | 404 em "Atas e decisões", seção 4.5 |
| 35 | Editar, mover e restaurar sem o `/wp-admin` | seção 4.5 |
| 36 | Vídeo sem contato com o provedor antes do clique | seção 4.5 |

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
