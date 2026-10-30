# Popularia — auditoria de freeze V1

Data: 30/10/2026. Bases: backend `7a2ae59`, mobile `8fcf954`, mais as alterações locais desta auditoria. Nenhum deploy, migration remota, envio de push ou publicação de build foi feito. Evidências locais em [beta-audit-evidence](beta-audit-evidence/). Após a queda do WSL, os checks finais foram repetidos e registrados fora de `/tmp`.

## A. Executive summary

**Inicial:** produto com cobertura funcional extensa, mas riscos reais em troca de sessão, notificações de comunas suspensas, preservação de denúncias, reminders, pins e lifecycle de vídeo. Backend passava 368 testes/3.823 assertions; mobile passava 66 suítes/418 testes, mas o processo Jest não encerrava normalmente. Assets do menu eram decodificados na primeira abertura, e ilustrações locais usavam fade Android.

**Final:** aproximadamente **3 P0, 9 P1 e 5 P2** tratados, agrupando sintomas com a mesma causa. Backend: **375 testes/3.903 assertions**. Mobile: **68 suítes/426 testes**, encerramento normal, TypeScript e lint sem erros ou warnings. PHPStan e CS Fixer passaram. Expo Doctor: **20/20**. Exportações Hermes Android/iOS concluídas, cerca de **8,2 MB por bundle**. Nenhuma dependência adicionada.

O código está em condição de gerar o próximo candidato. A preparação completa do ambiente para QA físico ainda está incompleta: associações HTTPS reais não funcionam, worker/credenciais push não foram comprovados em operação e não há binário release assinado validado nesta auditoria. Não confundir export JS com compilação nativa, nem testes Jest com fluidez medida em aparelho.

## B. Correções realizadas

### [P0] Respostas e mutations atravessavam a troca de conta

**Causa:** a geração da sessão protegia refresh, mas não toda request autenticada. Uma resposta 401 antiga podia repetir uma mutation usando a conta nova; uma 403 antiga podia encerrá-la; JSON privado podia terminar de ser lido após logout. Gravações assíncronas do Keychain também podiam terminar fora de ordem.

**Correção:** validação da geração após cada await relevante do transporte e depois do parse JSON; nenhuma repetição na identidade nova; fila serial para salvar/apagar refresh token; listener de sessão encerrada acionado antes de aguardar armazenamento. [client.ts](../../popularia_mobile/src/lib/api/client.ts).

**Validação:** regressões de respostas 200/401/403 tardias, JSON tardio e escrita lenta do Keychain concorrente com logout/login; testes completos mobile passaram.

### [P0] Avisos e reminders expunham atividade de comuna suspensa

**Causa:** visibilidade das notificações não excluía suspensão da comuna; worker podia produzir lembrete enquanto ela estava suspensa.

**Correção:** filtro antes de paginação/contagem no repositório e verificação de suspensão no worker.

**Validação:** `EventNotificationTest`: inbox, contagem e envio bloqueados para comuna suspensa; suíte completa passou.

### [P0] Exclusão de reporter/comuna apagava denúncias

**Causa:** FKs com CASCADE e exclusão explícita dos reports enviados na deleção de conta.

**Correção:** reporter nullable, FKs SET NULL, remoção da descrição livre do reporter excluído e trigger que encaminha casos órfãos à plataforma. Snapshot, motivo estruturado e trilha sobrevivem. [Migration](../migrations/Version20261110120000.php). Casos já apagados anteriormente não são reconstruídos.

**Validação:** `ReportPreservationTest` exercita exclusão de conta pela API, DELETE real da comuna e leitura do caso pela plataforma; migrations reaplicadas do zero na suíte.

### [P1] Logout com erro deixava a interface autenticada aberta

**Causa:** a API apagava tokens no finally, mas o AccountProvider só limpava estado quando a revogação terminava sem erro. Toasts e sheets globais também sobreviviam à identidade.

**Correção:** limpeza do estado em finally e reinício dos providers de avisos, moderação, toast e ações quando status/ID da conta mudam.

**Validação:** regressão de erro 500 na revogação confirma conta nula, rascunho vazio e cache vazio; lint, TypeScript, testes e exportações passaram.

### [P1] Reentrada em comuna não reconstruía reminder

**Causa:** o worker descartava reminder sem acesso; reentrada/aprovação não reavaliava RSVP existente.

**Correção:** restauração em lote ao entrar ou ter pedido aprovado, apenas para RSVP going/maybe e evento elegível. `ON CONFLICT DO NOTHING` preserva envio anterior e impede duplicação. Mantidas as regras existentes: antecedência mínima de duas horas; lembrete em 24h ou 1h conforme proximidade.

**Validação:** saída/reentrada privada após descarte, envio único, repetição do worker e reentrada pública após lembrete já enviado.

### [P1] Suspensão do autor destruía pin editorial

**Causa:** leitura eliminava permanentemente o pin ao encontrar autoria suspensa.

**Correção:** pin pertence à comuna; fica oculto enquanto autoria não pode aparecer e volta após reativação. Continua ocupando uma das três posições. Remoção/exclusão do post libera o pin; não há restauração automática de pin de conteúdo removido.

**Validação:** `ComunaPinnedPostTest` confirma ocultação, preservação e retorno após reativação.

### [P1] Pin de enquete truncava opções e pins seguintes

**Causa:** LIMIT 3 aplicado às linhas de JOIN das opções, em vez de três publicações.

**Correção:** retirada desse limite na hidratação; o limite de três pins continua garantido no banco. Opções ordenadas por posição.

**Validação:** regressão com quatro opções e outra publicação fixada; suite de pins passou.

### [P1] Ilustrações estruturais dependiam da primeira abertura

**Causa:** require estático inclui arquivo no bundle, mas não garante bitmap já decodificado; RN Image aplica fade Android por padrão.

**Correção:** quatro ilustrações recorrentes do menu são decodificadas no início por `expo-image`, com resolução limitada e ImageRefs retidas; fonte local de fallback e dimensões fixas. `transition=0` no menu; `fadeDuration=0` nas ilustrações locais de Home, Explore, Biblioteca, Praça, onboarding e capa local. [creation-assets.tsx](../../popularia_mobile/src/features/media/creation-assets.tsx).

**Validação:** typecheck, lint e bundles Android/iOS passaram. Preload não bloqueia startup e falha sem bloquear menu. Ganho visual e caso de tap imediatamente após cold launch **ainda precisam de aparelho**; não há medição nativa de decode/frame nesta auditoria.

### [P1] Vídeo podia iniciar antes da exclusividade e seguir em background

**Causa:** play no setup nativo podia emitir evento antes da inscrição do listener; pausa de background não era explícita.

**Correção:** efeito coordena pausa do player anterior antes do play, inicia apenas com app ativo e pausa ao sair do estado active. Cleanup remove listener. Mantidos lazy load, descarte fora da viewport, reset de intenção ao trocar mídia e liberação pelo hook nativo.

**Validação:** regressões de AppState/cleanup, intenção e viewport; suíte mobile passou. Controles nativos, áudio e interrupções reais pendentes de QA físico.

### [P1] Remoção moderada não avisava autoria

**Causa:** post/comentário simplesmente desaparecia; referências a recurso removido seriam filtradas da inbox.

**Correção:** aviso privado genérico sem vínculo navegável ao conteúdo removido, ator, trecho, reporter ou nota interna. Tipos `post_removed`/`comment_removed`, destino `notice` para Avisos. INSERT idempotente na transação da moderação, inclusive console. Uma vez por conteúdo; não cria appeal. Push usa a categoria existente `comunas`.

**Validação:** `ReportTest` verifica aviso, ausência de dados internos e não duplicação; compilação mobile aceita os novos tipos. Não há aviso ao reporter; Praça/Biblioteca conservam fluxos legados.

### [P1] URL direta de avatar/capa ignorava suspensão

**Causa:** bytes eram servidos sem revalidar status e recebiam cache immutable de um ano.

**Correção:** controller exige conta ativa/comuna não suspensa; cache público passa a 300s com revalidação. São imagens de apresentação públicas, inclusive para perfis privados; não passaram a transportar conteúdo privado de post.

**Validação:** `ProfileImageTest` verifica 404 após suspensão e Cache-Control. Cópias já armazenadas por cliente/CDN sob o header antigo não são revogáveis retroativamente por esta alteração.

### [P1] Jest mantinha handles vivos

**Causa:** quatro timers de garbage collection do QueryClient, isolados em image-cache e follow-cache; os testes limpavam antes, mas não após o último caso.

**Correção:** `queryClient.clear()` no teardown dessas suítes.

**Validação:** suite completa com `--runInBand --detectOpenHandles` encerra sozinha, sem `--forceExit` ou timer ignorado.

### [P2] Toast de ação era substituído por toast de missão

**Causa:** estado com uma única mensagem era sobrescrito por callbacks concorrentes.

**Correção:** fila FIFO mínima, deduplicação enquanto mensagem está na fila, cancelamento de animação/timer no cleanup e anúncio acessível por mensagem.

**Validação:** teste de duas confirmações simultâneas verifica ordem e duração; fila reinicia na troca de identidade.

### [P2] Praça/Biblioteca exibiam catálogo de motivos diferente

**Causa:** lista legada duplicada no mobile.

**Correção:** rótulos derivados do catálogo V1 compartilhado; endpoints legados continuam recebendo texto, preservando compatibilidade.

**Validação:** lint, TypeScript e testes completos. Não houve migração do workflow legado para o novo sistema.

### [P2] Dia da gamificação não era explícito

**Causa:** servidor usa America/Sao_Paulo, mas a interface não explicava a virada.

**Correção:** texto explícito sobre meia-noite no horário de Brasília. Mantida regra global existente para não quebrar streaks nem abrir farming por troca de timezone. A UI já aceita quantidade dinâmica de missões; não foi adicionada promessa de três.

**Validação:** suite de gamificação passou. Teste novo de concorrência usa dois processos/conexões reais, espera ambos bloquearem no mesmo perfil e confirma uma entrada de ação e soma de XP consistente.

### [P2] Metadados e documentação de moderação divergiam

**Causa:** predicados parciais não acompanhavam a forma introspectada pelo PostgreSQL, e docs descreviam nomes/retenção anteriores.

**Correção:** índices de `content_report` alinhados ao schema, documentação de auditoria, notificações e exclusão atualizada. Cinco ajustes pequenos preexistentes de CS Fixer também aplicados; nenhum SQL de migration antiga teve semântica alterada.

**Validação:** mapping Doctrine correto e diff sem alteração de `content_report`; CS Fixer e PHPStan passaram. O diff global ainda contém índices/FKs especiais geridos por migrations: não aplicar `schema:update --force`.

### [P2] Container saudável aparecia unhealthy

**Causa:** healthcheck herdado consultava Caddy admin:2019, mas `admin off` é intencional.

**Correção:** Dockerfile verifica `/api/v1/health` na porta HTTP da aplicação, incluindo conexão com DB.

**Validação:** endpoint local respondeu `{"status":"ok","database":"ok"}`. A imagem não foi reconstruída nesta auditoria; container antigo só recebe o novo healthcheck após rebuild/recreate. Staging e produção responderam HTTP 200 na checagem externa.

## C. Performance

| Área | Resultado e limites |
|---|---|
| Render/list | Mantidos PopulariaList/FlashList e caches existentes. Testes de paginação, tabs, perfil e saves passaram. Removido LIMIT incorreto dos pins. Sem memoização generalizada sem medição. FPS, offsets e reciclagem com mídia exigem release física. |
| Imagens | Frames existentes preservados; fade local removido. Avatares/capas agora revalidam status e têm TTL menor. Mídia privada continua com autorização e assinatura; cache não equivale a revogação de bytes já baixados. |
| Assets | Predecode limitado a quatro arquivos locais do menu; estimativa de até ~2,7 MiB de bitmap retido, não uma medição de RSS. Não pré-carrega biblioteca inteira nem fotos de usuários. |
| Sheets | Estrutura atual Gorhom, scroll e integração do TextField/keyboard mantidos; conteúdo pesado não ficou todo pré-montado. Preload desloca decode para antes da interação quando há tempo. Primeira abertura, rapid reopen e teclado ainda precisam de medida física. |
| Network/cache | Transporte impede resposta/retry de identidade velha; escrita de token serializada; cache/provider reset no logout. Fluxos já possuem debounce, paginação com guarda e rollback. Não foi observado request storm nos cenários automatizados; contagem em navegação real ainda necessária. |
| Media | Pause em background e exclusividade antes do play; players continuam sob hook que libera recursos. Nenhum vídeo é pré-carregado em massa. |
| Startup | Aquecimento de assets paralelo às fontes/restauração, sem wait extra na splash; limites de espera existentes mantidos. Não há valor de cold/warm launch medido em device. |

**DB, medição local sintética:** PostgreSQL 18.6, 5.000 pessoas, 500 comunas, 20.000 publicações; mistura de perfis privados, autores suspensos, comunas públicas/privadas. Consulta `educacao`, primeira página de 20; seleção e hidratação reais de DiscoveryQuery. Script recusa banco sem sufixo `_test`, usa transação e rollback. Não é benchmark de endpoint HTTP, p95 de produção ou teste de carga.

| Consulta | SQLs seleção + hidratação | EXPLAIN execução da seleção | Chamada seleção + hidratação |
|---|---:|---:|---:|
| Pessoas | 2 | 22,54 ms | 42,19 ms |
| Comunas | 2 | 4,21 ms | 13,34 ms |
| Posts (termo amplo) | 2 | 303,58 ms | 329,89 ms |
| Descoberta recente | 2 | 0,26 ms | 9,67 ms |

[Planos completos com buffers](beta-audit-evidence/query-plans.json). Busca ampla fuzzy avalia muitos candidatos; acompanhar antes de ampliar volume. Não foi criado índice/rewrite arriscado sem comparar distribuição real. Eventos, hashtags e fluxos com anexos têm cobertura funcional, mas não receberam benchmark de escala nesta rodada.

## D. Segurança/privacidade

Corrigidos isolamento entre sessões, avisos/reminders de comuna suspensa, acesso direto a imagem suspensa e perda de evidências de moderação. Aviso ao autor não revela autoria da denúncia nem decisão interna.

Revisados filtros de visibilidade, ownership, uploads e serialização. As suítes cobrem IDOR/papéis, bloqueios, perfil/comuna privados, remoção, saves, follow, deep links/OG, MIME/tamanho, autenticação e revogação. Isso é evidência de regressão, não uma certificação de ausência de vulnerabilidade.

Snapshots de até 280 caracteres continuam dados restritos e correlacionáveis. Reporter nulo não garante anonimização irreversível. Prazo de retenção e expurgo precisa de decisão do controlador; não foi inventado um prazo jurídico. [Política e pendência](moderacao-denuncias-v1.md#25-snapshot).

URLs de post emitidas antes de revogação continuam válidas até o TTL existente de assinatura, e bytes baixados não podem ser recolhidos. Se imagens públicas foram servidas por CDN com cache antigo immutable, invalidar essas URLs no provider. Não houve leitura de secrets nem alterações remotas.

## E. Backend

- **Queries:** privacidade aplicada antes de limit/contagem; pins hidratam todas as opções; reminder restaurado por INSERT SELECT, sem loop de consultas por evento. Planos sintéticos acima.
- **Concurrency:** teste novo comprova sobreposição real de dois registros de XP; ledger/constraint/lock impedem duplicação. Reminders e avisos de remoção usam chaves únicas. Não alego testes simultâneos novos para cada mutation: as demais usam cobertura funcional/constraints existente.
- **DB/migrations:** cadeia completa aplicada no banco de teste. Nova migration `Version20261110120000` segue a última versão do repositório (`20261109120000`), apesar de timestamps futuros já existentes. Down intencionalmente irreversível para não reintroduzir perda de evidências. Antes de rollout: backup, aplicar em staging, verificar FKs e casos órfãos; rollback de código deve conservar schema nullable ou usar versão compatível. Não reverter para código que pressupõe reporter não nulo.
- **Moderation:** casos sobrevivem à deleção; author notice genérico; pins suspensos preservados; nomes reais de auditoria documentados. Fluxos legados da Praça/Biblioteca não foram redesenhados; casos órfãos são preservados no banco e listagem administrativa, não aparecem automaticamente na nova fila mobile limitada a post/comment/profile/comuna.
- **Reminders:** saída/reentrada privada e envio anterior cobertos; suspensão não produz aviso; o worker de push também despacha lembretes.
- **Schema validate:** mapping passa; sincronização global ainda falha por índices de expressão/parciais, nomes e FKs compostas intencionais. [Diff apenas diagnóstico](beta-audit-evidence/schema-diff.sql). Não utilizar como migration.

## F. Mobile

- **Navigation/lifecycle:** guard de sessão existente preservado; providers reiniciam na troca de identidade; `notice` abre Avisos sem navegar para conteúdo removido; players pausam em background.
- **Gestures/lists:** thresholds existentes do perfil (36px horizontal/28px vertical) preservados. Nenhuma troca de pager ou list engine. Estado/offset e interação com controles nativos requerem aparelho.
- **Cache:** QueryClient e estado de conta são apagados mesmo com erro de logout; resposta velha rejeitada; Keychain serializado; timers de testes descartados.
- **Keyboard:** componentes existentes de formulário/sheet e keyboard behavior mantidos. Testes de formulário e sheet passam; Android resize, iOS safe area, teclado grande e foco acessível não são comprovados por mocks.
- **Media/assets:** predecode limitado, frames preservados, sem fades locais artificiais, cleanup de vídeo explícito.
- **Accessibility:** fila de toast anuncia cada item. Labels, alvos e estrutura existentes revisados; VoiceOver/TalkBack/font scale e foco de modal pendentes de avaliação física.

## G. Known issues

| Classe | O que realmente permanece |
|---|---|
| **BLOCKER — QA de links HTTPS** | Staging AASA HTTP 200 com `details: []`; assetlinks HTTP 200 com `[]`. Produção retorna 404 nos dois endpoints. Associação automática com o app não está pronta. |
| **BLOCKER — preparação do ambiente** | Correções/migration ainda locais e sem build release assinada desta revisão. Push/reminders dependem de worker cuja execução não foi comprovada; inventário Railway consultado mostra backend e PostgreSQL, sem serviço separado de worker/cron. Pode existir scheduler externo: não foi confirmado. |
| **BEFORE BETA** | Aprovar retenção de snapshots/auditoria e procedimento operacional dos casos legados órfãos; confirmar APNs/FCM, credenciais, recibos e entrega; QA físico completo e evento real no Sentry; conferir manifest/entitlements assinados e formulários de privacidade atualizados. |
| **DURING BETA** | Monitorar busca fuzzy ampla (~304 ms na fixture), memória dos assets, uso de rede/frames reais; acompanhar warning de exports internos `expo-router/.../router-store` que não impediu os dois bundles. Não há erro de runtime reproduzido atribuído a esse warning. |
| **LATER** | Menção abre discussão sem garantir foco no comentário; indicador da tab não segue dedo; Biblioteca pode remontar ao trocar tab; timezone por pessoa exigiria estratégia de migração; recurring events, calendário, drag-and-drop pins, backfill de hashtags e comentários contextuais de evento continuam fora do escopo. |

Não foram inventados problemas físicos a partir de mocks: atraso residual de decode, jank, teclado coberto ou scroll roubado **não foram medidos** aqui. Qualquer reprodução importante no roteiro abaixo reprova o candidato e exige correção antes da distribuição a pessoas reais.

## H. Tests

Comandos no diretório apropriado; logs em `docs/beta-audit-evidence`. Testes backend recriam **somente o banco de testes** por `tests/bootstrap.php`.

| Comando | Resultado final |
|---|---|
| `docker compose exec -T php php bin/phpunit` | 375 testes, 3.903 assertions, 46,375s, passou |
| `docker compose exec -T php php vendor/bin/phpstan analyse --no-progress --memory-limit=512M` | sem erros |
| `docker compose exec -T php php vendor/bin/php-cs-fixer fix --dry-run --diff` | 0/522 arquivos a corrigir |
| `docker compose exec -T php php bin/console doctrine:schema:validate --env=test` | mapping correto; sync global com ressalvas da seção E |
| `docker compose exec -T php php bin/console doctrine:schema:update --dump-sql --env=test` | apenas inspeção; nenhum SQL aplicado |
| `docker compose exec -T php php bin/console api:openapi:export --output=/tmp/audit-openapi.json --env=test` | exportou contrato; OpenApiTest passou na suíte |
| `docker compose exec -T php php docs/beta-audit-evidence/query-plans.php` | EXPLAIN ANALYZE BUFFERS de 4 consultas, fixture revertida |
| `npm run typecheck` | passou |
| `npm run lint` | passou, zero warnings |
| `npm test -- --runInBand --detectOpenHandles` | 68 suítes, 426 testes, 22,998s; encerramento normal |
| `npx --yes expo-doctor` | 20/20 checks |
| `NODE_OPTIONS=--max-old-space-size=2048 npx expo export --platform android --max-workers 1 --output-dir /tmp/popularia-export-android` | export Hermes concluído |
| Mesmo comando com `--platform ios --output-dir /tmp/popularia-export-ios` | export Hermes concluído |
| `npx expo config --type introspect --json`, com env dos perfis EAS | configuração staging/production inspecionada; não assina nem compila binário |
| `git diff --check` nos dois repositórios | passou |

A tentativa inicial de exportar todas as plataformas com paralelismo padrão terminou `Killed`; depois houve queda do WSL. Os exports separados com um worker concluíram. Não usei `forceExit` para esconder handles. Nenhum teste físico, Gradle, Xcode, TestFlight ou APK assinado foi executado/gerado.

## I. Native/build changes

Nenhuma nova dependency, plugin, entitlement, configuração Android/iOS ou asset binário nesta auditoria. `package.json` e lockfile não mudaram. Dockerfile backend recebeu healthcheck correto; exige nova imagem para entrar em vigor.

Já estavam no projeto, e devem entrar na próxima build nativa: datetimepicker 9.2.1, módulos de imagem/vídeo/notificações, Associated Domains e filtros `/p/`, `/u/`, `/c/`, `/d/`, `/e/`, `/t/`. OTA não adiciona módulos nem atualiza manifest/entitlement. Expo Go não substitui essa validação. Config introspect não prova o entitlement final assinado de APNs nem o manifest mesclado do Gradle.

## J. External configuration

1. Publicar o backend revisado em **staging**, aplicar migration com backup e verificar health. Não rode schema:update. Após validar, repetir o processo aprovado para produção.
2. Backend de cada ambiente: `PUBLIC_LINK_ORIGIN` correspondente; `LINKS_APPLE_TEAM_ID` igual ao prefixo real do application-identifier assinado; `LINKS_ANDROID_SHA256` do certificado que assina o APK instalado/Play app signing. IDs/certificados reais não foram inventados. [Instruções existentes](links-e-compartilhamento.md#configuração-externa-restante).
3. Garantir AASA/assetlinks HTTPS com JSON não vazio, paths corretos, sem challenge/redirect. Em produção o código que publica esses endpoints precisa estar implantado.
4. Configurar/confirmar executor de `php bin/console app:notifications:deliver --watch --interval=5` ou execução por minuto. Esse worker também atende reminders; precisa das mesmas configurações/DB/storage do ambiente e política de restart. Não executar um worker de staging contra DB de produção.
5. Confirmar APNs/FCM v1, Expo projectId, push token e recibo de um aparelho real. Revalidar logout offline, revogação pendente e troca de conta. Credenciais secretas ficam no backend/EAS apropriado, não em EXPO_PUBLIC.
6. Gerar Android `staging` e iOS `testflight-staging` com as variáveis EAS completas do perfil; instalar binários. Confirmar IDs Google staging/production e provisioning associado ao domínio correto.
7. Validar DSNs/ambientes e source maps do Sentry com erro controlado na build; conferir ausência de tokens, corpo de texto e dados pessoais indevidos no evento. Confirmar retenção/backups e expurgos já previstos no runbook.
8. Se houver CDN com imagens antigas immutable, invalidar entradas afetadas. Confirmar que bucket de mídia privada não tem URL pública que contorne a API. Sem confirmação remota de ACL nesta rodada.
9. Aprovar prazo e responsável para retenção de evidências; atualizar declarações de coleta das lojas conforme uploads/avatar/push/Sentry reais. Revisar documentação histórica pré-TestFlight antes de reutilizá-la: esta auditoria descreve o estado mais recente.

Checagem pública em 30/10: [health staging](https://api-stg.popularia.com.br/api/v1/health) e [health produção](https://api.popularia.com.br/api/v1/health) HTTP 200, database ok. [AASA staging](https://api-stg.popularia.com.br/.well-known/apple-app-site-association) vazio; [assetlinks staging](https://api-stg.popularia.com.br/.well-known/assetlinks.json) vazio; equivalentes de produção HTTP 404. Inventário Railway consultado em leitura com o workflow [use-railway](../../../.agents/skills/use-railway/SKILL.md); nenhuma configuração remota foi alterada.

## K. Physical test script

Preparar um iPhone e Android intermediário reais, build release assinada, sem Metro. Usar staging com conta A, B, founder, moderator e platform_moderator; comuna pública/privada; perfil privado; post com imagem, dois vídeos, enquete com quatro opções, PDF, evento futuro e três pins. Registrar aparelho/OS/build/API/commit, gravação de tela, request IDs e resultado de cada cenário. Resetar dados de teste antes de repetir fluxos destrutivos.

1. **Cold/warm launch:** 5 aberturas de processo novo e 5 retornos; sessão válida, expirada e offline. Sem splash infinita, flash de fontes ou conteúdo de outra conta. Medir tap→primeiro conteúdo utilizável e consumo de memória inicial.
2. **Menu de criação/sheets:** abrir imediatamente após cold launch e repetir 20 vezes. Testar publicar, PDF e comuna; fechar durante animação, reabrir, backdrop/back Android e swipe iOS. Ilustrações devem estar presentes sem salto de layout nem aparecer depois da estrutura. Registrar primeira abertura separada das demais.
3. **Feed/listas:** percorrer pelo menos cinco páginas, voltar de detalhe, alternar Home/Following/perfil e retornar. Conferir posição, ordem, ausência de duplicatas/empty flash e paginação única. Scroll agressivo com imagem/vídeo/enquete/evento; observar frame drops e crescimento de memória após 10 minutos.
4. **Perfil/gestures:** próprio, alheio, creator, público e privado; primeiro/último tab; swipe horizontal, diagonal, vertical e pull-to-refresh. Scroll vertical não pode trocar tab; tabs/controles de vídeo não devem perder toque. Conferir offset e avaliar se remontagem da Biblioteca é aceitável.
5. **Keyboard:** composer, comentário/reply, evento, busca, denúncia, login, edição de perfil e comuna; último campo, submit, teclado aberto ao abrir/fechar sheet. Android resize/back e iOS home indicator/notch. Nenhum campo ou CTA necessário coberto.
6. **Rede ruim/draft:** escrever texto, selecionar evento/anexo, cortar rede, enviar; texto não pode desaparecer. Cancelar o evento em B antes de publicar em A: erro legível com draft preservado. Restaurar rede e tentar uma vez; não criar cópia silenciosa. Repetir 401/409/422/500 quando reproduzíveis no ambiente de teste.
7. **Double tap:** post, comentário, RSVP, report, follow, voto e save; repetir taps rápidos e alternar rede. Conferir um efeito no banco/UI, loading estável, rollback correto e feedback legível.
8. **Vídeo:** iniciar dois diferentes, rolar para fora, trocar rota/tab, minimizar, bloquear tela e voltar. Nunca dois áudios; invisível/background pausado. Controles nativos devem responder sem acionar swipe do perfil; memória deve estabilizar após fechar.
9. **Conta/cache:** A abre conteúdo privado, deixa request lenta e sheet/toasts abertos; logout, B login, liberar resposta antiga. Nenhum conteúdo, draft, aviso, badge, save ou token de A aparece/atua em B. Repetir logout offline e revogação com erro.
10. **Busca/hashtags:** digitar rápido e apagar; resposta antiga não substitui nova. Pessoas/comunas/posts/events; `#Política`, `#politica`, `#SãoPaulo`, `#2026`, `#東京`, menção+tag e URL com fragmento. Bloqueados, removidos e privados sem acesso não entram por lista, link ou preview.
11. **Eventos/date picker:** create/edit/cancel, Android/iOS, cancelar picker, start/end inválidos, fuso do aparelho diferente. RSVP going→maybe→not_going. Em privada: sair antes do reminder, executar worker no horário, aprovar reentrada e confirmar reconstrução; nunca reenviar reminder já entregue. Usar horários elegíveis às regras de 2h/24h/1h.
12. **Pins:** três pins, enquete com quatro opções e outro texto visíveis; quarta tentativa negada. Suspender autor: pin some, posição é preservada; reativar: volta; remover conteúdo: pin liberado. Dois moderadores tentam operar ao mesmo tempo: contagem/ordem consistentes.
13. **Moderação:** denunciar post/comment/profile/comuna, fila local/global, claim/decisão concorrente, escalar, remover e restaurar. Só uma decisão final vence; autor recebe um aviso genérico e nunca reporter/nota interna. Excluir reporter e comuna de teste: caso/snapshot continuam acessíveis à operação autorizada.
14. **Links/push:** cada `/p`, `/u`, `/c`, `/d`, `/e`, `/t` por HTTPS, app foreground/background/killed, deslogado, onboarding e aceite pendente. Repetir privado sem membership, bloqueado, suspenso e removido. Validar associação nativa, não somente custom scheme. Push com permissão negada/aceita, dois devices, conta trocada e token inválido; recibos e badge coerentes.
15. **Acessibilidade:** VoiceOver/TalkBack, fonte ampliada, foco ao abrir/fechar modal, labels de ícones, alvos de toque, toast em sequência. Nenhum texto essencial cortado nem foco preso atrás do sheet.
16. **Release/observabilidade:** testar health, erro controlado/Sentry sem PII, config HTTPS correta e permissões efetivas. Repetir percurso principal offline/lento. Registrar crashes/ANRs, frames, memória e requests; reproduções relevantes dos critérios 94 reprovam o candidato.

## L. Beta recommendation

As correções locais e checks automatizados permitem preparar um candidato, mas **o ambiente completo ainda não está pronto para validar os fluxos físicos exigidos**: os documentos de associação de links têm falha comprovada, worker/credenciais precisam ser confirmados e falta a build assinada com backend migrado. Nenhum resultado de fluidez, teclado, gesto, consumo de memória ou entrega push em aparelho foi presumido.

Fechar J, instalar a build e executar K. Depois disso, a decisão de distribuir a pessoas reais deve usar as evidências físicas e qualquer regressão encontrada. A aprovação de retenção continua necessária antes de beta externo.

**NOT READY FOR PHYSICAL QA**
