# Auditoria de testes e CI

## Escopo e evidências

Análise por leitura das configurações e dos testes deste checkout. Fontes principais: [`composer.json`](../composer.json), [`phpunit.dist.xml`](../phpunit.dist.xml), [`tests/`](../tests/), [`config/services_test.yaml`](../config/services_test.yaml) e [`.github/workflows/ci.yml`](../.github/workflows/ci.yml).

Não foram executados PHPUnit, analisadores ou GitHub Actions; `vendor/` não está disponível neste checkout. Portanto, este documento descreve a configuração versionada, sem atestar que o pipeline esteja passando ou informar percentuais de cobertura. Não foram consultadas configurações remotas de proteção de branch ou históricos de execuções.

## Testes automatizados existentes

- **Executor:** PHPUnit `^13.4.6`, com PHP `>=8.4` e Symfony 7.4. `composer test` chama `bin/phpunit`; a configuração reúne `tests/` em uma única suíte, com bootstrap em `tests/bootstrap.php` e fontes de cobertura em `src/`. Deprecações, notices e warnings fazem a execução falhar, respeitando os filtros configurados.
- **Testes unitários:** geração de handles, parsing de hashtags, catálogo de localidades, progressão e dias civis da gamificação, armazenamento local, controlador de arquivos e proteção de dados em logs/Sentry. Há validação criptográfica de tokens Google/Apple e JWKS com chaves geradas localmente e HTTP simulado (`tests/Social/RealTokenValidationTest.php`).
- **Testes funcionais e de integração:** `tests/ApiTestCase.php` estende `WebTestCase`, usa o kernel Symfony via BrowserKit e oferece criação de usuários, autenticação, requisições JSON e acesso ao EntityManager. `tests/Api/` cobre autenticação e sessões, recuperação de senha, verificação de e-mail, perfis, exclusão de conta, permissões administrativas, comunas e seu ciclo de vida, posts, comentários, enquetes, eventos, notificações, relações sociais, moderação, denúncias, descoberta, gamificação, praças e OpenAPI. `tests/Library/` e `tests/PostMedia/` exercitam biblioteca e uploads; `tests/Command/` verifica comandos de exclusão, retenção de dados de segurança e lista de espera.
- **Banco e migrations:** o bootstrap apaga e recria o banco de teste e aplica todas as migrations antes da suíte; `SKIP_TEST_DB_RESET=1` permite dispensar esse preparo durante iteração local. Doctrine acrescenta `_test` e, se definido, `TEST_TOKEN` ao nome do banco (`config/packages/doctrine.yaml`). A extensão PHPUnit do DAMA DoctrineTestBundle e a conexão estática isolam os testes transacionalmente. Existem testes específicos de migração estrutural de praças, preservação de referências e idempotência em `tests/Square/StructuralSquareMigrationTest.php`.
- **Concorrência:** há cenários com processos/conexões separados em `GamificationConcurrencyTest.php` e `ComunaPinnedPostTest.php`; são verificações adicionais às transações usuais da suíte.
- **Dependências controladas:** o ambiente de teste usa transporte de e-mail nulo, armazenamento em memória e substitutos de validação/revogação social. Isso evita depender dos provedores externos nos testes funcionais. A CI instala `qpdf` para validação de PDFs.
- **Cobertura:** `composer test:coverage` solicita saída textual e Clover em `var/coverage/clover.xml`. A CI usa PCOV e publica esse XML. Não há limiar mínimo de cobertura configurado no workflow ou no PHPUnit. Não foram encontrados executores dedicados de testes de navegador, carga ou mutação nas configurações analisadas.

## Ferramentas de análise estática e qualidade

| Ferramenta | Configuração e alcance | Comando |
| --- | --- | --- |
| PHPStan `^2.3` | Nível 8; analisa `src/` e `tests/`, com `migrations/` em `scanDirectories` para descoberta de símbolos. Extensões Doctrine, Symfony e PHPUnit. Usa o contêiner XML de desenvolvimento e `tests/object-manager.php`, que inicializa o kernel `dev`. | `composer stan` |
| Psalm `^7.0.2` | `errorLevel="6"`; analisa `src/`, com supressões em `psalm-baseline.xml`. Detecção de código não usado e de entradas obsoletas do baseline desativadas. | `composer psalm` |
| PHP-CS-Fixer `^3.95` | Regras `@Symfony` e `declare_strict_types`, regras arriscadas permitidas; busca no projeto excluindo `var/`, `vendor/`, `config/bundles.php` e `config/reference.php`. É uma verificação de estilo, separada da análise de tipos. | `composer cs` (somente verifica); `composer cs-fix` (altera arquivos) |
| Auditoria Composer | Verifica vulnerabilidades das dependências; não é um analisador do código da aplicação. | `composer audit` |

`composer check` encadeia estilo, PHPStan, Psalm, PHPUnit e auditoria de dependências. As configurações estão em `phpstan.dist.neon`, `psalm.xml` e `.php-cs-fixer.dist.php`.

## Configuração do GitHub Actions

Existe um workflow, `.github/workflows/ci.yml`, chamado **CI**:

- Dispara em `push` para `main` e em `pull_request` com destino a `main`. Não declara execução agendada ou manual.
- Possui um único job, **Qualidade e testes**, em `ubuntu-latest`, sem matriz de versões.
- Sobe PostgreSQL `18.6-alpine`, expõe a porta 5432 e verifica disponibilidade com `pg_isready`. Usa credenciais locais de CI; nos testes, `DATABASE_URL` aponta para esse serviço e o banco efetivo é `popularia_test`. O usuário permite criar o banco e as extensões necessárias.
- Usa `actions/checkout@v4`, `shivammathur/setup-php@v2` com PHP 8.4, extensões `pdo_pgsql`, `intl`, `opcache`, `zip`, `apcu` e cobertura PCOV.
- Armazena `vendor/` com `actions/cache@v4`, chave baseada no hash de `composer.lock` e restauração pelo prefixo `composer-`. Instala dependências com `composer install --no-progress --no-scripts` e instala `qpdf` via APT.
- Executa, sequencialmente: `composer cs`, `composer stan`, `composer psalm`, `composer test`, nova execução completa do PHPUnit com cobertura, upload do Clover por `actions/upload-artifact@v4` e `composer audit`.
- O upload exige a presença do relatório (`if-no-files-found: error`). Como não há condição `always()`, falhas anteriores impedem os passos seguintes, inclusive a publicação de evidências e a auditoria final.
- Não declara `timeout-minutes`, `concurrency`, permissões explícitas do token, relatório JUnit ou limiar de cobertura. Não há etapa de implantação nesse workflow.

## Melhorias recomendadas, por prioridade

1. **Tornar explícito o preparo do PHPStan em checkout limpo.** `phpstan.dist.neon` aponta para `var/cache/dev/App_KernelDevDebugContainer.xml`, mas a CI instala com `--no-scripts` e não aquece o cache antes da análise. Definir as variáveis necessárias e gerar o contêiner `dev` antes de `composer stan`, verificando sua existência. Confirmar também a inicialização de `tests/object-manager.php` com o ambiente da CI: as variáveis do PostgreSQL são fornecidas apenas aos passos de testes/cobertura. Trata-se de um risco identificado por leitura, não de uma falha reproduzida.
2. **Executar a suíte uma única vez, já com cobertura.** Substituir os passos separados de testes e cobertura por uma execução que produza também JUnit. Isso evita repetir todos os testes e a recriação/aplicação de migrations. Publicar relatórios disponíveis mesmo em falhas, com condições adequadas, para facilitar o diagnóstico.
3. **Executar a auditoria de dependências independentemente dos testes.** Colocar `composer audit` em um job próprio ou estabelecer condição que permita executá-lo após falhas de qualidade, desde que as dependências estejam disponíveis. Hoje uma falha em estilo, análise ou PHPUnit impede essa verificação.
4. **Transformar cobertura em critério de regressão.** Medir primeiro o resultado real do Clover e então definir um limiar inicial ou controle de cobertura do código alterado. Atualmente o relatório é publicado, mas uma queda de cobertura por si só não reprova o job.
5. **Limitar e endurecer a execução do workflow.** Definir `timeout-minutes`, cancelar execuções substituídas com `concurrency`, declarar permissões mínimas como `contents: read` e fixar as actions por SHA de commit revisado, com atualização automatizada. Isso reduz execuções obsoletas e torna as dependências do pipeline mais controláveis.
