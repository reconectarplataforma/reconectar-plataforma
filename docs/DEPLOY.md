# Deploy — GitHub Actions para a instância EC2

Os workflows estão em [`.github/workflows/`](../.github/workflows/). Um push na
`main` sincroniza o código autoral com a instância e roda o `provision.sh`. Os
dados de demonstração ficam num workflow manual, fora do fluxo automático.

## O que a esteira faz

| Workflow | Quando | O que faz |
| --- | --- | --- |
| [`verificar.yml`](../.github/workflows/verificar.yml) | todo push e todo pull request de fork | `php -l` em `reconectar-core/`, `themes/reconectar/` e `scripts/`; `bash -n` nos `scripts/*.sh` |
| [`implantar.yml`](../.github/workflows/implantar.yml) | push na `main`, ou disparo manual | `rsync` do código autoral, `docker compose up -d db wordpress`, `provision.sh` e, com `WP_URL` em `https://`, `up -d proxy` |
| [`demonstracao.yml`](../.github/workflows/demonstracao.yml) | só manual, com escolha `instalar`/`remover` | `seed-demo.sh instalar` ou `seed-demo.sh remover -y` |

O preparo do SSH é uma ação composta, [`.github/actions/preparar-ssh/`](../.github/actions/preparar-ssh/action.yml),
usada pelos dois workflows que falam com o servidor. Não é enfeite: duas cópias
do mesmo bloco divergem, e a que não recebeu o ajuste passa a falhar por um
motivo que ninguém procuraria no arquivo certo.

O deploy é `rsync` a partir do runner, não `git pull` no servidor: o runner já
tem o checkout e já tem a chave SSH, então **o servidor não precisa de nenhuma
credencial do GitHub**.

### O recorte da sincronização

Só cinco caminhos sobem. Nos três diretórios o `--delete` vale **dentro de si**;
os dois arquivos vão sem ele:

```
wp-content/themes/reconectar/
wp-content/plugins/reconectar-core/
scripts/
docker-compose.yml
docker/Caddyfile
```

`docker/Caddyfile` sobe sozinho, e não a pasta `docker/`, que no checkout de
quem desenvolve guarda também os dumps do banco local.

`git ls-files wp-content/` devolve exatamente os dois primeiros. O núcleo do
WordPress, o Storefront, o WooCommerce, o Dokan, o bbPress, o BuddyPress e todo
o `uploads/` **não estão no Git** — chegam pelo `provision.sh`, no destino.

Um `--delete` no nível de `wp-content/` apagaria os cinco plugins de terceiros,
o tema pai e os uploads, que não têm origem nenhuma para serem restaurados.
Dentro de cada diretório listado o `--delete` é o comportamento desejado: um
arquivo removido do tema tem de sumir do servidor.

O `rsync` roda com `--rsync-path="sudo rsync"` porque os arquivos do bind-mount
pertencem ao UID 33 (a imagem Apache é Debian) e o `ec2-user` é 1000. Sem isso a
escrita falha em arquivo que já existe.

## Preparação do servidor (uma vez)

### 1. Docker e o plugin Compose

Amazon Linux 2023:

```bash
sudo dnf install -y docker
sudo systemctl enable --now docker
sudo usermod -aG docker ec2-user
sudo mkdir -p /usr/libexec/docker/cli-plugins
sudo curl -sSL https://github.com/docker/compose/releases/latest/download/docker-compose-linux-x86_64 -o /usr/libexec/docker/cli-plugins/docker-compose
sudo chmod +x /usr/libexec/docker/cli-plugins/docker-compose
```

Saia e entre de novo no SSH para o grupo `docker` valer. Confira com
`docker compose version`.

### 2. Diretório do projeto

```bash
sudo mkdir -p /opt/reconectar
sudo chown ec2-user:ec2-user /opt/reconectar
```

Este caminho é o valor da variável `CAMINHO_REMOTO`.

O deploy também cria a árvore sozinho, com `sudo`, e devolve a raiz ao usuário
de login. Mas isso **não dispensa** os dois comandos acima antes do primeiro
deploy: o `.env` do passo 3 tem de estar no diretório antes que o job suba o
container, e o job sobe o container na mesma execução em que cria a árvore. Rodar
o deploy primeiro e escrever o `.env` depois é chegar tarde. A criação
automática existe para os deploys seguintes, e para o dia em que o diretório
sumir.

O `chown` do job é da raiz e nunca recursivo: dentro dela, `wp-content/` passa a
pertencer ao UID 33 do container Apache, e um `chown -R` tiraria do WordPress a
escrita nos uploads.

### 3. O `.env`, **antes do primeiro `up`**

O `wp-config.php` nasce na primeira subida do container e **não é reescrito**
quando as variáveis de ambiente mudam depois. Senha de banco e porta precisam
estar certas antes, não depois. Se o arquivo já tiver nascido errado, o conserto
é derrubar o volume (`docker compose down -v`), o que apaga o banco.

Crie `/opt/reconectar/.env` à mão, com as chaves abaixo. **Troque as senhas** —
os defaults do `docker-compose.yml` são de desenvolvimento e estão no
repositório.

```
WORDPRESS_PORT=127.0.0.1:8090
WORDPRESS_DEBUG=0
WP_URL=https://3-148-211-66.sslip.io

MYSQL_DATABASE=reconectar
MYSQL_USER=reconectar
MYSQL_PASSWORD=<senha forte>
MYSQL_ROOT_PASSWORD=<outra senha forte>

WORDPRESS_DB_NAME=reconectar
WORDPRESS_DB_USER=reconectar
WORDPRESS_DB_PASSWORD=<a mesma de MYSQL_PASSWORD>

WP_ADMIN_USER=<login do super administrador>
WP_ADMIN_PASSWORD=<senha forte>
WP_ADMIN_EMAIL=<e-mail real>
```

`WP_ADMIN_PASSWORD` tem default `reconectar-admin` no `provision.sh:12`. Deixar o
default é publicar a conta de super administrador junto com o site.

Sobre o `WP_URL`: ele **precisa** ser o DNS público. A resolução dinâmica de host
(`scripts/configurar-url-dinamica.php:82`) é uma allowlist que só aceita
`localhost`, o loopback e os três blocos privados — um DNS da AWS não casa e cai
em `RECONECTAR_HOST_PADRAO`, derivado de `WP_URL`. Com o default
`http://localhost:8090`, o site sobe carimbando todo asset e todo link com
`localhost`, e o sintoma é a página crua, sem CSS, para qualquer visitante.

O esquema do `WP_URL` decide o HTTPS — veja a seção [HTTPS](#https) abaixo.
Com `https://`, `WORDPRESS_PORT` **tem** de estar presa ao loopback como no
exemplo; o deploy recusa a subida do proxy se não estiver.

#### E-mail (SMTP)

Sem estas chaves o site funciona, mas **não envia e-mail nenhum**: o `mail()`
do PHP não entrega a partir do container. Redefinição de senha, aviso de pedido
à loja e ao comprador, link de senha da loja nova — tudo some sem erro na tela.

```
RECONECTAR_SMTP_HOST=smtp.gmail.com
RECONECTAR_SMTP_PORTA=465
RECONECTAR_SMTP_USUARIO=reconectar.plataforma@gmail.com
RECONECTAR_SMTP_SENHA=<senha de app do Gmail, 16 letras, sem espaços>
RECONECTAR_SMTP_NOME=Reconectar
```

- **Senha de app, não a da conta.** O Gmail recusa a senha normal por SMTP. Ela
  é gerada em *Conta Google → Segurança → Senhas de app*, e exige verificação em
  duas etapas ligada. Revogar a senha de app ali corta o envio sem mexer na conta.
- **A porta decide a criptografia**: 465 abre já em TLS, 587 sobe com STARTTLS.
  Não há variável para isso de propósito — `Reconectar_Email::configurar_smtp()`
  deriva da porta, e as duas funcionam com o Gmail.
- `RECONECTAR_SMTP_REMETENTE` é opcional e cai no usuário. O Gmail reescreve o
  remetente para a conta autenticada de qualquer jeito, salvo alias verificado.
- O Gmail gratuito envia até **500 mensagens por dia**. Passando disso, ele
  recusa até o dia seguinte.

Ao contrário do resto do `.env`, estas chaves **não** passam pelo
`wp-config.php`: o plugin as lê do ambiente a cada envio. Dá para acrescentá-las
depois do primeiro `up` — basta o próximo deploy, que recria o container
`wordpress` ao ver o ambiente mudado.

Para conferir sem esperar um pedido, no servidor:

```bash
cd /opt/reconectar
docker compose exec wordpress php -r 'echo getenv("RECONECTAR_SMTP_HOST"), "\n";'
```

Deve imprimir `smtp.gmail.com`. Depois, **Esqueci a senha** pelo navegador, com
uma conta sua. Não teste pelo `wpcli`: só o serviço `wordpress` recebe as
chaves, e o envio de lá sai pelo `mail()` e falha — parecendo defeito do SMTP.

### 4. Elastic IP

O nome `3-148-211-66.sslip.io` **é** o IP: o sslip.io resolve o nome para o
número que está escrito nele. Uma instância parada e religada sem Elastic IP
ganha outro IP público, o nome passa a apontar para o nada, e o certificado
deixa de valer para o endereço novo. Associe um Elastic IP antes de ligar o
HTTPS e, se o número mudar, troque o `WP_URL` junto. Passo a passo no console,
com tudo o que muda junto com o número: [`ELASTIC_IP.md`](ELASTIC_IP.md).

### 5. Security Group

- **80/tcp** para `0.0.0.0/0` — o redirecionamento para HTTPS e o desafio
  HTTP-01 do Let's Encrypt, que é por onde o certificado é emitido e renovado.
  Fechar a 80 depois de emitir parece seguro e quebra a renovação, 60 dias depois.
- **443/tcp** para `0.0.0.0/0` — o site.
- **22/tcp** para os runners do GitHub.

Este é o preço da opção escolhida: os IPs dos runners hospedados pelo GitHub são
amplos e variáveis (a lista está em `https://api.github.com/meta`, campo
`actions`, e muda), então na prática a 22 fica aberta. Um runner auto-hospedado
na própria EC2 eliminaria a exposição, ao custo de manter o runner.

A 8081 (phpMyAdmin) e a 8090 **não** devem ser liberadas. O phpMyAdmin nem sobe:
o job nomeia os serviços (`up -d db wordpress`), e é assim que ele fica fora do
ar sem precisar de arquivo de override.

## HTTPS

O TLS é do serviço `proxy` do `docker-compose.yml`, um Caddy configurado em
[`docker/Caddyfile`](../docker/Caddyfile). Ele obtém e renova o certificado do
Let's Encrypt sozinho — sem certbot, sem cron — e redireciona todo acesso HTTP,
de qualquer nome, para o endereço canônico, preservando o caminho. O certificado
mora no volume `caddy-data`; o Let's Encrypt limita a cinco emissões idênticas
por semana, e o volume é o que impede que cada recriação do container gaste uma.

**Por que sslip.io.** O Let's Encrypt recusa, por política, os nomes
`*.compute.amazonaws.com`. Sem domínio próprio, o caminho é um nome que resolve
para o IP da instância: `3-148-211-66.sslip.io`. Com domínio próprio, basta um
registro A para o Elastic IP e o `WP_URL` apontando para ele — nada mais muda.

**Uma fonte só.** O Caddy lê o endereço do mesmo `WP_URL` que o `provision.sh`
grava no `wp-config.php`. O bloco gerado por `scripts/configurar-url-dinamica.php`
dá ao host padrão o esquema do `WP_URL` e mantém `http` para os hosts da rede
local, que seguem atendidos na 8090 sem proxy.

**Por que o loopback.** O `wp-config.php` da imagem oficial do WordPress liga
`HTTPS` quando recebe `X-Forwarded-Proto: https`, venha de onde vier. O Caddy
descarta o cabeçalho que o cliente mandar e escreve o dele; mas uma porta do
`wordpress` aberta para fora aceitaria o cabeçalho forjado de qualquer um. Daí
`WORDPRESS_PORT=127.0.0.1:8090` — e com ela a 80 fica livre para o proxy.

**Ligar numa instância que já está no ar**, na ordem:

1. Elastic IP associado e 443/tcp aberta no Security Group.
2. No `.env` do servidor, `WP_URL=https://3-148-211-66.sslip.io` e
   `WORDPRESS_PORT=127.0.0.1:8090`. As duas chaves não estão entre as que o
   `wp-config.php` congela na primeira subida: a porta é do Compose, e o
   `WP_URL` é relido pelo `provision.sh` a cada deploy.
3. Disparar o `implantar.yml`. O `up -d db wordpress` recria o container com a
   porta nova, o `provision.sh` regrava o bloco do `wp-config.php` com
   `https`, e o passo "Subir o proxy HTTPS" sobe o Caddy, que emite o
   certificado no primeiro acesso.

Conferir: `curl -sI http://3-148-211-66.sslip.io/` responde 301 para o
`https://`; `curl -s https://3-148-211-66.sslip.io/ | grep -c 'http://3-148'`
dá zero; e `curl -sI http://<ip>:8090/` falha de fora da instância.

Links `http://ec2-…amazonaws.com` já gravados no banco (menus, widgets,
conteúdo) continuam funcionando, porque o proxy redireciona qualquer nome na
porta 80 para o endereço canônico. Reescrevê-los é opcional, com
`wp search-replace` e `--dry-run` antes.

Sem HSTS, de propósito, por enquanto: com ele o navegador passa a recusar o
HTTP por meses, e um nome sslip.io que mude de IP deixa de abrir até no
redirecionamento. Vale ligar quando houver domínio próprio.

## Secrets e variables do repositório

Settings → Secrets and variables → Actions.

**Aba Secrets** — só um, o único valor que precisa ficar ilegível depois de
gravado:

| Secret | Valor |
| --- | --- |
| `SSH_CHAVE_PRIVADA` | o conteúdo inteiro do `dev.pem`, com as linhas `BEGIN`/`END` |

**Aba Variables** — o resto. Nenhum deles é segredo, e como variável eles
aparecem legíveis no log, o que é o que se quer quando um deploy falha por
endereço errado:

| Variable | Valor |
| --- | --- |
| `SSH_HOST` | `ec2-3-148-211-66.us-east-2.compute.amazonaws.com` |
| `SSH_USUARIO` | `ec2-user` |
| `SSH_HOST_KEY` | saída do `ssh-keyscan` (abaixo) |
| `CAMINHO_REMOTO` | `/opt/reconectar` |

A chave **do host** é pública por definição — é o que o servidor apresenta a
quem conecta. Guardá-la como secret só faria o GitHub mascará-la no log, e a
mensagem de erro de host mudado viraria `***`, ilegível justamente na hora em que
ela importa. `SSH_HOST` como variable é o que permite montar a URL do
environment; como secret ela sairia mascarada ali também.

Para o `SSH_HOST_KEY`, na sua máquina:

```bash
ssh-keyscan -t ed25519 ec2-3-148-211-66.us-east-2.compute.amazonaws.com
```

Cole a linha inteira. Ela é o que dispensa o `StrictHostKeyChecking=no`:
desligar a verificação faria a esteira aceitar qualquer servidor que
respondesse naquele endereço, numa sessão que carrega uma chave de produção.

Se algum dos cinco faltar, o primeiro passo da ação composta interrompe o
workflow nomeando exatamente o que não está cadastrado. A guarda existe porque
o `required: true` do input **não** barra valor vazio — ele só exige que o
`with:` traga a chave — e a falha sem ela acontecia adiante, como o `usage` do
`ssh`, que parece erro de sintaxe do comando.

### O environment `producao`

Os dois workflows que falam com o servidor declaram `environment: producao`.
Criá-lo em Settings → Environments não é obrigatório para eles rodarem, mas é
onde ficam as duas proteções que valem a pena:

- **Required reviewers** — o deploy espera aprovação humana antes de rodar.
- **Deployment branches** — restringe o environment à `main`, de modo que nenhum
  workflow de outra branch alcance os secrets dele.

Isso é o análogo do *Protected* do GitLab. Some-se a ele o comportamento do
próprio GitHub: **secrets não são entregues a workflow disparado por pull
request de fork**, então um PR de terceiro nunca vê a chave, mesmo que altere o
arquivo do workflow.

Se alguma dessas cinco entradas (`SSH_CHAVE_PRIVADA`, `SSH_HOST`, `SSH_USUARIO`,
`SSH_HOST_KEY`, `CAMINHO_REMOTO`) estiver ausente, os workflows remotos param
antes do primeiro `ssh` e nomeiam exatamente o que não está cadastrado. O erro
antigo era pior: o job seguia com `ALVO=@` e falhava com a ajuda do `ssh`, sem
dizer o que realmente estava faltando.

A chave `dev.pem` **nunca entra no repositório** — `*.pem` está no `.gitignore`.
Na sua máquina, `chmod 400 dev.pem`.

## Verificação depois do primeiro deploy

1. **O recorte do `rsync`.** Na instância, `ls /opt/reconectar/wp-content/plugins/`
   tem de listar os cinco plugins de terceiros ao lado do `reconectar-core`, e
   `ls /opt/reconectar/wp-content/uploads/` não pode estar vazio. Rode o deploy
   **duas vezes** e repita — a segunda é a que pega o erro.
2. **`WP_URL`.** Abra o site pelo DNS público e confira no HTML que nenhum asset
   sai com `localhost`.
3. **Idempotência.** Dois deploys seguidos sem commit no meio (o segundo pelo
   "Run workflow" do `implantar.yml`): o `provision.sh` tem de imprimir "já
   instalado" / "já ativo" em todas as linhas.
4. **Superfície.** `curl` na 8081 e na 8090 do DNS público tem de falhar; só a 80
   (que redireciona) e a 443 respondem.
5. **RBAC.** `./scripts/verificar-acessos.sh` apontado para o servidor. As travas
   não têm outro teste, e este é o primeiro ambiente onde elas rodam fora do
   `localhost`:

   ```bash
   RECONECTAR_ADMIN_SENHA='…' BASE=https://3-148-211-66.sslip.io ./scripts/verificar-acessos.sh
   ```

   Fora do `localhost` só roda a parte HTTP — 85 dos 274 casos. Os que dependem
   do banco são pulados, porque o WP-CLI desta máquina leria o banco local, não
   o do servidor; o POST forjado nem é enviado. "Minha conta" é sondada entre
   `/my-account/` e `/minha-conta/` (aqui é a segunda); outro slug vai em
   `MINHA_CONTA=/caminho/`. Sem a senha do `admin` de produção, o login dele
   conta como uma falha.

## O que esta esteira não faz

- **Backup do banco antes do deploy.** O deploy não roda migração de schema, mas
  o `provision.sh` escreve opções. Um dump prévio seria a rede de segurança.
- **Rollback automático.** Voltar é reverter na `main` e disparar o
  `implantar.yml` à mão. Sem release versionada e sem symlink de versão.
- **Ambiente de homologação separado.** Um servidor só, uma branch só.
- **Backup de `uploads/`.** Imagens e PDFs enviados à Incubadora ficam em
  `wp-content/uploads/reconectar-incubadora/`, que não está no Git nem no
  `rsync` do deploy: existem só no servidor que os recebeu. O banco guarda a
  referência, não o arquivo — restaurar só o dump deixa as páginas com imagem
  quebrada. O backup dessa pasta é da infraestrutura. Arquivo enviado e nunca
  salvo numa página vira órfão; `Reconectar_Incubadora_Arquivos::limpar_orfaos()`
  os lista, e só apaga quando chamado com `false`.
- **Rotação da chave.** O `dev.pem` é uma chave de longa duração num secret.
