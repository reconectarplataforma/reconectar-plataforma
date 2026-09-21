# Reconectar — Incubadora Digital para Vínculos e Negócios

Plataforma digital do projeto "Reconectar", desenvolvida no âmbito do edital
FUNDEPES/UNOPS, para conectar pessoas, iniciativas e negócios locais por meio
de um marketplace multi-vendedor, comunidade colaborativa e mecanismos de
governança digital participativa.

Consulte [PLANO_DESENVOLVIMENTO.md](PLANO_DESENVOLVIMENTO.md) para o plano
completo (fases, cronograma do edital, indicadores) e
[DIARIO_DESENVOLVIMENTO.md](DIARIO_DESENVOLVIMENTO.md) para o histórico
cronológico de cada etapa concluída.

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

## Estrutura do repositório

```
reconectar-plataforma/
├── PLANO_DESENVOLVIMENTO.md   # plano completo do projeto
├── DIARIO_DESENVOLVIMENTO.md  # log cronológico de desenvolvimento
├── docker-compose.yml         # ambiente local (WordPress + MariaDB + WP-CLI)
├── scripts/
│   └── provision.sh           # instala WordPress, plugins e páginas essenciais
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

```bash
# 1. Subir os containers (WordPress + MariaDB + phpMyAdmin)
docker compose up -d

# 2. Aguardar os containers ficarem saudáveis
docker compose ps

# 3. Rodar o script de provisionamento (instala WordPress, plugins e páginas)
docker compose run --rm wpcli /var/www/scripts/provision.sh
```

Após o provisionamento:

- Site: http://localhost:8080
- Painel de administração: http://localhost:8080/wp-admin
- phpMyAdmin (suporte ao banco de dados): http://localhost:8081

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
