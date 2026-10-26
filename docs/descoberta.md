# Explorar e assuntos

## Auditoria direcionada (antes da implementação)

- API Symfony/API Platform + Doctrine/PostgreSQL 18. `Topic` é enum oficial com 18 códigos; `/topics` já publica nomes e grupos. Não há tabela paralela nem criação de assuntos por usuários.
- `User.interests` é JSONB da mesma taxonomia, editável em `/me`; os perfis públicos não o expõem. Onboarding e edição de interesses mobile já consomem `/topics`.
- `Comuna.topics` é JSONB, até três itens na criação da API. O formulário mobile não envia assuntos. A edição estrutural está em `ComunaGovernance::updateDetails`, com transação, lock, owner e recusa de arquivadas; apenas descrição/regras eram editáveis.
- Posts têm texto, autor e comuna opcional; fora de comuna são públicos no perfil. Não existe edição de posts no produto. Remoção e exclusão têm estados distintos. A leitura usa ContentPolicy e bloqueios bilaterais; comunas privadas exigem membership para conteúdo, mas seus metadados são públicos.
- Feed/listas usam UUID v7 como cursor, eager loading de autor/comuna e memberships em lote. Existem índices de comuna/autor/status em posts, unicidade de membership, bloqueios nos dois sentidos e follow. Não há busca real ou índices textuais.
- Explorar mobile carrega um índice inteiro de mocks; com API configurada falha. Busca é local, sem debounce/paginação. Os filtros e cards editoriais já existem. Não existe página dedicada de assunto na navegação atual.
- UI: Archivo/Archivo Narrow e IBM Plex Mono, creme/preto/vermelho, Screen com safe area/largura máxima, SectionHeading, ChipRow, ComunaRow, PersonRow, PostCard, StateView e BottomSheet. `display` já usa entrelinha 48 para fonte 40; novos títulos precisam permitir quebra e altura natural.
- Fetching: hooks `useAsyncData` e `usePaginatedData` descartam respostas antigas e limpam conteúdo após 401/403/404; contexto de interações mantém participação/follow e versão de posts. Telas de comuna recarregam ao recuperar foco; não há cache global de consultas.

## Plano interno por camadas

1. Reutilizar a taxonomia, definir assunto principal e edição autorizada; assunto opcional nos posts de perfil. Validar contratos e autorização.
2. Migration PostgreSQL com normalização de acentos, trigramas e índices de descoberta; serviço de consulta com visibilidade no SQL e hidratação em lote.
3. API agregada de previews e busca paginada, global/contextual. Testes de segurança, paginação, ranking e herança dinâmica antes da integração mobile.
4. Integrar Explorar/página de assunto, com debounce, filtros, seções, navegação existente e estados; seletor oficial na criação/gestão e criação de posts gerais.
5. Testes mobile, typecheck/lint, suíte backend/PHPStan/CS fixer; revisão de privacidade, consultas, cache, navegação, teclado e tipografia.

## Decisões

- O primeiro elemento de `Comuna.topics` é o assunto principal para descoberta. Dados legados com vários assuntos são preservados; criar/editar pelo app oferece uma escolha. A API continua aceitando o contrato legado na criação, sem criar outra categoria.
- Posts de comuna derivam o assunto de `comuna.topics[0]` em cada consulta. Alterar o assunto não reescreve posts. Posts do perfil têm `topic` opcional na mesma enum; sem assunto continuam na busca global, sem aparecer artificialmente em um assunto.
- Não criar edição de texto de posts como efeito colateral: esse fluxo não existe. Seleção de assunto acompanha a criação; uma futura edição deve reutilizar o mesmo campo e autorização de autoria.
- Metadados públicos de comunas privadas continuam descobríveis; conteúdo só é retornado para membros. Arquivadas ficam fora da descoberta, mantendo as rotas de histórico existentes. Bloqueios são bilaterais para pessoas/autorias; não tornam uma comuna coletiva invisível por bloqueio com um membro.

## Ambiente oficial de validação

O projeto executa PHP pelo serviço `php` do Docker Compose, construído com
`dunglas/frankenphp:1.13-php8.4` (target `dev`). O Composer exige PHP `>=8.4` e fixa
`config.platform.php` em `8.4.27`; essa versão de resolução não substitui a conferência
da versão efetivamente instalada no container. O PostgreSQL é o serviço `database`,
imagem `postgres:18.6-alpine`; a API espera sua saúde antes de iniciar. O Compose
inclui Mailpit (`mailer`) para captura de e-mails.

Os scripts oficiais são `composer test`, `composer stan` (nível 8), `composer cs`
(check sem escrita), `composer cs-fix`, `composer audit` e `composer openapi`.
`composer check` agrega estilo, análise estática, testes e auditoria de dependências.
Nenhuma configuração ou dependência foi adaptada para o PHP 8.1 do host.

PHPUnit força `APP_ENV=test`, usa `tests/bootstrap.php` e considera warnings/notices/
depreciações como falhas. O bootstrap recria somente o banco de testes, com o sufixo
`_test`, aplicando as migrations; DAMA desfaz a transação de cada teste. Isso não
reaplica migrations no banco de desenvolvimento já validado pelo usuário.

O usuário executou os comandos oficiais no terminal da máquina: Docker Desktop
29.5.2 sobre WSL2, Compose 5.1.4, PHP **8.4.27 ZTS** e PostgreSQL 18.6. O ambiente
oficial funciona nesse terminal. O host disponível diretamente ao agente continua
com PHP 8.1.2, e o acesso ao socket Docker permanece negado dentro desta sessão;
essa restrição não deve ser confundida com falha do Docker na máquina do usuário.
Nenhum ambiente alternativo foi criado.

## Contratos e ranking implementados

- `GET /api/v1/explore?topic=tec`: até cinco comunas, pessoas e discussões; no assunto, também cinco publicações públicas recentes. Pessoas não participam da descoberta contextual. Resposta inclui a taxonomia oficial e relações de participação/follow em lote. Previews não são cursores de ranking: “Ver todas” abre uma listagem cronológica independente.
- `GET /api/v1/search?q=linux&type=all&topic=tec`: `type=all|comunas|people|posts`, `limit=1..50` (padrão 20), UUID `cursor`. “Tudo” limita a três por categoria; paginação só na categoria específica, ordenada por UUID v7 descendente. `next` contém cursores por categoria. Query vazia permite listar uma categoria. Termos exigem 3–100 caracteres e três letras/números consecutivos; @ inicial é aceito para handles. `%`, `_` e o escape são literais.
- `PATCH /api/v1/comunas/{slug}` aceita `topic` oficial e mantém owner/lock/recusa de arquivadas/auditoria. `primaryTopic` expõe o rótulo oficial sem pedir uma consulta extra por card.
- `POST /api/v1/posts` aceita `topic` opcional apenas sem comuna; enviar assunto próprio com comuna é 400. `PostView.topic` devolve o assunto efetivo.
- Discussões: janela de 14 dias; `(1 + min(comentários, 20)) / (1 + idade em dias)`, multiplicado por 1,5 quando o assunto está nos interesses de quem vê. Desempate por UUID descendente. A janela retira conteúdo antigo independentemente do engajamento acumulado. Só publicações públicas entram nos destaques/recentes.
- Comunas globais: excluem as já frequentadas, afinidade primeiro, depois membros e UUID descendente. No assunto, listam também as conhecidas. Pessoas: contas ativas mais novas (UUID descendente), sem o próprio usuário, sem bloqueios e, nas sugestões, sem quem já segue. Nenhum interesse privado é devolvido.
- Busca permite posts privados somente para membros atuais; arquivadas, removidos/apagados, autores inativos e bloqueados bilateralmente são filtrados no SQL. Metadados coletivos de comunas privadas permanecem públicos como antes; previews omitem a identidade de owner bloqueado, sem ocultar a comuna inteira.
- Busca textual usa `unaccent` com dicionário fixo, lowercase e índices GIN/`pg_trgm` sobre as mesmas expressões do `LIKE`. Índices parciais cobrem somente contas/comunas ativas e posts publicados. Há índices de assunto principal, assunto de post geral e recência. Alterar regras do dicionário exige reconstruir os índices.
- Mobile: debounce 350 ms, mínimo acima, hooks existentes ignoram gerações antigas, paginação “Carregar mais”, retorno preserva texto/filtro e revalida ao recuperar foco. Não há cache global adicional; interesses alterados invalidam os previews. Resultados reutilizam rotas de perfil/comuna/discussão na pilha Explorar. Comuna privada oferece “Solicitar”, abrindo o fluxo existente de solicitação no detalhe.
- Trechos de publicações mostram autor, comuna/perfil, texto, recência e comentários reais.
- Títulos têm altura natural e quebra; display 40/48 e ranking 38/48. Título de BottomSheet passa a 32/40. Rótulos de seções podem encolher/quebrar sem empurrar ações para fora. A folha existente cuida de safe area e rolagem; o seletor não aninha outro ScrollView.

Referências técnicas consultadas: [pg_trgm PostgreSQL 18](https://www.postgresql.org/docs/18/pgtrgm.html), [unaccent](https://www.postgresql.org/docs/18/unaccent.html), [Expo Router SDK 58](https://docs.expo.dev/versions/v58.0.0/sdk/router/).

## Estado da validação com os resultados executados pelo usuário

- **Validação manual confirmada:** migration aplicada, endpoints após a migration, funcionamento básico da API e feature no celular. Concluída; não foi refeita nesta tarefa.
- **Mobile (execução anterior):** 15 suítes/118 testes aprovados, TypeScript e lint aprovados. Os checks mobile não foram repetidos nesta tarefa exclusivamente backend.
- **PHPUnit, PHP 8.4.27 / PHPUnit 13.4.3:** `DiscoveryTest` aprovado com **8 testes e 91 assertions**; suíte completa aprovada com **143 testes e 1.105 assertions**, conforme os logs enviados pelo usuário. A suíte completa utilizou o reset normal do banco de testes.
- **Composer audit:** aprovado, sem avisos de vulnerabilidades conhecidos na execução informada pelo usuário.
- **PHPStan:** a execução informou dois erros. Em `DiscoveryProvider`, `getUriTemplate()` pertence a `HttpOperation`, não ao tipo base `Operation`; foi adicionada a verificação real de tipo com `instanceof HttpOperation`. No teste de notificações, uma resposta vazia de uma chamada HTTP anterior era reaproveitada pela análise estática como se o helper fosse puro. `ApiTestCase::request` foi marcado com `@phpstan-impure`, representando seus efeitos reais sobre kernel/banco e leitura de estado mutável. Não foram adicionados ignores/baselines nem removidas assertions. **A execução após essas correções ainda precisa ser confirmada.**
- **CS Fixer/check:** os cinco arquivos apontados foram ajustados conforme o diff produzido pelo próprio fixer: alinhamento PHPDoc, imports, aspas e organização de linhas/espaços. As expressões SQL permanecem idênticas. **O check após esses ajustes ainda precisa ser confirmado.**
- **OpenAPI:** o usuário executou `composer openapi` com sucesso. O arquivo gerado `docs/openapi.json` foi conferido nesta sessão: contém `/api/v1/explore`, `/api/v1/search`, os parâmetros de busca e os campos de resposta agregada; `topic` está presente, com enum oficial e nullable, nos schemas de entrada de `PATCH /api/v1/comunas/{slug}` e `POST /api/v1/posts`. O snapshot não foi editado manualmente. Exportação e conferência concluídas.
- **`git diff --check`:** aprovado após as correções desta sessão.
- **Scripts oficiais:** `bin/console` e `bin/phpunit` tiveram o bit de execução restaurado de `644` para `755` na etapa anterior; seus conteúdos foram preservados.
- **Comportamento preservado:** nenhuma alteração nas regras de ranking, paginação, assunto, autorização, visibilidade, bloqueios ou remoção; nenhuma nova feature ou migration. O guard de tipo conserva o comportamento das operações HTTP existentes. As demais alterações são de metadados de análise e estilo.

### Confirmação final após as correções

O aviso de Git sobre ownership vem do processo dentro do container. Adicionar
`safe.directory /app` no Git do host não configura o Git do container. Para confiar
somente neste checkout conhecido e executar os checks oficiais após os ajustes:

```bash
cd /home/vini/projects/popularia_backend
docker compose exec -T php git config --global --add safe.directory /app
docker compose exec -T php composer check
git diff --check
```

`composer check` executa CS Fixer/check, PHPStan, a suíte completa e Composer audit,
sem alterar suas regras. Não é necessário regenerar novamente o OpenAPI por causa
destas correções: nenhum contrato foi alterado depois da exportação já conferida.

A saída enviada também mostra o serviço `php` como `unhealthy`, apesar de API e
CLI funcionarem. Não foi possível consultar a causa do healthcheck nesta sessão;
não se atribuiu esse estado a Busca/Explorar nem se alterou infraestrutura por
suposição. O diagnóstico exato pode ser obtido no terminal com:

```bash
docker inspect --format '{{json .State.Health}}' popularia_backend-php-1
```

**Pendências reais:** confirmar `composer check` após as correções aplicadas e
esclarecer o healthcheck `unhealthy` do serviço PHP. Os testes já aprovados, a
auditoria de dependências, a migration, a validação no celular e o OpenAPI gerado
não permanecem registrados como bloqueados ou não executados.
