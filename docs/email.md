# E-mail Transacional — Popularia

E-mails de conta e segurança: verificação de e-mail, recuperação de senha e alertas de segurança.
Sem newsletter, marketing ou notificações sociais.

---

## Arquitetura

```
Fluxo de negócio (RegistrationProcessor, PasswordResetRequestProcessor…)
    ↓
AccountMailer (src/Mail/AccountMailer.php)
    ↓ enfileira — envia em kernel.terminate
MailerInterface (Symfony Mailer)
    ↓
StagingMailGuard (filtra destinatários em staging)
    ↓
Transport (MAILER_DSN)
    → dev:  null://null  (descartado / capturado em testes)
    → staging/prod: brevo+api://KEY@default
```

### `AccountMailer`

Abstração do domínio. Nenhum código fora dela deve conhecer Brevo, SMTP ou qualquer provider.
Trocar de provider = alterar `MAILER_DSN` + adapter, nunca os processadores de negócio.

Métodos públicos:

| Método | Disparado por |
|---|---|
| `sendVerificationCode(User, code)` | Cadastro e reenvio |
| `sendAccountAlreadyExists(User)` | Cadastro com e-mail duplicado |
| `sendPasswordResetCode(User, code)` | Solicitação de reset |
| `sendPasswordChanged(User)` | Senha alterada (reset ou troca) |

Envio é sempre **pós-resposta HTTP** (`kernel.terminate`): timing uniforme independente de a conta existir,
e falha do provider não derruba a requisição.

### Formato

Cada e-mail é enviado com **HTML + texto puro** (multipart/alternative).

Templates HTML: `templates/emails/` — layout inline-CSS, compatível com Gmail, Outlook, Apple Mail, mobile.
Texto puro: definido inline no `AccountMailer` como fallback.

---

## Provider — Brevo

Provider inicial: **Brevo** (antes Sendinblue).

- Plano gratuito: 300 e-mails/dia, sem limite mensal de contatos.
- Integração via API transacional (não SMTP), usando `symfony/brevo-mailer`.
- DSN: `brevo+api://API_KEY@default`

### Por que API em vez de SMTP

- Erros estruturados com código e mensagem claros.
- `messageId` retornado para rastreamento.
- Melhor integração com webhooks de entrega.
- Um parâmetro (`MAILER_DSN`) é suficiente para trocar de provider.

### Troca futura de provider

1. Substitua `MAILER_DSN` pela DSN do novo provider (ex.: `ses+api://...`, `postmark+api://...`).
2. Instale o pacote Symfony do novo transport se necessário.
3. Nenhuma regra de negócio precisa ser alterada.

---

## Tokens e segurança

O fluxo usa **códigos de 6 dígitos** digitados no app (não links clicáveis).
Sem deep links, sem URLs nos e-mails — mais simples e confiável para apps móveis.

| Token | TTL | Tentativas máximas |
|---|---|---|
| Verificação de e-mail | 24 horas | 5 |
| Recuperação de senha | 30 minutos | 5 |

- Armazenado como HMAC-SHA256 (chave = `APP_SECRET`), vinculado ao usuário e à finalidade.
- Emitir novo código invalida automaticamente o anterior.
- Uso único: `close()` ao ser consumido.
- Nunca logado, nunca exposto em resposta API.

### Account enumeration

- Cadastro com e-mail já existente: mesma resposta 202, e-mail diferente enviado ao dono.
- Reset de senha: resposta neutra independente de a conta existir.
- Todos os fluxos têm rate limiting por IP e por conta.

---

## Rate limits

Definidos em `config/packages/framework.yaml`:

| Limiter | Política | Limite | Janela |
|---|---|---|---|
| `register_ip` | sliding_window | 10 | 1 hora |
| `email_verification` | sliding_window | 10 | 15 min |
| `email_verification_resend` | sliding_window | 3 | 15 min |
| `password_reset_ip` | sliding_window | 10 | 1 hora |
| `password_reset_account` | sliding_window | 3 | 1 hora |
| `password_reset_confirm` | sliding_window | 20 | 15 min |

Guardados no PostgreSQL (`cache_items`), compartilhados entre instâncias, sem Redis.

---

## Desenvolvimento local

`MAILER_DSN=null://null` — e-mails são descartados.

O projeto tem **Mailpit** no `compose.yaml`: se quiser visualizar e-mails em dev, configure:
```
MAILER_DSN=smtp://mailer:1025
```
E acesse http://localhost:8025.

Testes automatizados nunca chamam Brevo — Symfony captura as mensagens em memória
(via `mailer.test` ativado por `framework.test: true`).

---

## Staging

Staging envia e-mails reais pelo Brevo, mas somente para destinatários autorizados.

**Variável:** `MAILER_STAGING_ALLOWLIST`  
**Valor:** lista de e-mails separados por vírgula.  
**Exemplo:** `tester@exemplo.com,dev@popularia.com.br`

Quando definida, `StagingMailGuard` descarta silenciosamente e-mails a outros destinatários
e registra no log. Sem allowlist = sem restrição (comportamento production).

**Configurar no Railway (staging):**
- `MAILER_DSN` = `brevo+api://STAGING_KEY@default`
- `MAILER_FROM` = `nao-responda@SEU_DOMINIO`
- `MAILER_STAGING_ALLOWLIST` = e-mails dos testadores

---

## Production

- `MAILER_DSN` = `brevo+api://PROD_KEY@default`
- `MAILER_FROM` = `nao-responda@SEU_DOMINIO`
- `MAILER_STAGING_ALLOWLIST` = (deixar vazio ou não definir)
- Envio para qualquer destinatário legítimo dos fluxos de negócio.

Não existe endpoint público de envio arbitrário. Todos os envios partem de fluxos autorizados.

---

## Secrets

| Variável | Descrição | Onde definir |
|---|---|---|
| `BREVO_API_KEY` | Chave de API Brevo | Railway staging + production |
| `BREVO_WEBHOOK_SECRET` | Token para validar webhooks | Railway staging + production |
| `MAILER_FROM` | Endereço remetente | Railway staging + production |
| `MAILER_STAGING_ALLOWLIST` | Allowlist staging | Railway staging apenas |

Usar chaves **separadas** para staging e production (geradas no dashboard Brevo).
Nunca colocar no Git, `.env` versionado, logs ou no app mobile.

---

## Remetente e domínio

`MAILER_FROM` define o endereço remetente e o `From:` nos e-mails.

Recomendação:
- `nao-responda@SEU_DOMINIO` — comunica que não há caixa monitorada.
- Se existir suporte real: adicionar `Reply-To: suporte@SEU_DOMINIO` (não implementado ainda).

**Subdomínio de envio:** recomendado usar `mail.SEU_DOMINIO` para isolar reputação
do domínio principal. Configurar SPF/DKIM neste subdomínio.

---

## Configuração DNS

> **Não invente registros.** Os valores exatos de DKIM e SPF são gerados pelo Brevo
> após adicionar o domínio no dashboard. Os registros abaixo são estruturais — substitua
> pelos valores reais obtidos em **Brevo → Senders & Domains → seu domínio → DNS records**.

### SPF

```
Tipo:  TXT
Host:  mail.SEU_DOMINIO   (ou @ se usar o domínio principal)
Valor: v=spf1 include:spf.brevo.com ~all
```

⚠️ Se o domínio já tiver um registro SPF, **combine** (não crie dois registros TXT de SPF):
```
v=spf1 include:spf.brevo.com include:OUTRO_PROVIDER ~all
```

### DKIM

```
Tipo:  TXT (ou CNAME, conforme Brevo indicar)
Host:  mail._domainkey.mail.SEU_DOMINIO  (Brevo fornece o nome exato)
Valor: [gerado pelo Brevo — obtido em Senders & Domains]
```

O Brevo gerencia a chave privada — não é necessário colocar nada no repositório.

### DMARC

```
Tipo:  TXT
Host:  _dmarc.SEU_DOMINIO
Valor: v=DMARC1; p=none; rua=mailto:dmarc@SEU_DOMINIO; aspf=r; adkim=r
```

**Política `p=none`** para início: observação sem rejeição, até SPF e DKIM estarem validados.
Evolução planejada:
1. `p=none` → monitorar relatórios por 2–4 semanas
2. `p=quarantine` → após confirmar alinhamento SPF+DKIM
3. `p=reject` → quando deliverability estiver consolidada

### Outros registros Brevo

O Brevo pode exigir um registro adicional para verificar propriedade do domínio (TXT ou CNAME).
Obtido em **Brevo → Senders & Domains → Verificar domínio**.

---

## Webhooks de entrega

Endpoint: `POST /webhooks/brevo/{BREVO_WEBHOOK_SECRET}`

Eventos processados (apenas log operacional):
- `delivered` — confirmação de entrega
- `soft_bounce` — rejeição temporária
- `hard_bounce` — rejeição permanente (endereço inválido)
- `spam` — marcado como spam
- `blocked` — bloqueado pelo provider
- `invalid_email` — e-mail inválido
- `deferred` — adiado pelo servidor de destino

**Autenticação:** o token no path é o `BREVO_WEBHOOK_SECRET`. Sem ele, 401.
**Idempotente:** só loga — sem efeitos colaterais que precisem de proteção de duplicação.

**Configurar no Brevo:**
1. Dashboard → Transactional → Settings → Webhooks
2. URL: `https://SEU_DOMINIO/webhooks/brevo/VALOR_DE_BREVO_WEBHOOK_SECRET`
3. Selecionar eventos: Delivered, Soft bounce, Hard bounce, Spam, Blocked, Invalid email

> Para bounce futuro: ao acumular hard bounces, marcar o e-mail do usuário como inválido
> e parar de enviar. Não implementado ainda — requer campo na entidade User.

---

## Bounces

Comportamento atual:
- Hard bounces são **logados** mas não bloqueiam o usuário.
- Reenvios continuarão a falhar silenciosamente até que o Brevo bloqueia o endereço.

Evolução futura: adicionar campo `emailBounced` em `User`, preencher via webhook de hard bounce,
e impedir novos envios para endereços definitivamente inválidos.

---

## Monitoramento de quota

Brevo gratuito: **300 e-mails/dia**.

- Ver uso: **Brevo → Dashboard** ou **Reports → Transactional email**
- Ao atingir o limite: envios falham com erro do transport. `AccountMailer` captura a exceção
  e loga `Falha ao enviar e-mail da conta.` — a conta é criada/operação é completada normalmente.
- Alerta: configurar notificação de quota no Brevo (Settings → Alerts).
- Upgrade: mudar para plano pago sem alterar código ou domínio.

---

## Tracking e privacidade

Rastreamento de abertura e clique **desativado** para e-mails transacionais.
Configurar no Brevo: **Settings → Tracking** → desabilitar tracking para templates transacionais.
Justificativa: e-mails de segurança não precisam de analytics de marketing.

Eventos de entrega/bounce continuam sendo rastreados (operacional, não marketing).

---

## Logs

Informações logadas:
- `Falha ao enviar e-mail da conta.` — subject + mensagem de erro do transport (sem endereço).
- `StagingMailGuard: e-mail descartado` — lista de endereços descartados (staging apenas).
- `Brevo: evento de entrega recebido.` — event type + messageId + reason (sem endereço completo).

Nunca logado: token/código, URL completa com token, API key, senha, endereço do usuário.

---

## Testes

Os testes cobrem todos os fluxos de e-mail:

- `RegistrationTest` — código enviado no cadastro, e-mail duplicado, rate limit
- `EmailVerificationTest` — código válido, expirado, bloqueado por tentativas, reenvio
- `PasswordResetTest` — account enumeration, código único, expirado, rate limit
- `ApiTestCase::lastCodeSentTo()` — helper que extrai o código 6 dígitos do e-mail capturado

Os testes usam o mailer em modo de captura (Symfony `mailer.test`). A Brevo não é chamada em testes.
`MAILER_DSN` pode ser qualquer valor em ambiente de teste — mensagens são interceptadas antes.

---

## Ações manuais necessárias

> O que depende de você (não automatizável pelo código):

### 1. Criar conta Brevo

Acessar brevo.com e criar conta.

### 2. Adicionar domínio

Dashboard → Senders & Domains → Add a domain  
Usar subdomínio de envio recomendado: `mail.SEU_DOMINIO`

### 3. Obter registros DNS

No dashboard Brevo, após adicionar o domínio, copiar os valores exatos de:
- SPF (TXT)
- DKIM (TXT ou CNAME, com nome exato do host)
- Registro de verificação de propriedade (se solicitado)

**Não use os valores genéricos deste doc — use os valores reais do seu dashboard.**

### 4. Configurar DNS

No seu provedor de DNS, criar os registros obtidos no passo anterior.  
Se já existir SPF, combinar (um único registro TXT de SPF).

### 5. Configurar DMARC

Criar registro TXT `_dmarc.SEU_DOMINIO` com política `p=none` para início.

### 6. Aguardar propagação e verificar

DNS pode levar até 24–48h para propagar globalmente.  
No Brevo: Senders & Domains → Verify → aguardar verde em SPF e DKIM.

### 7. Gerar API keys

- Uma chave para **staging** (permissão: "Transactional emails only")
- Uma chave para **production** (permissão: "Transactional emails only")

### 8. Configurar Railway — staging

```
MAILER_DSN=brevo+api://STAGING_API_KEY@default
MAILER_FROM=nao-responda@mail.SEU_DOMINIO
BREVO_WEBHOOK_SECRET=<openssl rand -hex 32>
MAILER_STAGING_ALLOWLIST=tester1@exemplo.com,tester2@exemplo.com
```

### 9. Configurar Railway — production

```
MAILER_DSN=brevo+api://PRODUCTION_API_KEY@default
MAILER_FROM=nao-responda@mail.SEU_DOMINIO
BREVO_WEBHOOK_SECRET=<openssl rand -hex 32>
```
(MAILER_STAGING_ALLOWLIST = não definir)

### 10. Configurar webhook no Brevo

1. Brevo → Transactional → Settings → Webhooks → Add a webhook
2. URL: `https://SEU_DOMINIO_PRODUCAO/webhooks/brevo/VALOR_DE_BREVO_WEBHOOK_SECRET`
3. Eventos: Delivered, Soft bounce, Hard bounce, Spam, Blocked, Invalid email
4. Repetir para staging com URL e secret de staging.

### 11. Desabilitar tracking

Brevo → Settings → Tracking → desabilitar Open tracking e Click tracking para transactional.

### 12. Testar entrega real

Após configurar staging:
1. Criar conta com e-mail real (da allowlist) no staging.
2. Confirmar recebimento do e-mail de verificação.
3. Verificar: remetente correto, não cai em spam, SPF/DKIM/DMARC passam.
4. Testar recuperação de senha.
5. Testar em Gmail, Outlook e Apple Mail se possível.
6. Verificar headers: `Authentication-Results` deve mostrar `spf=pass`, `dkim=pass`, `dmarc=pass`.

### 13. Ativar production

Após staging validado: replicar para production e testar com conta real.

---

## Checklist de validação

### Código
- [x] AccountMailer: HTML + texto em todos os e-mails
- [x] Tokens 6 dígitos HMAC, TTL adequado, uso único
- [x] Rate limiting em todos os endpoints de e-mail
- [x] Account enumeration protegido
- [x] Falha do provider não quebra o fluxo
- [x] Testes passando (20 testes de email/auth, 0 falhas)
- [x] PHPStan sem erros

### DNS (pendente — manual)
- [ ] SPF configurado
- [ ] DKIM configurado e verificado no Brevo
- [ ] DMARC configurado (p=none inicial)
- [ ] Subdomínio de envio configurado

### Provider (pendente — manual)
- [ ] Conta Brevo criada
- [ ] Domínio adicionado e verificado
- [ ] API keys separadas para staging e production
- [ ] Tracking desabilitado
- [ ] Webhook configurado

### Envio real (pendente — após DNS)
- [ ] Staging: e-mail recebido em Gmail
- [ ] Staging: não vai para spam
- [ ] Staging: SPF/DKIM/DMARC passam nos headers
- [ ] Production: idem
