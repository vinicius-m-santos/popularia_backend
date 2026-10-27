# Observabilidade — Popularia Beta

Objetivo: quando alguém disser "deu erro quando tentei entrar na Praça", conseguimos descobrir o que aconteceu sem precisar reproduzir no celular do tester.

---

## Arquitetura

```
Expo / React Native
  └── Sentry Mobile (popularia-mobile)
        └── envia exceções JS, crashes nativos, breadcrumbs de navegação

Symfony / API Platform
  └── Sentry Backend (popularia-backend)
        └── envia exceções 5xx e falhas de infraestrutura
  └── Monolog → stderr → Railway Logs
        └── request_id, nível, rota, status

Railway /api/v1/health
  └── Uptime Monitor (BetterUptime / UptimeRobot)
        └── alerta por e-mail / webhook Discord
```

**Correlação entre mobile e backend:** o backend gera um `X-Request-Id` UUID para cada requisição e o devolve no header de resposta. O mobile captura esse ID nos erros 5xx. Com ele, você consegue localizar o log e o evento Sentry do backend correspondente.

---

## Sentry — Backend (Symfony)

### Pacote

`sentry/sentry-symfony ^5.14`

### Configuração

`config/packages/sentry.yaml` — DSN, environment, release, privacidade.

### Variáveis Railway necessárias

| Variável | Staging | Production |
|---|---|---|
| `SENTRY_DSN` | DSN do projeto `popularia-backend` | idem |
| `SENTRY_ENVIRONMENT` | `staging` | `production` |
| `SENTRY_RELEASE` | `$RAILWAY_GIT_COMMIT_SHA` | idem |

### O que é capturado

- Exceções não tratadas e erros de infraestrutura (5xx)
- Falhas de banco de dados, storage, e-mail
- Tags: `request_id`, `environment`, `release`
- Usuário: apenas UUID interno (sem e-mail, nome ou dados pessoais)

### O que NÃO é capturado (por design)

- Erros HTTP esperados: 400, 401, 403, 404, 405, 409, 410, 422, 429
- Autenticação recusada (`AuthenticationException`)
- Qualquer corpo de requisição (`max_request_body_size: never`)
- IP do cliente (`send_default_pii: false`)

---

## Sentry — Mobile (Expo / React Native)

### Pacote

`@sentry/react-native ~8.28.0`

### Configuração

Inicializado em `src/app/_layout.tsx` via `initSentry()` (`src/lib/monitoring.ts`).

### Variáveis EAS necessárias

| Variável | Como configurar |
|---|---|
| `EXPO_PUBLIC_SENTRY_DSN` | `eas secret:create --name EXPO_PUBLIC_SENTRY_DSN --value "https://..."` |
| `EXPO_PUBLIC_SENTRY_ENVIRONMENT` | Definido automaticamente por `eas.json` (staging / production) |

> ⚠️ O `EXPO_PUBLIC_SENTRY_DSN` é público (embutido no bundle). Isso é normal para DSNs de cliente. O **auth token de upload de source maps** é diferente — nunca deve ser embutido no app. Configurar apenas como secret do EAS/CI.

### O que é capturado

- Crashes nativos (Android e iOS)
- Exceções JS não tratadas
- `ErrorBoundary` da rota raiz
- Erros HTTP 5xx (com request_id quando disponível)
- Breadcrumbs de navegação (rota/tela, sem conteúdo)
- Usuário: UUID interno, limpo no logout

### O que NÃO é capturado

- Erros 4xx esperados (401, 403, 404, 422, 429)
- Erros de rede esperados (`AbortError`, dispositivo offline)
- `console.log` em production
- Corpo de requisições ou respostas
- Tokens, Authorization headers
- Conteúdo da Praça, posts, comentários, PDFs

---

## Source Maps

Source maps permitem ver stack traces legíveis (TypeScript/JS) em vez de código minificado.

### Como funciona

O plugin `@sentry/react-native` em `app.json` instrui o EAS a:
1. Gerar source maps durante o build
2. Fazer upload ao Sentry usando o auth token

### Auth Token de Source Maps

1. Criar em: Sentry → Settings → Auth Tokens → Create Token (escopo: `project:releases`)
2. Configurar como secret do EAS:
   ```bash
   eas secret:create --name SENTRY_AUTH_TOKEN --value "sntrys_..."
   ```
3. Nunca colocar no app ou no Git.

### Validação

Após o primeiro build EAS de staging:
1. Provocar erro controlado (ver seção "Teste")
2. Abrir o evento no Sentry
3. Verificar se a stack trace mostra o arquivo `.tsx`/`.ts` correto, não código minificado

---

## Request ID / Correlation

Cada requisição HTTP recebe um `X-Request-Id` gerado pelo backend (`src/Logging/RequestIdSubscriber.php`).

- O ID aparece em **todos os logs** Railway daquela requisição (via `LogContextProcessor`)
- O ID aparece como **tag `request_id`** nos eventos Sentry backend
- Em erros 5xx do mobile, o ID é capturado do header de resposta e enviado ao Sentry mobile

### Fluxo de investigação

```
Sentry mobile → request_id: "abc123"
  → Railway Logs: filtrar por "abc123"
  → Sentry backend: filtrar por tag request_id = "abc123"
```

O backend também aceita `X-Request-Id` vindo do cliente (com validação de formato), mas na prática o mobile não envia — o backend sempre gera um ID definitivo.

---

## Logs Railway

### Configuração

Monolog escreve em `stderr` em JSON (`config/packages/monolog.yaml`).

**Prod (staging e production):**
- `fingers_crossed`: acumula até erro, então libera tudo
- Exclui: 400, 401, 403, 404, 405, 409, 422, 429
- Nível: `info` e acima

### Campos presentes em cada log

```json
{
  "message": "...",
  "context": { ... },
  "level": 400,
  "level_name": "ERROR",
  "channel": "app",
  "datetime": "2026-10-27T12:00:00.000000+00:00",
  "extra": {
    "request_id": "abc123-..."
  }
}
```

### Como filtrar no Railway

No dashboard Railway → Logs, use os filtros:
- `abc123` → todos os logs de uma requisição específica
- `"level_name":"ERROR"` → só erros
- `"channel":"security_audit"` → eventos de segurança

---

## Uptime Monitor

### Endpoint

```
GET /api/v1/health
```

Retorna `200` quando API e banco estão operacionais. Não expõe versões, hosts ou detalhes internos.

### Serviço recomendado

**BetterUptime** (gratuito para 1 monitor, 3 min de intervalo) ou **UptimeRobot** (5 min, gratuito).

### Como configurar (BetterUptime)

1. Criar conta em betteruptime.com
2. Criar monitor HTTP:
   - URL: `https://api.popularia.app/api/v1/health` (production)
   - Método: GET
   - Intervalo: 3 minutos
   - Alerta: e-mail + webhook Discord (quando o canal estiver criado)
3. Repetir para staging (intervalo maior, ex: 10 min)

### Alerta Discord

Quando criar o canal Discord:
1. Criar webhook no canal: Configurações → Integrações → Webhooks
2. Copiar URL do webhook
3. Adicionar no BetterUptime → Integrations → Discord (ou webhook custom)

---

## Alertas Sentry

### Configuração recomendada (após criar os projetos)

**Sentry → Alerts → Create Alert Rule:**

Para cada projeto (backend e mobile):

| Condição | Ação |
|---|---|
| Novo erro detectado | E-mail + Discord webhook |
| Erro cresce 50%+ em 1h | E-mail |
| Crash rate mobile > 1% | E-mail urgente |

**Não criar alerta para cada exception individual** — usar agrupamento padrão do Sentry.

### Discord no Sentry

Sentry → Settings → Integrations → Discord → configurar após criar o servidor/canal.

---

## Releases

### Backend

`SENTRY_RELEASE` deve ser o SHA do commit. No Railway CI:
```bash
# No GitHub Actions (ci.yml), adicionar ao deploy step:
SENTRY_RELEASE=${{ github.sha }}
```
Ou configurar como variável Railway com o valor `$RAILWAY_GIT_COMMIT_SHA` (Railway injeta automaticamente em builds).

### Mobile

Release é montado em `src/lib/monitoring.ts`:
```
popularia@1.0.0+1  (version + buildNumber)
```

Isso é gerado automaticamente a partir de `app.json → version` e `ios.buildNumber` / `android.versionCode`.

---

## Ambientes — Separação Staging / Production

| | Staging | Production |
|---|---|---|
| Backend `SENTRY_ENVIRONMENT` | `staging` | `production` |
| Mobile `EXPO_PUBLIC_SENTRY_ENVIRONMENT` | `staging` | `production` |
| Sentry project | mesmo (environment tag distingue) | mesmo |
| Logs Railway | ambiente separado no Railway | ambiente separado |

Ao abrir um evento no Sentry, a tag `environment` indica imediatamente onde aconteceu.

---

## Desenvolvimento Local

- `SENTRY_DSN` vazio → Sentry backend desabilitado
- `EXPO_PUBLIC_SENTRY_DSN` vazio → Sentry mobile desabilitado
- Nenhum evento enviado em dev/test
- Testes PHPUnit não enviam ao Sentry (DSN vazio por padrão)

---

## Privacidade — Dados Nunca Enviados

O Popularia contém conteúdo político e conversas privadas. Trate observabilidade com cuidado especial.

**Nunca enviados ao Sentry ou logs:**
- Conteúdo da Praça, posts, comentários, PDFs
- Nomes de documentos privados
- Senha, tokens, cookies, API keys
- Authorization headers
- Signed URLs
- E-mail completo
- CPF, telefone, endereço
- Corpo de requisições ou respostas completas

**Permitido:**
- UUID interno do usuário (para correlacionar erros)
- Rota/tela sem conteúdo
- Status HTTP, método, duração
- Stack trace técnico (sem conteúdo de negócio)
- `request_id`

---

## Retenção

| Serviço | Plano gratuito | Retenção |
|---|---|---|
| Sentry | Developer (gratuito) | 90 dias de eventos |
| Railway Logs | Incluído | 7 dias (depende do plano) |
| BetterUptime | Gratuito | Histórico disponível |

Para o beta, 90 dias de eventos no Sentry é suficiente para investigar bugs reportados.

---

## Runbook: Usuário Relatou um Erro

> "Entrei na Praça e deu erro."

### 1. Coletar informações básicas

- Horário aproximado
- Ação realizada ("tentei abrir a Praça X", "mandei mensagem")
- Aparelho e versão do app (se souber)
- Staging ou production?

### 2. Verificar Sentry Mobile

1. Abrir [sentry.io](https://sentry.io) → projeto `popularia-mobile`
2. Filtrar por environment (staging / production)
3. Filtrar por horário
4. Identificar evento correspondente
5. Anotar: `request_id`, `release`, tela/rota, plataforma

### 3. Cruzar com Sentry Backend

1. Abrir projeto `popularia-backend`
2. Filtrar por tag `request_id = <valor encontrado>`
3. Ver stack trace completo e exception

### 4. Verificar Railway Logs

1. Abrir Railway → ambiente → serviço backend → Logs
2. Filtrar pelo `request_id`
3. Ver sequência de logs daquela requisição

### 5. Conclusão

Com esses 3 passos você terá:
- Qual versão do app e do backend
- Qual dispositivo e plataforma
- Qual rota e operação
- O erro exato com stack trace
- O contexto técnico anterior (breadcrumbs)

**Sem precisar acessar o celular do tester ou reproduzir o erro.**

---

## Configuração de Staging — Checklist

- [ ] Criar conta Sentry
- [ ] Criar projeto `popularia-backend` (Platform: PHP/Symfony)
- [ ] Criar projeto `popularia-mobile` (Platform: React Native)
- [ ] Obter DSNs dos dois projetos
- [ ] Configurar variáveis Railway staging: `SENTRY_DSN`, `SENTRY_ENVIRONMENT=staging`, `SENTRY_RELEASE`
- [ ] Configurar secret EAS: `EXPO_PUBLIC_SENTRY_DSN`
- [ ] Criar auth token de source maps (`project:releases` scope)
- [ ] Configurar secret EAS: `SENTRY_AUTH_TOKEN`
- [ ] Atualizar `app.json` com slug e org corretos do Sentry
- [ ] Fazer build EAS staging: `eas build --profile staging`
- [ ] Testar fluxo end-to-end (ver seção abaixo)

---

## Configuração de Production — Checklist

Mesmas etapas de staging, com:
- `SENTRY_ENVIRONMENT=production` no Railway production
- `EXPO_PUBLIC_SENTRY_ENVIRONMENT=production` (automático via `eas.json`)
- Alert rules mais rígidas no Sentry
- Uptime monitor production configurado
- DSN pode ser o mesmo projeto (environment distingue) ou projeto separado (mais isolado)

---

## Teste End-to-End (Staging)

### Backend

```bash
# Testar via PHPUnit (não deixa endpoint público):
php bin/phpunit tests/Sentry/SentryBeforeSendTest.php

# Para validar evento real, em staging:
# Provocar temporariamente um RuntimeException num processor
# ou via comando de console de teste (remover depois).
```

Verificar no Sentry:
- [ ] Evento chegou
- [ ] `environment = staging`
- [ ] `release` = SHA do commit
- [ ] Tag `request_id` presente
- [ ] Stack trace útil (não genérico)
- [ ] Sem dados sensíveis (senha, token, conteúdo da Praça)

### Mobile

No build de staging (`eas build --profile staging`):

```typescript
// Adicionar temporariamente num botão de DEBUG (remover antes do release):
import * as Sentry from '@sentry/react-native';
Sentry.captureException(new Error('Teste de observabilidade — remover antes do release'));
```

Verificar no Sentry:
- [ ] Evento chegou
- [ ] `environment = staging`
- [ ] `release = popularia@1.0.0+1` (ou equivalente)
- [ ] Plataforma correta (android / ios)
- [ ] Stack trace desminificado (TypeScript/JS legível)
- [ ] Usuário UUID presente (se logado)
- [ ] Sem e-mail, nome, token nos dados

### Teste de Correlação

1. Provocar falha 5xx no backend (ex: desligar banco temporariamente)
2. Executar uma operação no mobile que faça requisição
3. No Sentry mobile: localizar o evento → anotar `request_id`
4. No Railway Logs: filtrar pelo `request_id`
5. No Sentry backend: filtrar por tag `request_id`

✅ Se encontrar o mesmo `request_id` nos 3 lugares, a correlação funciona.
