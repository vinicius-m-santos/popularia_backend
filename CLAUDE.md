# Popularia Backend — regras para agentes

API em Symfony 7.4 + API Platform 4.4, PHP 8.4 e PostgreSQL 18. O ambiente de
desenvolvimento roda em Docker Compose; os comandos abaixo assumem o container
`php` em execução.

## Gate obrigatório antes de commitar

Nenhum commit pode ser criado com estilo, PHPStan ou Psalm vermelhos. Rode, nesta
ordem, e corrija o que falhar:

```bash
docker compose exec -T php composer cs
docker compose exec -T php composer stan
docker compose exec -T php composer psalm
```

Depois de corrigir, rode os três novamente. Só crie o commit quando todos
passarem. O hook `.claude/hooks/pre-commit-check.sh` repete esse gate em todo
`git commit` feito pelo Claude Code e bloqueia o commit se algo falhar.

Antes de push/PR, rode também os testes e a auditoria:

```bash
docker compose exec -T php composer test
docker compose exec -T php composer audit
```

Ou tudo de uma vez:

```bash
docker compose exec -T php composer check
```

`composer check` = `cs` + `stan` + `psalm` + `test` + `audit`. Se o `audit`
apontar vulnerabilidade sem correção disponível, registre no PR e não ignore em
silêncio.

## Psalm

- Configuração: `psalm.xml` (fonte em `src/`, nível 6).
- Erros legados estão em `psalm-baseline.xml`. **Não acrescente entradas ao
  baseline para código novo.** Corrija o código; se uma exceção for realmente
  necessária, justifique no PR.
- Rode sempre com `--no-diff` (o script `composer psalm` já faz isso) para
  analisar o projeto inteiro, não só o diff local.
- Para conferir se o baseline ainda é necessário:
  `vendor/bin/psalm --update-baseline`.

## Testes

- `composer test` recria o banco `popularia_test` a partir das migrations. Isso
  valida que as migrations funcionam em banco limpo.
- Para um teste específico sem recriar o banco:
  `docker compose exec -e SKIP_TEST_DB_RESET=1 php bin/phpunit --filter NomeDoTeste`.
- Testes novos devem cobrir o comportamento alterado, incluindo autorização,
  validação e casos de erro.

## Contrato e integração com o mobile

- O contrato fica em `docs/openapi.json` e é gerado com `composer openapi`.
- Ao alterar DTOs, rotas, status ou payloads, regenere o OpenAPI e avise no PR
  que o mobile depende da mudança.
- O E2E do mobile mocka a API; ele não prova contrato. Mudança de contrato exige
  o CI deste repositório verde e, quando aplicável, PR correspondente no mobile.

## Git

- Trabalhe em branch própria a partir da `main`.
- Commits em inglês no formato `feat(<escopo>): <mensagem>`, `fix(<escopo>): ...`
  ou `chore(<escopo>): ...`.
- Não commite segredos, `.env` local, `var/`, `vendor/` nem artefatos de teste.
- Nunca use `--no-verify` nem contorne o hook para "ganhar tempo".

## Estilo e análise estática

- PHP-CS-Fixer: regras `@Symfony` em `.php-cs-fixer.dist.php`; corrija com
  `composer cs-fix`.
- PHPStan: nível 8 em `phpstan.dist.neon`, com extensões Symfony, Doctrine e
  PHPUnit.
- Psalm: nível 6, com os plugins oficiais instalados pelo Psalm 7.
- Não reduza o nível de nenhum analisador para fazer o CI passar.

## CI

`.github/workflows/ci.yml` roda estilo, PHPStan, Psalm, PHPUnit, cobertura e
`composer audit`. O merge na `main` é bloqueado enquanto esses checks não
estiverem verdes.
