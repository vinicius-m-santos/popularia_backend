# Popularia — backend

API do app Popularia: PHP 8.4, Symfony 7.4 LTS, API Platform 4.4, PostgreSQL 18 e Docker Compose.
A API é versionada em `/api/v1`, responde JSON e documenta tudo em OpenAPI.

| Documento | Conteúdo |
| --- | --- |
| [docs/seguranca.md](docs/seguranca.md) | Sessões, tokens, limites de taxa, IP, registros de segurança, retenção e auditoria |
| [docs/moderacao.md](docs/moderacao.md) | Papéis, criação e gestão de comunas (permissões, arquivamento, exclusão, transferência) e o desenho da moderação |
| [docs/configuracao.md](docs/configuracao.md) | Variáveis de ambiente e checklist de produção |
| [docs/openapi.json](docs/openapi.json) | Especificação OpenAPI exportada (`composer openapi`) |

## Subir o ambiente local

Requisitos: Docker Desktop (ou Docker Engine) com Compose. Nada de PHP ou PostgreSQL na máquina.

```bash
docker compose up -d --build                                  # API, PostgreSQL e Mailpit
docker compose exec php composer install                      # dependências
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console app:dev:seed               # opcional: comunas e posts FICTÍCIOS
```

| Serviço | Endereço |
| --- | --- |
| API | http://localhost:8080/api/v1 (saúde: `/api/v1/health`) |
| Documentação (Swagger UI, só em dev) | http://localhost:8080/api/v1/docs |
| OpenAPI (JSON) | http://localhost:8080/api/v1/docs.jsonopenapi |
| E-mails capturados (Mailpit) | http://localhost:8025 — os códigos de verificação e recuperação chegam aqui |
| PostgreSQL | `127.0.0.1:55432`, usuário `popularia`, senha `popularia_dev` (só local) |

No Windows e no macOS a primeira requisição depois de mudar o código pode levar alguns segundos:
o Symfony recompila o cache (que fica num volume do Docker, `php_var`, por desempenho).

Logs da aplicação (JSON): `docker compose exec php tail -f var/log/dev.log`.

### Ligar o app mobile

No `popularia_mobile`, crie `.env.local` com o IP do computador na rede Wi-Fi:

```bash
EXPO_PUBLIC_API_URL=http://192.168.0.10:8080/api/v1
```

e reinicie `npx expo start`. A porta 8080 fica aberta na rede local para o celular alcançar a API.
Detalhes no README do app.

## Testes e verificações

```bash
docker compose exec php composer test     # PHPUnit: recria o banco de testes só com as migrations
docker compose exec php composer stan     # PHPStan nível 8 (com extensões Symfony, Doctrine e PHPUnit)
docker compose exec php composer cs       # estilo (PHP-CS-Fixer, regras @Symfony); cs-fix corrige
docker compose exec php composer check    # tudo acima + composer audit
```

No WSL, se `composer test` responder "Permission denied" em `bin/phpunit`, rode `php bin/phpunit`
(os arquivos de `bin/` podem perder o bit de execução no checkout).

Cada execução de `composer test` apaga e recria o banco `popularia_test` aplicando as migrations,
o que confirma a cada rodada que elas funcionam num banco limpo. Cada teste roda numa transação
desfeita ao final (DAMA DoctrineTestBundle). Para iterar mais rápido sem recriar o banco:
`docker compose exec -e SKIP_TEST_DB_RESET=1 php bin/phpunit --filter LoginTest`.

## Comandos de console

| Comando | Para quê |
| --- | --- |
| `app:security:purge [--dry-run]` | Rotina de retenção (rodar 1×/dia via cron): registros de segurança vencidos, sessões e códigos antigos |
| `app:user:set-role <handle> <user\|platform_moderator\|admin> "<motivo>"` | Único jeito de dar papéis; fica na auditoria |
| `app:user:set-status <handle> <active\|suspended> "<motivo>"` | Suspende (encerra as sessões) ou reativa uma conta; auditado. Avisa se a conta é proprietária de comunas |
| `app:comuna:transfer-owner <slug> <handle> "<motivo>"` | Equipe passa a propriedade de uma comuna para um membro dela (ex.: proprietário suspenso); auditado |
| `app:dev:seed` | Dados fictícios para desenvolvimento (recusa rodar em produção) |
| `composer openapi` | Atualiza `docs/openapi.json` |

## Endpoints (resumo)

Lista completa, com exemplos e códigos de erro, no Swagger UI ou em `docs/openapi.json`.

| Área | Endpoints |
| --- | --- |
| Sistema | `GET /health` |
| Autenticação | `POST /auth/register`, `/auth/login`, `/auth/refresh`, `/auth/logout`, `/auth/logout-all`, `/auth/password-reset/request`, `/auth/password-reset/confirm` |
| Conta | `GET/PATCH /me`, `POST /me/email-verification`, `POST /me/email-verification/resend`, `POST /me/password`, `GET /me/sessions`, `DELETE /me/sessions/{id}`, `GET /me/blocks`, `PUT/DELETE /me/blocks/{handle}`, `GET /me/comunas`, `GET /me/comuna-quota` |
| Perfis e assuntos | `GET /users/{handle}`, `GET /handles/{handle}/availability`, `GET /topics` |
| Comunas | `GET /comunas`, `GET /comunas/{slug}`, `GET /comunas/{slug}/posts`, `GET /comunas/{slug}/members`, `PUT/DELETE /comunas/{slug}/membership`, `PUT/DELETE /comunas/{slug}/join-request` |
| Gestão de comunas | `POST /comunas`, `GET /comuna-names/availability?name=`, `PATCH/DELETE /comunas/{slug}`, `POST /comunas/{slug}/archive`, `DELETE /comunas/{slug}/members/{handle}`, `PUT/DELETE /comunas/{slug}/moderators/{handle}`, `GET /comunas/{slug}/join-requests`, `POST /comunas/{slug}/join-requests/{id}/approve\|reject`, `PUT/DELETE /comunas/{slug}/ownership-transfer`, `POST /comunas/{slug}/ownership-transfer/accept\|decline`, `POST /posts/{id}/removal`, `POST /comments/{id}/removal` |
| Publicações | `GET /feed?tab=para-voce\|minhas`, `POST /posts`, `GET/DELETE /posts/{id}`, `GET/POST /posts/{id}/comments` |
| Administração | `GET /admin/security-events?userId=&reason=`, `GET/POST /admin/evidence-holds`, `POST /admin/evidence-holds/{id}/release` |

Convenções:

- **Erros** em `application/problem+json` (RFC 9457), com `type` estável (`/errors/invalid-credentials`,
  `/errors/email-not-verified`, `/errors/not-a-member`…). Validação: 422 com `violations[{propertyPath, message}]`.
  Limite de taxa: 429 com `Retry-After`.
- **Campos desconhecidos são recusados (400)**: o cliente não consegue mandar `id`, `authorId`, `platformRole`,
  `status` ou contadores. Autoria, permissões e contagens são sempre calculadas no servidor.
- **Paginação por cursor**: `?cursor=<nextCursor>&limit=1..50`; a resposta é `{ "items": [...], "nextCursor": "..." | null }`.
- Datas em ISO 8601 UTC; IDs são UUID v7.

## Estrutura

```
src/
  Api/            Recursos do API Platform: objetos de entrada e saída próprios + providers/processors
    Auth/ Me/ Profile/ Topic/ Comuna/ Post/ Comment/ Block/ Admin/ Health/
    ApiProblem.php            Erros problem+json com "type" estável
  Auth/           Sessões (SessionManager), códigos por e-mail, registro de segurança, auditoria
  Content/        ContentPolicy (acesso a conteúdo), ComunaModerationPolicy (permissões por comuna),
                  ComunaGovernance (criar, gerir, arquivar, excluir e transferir comunas, com travas e auditoria)
  Entity/         Entidades Doctrine (nunca expostas diretamente)
  Repository/     Consultas; filtros de visibilidade e bloqueio ficam aqui
  Security/       Autenticação por access token, verificação da conta, limites de taxa
  Logging/        Request ID e remoção de dados sensíveis dos logs
  Command/        Comandos de console (retenção, papéis, suspensão, dados fictícios)
  Mail/           E-mails da conta (enviados depois da resposta)
migrations/       Migrations versionadas (todas as mudanças de esquema)
tests/            Testes funcionais da API e do comando de retenção
docker/           Caddyfile e php.ini
```

## Decisões principais

- **Versões**: Symfony 7.4 (LTS, segurança até nov/2029) em vez de 8.x; API Platform 4.4 em vez da 5.0
  (lançada há um mês); PHP 8.4; PostgreSQL 18; FrankenPHP como servidor (imagem oficial usada pelo API Platform).
- **JSON simples** (sem JSON-LD/Hydra): mais direto para o app; a paginação usa `{items, nextCursor}`.
- **Objetos de entrada/saída próprios** em `src/Api` em vez de publicar entidades: o que sai e o que entra é explícito.
- **Access token opaco** (não JWT), conferido no banco a cada requisição. Motivo e alternativas em docs/seguranca.md.
- **Sem Redis/fila**: limites de taxa no PostgreSQL (`cache_items`); e-mails enviados depois da resposta HTTP.
- **Sem CPF**: o cadastro não coleta documento civil.
- **Comunas criadas pelas pessoas**, com proprietário: até 3 comunas ativas por pessoa, sem cooldown entre criações,
  e-mail confirmado. As regras valem no servidor, sob trava, e o banco garante o limite e o proprietário único
  (detalhes em docs/moderacao.md). Comunas sem proprietário continuam possíveis como "comunas da equipe".

## Produção (resumo)

`docker build --target prod .` gera uma imagem sem dependências de desenvolvimento, com cache aquecido,
rodando como `www-data`. Antes de publicar, siga o checklist de [docs/configuracao.md](docs/configuracao.md)
(segredos, proxies confiáveis, e-mail, retenção validada juridicamente, backups).
