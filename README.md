# Reconectar — Incubadora Digital para Vínculos e Negócios

Plataforma digital do projeto "Reconectar", desenvolvida no âmbito do edital
FUNDEPES/UNOPS, para conectar pessoas, iniciativas e negócios locais por meio
de um marketplace multi-vendedor, comunidade colaborativa e mecanismos de
governança digital participativa.

Consulte [PLANO_DESENVOLVIMENTO.md](PLANO_DESENVOLVIMENTO.md) para o plano
completo (fases, cronograma do edital, indicadores),
[DIARIO_DESENVOLVIMENTO.md](DIARIO_DESENVOLVIMENTO.md) para o histórico
cronológico de cada etapa concluída, e
[docs/PAGAMENTOS.md](docs/PAGAMENTOS.md) para a estrutura do módulo de
pagamentos (Pix/Cartão/Boleto) e [docs/DEPLOY.md](docs/DEPLOY.md) para a esteira
de publicação em GitHub Actions.

## Stack técnica

- **WordPress** + **WooCommerce** — base de conteúdo e e-commerce.
- **Dokan Lite** — marketplace multi-vendedor (permite que cada
  empreendedor(a)/iniciativa local tenha sua própria loja na plataforma).
- **BuddyPress** + **bbPress** — módulo de comunidade e fóruns de discussão.
- **Tema `reconectar`** — child theme do Storefront (tema oficial
  WooCommerce), com a identidade visual do projeto.
- **Plugin `reconectar-core`** — código autoral do projeto: módulo de
  Governança Digital (propostas de votação, painel de transparência).
- **Pagamentos (Pix / Cartão / Boleto)** — módulo estruturado para receber um
  plugin de gateway (ex.: Mercado Pago ou Asaas) assim que a equipe do
  projeto definir o provedor. Nenhuma credencial real é usada nesta etapa.

## Licença

Este repositório é distribuído sob a licença **GPL-2.0-or-later** (ver
[LICENSE](LICENSE)), compatível com WordPress, WooCommerce e demais
dependências GPL do projeto, conforme exigido pelo edital para manter o
código-fonte aberto e livremente reutilizável.

O editor da Incubadora é o **TinyMCE 8.9.2**, auto-hospedado em
`wp-content/plugins/reconectar-core/assets/vendor/tinymce/8.9.2/` sob a mesma
GPL-2.0-or-later, com a tradução pt-BR do pacote `tinymce-i18n`. Origem,
somas de verificação, recorte e licença das bibliotecas embutidas estão no
`LEIAME.md`, no `license.md` e no `notices.txt` daquele diretório.

## Estrutura do repositório

```
reconectar-plataforma/
├── PLANO_DESENVOLVIMENTO.md   # plano completo do projeto
├── DIARIO_DESENVOLVIMENTO.md  # log cronológico de desenvolvimento
├── CLAUDE.md                  # instruções e armadilhas para agentes de código
├── docker-compose.yml         # ambiente local (WordPress + MariaDB + WP-CLI)
├── docs/
│   ├── STACKS.md              # as camadas da plataforma e por que cada uma existe
│   ├── PERFIS_E_PERMISSOES.md # os cinco atores e a matriz de permissões
│   ├── ROTEIRO_PERFIS.md      # roteiro de demonstração, com credenciais
│   ├── DADOS_DEMONSTRACAO.md  # o que a carga de demonstração cria
│   └── PAGAMENTOS.md          # meios de pagamento e o que falta decidir
├── scripts/
│   ├── provision.sh           # instala WordPress, plugins e páginas essenciais
│   ├── demo-completa.sh       # ambiente + provisionamento + carga, em um comando
│   ├── seed-demo.sh           # instala ou remove só os dados de demonstração
│   ├── verificar-acessos.sh   # testa as travas de permissão por HTTP
│   ├── verificar-incubadora.php # operações da Incubadora por WP-CLI (chamado pelo anterior)
│   ├── permissoes-dev.sh      # acerta dono e permissões de wp-content/
│   └── seed/                  # catálogo declarativo e motor da carga
└── wp-content/                 # único conteúdo de WordPress versionado
    ├── themes/
    │   └── reconectar/         # tema autoral (child theme do Storefront)
    └── plugins/
        └── reconectar-core/    # plugin autoral (governança digital)
```

Plugins e temas de terceiros (WooCommerce, Dokan Lite, BuddyPress, bbPress,
Storefront) **não são versionados** neste repositório — são instalados
automaticamente pelo `scripts/provision.sh` via WP-CLI. Apenas o código
autoral do projeto (tema `reconectar` e plugin `reconectar-core`) fica
rastreado no Git.

## Ambiente de desenvolvimento local

Pré-requisitos: **Docker** e **Docker Compose**.

Para ter a plataforma de pé **com conteúdo na tela**, um comando basta:

```bash
./scripts/demo-completa.sh
```

Ele sobe os contêineres, espera o núcleo do WordPress terminar de se instalar
no volume, provisiona (idioma, tema, plugins, páginas, permalinks) e popula a
base de demonstração. É idempotente: rodar de novo não duplica nada.

Se preferir só a instalação, vazia:

```bash
docker compose up -d
docker compose run --rm wpcli bash /var/www/scripts/provision.sh
```

Em qualquer dos dois caminhos:

- Site: http://localhost:8090
- Painel de administração: http://localhost:8090/wp-admin
- Painel do vendedor (Dokan): http://localhost:8090/dashboard
- phpMyAdmin (suporte ao banco de dados): http://localhost:8081

A porta é **8090**, e não a 8080 usual — esta estava ocupada na máquina de
desenvolvimento.

Os dados de demonstração são fictícios e se identificam como tais: enquanto
estiverem no banco, uma faixa de advertência aparece no site e no painel. Para
apagá-los sem desfazer a instalação, `./scripts/seed-demo.sh remover`. O que a
carga cria está detalhado em [docs/DADOS_DEMONSTRACAO.md](docs/DADOS_DEMONSTRACAO.md),
e o passo a passo para demonstrar cada perfil, com as credenciais, está em
[docs/ROTEIRO_PERFIS.md](docs/ROTEIRO_PERFIS.md).

Depois de mexer em permissões, confira as travas:

```bash
./scripts/verificar-acessos.sh
```

Se em algum momento o painel reclamar que não consegue criar um diretório, ou
se o seu editor esbarrar em `Permission denied` dentro de `wp-content/`, é a
disputa de dono entre o host e o contêiner:

```bash
./scripts/permissoes-dev.sh
```

As credenciais de administrador usadas localmente e as demais variáveis do
ambiente podem ser customizadas criando um arquivo `.env` na raiz do projeto
(veja o exemplo de variáveis no histórico do
[DIARIO_DESENVOLVIMENTO.md](DIARIO_DESENVOLVIMENTO.md)); sem esse arquivo, o
`docker-compose.yml` usa valores-padrão seguros apenas para uso local.

> **Nunca** commite um arquivo `.env` com valores reais — ele já está listado
> em `.gitignore`.

## Pendências que dependem de decisão da equipe/processo participativo

- Escolha final do gateway de pagamento (Mercado Pago, Asaas, etc.) e suas
  credenciais de produção.
- Definição participativa das regras de funcionamento da plataforma
  (Atividade 2.10 do edital), com beneficiários do projeto.
- Domínio e hospedagem de produção.
- Decisão sobre quando tornar este repositório público no GitHub.
