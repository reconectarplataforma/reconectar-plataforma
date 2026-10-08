# Elastic IP — fixar o endereço da instância

O endereço do site, `https://3-148-211-66.sslip.io`, **é** o IP da instância:
o sslip.io resolve o nome para o número escrito nele. O IP público que a AWS dá
por padrão não é fixo — parar e ligar a instância troca o número, o nome passa a
apontar para o nada, e o site cai sem erro nenhum do lado do servidor.

O Elastic IP é um IP público reservado na conta, que fica com a instância até
ser desassociado.

## Antes de começar: o número vai mudar

A AWS **não** transforma o IP atual em Elastic IP. Ela aloca um número novo, e
ao associá-lo o `3.148.211.66` é devolvido ao pool — **sem volta**. Por isso a
troca não é só no console; mudam junto:

| Onde | Valor de hoje | Valor depois |
| --- | --- | --- |
| `WP_URL` no `/opt/reconectar/.env` | `https://3-148-211-66.sslip.io` | `https://<novo-ip-com-hífens>.sslip.io` |
| Variable `SSH_HOST` no GitHub | `ec2-3-148-211-66.us-east-2.compute.amazonaws.com` | `ec2-<novo-ip-com-hífens>.us-east-2.compute.amazonaws.com` |
| Variable `SSH_HOST_KEY` no GitHub | linha do `ssh-keyscan` atual | linha nova, do `ssh-keyscan` contra o nome novo |

O site fica fora do ar entre a associação (passo 3) e o fim do deploy (passo 6):
alguns minutos. A ordem abaixo existe para que esse intervalo seja só isso.

Nos exemplos, o novo IP é `18.220.1.2` — **troque pelo seu**.

## 1. Alocar o Elastic IP

1. Console da AWS → confira no canto superior direito que a região é
   **Ohio (`us-east-2`)**. Elastic IP é por região, e um alocado em outra não
   aparece para a instância.
2. **EC2** → menu lateral **Network & Security** → **Elastic IPs**
   (em português: *Rede e segurança* → *IPs elásticos*).
3. **Allocate Elastic IP address** (*Alocar endereço IP elástico*).
4. Deixe **Amazon's pool of IPv4 addresses** e o *Network border group* que vier
   preenchido (`us-east-2`). Opcional: em **Tags**, `Name = reconectar`.
5. **Allocate**. Anote o número que aparece na lista — é o novo IP.

Nada muda no site ainda: o IP está reservado, mas solto.

## 2. Trocar o `WP_URL` no servidor (ainda pelo endereço antigo)

Pelo SSH de hoje, que ainda funciona:

```bash
ssh -i dev.pem ec2-user@ec2-3-148-211-66.us-east-2.compute.amazonaws.com
sudo nano /opt/reconectar/.env
```

Mude só a linha do `WP_URL`, com o número em hífens:

```
WP_URL=https://18-220-1-2.sslip.io
```

E confira:

```bash
grep -E '^(WP_URL|WORDPRESS_PORT)=' /opt/reconectar/.env
```

O arquivo só é lido no próximo deploy, então o site segue no ar no endereço
antigo. Fazer isso **antes** de associar poupa ter de descobrir o endereço SSH
novo para editar um arquivo.

## 3. Associar o Elastic IP à instância

A partir daqui o endereço antigo para de responder.

1. Em **Elastic IPs**, marque o IP alocado → **Actions** → **Associate Elastic
   IP address** (*Ações* → *Associar endereço IP elástico*).
2. **Resource type: Instance**.
3. **Instance**: escolha a instância do Reconectar.
4. **Private IP address**: o único que aparecer.
5. **Allow this Elastic IP address to be reassociated**: deixe **desmarcado**.
   Marcado, uma associação futura feita por engano a tiraria desta instância
   sem perguntar.
6. **Associate**.

Confira em **Instances** → a instância → aba **Details**: *Public IPv4 address*
e *Elastic IP addresses* mostram o número novo, e *Public IPv4 DNS* vira
`ec2-18-220-1-2.us-east-2.compute.amazonaws.com`.

O Security Group é da instância, não do IP: as regras de 22, 80 e 443 continuam
valendo sem mexer em nada.

## 4. Atualizar o acesso SSH do GitHub

Na sua máquina:

```bash
ssh-keyscan -t ed25519 ec2-18-220-1-2.us-east-2.compute.amazonaws.com
```

No repositório: **Settings → Secrets and variables → Actions → aba Variables**:

- `SSH_HOST` → `ec2-18-220-1-2.us-east-2.compute.amazonaws.com`
- `SSH_HOST_KEY` → a linha inteira que o `ssh-keyscan` imprimiu

A chave do host é a mesma de antes, mas a linha começa pelo **nome**. Com o nome
velho o `ssh` do job compara a chave com um host que ele não conhece e recusa a
conexão — `Host key verification failed`, que parece chave trocada e não é.

O secret `SSH_CHAVE_PRIVADA` não muda.

## 5. Atualizar o seu `known_hosts`

O seu `ssh` local também guardou o nome antigo. Para o novo, a primeira conexão
pergunta se confia no host — confira a impressão digital contra a do
`ssh-keyscan` acima e responda `yes`:

```bash
ssh -i dev.pem ec2-user@ec2-18-220-1-2.us-east-2.compute.amazonaws.com
```

A entrada velha pode sair com
`ssh-keygen -R ec2-3-148-211-66.us-east-2.compute.amazonaws.com`.

## 6. Rodar o deploy

**Actions → Implantar → Run workflow** (branch `main`). Não precisa de commit.

O que deve aparecer no log:

- **Provisionar** → `fora da rede local: https://18-220-1-2.sslip.io`
- **Subir o proxy HTTPS** → o container `reconectar-proxy-1` **recriado**: o
  Compose percebe que o `WP_URL` mudou e refaz o Caddy com o endereço novo.

O Caddy emite o certificado do nome novo sozinho, no primeiro acesso. O do nome
antigo fica no volume, sem uso.

## 7. Conferir

```bash
H=18-220-1-2.sslip.io
curl -sI http://$H/ | head -3                       # redireciona para https://
echo | openssl s_client -connect $H:443 -servername $H 2>/dev/null \
  | openssl x509 -noout -issuer -subject -dates     # Let's Encrypt, CN = o nome novo
curl -s https://$H/ | grep -c 'http://'             # 0 links em http
curl -s https://$H/ | grep -c '3-148-211-66'        # 0 restos do endereço antigo
curl -s -m 8 http://18.220.1.2:8090/ ; echo $?      # falha: a 8090 fica fechada
```

Se o último `grep` contar algo, é URL absoluta com o host antigo gravada no
banco — em conteúdo de página, por exemplo. Menus e widgets que o
`provision.sh` cria usam caminho relativo e não são afetados. Para reescrever,
no servidor, primeiro só simulando:

```bash
cd /opt/reconectar
docker compose run --rm wpcli wp search-replace \
  'https://3-148-211-66.sslip.io' 'https://18-220-1-2.sslip.io' --dry-run
```

Sem `--dry-run`, só depois de ler a contagem por tabela.

## Custo

A AWS cobra todo IPv4 público por hora — o automático de hoje inclusive. Um
Elastic IP **associado a uma instância ligada** custa o mesmo que o IP que ele
substitui. O que gera cobrança sem uso é o Elastic IP **solto**: alocado e não
associado, ou associado a uma instância desligada.

Se a instância for desligada de vez, libere o IP em **Elastic IPs → Actions →
Release Elastic IP addresses** — mas só se não for voltar: liberado, o número
não volta, e o endereço sslip.io muda de novo.

## Depois de feito

Troque `3-148-211-66` pelo número novo em `docs/DEPLOY.md` — ele cita o endereço
no `.env` de exemplo, na seção HTTPS e na verificação.
