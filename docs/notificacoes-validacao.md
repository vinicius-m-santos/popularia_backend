# Validação das notificações — 29/10/2026

Relatório funcional, operação, configuração externa e roteiro em aparelhos:
[notificacoes.md](notificacoes.md).

## Checks executados

| Check | Resultado |
| --- | --- |
| Backend: `php bin/phpunit` no container PHP 8.4.27 | **253 testes, 2.203 assertions, todos passaram**; banco de teste recriado pelas migrations |
| Backend: `composer stan` | **Passou**, nenhum erro |
| Backend: `lint:container` em dev e prod | **Passou**; configuração de produção emitiu deprecações anteriores da configuração Symfony |
| Estilo PHP dos arquivos da feature | **Passou**, 30 arquivos conferidos, nenhuma correção pendente |
| Backend: `composer cs` global | Falha por cinco arquivos anteriores, listados abaixo |
| Doctrine: `doctrine:schema:validate --env=test` | Mapeamentos corretos; schema global tem diferenças anteriores. Dump SQL não pede alterações nas novas tabelas/coluna/índice de push |
| OpenAPI: `composer openapi` | Exportação passou. Foram incorporados ao arquivo versionado apenas os novos caminhos/schemas de notificações, preservando a documentação de outros recursos |
| Mobile: `npm run typecheck` | **Passou** |
| Mobile: `npm run lint` | **Passou**, sem warnings |
| Mobile: Jest completo | **38 suítes, 251 testes, todos passaram** |
| Mobile: testes de notificações | 34 testes entre tela/API, push e runtime; incluídos nos 251 da suíte completa |
| Mobile: `npx expo-doctor` | **20/20 checks passaram** |
| Mobile: `npx expo export --platform all` | **Passou** para iOS, Android e web; artefatos finais em `/tmp/popularia-notifications-export-final` |
| `git diff --check` | **Passou** nos dois projetos |
| Backend: `composer audit` | Dois advisories em dependências já existentes, detalhados abaixo |
| Mobile: `npm audit --omit=dev` | 15 alertas (12 low, 3 high) na árvore existente; detalhados abaixo |

O provider foi substituído por `MockHttpClient` nos testes. Nenhum push real foi enviado.
Não foi executada uma build nativa assinada nem validação física de entrega APNs/FCM nesta
execução; essas etapas dependem das credenciais e do roteiro descrito no relatório funcional.

## Pendências anteriores mantidas fora do escopo

`composer cs` global encontra estilo em:

- `src/Api/Support/ContactMessage.php`;
- `src/Api/Support/ContactMessageProcessor.php`;
- `src/Api/Library/LocalFileController.php`;
- `src/Api/Waitlist/WaitlistProcessor.php`;
- `migrations/Version20261030130000.php`.

O dump de schema global aponta índices/FKs anteriores de biblioteca, praça, follows,
descoberta, memberships e nomes de índices de notificações antigas. Nenhum `schema:update`
foi aplicado. As migrations completas se aplicam e a suíte de testes passa.

Jest normal também foi executado. Todos os testes passaram, mas o processo mantém timers
abertos. `--detectOpenHandles` identificou três timers de GC do TanStack Query em
`src/features/media/__tests__/image-cache.test.ts` (linhas 41/42 e `publishCover` na linha 44).
Esse teste anterior não foi modificado. A execução final usou
`npm test -- --runInBand --forceExit` para devolver exit code 0 após as 38 suítes. As consultas
de notificações têm cancelamento e limpeza no unmount; a tela testa também leitura otimista
com rollback e recebimento durante a primeira carga.

O export conclui com warnings do pacote Expo Router sobre `exports` de
`build/global-state/router-store` e fallback de resolução, além de `NO_COLOR`/`FORCE_COLOR`.
Não foi alterado o pacote Router para corrigir isso.

`composer audit` informou:

- `phpunit/phpunit` 13.4.3: GHSA-9vw2-f4vp-x7gf, corrigido a partir de 13.4.4;
- `symfony/mime`: CVE-2026-45070, advisory na versão instalada.

A auditoria npm aponta alertas high em `zod` e seus dependentes `@expo/cli`/`expo`, além dos
alertas low da árvore existente. `expo-notifications` não aparece como pacote vulnerável
nesse resultado. O lockfile só adiciona a dependência de notificações e seus transitivos;
não foram atualizadas dependências fora do escopo.

As primeiras consultas npm/Expo Doctor falharam por DNS no sandbox; as repetições com acesso
à rede concluíram e os resultados acima são dessas consultas completas.

## Verificação externa restante

Aplicar migration, ativar worker, configurar credenciais APNs/FCM no EAS e
`GOOGLE_SERVICES_JSON` para Android, gerar novas builds e executar o roteiro em iPhone e
Android do relatório. Os identifiers e o projeto EAS existentes foram preservados.
Nenhum serviço remoto foi publicado ou modificado nesta execução.
