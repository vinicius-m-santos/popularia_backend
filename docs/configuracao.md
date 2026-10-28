# Configuração por ambiente

Valores padrão de desenvolvimento ficam em `.env` (versionado, sem segredos reais). Em produção,
defina **variáveis de ambiente reais** (ou use `bin/console secrets:set`); nunca versione `.env.local`.

| Variável | Desenvolvimento | Produção |
| --- | --- | --- |
| `APP_ENV` | `dev` | `prod` (a imagem `--target prod` já define) |
| `APP_SECRET` | valor fixo em `.env.dev` | **obrigatório**: `openssl rand -hex 32`. Também é a chave do HMAC dos códigos por e-mail (trocá-lo invalida códigos pendentes) |
| `DATABASE_URL` | definida no `compose.yaml` | PostgreSQL gerenciado, com TLS (`?sslmode=require`) e usuário com permissões mínimas |
| `MAILER_DSN` | `smtp://mailer:1025` (Mailpit) | provedor SMTP/API, com SPF/DKIM/DMARC no domínio |
| `MAILER_FROM` | `no-reply@popularia.com.br` | endereço do domínio real |
| `TRUSTED_PROXIES` | vazio | IPs/CIDR do balanceador. Vazio se não houver proxy. Nunca `0.0.0.0/0` |
| `CORS_ALLOW_ORIGIN` | localhost | só origens próprias (o app nativo não precisa) |
| `ACCESS_TOKEN_TTL` | `900` | `900` (15 min) |
| `REFRESH_TOKEN_TTL` | `2592000` | `2592000` (30 dias) |
| `SECURITY_EVENT_RETENTION_DAYS` | `180` | **definir após validação jurídica** (docs/seguranca.md §9) |
| `DEFAULT_URI` | `http://localhost:8080` | URL pública da API |

Diferenças por ambiente já configuradas:

| | dev | test | prod |
| --- | --- | --- | --- |
| Swagger UI / HTML de docs | sim | não | não |
| Detalhes de erro | trace | trace | nenhum |
| Logs | JSON em `var/log/dev.log` | só em caso de erro | JSON em stderr, nível info |
| Hash de senha | padrão | custo mínimo (rapidez) | padrão |
| Banco | `popularia` | `popularia_test` (recriado a cada `composer test`) | o seu |

## Checklist antes de produção

- [ ] `APP_SECRET`, `DATABASE_URL`, `MAILER_DSN` definidos fora do repositório.
- [ ] TLS no proxy/balanceador; `TRUSTED_PROXIES` apontando só para ele.
- [ ] `php bin/console doctrine:migrations:migrate --no-interaction` no deploy.
- [ ] Cron diário de `php bin/console app:security:purge` e de `php bin/console cache:pool:prune`.
- [ ] Prazo de retenção e política de acesso a registros validados juridicamente e publicados.
- [ ] Backups do PostgreSQL com recuperação pontual e teste de restauração.
- [ ] Pelo menos uma conta `admin` criada com `app:user:set-role`, com motivo registrado.
- [ ] `composer check` verde no CI.
