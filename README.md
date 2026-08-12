# apy-gateway

Sistema auto-gerenciável de proxy reverso baseado em Nginx, com múltiplas instâncias
distribuídas em um cluster.

## O problema

Hoje não existe uma solução que permita rodar múltiplas instâncias de Nginx em máquinas
diferentes do cluster, todas cientes umas das outras e sincronizadas a partir de uma única
fonte de verdade. O [Nginx Proxy Manager](https://nginxproxymanager.com/) resolve bem o caso de
uma única instância, mas não atende esse cenário multi-réplica.

## A proposta

- Cada réplica roda seu próprio Nginx e conhece as outras via **Redis** (Pub/Sub).
- Um **banco de dados central** guarda os proxies configurados e os certificados SSL. Quando uma
  réplica sobe, ela se conecta no banco, verifica se já tem a versão mais recente de cada domínio
  e cria/atualiza os arquivos localmente.
- **O container só é considerado pronto quando o Nginx sobe com a configuração sincronizada.**
- Uma **API autenticada em Laravel** é responsável por todo o CRUD de proxies e certificados —
  os mesmos processos que hoje o NPM oferece (criação, edição, remoção, administração de
  certificados).
- Autenticação com controle de acesso por IP: um IP não autorizado pode solicitar liberação, que é
  aprovada por e-mail pelo administrador do sistema. Depois disso, login com e-mail/senha + segundo
  fator de autenticação (código por e-mail).
- Toda alteração feita pela API gera um evento publicado no Redis. Cada réplica escuta esses
  eventos e executa o que for necessário: sincronizar arquivos, validar configuração, recarregar o
  Nginx e confirmar execução.
- O agente que roda ao lado de cada Nginx é escrito em **Rust**.

A especificação técnica completa (modelo de dados, fluxos de autenticação, protocolo de
sincronização do agente, gestão de certificados etc.) está em **[SPEC.md](./SPEC.md)**.
O que já foi implementado e os próximos passos estão em **[CHANGELOG.md](./CHANGELOG.md)**.

## Arquitetura

```
                    +----------------------------------+
                    |          Web Interface            |
                    |             Laravel               |
                    +----------------------------------+
                                     │
                                     ▼
                    +----------------------------------+
                    |            REST API               |
                    |  (control-plane, apps/control-plane) |
                    +----------------------------------+
                          │                    │
                          ▼                    ▼
              +-------------------+   +-------------------+
              |       MySQL       |   |       MinIO        |
              | (dados/metadados) |   | (certificados SSL) |
              +-------------------+   +-------------------+
                                     │
                                     ▼
                    +----------------------------------+
                    |          Redis Pub/Sub            |
                    +----------------------------------+
                    │                  │                │
          ┌─────────┘                  │                └──────────┐
          ▼                            ▼                           ▼
    +-------------------+      +-------------------+      +-------------------+
    |    Agent (Rust)   |      |    Agent (Rust)   |      |    Agent (Rust)   |
    +-------------------+      +-------------------+      +-------------------+
            │                           │                          │
            ▼                           ▼                          ▼
    +-------------------+      +-------------------+      +-------------------+
    |       Nginx       |      |       Nginx       |      |       Nginx       |
    +-------------------+      +-------------------+      +-------------------+
            │                           │                          │
            ▼                           ▼                          ▼
        Tráfego HTTP(S)             Tráfego HTTP(S)           Tráfego HTTP(S)

              │                        │                             │
              └─────────────── Logs (rsyslog) ────────────────────────┘
                                      │
                                      ▼
                          +---------------------------+
                          |   Central Log Server      |
                          +---------------------------+
```

## Estrutura do repositório

| Caminho | Descrição |
|---|---|
| `apps/control-plane` | API + interface web em Laravel. |
| `agent` | Agente em Rust, roda ao lado do Nginx em cada réplica. |
| `nginx` | Dockerfile, templates e snippets de configuração do Nginx. |
| `docker` | Arquivos de suporte ao ambiente Docker. |
| `examples` | Exemplos de `docker-compose` para dev e para topologia de cluster. |
| `scripts` | Scripts de instalação, atualização e healthcheck. |

## Como rodar localmente

Nenhuma dependência (PHP, MySQL, Redis, Nginx) precisa ser instalada manualmente na máquina do
desenvolvedor — tudo sobe via Docker com um único comando:

```bash
./scripts/install.sh
```

Isso sobe o ambiente completo (`examples/docker-compose.yml`): control-plane (web + queue +
scheduler), MySQL, Redis, MinIO, Mailpit e uma réplica real de agent+nginx; gera o `.env` a
partir de `examples/.env.example` na primeira execução; espera todos os serviços ficarem
saudáveis; e libera `127.0.0.1` + cria o primeiro usuário admin (link de senha via Mailpit em
http://localhost:8025). `./scripts/update.sh` reconstrói as imagens após mudanças de código;
`./scripts/healthcheck.sh` confirma que cada serviço está respondendo de verdade.

## Status

Projeto em fase inicial de implementação. Veja o progresso e o roadmap em
**[CHANGELOG.md](./CHANGELOG.md)**.

## Licença

Ver [LICENSE](./LICENSE).
