# Gate de qualidade do backend

Data: 04/11/2026. Este documento cobre o gate local, o CI e o que falta para
bloquear o merge na `main` do `popularia_backend`.

## O que roda hoje

| Camada | Ferramenta | Comando |
| --- | --- | --- |
| Estilo | PHP-CS-Fixer (`@Symfony`) | `composer cs` / `composer cs-fix` |
| Análise estática | PHPStan nível 8 (Symfony, Doctrine, PHPUnit) | `composer stan` |
| Análise estática | Psalm 7 nível 6 (baseline) | `composer psalm` |
| Testes | PHPUnit 13 (Postgres) | `composer test` |
| Dependências | Composer Audit | `composer audit` |
| Tudo | `cs + stan + psalm + test + audit` | `composer check` |

O CI (`.github/workflows/ci.yml`) roda estilo, PHPStan, Psalm, PHPUnit,
cobertura e audit. O job se chama **`Qualidade e testes`**.

## Gate antes do commit

O hook `.claude/hooks/pre-commit-check.sh` é carregado por
`.claude/settings.json` no Claude Code. Em todo `git commit` ele roda, no
container PHP 8.4:

```bash
docker compose exec -T php composer cs
docker compose exec -T php composer stan
docker compose exec -T php composer psalm
```

Se qualquer um falhar, o hook sai com código 2 e o commit é bloqueado. Corrija,
rode os três de novo e só então commite.

Antes do push/PR, rode também os testes e a auditoria (ou `composer check`):

```bash
docker compose exec -T php composer test
docker compose exec -T php composer audit
```

No GitHub Actions (`CI=true`), o hook usa o PHP/vendor do runner em vez de
Docker, para o mesmo gate valer em um futuro workflow de fix.

## Psalm

- Configuração: `psalm.xml`, fonte em `src/`, nível 6.
- Erros legados: `psalm-baseline.xml` (211 entradas). Código novo não deve
  adicionar entradas; corrija o código.
- O script usa `--no-diff`, então analisa o projeto inteiro mesmo em checkout
  limpo de CI.
- Para remover entradas já corrigidas:
  `vendor/bin/psalm --update-baseline`.
- Não baixe o nível nem remova o baseline para fazer o CI passar.

## Ruleset da `main`

Depois que este PR estiver na `main` e o job tiver rodado ao menos uma vez:

```bash
gh api repos/vinicius-m-santos/popularia_backend/rulesets \
  --method POST --input - <<'JSON'
{
  "name": "main-protection",
  "target": "branch",
  "enforcement": "active",
  "conditions": {
    "ref_name": { "include": ["refs/heads/main"], "exclude": [] }
  },
  "rules": [
    {
      "type": "pull_request",
      "parameters": {
        "required_approving_review_count": 0,
        "dismiss_stale_reviews_on_push": true,
        "required_review_thread_resolution": true
      }
    },
    {
      "type": "required_status_checks",
      "parameters": {
        "strict_required_status_checks_policy": true,
        "required_status_checks": [
          { "context": "Qualidade e testes" }
        ]
      }
    },
    { "type": "non_fast_forward" },
    { "type": "deletion" }
  ]
}
JSON
```

O nome do check é o nome do job no workflow (`Qualidade e testes`). Se o job
for renomeado, atualize o ruleset.

## Integração com o Claude

O backend já tem o gate local (hook) e o CI. O review automático de PR e o fix
por label podem ser adicionados depois, no mesmo padrão do mobile:

1. `claude-review.yml` com `CLAUDE_CODE_OAUTH_TOKEN`, lendo um `REVIEW.md` do
   backend e retornando JSON estruturado.
2. Um gate que falha o check `AI review` com `blocker`/`major`.
3. `claude-fix.yml` com label `ai:fix`, rodando `composer check` antes do
   commit e dando push com o Claude App.

Para o backend, o fix precisa de PHP 8.4, Composer e o serviço Postgres do CI;
o `composer check` já cobre estilo, PHPStan, Psalm, PHPUnit e audit. Não use
`GITHUB_TOKEN` para o push: ele não dispara o CI.

## Resultado da validação deste PR

- `composer check` verde: estilo, PHPStan, Psalm, 393 testes (4352 asserções)
  e audit sem advisories.
- PHPStan: 8 erros legados corrigidos (`PostCreateProcessor`, `LocationCatalog`,
  `PreviewController`, `RegistrationTest`).
- Dependências: Symfony atualizado para 7.4.23 e PHPUnit para 13.4.6 para
  corrigir os advisories de segurança.
- PHPUnit ainda emite 2 notices (exit 0); não são falhas do gate.
