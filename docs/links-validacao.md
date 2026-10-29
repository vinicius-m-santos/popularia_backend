# Validação de links e compartilhamento — 29/10/2026

Arquitetura, URLs, privacidade, configuração externa e roteiro completo em
[links-e-compartilhamento.md](links-e-compartilhamento.md).

## Checks executados

| Check | Resultado |
| --- | --- |
| Backend: `php bin/phpunit` no container PHP 8.4.27 | **264 testes, 2.275 assertions, todos passaram**; banco de teste recriado pelas migrations |
| Backend: `composer stan` | **Passou**, sem erros |
| Backend: `lint:container`, dev e prod | **Passou**; prod com `PUBLIC_LINK_ORIGIN` HTTPS explícita; deprecações anteriores Symfony continuam |
| Backend: `lint:twig templates/sharing` | **Passou** |
| Estilo PHP dos três serviços e do teste novo | **Passou**, sem correções pendentes |
| Backend: `composer cs` global | Falha nos mesmos cinco arquivos anteriores da validação de notificações; nenhum arquivo da feature |
| Mobile: `npm run typecheck` | **Passou** |
| Mobile: `npm run lint` | **Passou**, sem warnings |
| Mobile: Jest completo | **39 suítes, 280 testes, todos passaram** |
| Mobile: Expo Doctor | **20/20 checks passaram** na repetição com rede |
| Expo config: staging, production e testflight-staging | **Passou**: host próprio por ambiente, quatro prefixos, IDs/scheme preservados; origem divergente recusada |
| `expo config --type introspect` em produção | **Passou**: entitlement `applinks:api.popularia.com.br` e manifest Android com os intent filters gerados; saída em `/tmp/popularia-links-native-config.json` |
| `expo export --platform all` em configuração de produção | **Passou**, iOS/Android/web; export final em `/tmp/popularia-links-export-final` e log em `/tmp/popularia-links-export-final.log` |
| HTTP real no Caddy/FrankenPHP local | AASA e assetlinks: **200**, `application/json`, sem redirect; imagem OG: **200**, `image/png` |
| `git diff --check` | **Passou** nos dois projetos |
| Smoke visual com Playwright/Chromium | Não executou: navegador instalado depende de `libnspr4.so`, ausente também fora do sandbox. Nenhum screenshot/validação visual foi alegado |

Não houve migration nova nem alteração de dependências/lockfiles. Não foi necessário
alterar OpenAPI: os caminhos adicionados são páginas HTML e associações, fora da API JSON.

## Cobertura adicionada

**Backend:** `tests/Api/SharingTest.php`, 11 testes novos. Cobre HTML inicial sem JS,
canonical independente de Host/query recebidos, OG/Twitter, imagem padrão, MIME/headers,
perfil/comuna pública, perfil/comuna privada e seus posts, conteúdo removido após preview
público sem cache persistente, bloqueio para visitante autenticado, IDs inválidos/inexistentes,
PDF protegido mesmo com `isPublic=true`, associações vazias/configuradas e ausência de
segredos/redirect. A suíte existente continua conferindo autorização das telas/endpoints.

**Mobile:** 29 testes novos, somados aos 251 anteriores:

- `features/links/__tests__/destinations.test.ts`: URLs dos quatro recursos, round trip de
  HTTPS/scheme, entradas inválidas/host de outro ambiente/recurso desconhecido/ID local,
  queries sensíveis e parâmetros de redes sociais, resolver compartilhado com notificações,
  filas iniciais, share nativo iOS/Android com contexto curto e URL.
- `features/notifications/__tests__/push-runtime.test.tsx`: mantém os testes anteriores de
  push e adiciona cold start com restauração/termos/onboarding/boas-vindas, aberto/background,
  confirmação da ponte pelo Router, login sem link seguido de link futuro e corrida
  link-versus-leitura assíncrona de push.
- `features/library/__tests__/document-screen.test.tsx`: ação de share pelo menu sem baixar
  o arquivo; destinos inexistentes/sem permissão (404/403) não mostram dados ou ação de share.

Os testes de native intent/runtime simulam a entrega do evento por Expo Router e os estados
da conta; não equivalem à instalação em aparelho nem comprovam verificação de domínio.

## Pendências anteriores e limites desta execução

`composer cs` global mantém as cinco pendências anteriores:

- `src/Api/Support/ContactMessage.php`;
- `src/Api/Support/ContactMessageProcessor.php`;
- `src/Api/Library/LocalFileController.php`;
- `src/Api/Waitlist/WaitlistProcessor.php`;
- `migrations/Version20261030130000.php`.

Jest completo foi executado com `npm test -- --runInBand --forceExit`, como na validação de
notificações. Timers de GC anteriores do TanStack Query no teste de image-cache continuam
fora do escopo; não houve diagnóstico novo de handles nem correção desses testes.

O export mantém warnings anteriores de `expo-router/build/global-state/router-store` sobre
`exports` e fallback de resolução, além de `NO_COLOR`/`FORCE_COLOR`. O export final concluiu
com exit code 0. A configuração nativa foi inspecionada sem criar/editar `ios/` ou `android/`.

Expo Doctor inicialmente falhou por DNS do sandbox (`EAI_AGAIN`); a repetição com rede passou.
Não se alteraram dependências para resolver problemas anteriores nem se executou nova auditoria
de vulnerabilidades sem mudanças na árvore; o estado anterior está em
[notificacoes-validacao.md](notificacoes-validacao.md).

## Verificação externa restante

Configurar `PUBLIC_LINK_ORIGIN`, `LINKS_APPLE_TEAM_ID` e `LINKS_ANDROID_SHA256` em cada backend,
publicar os endpoints nos hosts HTTPS existentes, gerar novas builds assinadas e executar o
roteiro de iPhone/Android/redes sociais no relatório funcional. As associações locais sem
credenciais de assinatura retornam listas vazias intencionalmente, e ainda não autorizam apps.

Sem Team ID/fingerprints inventados, deploy remoto, build assinada, teste físico em aparelhos
ou envio real para WhatsApp/Telegram/Discord/X/Meta nesta execução. A compatibilidade real de
associação e unfurl depende dessas etapas externas; os checks locais não as substituem.
