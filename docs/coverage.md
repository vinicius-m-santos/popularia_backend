# Cobertura de testes do backend

O PHPUnit já está configurado para medir `src/` (`phpunit.dist.xml`). O driver usado no CI é o **PCOV**, que é mais leve que o Xdebug para uma suíte de integração.

## Rodar localmente

Se o PHP local tiver PCOV:

```bash
cd popularia_backend
mkdir -p var/coverage
php -d pcov.enabled=1 bin/phpunit --coverage-text --coverage-clover=var/coverage/clover.xml
```

Ou, no CI/container com as extensões instaladas:

```bash
composer test:coverage
```

O relatório em texto mostra a cobertura por arquivo; o `clover.xml` é publicado como artefato do GitHub Actions.

## Baselines

Medição de 02/11/2026, com PCOV:

- Classes: **58,53%** (223/381)
- Métodos: **74,06%** (988/1334)
- Linhas: **87,11%** (6608/7585)

O relatório completo por arquivo fica em `var/coverage/` e é publicado no CI.

## Meta

A meta é **100% de linhas em `src/`**, mas ela ainda não deve ser usada como gate enquanto a base não estiver estabilizada: migrations, entidades e trechos defensivos de infraestrutura precisam de testes específicos. O primeiro passo é medir e acompanhar a evolução no CI; depois, elevar o gate gradualmente (por exemplo 80%, 90%, 100%) com um script que leia o `clover.xml`.

Não trate cobertura alta como substituta de testes de comportamento: um teste pode executar uma linha sem verificar o resultado.
