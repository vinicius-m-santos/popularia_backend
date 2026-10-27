# Infraestrutura do Popularia

> Auditoria em 27/10/2026: estado efetivo e pendências em [beta-release-runbook.md](beta-release-runbook.md) e [data-inventory.md](data-inventory.md). As seções históricas abaixo incluem exemplos de provisionamento; não comprovam configuração remota. Domínios reais: api.popularia.com.br e api-stg.popularia.com.br; health atual /api/v1/health; email Brevo ativo e PDFs em Cloudflare R2. Frequência/retenção de backups não foram confirmadas: os números de plano abaixo não devem ser tratados como política vigente.

Symfony 7.4 + FrankenPHP (PHP 8.4) → Railway · PostgreSQL 18

---

## Arquitetura

```
Mobile (Expo/React Native)
    ↓ HTTPS
Railway Edge (TLS termination + proxy)
    ↓ HTTP interno
FrankenPHP / Caddy  ←→  PostgreSQL (Railway)
    ↓
var/ (efêmero)   S3-compatible (Library PDFs)
```

**Componentes:**

| Camada | Tecnologia | Observações |
|---|---|---|
| Runtime PHP | FrankenPHP 1.13 (Caddy + PHP 8.4) | Dockerfile multi-stage |
| Servidor HTTP | Caddy (embutido no FrankenPHP) | TLS termina no Railway |
| Banco | PostgreSQL 18 (Railway managed) | Um por ambiente |
| Uploads | S3-compatible (Railway Bucket) | Biblioteca V1 (PDFs) |
| Cache / Rate Limit | PostgreSQL (`cache_items`) | Sem Redis |
| Auth | Tokens opacos em banco | Sem JWT |
| Logs | JSON → stderr → Railway dashboard | Monolog fingers_crossed |

---

## Ambientes

### Staging

- URL conceitual: `https://api-staging.<dominio>`
- Banco: PostgreSQL staging (separado do production)
- Propósito: testes integrados, validação de migrations, builds mobile de teste
- Auto-deploy: branch `main` (configurar no Railway dashboard)
- `APP_ENV=prod` — Symfony prod mode, sem debug, sem profiler

### Production

- URL conceitual: `https://api.<dominio>`
- Banco: PostgreSQL production (com backups habilitados)
- Deploy: manual via Railway dashboard (não auto-deploy)
- `APP_ENV=prod`

Os ambientes são **completamente isolados**: banco, secrets, variáveis e URLs independentes.
Staging nunca acessa o banco de production.

---

## Variáveis de Ambiente

### Compartilháveis / configuração

| Variável | Staging | Production | Observação |
|---|---|---|---|
| `APP_ENV` | `prod` | `prod` | Symfony prod mode em ambos |
| `TRUSTED_PROXIES` | `REMOTE_ADDR` | `REMOTE_ADDR` | Railway proxy direto ao contêiner |
| `CORS_ALLOW_ORIGIN` | `^https?://(localhost\|127\.0\.0\.1)(:[0-9]+)?$` | `^$` | App nativo não usa CORS; ajustar quando houver web |
| `MAILER_DSN` | `null://null` | `null://null` | Email não implementado ainda |
| `MAILER_FROM` | `nao-responda@popularia.app` | `nao-responda@popularia.app` | Atualizar com domínio real |
| `ACCESS_TOKEN_TTL` | `900` | `900` | 15 minutos |
| `REFRESH_TOKEN_TTL` | `2592000` | `2592000` | 30 dias |
| `SECURITY_EVENT_RETENTION_DAYS` | `180` | validar juridicamente | Ver docs/seguranca.md |
| `DEFAULT_URI` | URL staging | URL production | Geração de links |
| `LIBRARY_S3_REGION` | `auto` | `auto` | |
| `LIBRARY_S3_PATH_STYLE` | `0` | `0` | |

### Secrets (nunca no Git)

| Variável | Como gerar | Observação |
|---|---|---|
| `APP_SECRET` | `openssl rand -hex 32` | Um por ambiente; trocar invalida códigos pendentes |
| `DATABASE_URL` | `${{Postgres.DATABASE_URL}}` | Railway variable reference ao serviço PostgreSQL |
| `LIBRARY_S3_ENDPOINT` | Railway Bucket endpoint | Configurar quando Biblioteca entrar em produção |
| `LIBRARY_S3_BUCKET` | Railway Bucket name | |
| `LIBRARY_S3_ACCESS_KEY` | Railway Bucket credentials | |
| `LIBRARY_S3_SECRET_KEY` | Railway Bucket credentials | |

> **`DATABASE_URL` com Railway:** use `${{Postgres.DATABASE_URL}}` no campo de valor da variável
> no Railway dashboard. O Railway expande a referência automaticamente para a string de conexão
> correta do serviço PostgreSQL do mesmo environment.

---

## PostgreSQL

### Extensões necessárias

As migrations criam automaticamente:

```sql
CREATE EXTENSION IF NOT EXISTS unaccent WITH SCHEMA public;
CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public;
```

O PostgreSQL gerenciado da Railway (superusuário) permite `CREATE EXTENSION`. Não é necessária
configuração manual adicional.

### Charset e timezone

- `charset=utf8` na `DATABASE_URL`
- `date.timezone = UTC` no PHP (`docker/php/app.ini`)
- Colunas `TIMESTAMP(0) WITH TIME ZONE` em todo o schema

### Connection pooling

Railway PostgreSQL não oferece PgBouncer por padrão. Para a escala inicial (única instância
Symfony), a conexão direta é suficiente. Revisar quando houver mais de 3 réplicas simultâneas.

### Backups (ver seção dedicada abaixo)

---

## Deploy

### Como funciona

1. Push para o branch configurado no Railway.
2. Railway constrói a imagem usando `Dockerfile` target `prod`:
   - `composer install --no-dev`
   - `composer dump-autoload --classmap-authoritative`
   - `composer dump-env prod` (compila `.env` em `.env.local.php`)
   - `php bin/console cache:warmup`
3. Railway executa o container com `sh /app/docker/start.sh`:
   - Ajusta `SERVER_NAME` ao `$PORT` injetado pelo Railway
   - Roda `doctrine:migrations:migrate --no-interaction --allow-no-migration`
   - Inicia FrankenPHP
4. Railway aguarda o healthcheck `/api/health` responder 200 (até 300 s).
5. Se o healthcheck passar → novo container substitui o anterior (zero/low downtime).
6. Se o healthcheck falhar → deploy abortado; versão anterior continua no ar.

### Proteção contra migration falha

- `start.sh` roda com `set -e`: migration falha → script aborta → container não sobe.
- Railway detecta a falha via healthcheck timeout → marca deploy como falho.
- Versão anterior **não é substituída**.

### Estratégia staging vs. production

| | Staging | Production |
|---|---|---|
| Branch | `main` | `main` (manual trigger) |
| Auto-deploy | Sim | Não — deploy manual no dashboard |
| Migration | Automática no start | Automática no start |

**Para fazer deploy em production:** no Railway dashboard → aba "Deployments" → "Deploy Now"
(ou conectar `main` com "Require Manual Deploy").

---

## Healthcheck

**Endpoint:** `GET /api/health`

**Resposta 200 (ok):**
```json
{"status":"ok","database":"ok"}
```

**Resposta 503 (banco indisponível):**
```json
{"type":"...","title":"Service Unavailable","status":503}
```

Não expõe versão, host, credenciais nem stack trace.

Configurado em `railway.toml`:
```toml
healthcheckPath = "/api/health"
healthcheckTimeout = 300
```

---

## Migrations

### Executar automaticamente (normal)

Acontece no `start.sh` a cada deploy. Idempotente: se não houver pendências, não faz nada.

### Executar manualmente (emergência)

Via Railway CLI ou SSH no container em execução:

```bash
# Via Railway CLI (conecta ao container em execução)
railway run --environment=staging php bin/console doctrine:migrations:migrate --no-interaction

# Verificar estado
railway run --environment=staging php bin/console doctrine:migrations:status
```

### Criar nova migration

```bash
# Local
php bin/console doctrine:migrations:diff   # gera a partir das entidades
php bin/console doctrine:migrations:generate  # migration em branco
```

### Migrations como migrations reais de produção

A partir do primeiro deploy em production, toda migration deve ser:

- **Backwards-compatible** quando possível (adicionar coluna nullable antes de torná-la NOT NULL)
- **Não destrutiva** sem estratégia (DROP TABLE/COLUMN apenas quando os dados foram migrados)
- **Testada em staging** antes de ir para production

---

## HTTPS e Domínio

### Como funciona

- Railway termina TLS no edge (Railway-managed certificate via Let's Encrypt)
- O container recebe HTTP simples na porta `$PORT` (padrão 8080)
- `Caddyfile` tem `auto_https off` e `admin off` — correto para este setup
- `TRUSTED_PROXIES=REMOTE_ADDR` instrui o Symfony a aceitar `X-Forwarded-Proto` etc. somente do proxy direto (o Railway edge)

### Domínios

Configurar no Railway dashboard → Settings → Domains:

| Ambiente | Domínio recomendado |
|---|---|
| Staging | `api-staging.<seudominio>` |
| Production | `api.<seudominio>` |

Após adicionar o domínio no Railway:
1. Atualizar `DEFAULT_URI` com a URL final
2. Configurar DNS: CNAME apontando para o domínio Railway

### Geração de domínio Railway (temporário)

Enquanto o domínio real não está configurado, o Railway gera um subdomínio:
`<nome>.up.railway.app` — funciona com HTTPS imediatamente.

---

## CORS

O app nativo (React Native/Expo) **não usa CORS** — é uma característica de browsers.
CORS só se aplica quando houver um frontend web.

| Ambiente | `CORS_ALLOW_ORIGIN` | Motivo |
|---|---|---|
| Development local | `^https?://(localhost\|127\.0\.0\.1)(:[0-9]+)?$` | Swagger UI local |
| Staging (Railway) | `^https?://(localhost\|127\.0\.0\.1)(:[0-9]+)?$` | Testes web locais apontando para staging |
| Production | `^$` (bloqueia tudo) | Sem frontend web ainda |

Quando houver frontend web: atualizar production para o domínio específico.

---

## Logs

**Onde ver:** Railway dashboard → aba "Logs" do serviço.

**Formato:** JSON (Monolog `JsonFormatter`) → stderr → Railway captura.

**Em production e staging (`APP_ENV=prod`):**
- `fingers_crossed`: só persiste logs quando nível `error` ou acima é atingido
- Buffer de 50 linhas de contexto antes do erro
- 400/401/403/404/405/409/422/429 são excluídos (não disparam flush)
- Logs de segurança (sem IP/e-mail) em canal separado

**O que NÃO é logado:**
- Senha, tokens, Authorization header, secrets, dados pessoais

---

## Rate Limiting

Rate limiting armazenado no PostgreSQL (`cache_items` table via `cache.adapter.doctrine_dbal`).

Funciona corretamente entre instâncias e reinicializações (compartilhado via banco).
Não depende de Redis.

Para a escala inicial (única instância), é adequado. Revisar se houver alta concorrência e
muitos rate limits expirando simultaneamente causando contenção no banco.

---

## Persistência de Arquivos

| Tipo | Destino | Persistente? |
|---|---|---|
| `var/cache/`, `var/log/` | Container (efêmero) | Não — correto; cache é aquecido no build |
| `var/share/` | Container (efêmero) | Não — apenas APCu compartilhado entre workers |
| PDFs da Biblioteca | S3-compatible (Railway Bucket) | Sim |
| Uploads temporários | Container durante o request | Não — limpos após processamento |

**Sem dados permanentes no filesystem do container.** Cada deploy cria um container novo.

---

## Backup do PostgreSQL

### Railway Backup (managed)

O PostgreSQL gerenciado da Railway (plano pago) oferece:

- **Daily backups automáticos** com retenção de 7 dias (Pro plan)
- **PITR** (Point-in-Time Recovery) disponível em planos maiores

**Configuração obrigatória no dashboard (não pode ser feita por código):**

1. Acesse: Railway dashboard → seu projeto → ambiente `production`
2. Selecione o serviço PostgreSQL
3. Aba **"Backups"** → habilitar backups automáticos
4. Confirme a retenção disponível no seu plano
5. Teste: clique em "Create Backup" manualmente para verificar que funciona

> ⚠️ Staging: backup é opcional mas recomendado se staging for usado para dados reais de teste.

### Verificar que está funcionando

Railway dashboard → PostgreSQL → Backups → lista de backups com timestamps.
Se a lista estiver vazia 24h após ativar, abra um suporte na Railway.

---

## Restore (Runbook)

### Identificar o backup

Railway dashboard → PostgreSQL (production) → Backups → selecionar o ponto desejado.

### Restaurar

**Railway Restore (PITR ou snapshot):**

1. Railway dashboard → PostgreSQL → Backups → "Restore"
2. Selecionar ponto no tempo ou snapshot
3. Railway cria um novo banco (não substitui o atual automaticamente)
4. Atualizar `DATABASE_URL` no serviço backend para apontar para o banco restaurado:
   - Settings → Variables → `DATABASE_URL` → novo valor
   - Redeploy o serviço backend

**Alternativa via pg_restore (backup externo):**

```bash
# 1. Dump do banco atual (antes de qualquer restore destrutivo)
pg_dump "$DATABASE_URL_PRODUCTION" -Fc -f backup_$(date +%Y%m%d_%H%M%S).dump

# 2. Restore para banco novo
pg_restore -d "$DATABASE_URL_RESTAURADO" backup_YYYYMMDD_HHMMSS.dump

# 3. Validar dados
psql "$DATABASE_URL_RESTAURADO" -c "SELECT count(*) FROM app_user;"

# 4. Atualizar DATABASE_URL no Railway e redeploy
```

### Validar após restore

```bash
# Via Railway CLI
railway run --environment=production php bin/console doctrine:migrations:status
railway run --environment=production php bin/console doctrine:schema:validate
```

### Retornar o serviço

Após validação, o serviço já está funcionando com o banco restaurado.
Monitorar logs nas primeiras horas: Railway dashboard → Logs.

---

## Rollback de Código

### Rollback sem migration destrutiva (caso comum)

1. Railway dashboard → aba "Deployments"
2. Selecionar o deploy anterior
3. Clicar em "Rollback" → Railway reimplanta a imagem anterior
4. O banco permanece no estado atual (migrations não são revertidas)
5. A versão anterior deve ser compatível com o schema atual

### Rollback com migration destrutiva (caso raro)

Se a migration mais recente foi destrutiva (DROP, dados apagados):

1. **Não há rollback automático de dados** — migrations `down()` não restauram dados.
2. Usar backup/PITR para restaurar o banco (ver seção Restore acima).
3. Rollback do código (passo acima).
4. Comunicar o incidente.

**Por isso:** nunca fazer DROP em production sem confirmar backup recente e estratégia de rollback.

---

## CI/CD

### Pipeline (GitHub Actions)

Arquivo: `.github/workflows/ci.yml`

A cada push/PR para `main`:
1. `composer cs` — verificação de estilo
2. `composer stan` — PHPStan (análise estática)
3. `composer test` — PHPUnit (recria banco de testes do zero via migrations)
4. `composer audit` — auditoria de dependências

### Fluxo de deploy

```
git push main
    ↓
CI passa (Actions) → Railway staging auto-deploy
    ↓
Testes manuais em staging (mobile, múltiplas contas, migrations)
    ↓
Deploy manual em production (Railway dashboard → "Deploy Now")
```

---

## Configuração Mobile (URLs de API)

O app Expo usa `EXPO_PUBLIC_API_URL` para a URL base da API.

| Ambiente | Valor |
|---|---|
| Dev local | `http://<ip-do-computador>:8080` (conforme `compose.yaml`) |
| Staging | `https://api-staging.<dominio>` |
| Production | `https://api.<dominio>` |

Configurar no app via `.env` do Expo (ou `app.config.js`):

```bash
# .env.local (não versionar com valor de production)
EXPO_PUBLIC_API_URL=https://api-staging.<dominio>
```

**Segredos nunca vão no bundle mobile.** A URL da API é pública por natureza.

---

## Segurança Básica de Produção

| Item | Status | Como |
|---|---|---|
| Debug desligado | ✅ | `APP_ENV=prod` + `display_errors = Off` |
| Stack trace não exposto | ✅ | API Platform formata erros sem trace em prod |
| Profiler/toolbar | ✅ | Só ativo no firewall `dev` (pattern `/_profiler`), não acessível em prod |
| HTTPS | ✅ | Railway edge termina TLS |
| Trusted proxies | ✅ | `TRUSTED_PROXIES=REMOTE_ADDR` |
| CORS restrito | ✅ | Variável por ambiente |
| Secrets fora do repo | ✅ | Variáveis Railway, nunca em `.env` ou Dockerfile |
| Headers de segurança | ✅ | `Caddyfile`: `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`, `-Server` |
| Expose PHP | ✅ | `expose_php = Off` em `docker/php/app.ini` |
| Rate limiting | ✅ | PostgreSQL-backed, compartilhado entre instâncias |
| Auth atrás de proxy | ✅ | Tokens opacos verificados no banco; `REMOTE_ADDR` trusted proxy |

---

## Custos e Limitações

### O que está em uso (inicial)

| Recurso | Railway | Custo aprox. |
|---|---|---|
| Serviço PHP (staging) | ~0.5 vCPU / 512 MB RAM | ~$5–10/mês |
| PostgreSQL (staging) | Instância pequena | ~$5/mês |
| Serviço PHP (production) | ~0.5–1 vCPU / 512 MB RAM | ~$10–15/mês |
| PostgreSQL (production) | Instância pequena | ~$5/mês |

**Total estimado: ~$25–35/mês** para dois ambientes com pouco tráfego.

### O que NÃO foi adicionado (deliberadamente)

Redis, RabbitMQ, Elasticsearch, múltiplas réplicas, CDN própria, load balancer customizado.
Adicionar somente quando houver demanda real.

### Limitações conhecidas

- **Filesystem efêmero:** uploads temporários sobrevivem apenas ao request; dados permanentes exigem S3.
- **Single replica:** sem redundância de instância. Aceitável para fase inicial.
- **Connection pooling:** sem PgBouncer; adequado para ~1 instância. Revisar ao escalar.

---

## Ações Manuais Necessárias

As seguintes ações **não podem ser feitas por código** e exigem intervenção no dashboard ou DNS.

### 1. Criar projeto Popularia na Railway

Railway dashboard → "New Project" → nome: `popularia`

### 2. Criar environments staging e production

No projeto popularia → "Environments" → criar `staging` e `production`.
Não usar o environment `production` padrão se quiser controle total dos nomes.

### 3. Provisionar PostgreSQL em cada environment

Em cada environment: "New Service" → "Database" → "PostgreSQL".
Nome sugerido: `postgres` (Railway usa isso para a referência `${{Postgres.DATABASE_URL}}`).

### 4. Conectar repositório GitHub

Railway dashboard → projeto popularia → "New Service" → "GitHub Repo".
Conectar o repositório `popularia_backend`.

Configurar por environment:
- **Staging:** branch `main`, auto-deploy ativado
- **Production:** branch `main`, auto-deploy **desativado** ("Require Manual Deploy")

### 5. Inserir variáveis de ambiente

Para cada environment (staging e production), inserir as variáveis listadas na seção
"Variáveis de Ambiente" acima.

Variáveis obrigatórias antes do primeiro deploy:
- `APP_SECRET` — gerar com `openssl rand -hex 32` (um valor diferente por ambiente)
- `DATABASE_URL` — usar `${{Postgres.DATABASE_URL}}` (Railway expande automaticamente)
- `TRUSTED_PROXIES` — `REMOTE_ADDR`
- Demais variáveis conforme a tabela

### 6. Habilitar backups no PostgreSQL de production

Railway dashboard → ambiente `production` → serviço PostgreSQL → aba "Backups".
Habilitar backups automáticos. Confirmar retenção disponível no plano.
Criar um backup manual imediatamente para testar.

### 7. Configurar domínio e DNS

Railway dashboard → serviço backend → "Settings" → "Domains" → "Add Custom Domain".
Após adicionar: configurar DNS com CNAME para o endereço Railway fornecido.
Atualizar `DEFAULT_URI` e `CORS_ALLOW_ORIGIN` com os domínios finais.

### 8. Configurar Railway Bucket para a Biblioteca (quando necessário)

Railway dashboard → "New Service" → "Database" → "Object Storage (S3)".
Inserir as credenciais nas variáveis `LIBRARY_S3_*` do serviço backend.

### 9. Configurar crons de manutenção

Após o primeiro deploy, configurar no Railway as seguintes tarefas periódicas:

```
# Diário — limpeza de dados de segurança expirados e cache acumulado
php bin/console app:security:purge
php bin/console cache:pool:prune

# A cada semana (ou conforme necessidade) — limpeza de uploads da Biblioteca não finalizados
php bin/console app:library:purge-uploads
```

No Railway: "New Service" → "Cron Job" → usar o mesmo Docker image do backend.

### 10. Criar conta admin inicial em production

Após o primeiro deploy em production:
```bash
railway run --environment=production php bin/console app:user:set-role <handle> admin "Setup inicial"
```

---

## Perguntas Rápidas

**Como faço deploy em staging?**
Push para `main` — o Railway faz o resto. CI deve passar antes.

**Como promovo para production?**
Railway dashboard → ambiente `production` → "Deploy Now" (deploy manual).

**Como migrations são executadas?**
Automaticamente no start do container (`docker/start.sh`). Migration falha = deploy falha = versão anterior fica no ar.

**O que acontece se migration falhar?**
O container não sobe. Railway mantém a versão anterior. Ver logs para diagnosticar.

**Como verifico se a API está saudável?**
`curl https://api-staging.<dominio>/api/health` (deve retornar 200 com `{"status":"ok","database":"ok"}`).

**Onde vejo logs?**
Railway dashboard → serviço backend → aba "Logs".

**Onde ficam secrets?**
Exclusivamente nas variáveis do Railway. Nunca no repositório.

**Como staging e production são isolados?**
Bancos, variáveis, secrets e URLs completamente separados. `DATABASE_URL` de cada ambiente aponta para seu próprio PostgreSQL.

**Como o banco é protegido?**
Backups automáticos no plano Railway (habilitar manualmente — ver item 6 acima).

**Como restauro um backup?**
Ver seção "Restore (Runbook)" acima.

**Como faço rollback de código?**
Railway dashboard → "Deployments" → selecionar deploy anterior → "Rollback".

**Qual URL o mobile usa em cada ambiente?**
Dev: `http://<ip>:8080` · Staging: `https://api-staging.<dominio>` · Production: `https://api.<dominio>`.

**Quais ações ainda dependem de mim?**
Ver seção "Ações Manuais Necessárias" acima.
