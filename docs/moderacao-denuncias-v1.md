# Moderação e denúncias V1

Entrega operacional. Denúncia não remove nada sozinha e a quantidade de denúncias não pune ninguém.
A validação física no aparelho não foi feita. O que rodou está na seção 43.

## 1. Arquitetura que já existia

Dois eixos independentes: `PlatformRole` na conta e `ComunaRole` na membership.
`ContentPolicy` decide leitura, publicação, comentário e voto. `ComunaModerationPolicy` e
`ComunaGovernance` decidem o que a moderação da comuna pode fazer. `ContentStatus` já separava
`published`, `removed` e `deleted`. `AccountStatus` e `UserChecker` já suspendiam conta e
revogavam sessão. `content_report` já existia para praça e biblioteca, com alvo por UUID sem
chave estrangeira. `admin_audit_log` já era só de inserção. Pins, busca, preview OG e bloqueio
já passavam por essas políticas.

A V1 estende `content_report` e reutiliza o audit log. Não criou `report_case`, `moderation_action`,
restrição temporária nem recurso.

## 2. Papéis encontrados

| Eixo | Valores | Efeito |
| --- | --- | --- |
| Plataforma | `user`, `platform_moderator`, `admin` | `ROLE_USER` ⊂ `ROLE_PLATFORM_MODERATOR` ⊂ `ROLE_ADMIN` |
| Comuna | `member`, `moderator`, `founder` | `ComunaRole::canModerate()` para moderator e founder |

O papel de plataforma só muda por `app:user:set-role`. A API não aceita papel vindo do cliente.
Moderador de comuna não ganha papel de plataforma.

## 3. Políticas encontradas

`ContentPolicy`, `ComunaModerationPolicy`, `ComunaGovernance`, `PostRepository::applyVisibility`,
`PreviewController`, `Discovery` (filtra `status = active`) e `UserChecker`.
A V1 encaixa a comuna suspensa nesses mesmos pontos, em vez de criar uma política paralela.

## 4. Modelagem da denúncia

Tabela `content_report`, entidade `ContentReport`.

| Campo | Uso |
| --- | --- |
| `reporter` | Quem denunciou. Não sai na API de moderação |
| `target_type`, `target_id` | Alvo. O id não tem FK, então a denúncia sobrevive ao alvo |
| `reason_code` | Motivo fechado |
| `reason` | Descrição opcional, até 800 caracteres |
| `snapshot` | Trecho textual, até 280 caracteres |
| `scope` | `comuna` ou `platform` |
| `comuna_id` | Só no escopo da comuna. Nulo na denúncia de perfil, de comuna e de publicação pessoal |
| `status` | `pending`, `reviewing`, `resolved`, `dismissed`. `removed` continua para praça, biblioteca e console |
| `escalated` | A plataforma passou a ser dona da decisão |
| `reviewer_id`, `claimed_at` | Quem assumiu a análise |

## 5. Auditoria

Não há tabela nova. Cada decisão grava uma linha em `admin_audit_log`:
`report.claimed`, `report.dismissed`, `report.resolved`, `report.escalated`, `content.restored`,
`account.suspended`, `account.reactivated`, `comuna.suspended`, `comuna.reactivated`,
`comuna.post_removed`, `comuna.comment_removed`, `platform.post_removed`, `platform.comment_removed`.
O detalhe leva `report_id`, tipo e id do alvo. Não leva quem denunciou.
O gatilho do PostgreSQL recusa UPDATE, DELETE e TRUNCATE.

## 6. Migration

`migrations/Version20261108120000.php`. Alarga `reason`, adiciona motivo, escopo, escalação,
snapshot, revisor e data de assunção, troca o unique eterno por um índice parcial das denúncias
abertas, indexa a fila da plataforma e inclui `suspended` no check de status da comuna.
Aplicada no banco local `popularia`. Não foi usado `schema:update`.

## 7. Alvos

Obrigatórios: publicação, comentário, perfil (`profile` na API) e comuna.
Praça e biblioteca continuam nas filas que já tinham. Evento e documento não entraram neste fluxo.
Denunciar mídia é denunciar a publicação.

## 8. Motivos

`harassment`, `hate`, `threat`, `spam`, `fraud`, `sexual`, `doxxing`, `illegal`, `other`.
Rótulos em português no app e em `ReportReason::label()`.
Discordância política não é categoria.

## 9. Deduplicação

Índice único parcial: mesma pessoa, mesmo tipo, mesmo alvo, enquanto o status é `pending` ou `reviewing`.
A segunda denúncia aberta responde 204 e não cria outra linha. Depois de resolvida ou dispensada, uma nova denúncia é aceita.

## 10. Limite de ritmo

Limiter Symfony `report`: 20 por hora, janela deslizante, `cache.rate_limiter`.
O `ReportProcessor` deixou de usar o limiter de criação de conteúdo. Não há Redis novo.

## 11. Fluxo local

Publicação ou comentário de uma comuna nasce com `scope = comuna`.
`GET /comunas/{slug}/content-reports` exige moderator ou founder daquela comuna.
Comuna suspensa responde 409; a fila passa a aparecer para a plataforma, para a denúncia não ficar presa.
Pendentes mais antigas primeiro (`id` ASC). Resolvidas, mais recentes primeiro (`id` DESC).

## 12. Fluxo global

`GET /moderation/reports` exige `ROLE_PLATFORM_MODERATOR`. Admin herda esse papel.
Entram denúncias de escopo plataforma, denúncias escaladas e denúncias de comuna já suspensa.
Não exige membership. Moderador de outra comuna recebe 403.

## 13. Escalonamento

Ações `escalate` e `remove_and_escalate`. A denúncia continua a mesma linha: `escalated = true` e status volta a `pending`.
A moderação local ainda vê o item, mas não fecha mais. A plataforma decide.

## 14. Poderes do moderator da comuna

Ver a fila local, assumir, dispensar, remover publicação ou comentário que a política já permitia, restaurar, escalar.
Não suspende conta, não suspende comuna, não vê fila de outra comuna, não vê a fila global e não muda papel de plataforma.

## 15. Poderes do moderator global

Fila global, as ações locais quando o caso é dele, remoção e restauração mesmo sem membership, suspensão e reativação de conta, suspensão e reativação de comuna.
A remoção pela plataforma não obedece à proteção do conteúdo do fundador.

## 16. Poderes do admin

Nenhum poder novo de moderação. `ROLE_ADMIN` já inclui `ROLE_PLATFORM_MODERATOR`, então o admin usa a mesma fila.
Os endpoints antigos de evidência e eventos de segurança continuam separados.

## 17. Remoção de publicação

Estado `removed`. O texto fica para auditoria. Some do feed, da busca, do detalhe (404), do pin e do preview.
Salvos não devolvem o texto. A mídia não é apagada. XP não é retirado. Evento ligado à publicação não é apagado.

## 18. Remoção de comentário

Estado `removed`. O corpo público sai nulo e o app mostra “Comentário removido pela moderação.”
Apagar pela autoria continua sendo `deleted`, com o texto limpo. São estados diferentes.

## 19. Respostas

A subárvore permanece. O pai removido vira marcador e as respostas continuam publicadas.

## 20. Restauração

`restore` só a partir de `removed`. Volta a `published`. Não refixa, não recria XP e não recria aviso.
Conteúdo apagado pela autoria não é restaurado por este fluxo.

## 21. Suspensão de conta

Só a plataforma, ação `suspend`. Usa `AccountStatus::Suspended` e `SessionManager::revokeAll`.
A sessão cai com 401. Um login novo esbarra no `UserChecker` (“Esta conta está suspensa.”).
A conta não publica, não comenta, não responde RSVP e não ganha gamificação, porque essas ações já exigem conta ativa.

## 22. Reativação

Ação `reactivate`, de volta para `Active`, se a conta não estiver excluída.
Não há suspensão com prazo. Fica suspensa até alguém da plataforma reativar. Sem scheduler.

## 23. Ação sobre comuna

`archived` não é punição: continua irreversível e ainda legível para quem já participava.
`suspended` é o estado novo. A comuna não é lida (404, inclusive para a plataforma, que usa o snapshot da denúncia),
não entra na busca nem no OG, e não aceita publicação, comentário ou voto.
`suspend_comuna` e `reactivate_comuna` são só da plataforma. Os dados ficam.

## 24. Alvo apagado

`target_id` não tem FK. A denúncia permanece, com snapshot e rótulo. A fila marca o alvo como indisponível.

## 25. Snapshot

Até 280 caracteres do texto, sem HTML, no momento da denúncia. Não copia PDF, imagem, vídeo nem perfil inteiro.
É dado privado da moderação. Retenção longa fica como dívida.

## 26. Privacidade

A API de denúncia não devolve denunciante, e-mail, nota interna nem histórico de auditoria.
Membro comum não vê fila. Moderador da comuna A não vê a fila da B. Denúncia de comuna não cai na fila dessa comuna.
Denúncia de perfil não cai numa comuna só porque as duas pessoas participam dela.

## 27. Bloqueio

Denunciar não bloqueia. No fim do envio o app oferece “Você também pode bloquear esta pessoa.”
O bloqueio só acontece se a pessoa tocar. Não aparece na denúncia de comuna.

## 28. Notificações

Não há aviso só porque alguém foi denunciado. Não há push de fila. Não há categoria nova.
A suspensão aparece porque a sessão cai e o login explica a conta suspensa.
A remoção aparece porque o conteúdo some. Não há inbox de acompanhamento: o envio é fire-and-forget.

## 29. Trilha de auditoria

Imutável, como na seção 5. Nota futura é uma ação nova, não uma edição.

## 30. Busca

A descoberta já filtra comuna e conteúdo ativos. Comuna suspensa e publicação `removed` não entram.
Não houve ranking novo.

## 31. Deep link e OG

Publicação removida continua 404. Preview de comuna e de evento exige comuna pública e ativa.
Comuna suspensa não gera preview.

## 32. Pins

Remover publicação fixada solta o pin (`ComunaPins::release`). Restaurar não fixa de novo.

## 33. Gamificação

Sem clawback e sem mudança de nível. Restaurar não concede XP outra vez.

## 34. API

| Método | Caminho | Quem |
| --- | --- | --- |
| POST | `/reports` | Conta ativa. 204 |
| GET | `/comunas/{slug}/content-reports` | Moderação daquela comuna |
| GET | `/moderation/reports` | Plataforma |
| GET | `/moderation/reports/{id}` | Quem pode decidir aquela denúncia |
| POST | `/moderation/reports/{id}/decision` | O mesmo, de novo no servidor |

Ações: `claim`, `dismiss`, `remove`, `remove_and_escalate`, `escalate`, `restore`, `suspend`, `reactivate`, `suspend_comuna`, `reactivate_comuna`.
A lista de ações de cada item já vem filtrada para quem está olhando.
OpenAPI nos recursos de `App\Api\Moderation`. O teste de contrato inclui esses caminhos.

## 35. UI de denúncia

Menu `⋯`: Denunciar. Folha com motivo, contexto opcional (“Conte mais, se quiser”) e envio.
Texto de sucesso: “Denúncia enviada. Vamos analisar o conteúdo.”
Na comuna, “Denunciar comuna” abre direto nos motivos. O fundador não vê essa ação.
Praça e biblioteca continuam com a lista antiga de motivos, porque são outro fluxo.

## 36. Fila local

Em Ferramentas de moderação, a seção “Publicações e comentários” fica acima da praça e da biblioteca.
Abas Pendentes e Resolvidas. Ações editoriais. Remover não pede um “tem certeza?” genérico, porque é reversível.

## 37. Fila global

Rota `moderacao-plataforma`, aberta em Conta só quando `platformRole` é `platform_moderator` ou `admin`.
O servidor recusa os demais com 403. Confirmação antes de suspender conta ou comuna, com o efeito escrito por extenso.

## 38. Cache

Chaves `['comuna-reports', slug, bucket]` e `['platform-reports', bucket]`.
Depois da decisão, invalidam-se essa fila, a fila da plataforma, feed, publicação, posts do perfil, busca e pins da comuna.
O restante do cache fica.

Suspensão e suspensão de comuna esperam o 204. Não há sucesso otimista.

## 39. Concorrência

A decisão trava a linha com `PESSIMISTIC_WRITE`. A segunda pessoa que tenta fechar recebe 409 `/errors/report-already-decided`.
Assumir a análise é o último gravador dentro dessa trava. Denúncia duplicada aberta esbarra no índice único.
Perda de papel, comuna suspensa e alvo já apagado são recusados na hora da ação.

## 40. Desempenho

A listagem carrega posts, comentários, autores e comunas em lote, não um por item.
A página usa cursor no UUID da denúncia, limite 1–50, padrão 20.
Pendentes: mais antigas primeiro. O desempate é o próprio id.

## 41. Testes de backend

`tests/Api/ModerationTest.php` cobre validação, privacidade, deduplicação, fila local, comentário com resposta, restauração, perda de papel, plataforma, suspensão, comuna, escalação, conteúdo do fundador, comuna arquivada e paginação.
`tests/Api/ReportTest.php` segue enviando `reasonCode`.
PHPUnit completo: 358 testes, 3749 asserções, OK.

## 42. Testes de mobile

`content-reports.test.ts`: corpo da denúncia, descrição vazia, corte em 800, texto da confirmação de suspensão e chaves de cache.
`comment-row.test.tsx`: pai removido mantém o marcador e o controle das respostas.
`content-api.test.ts` já garantia o texto “Comentário removido pela moderação.”
Jest completo: 59 suítes, 378 testes, OK.

## 43. Checks

| Check | Resultado |
| --- | --- |
| PHPUnit | OK, 358 testes |
| PHPStan | OK |
| PHP CS Fixer no projeto | 5 arquivos antigos, nenhum desta tarefa: `Version20261030130000`, `WaitlistProcessor`, `ContactMessage`, `ContactMessageProcessor`, `LocalFileController` |
| Doctrine mapping | OK |
| Migration | Aplicada no banco local |
| OpenAPI | Caminhos cobertos por `OpenApiTest`, que passou na suíte |
| `tsc --noEmit` | OK |
| ESLint nos arquivos tocados | OK |
| Jest | OK, 378 testes |
| `git diff --check` | OK nos dois repositórios |
| Expo Doctor | Não rodou. Configuração nativa não mudou |
| Validação física | Não feita |

## 44. Arquivos

Backend: `ContentReport`, `ReportReason`, `ComunaStatus`, `ReportModeration`, recursos em `src/Api/Moderation`, políticas, visibilidade, preview, pins no console de revisão, limiter `report`, migration `Version20261108120000`, `ModerationTest`, docs.

Mobile: folha de denúncia, motivos estruturados, fila local, fila da plataforma, atalho em Conta, “Denunciar comuna”, cache e testes.

## 45. Limitações deliberadas

- Suspensão sem data de fim.
- Sem aviso ao autor quando o conteúdo é removido.
- Sem acompanhamento da denúncia para quem denunciou.
- Sem recurso, strikes, shadowban ou punição por contagem.
- Mídia não é apagada. A URL assinada (300 s) só é emitida se a publicação ainda for visível. URL já emitida ou objeto público de CDN, se existir fora dessa assinatura, não ganhou um coletor nesta tarefa.
- Comuna suspensa não conta no limite de 3 comunas ativas do fundador, porque o gatilho só conta `status = active`.
- Apagar a linha da comuna ainda apaga as denúncias locais (`ON DELETE CASCADE`). A denúncia da comuna em si sobrevive, porque `comuna_id` é nulo.
- Apagar a conta de quem denunciou ainda apaga as denúncias feitas por essa conta.
- Praça e biblioteca não migraram para os motivos novos.
- Modo demonstração continua só no aparelho.

## 46. Dívida técnica encontrada

- A seção 5 de `moderacao.md` ainda lista alguns nomes antigos de auditoria. O código grava nomes com ponto (`comuna.post_removed`).
- O desenho de `report_case` / recurso nunca foi o caminho implementado. A V1 não o construiu.
- PHP CS Fixer aponta 5 arquivos anteriores a esta tarefa.
- O índice parcial do Doctrine pode divergir num `schema:validate` completo de banco. O mapping isolado passou. Não rodar `schema:update`.

## 47. Configuração externa

Nenhuma variável nova. Para operar a fila global, uma conta precisa de `platform_moderator` ou `admin` via `app:user:set-role`.
A migration `Version20261108120000` precisa estar aplicada em cada ambiente. No banco local, já está.

## 48. Roteiro de validação física

Não executado. Quando houver aparelho com a API migrada:

1. Com uma conta comum, denunciar publicação, comentário, perfil e comuna. Confirmar o texto de sucesso e que denunciar de novo o mesmo alvo não cria outra denúncia aberta.
2. Entrar como moderator da comuna. Ver só a fila daquela comuna. Remover um comentário com resposta e conferir o marcador e as respostas. Escalar outra denúncia.
3. Confirmar que esse moderator não abre a fila da plataforma e não suspende conta.
4. Entrar como `platform_moderator` sem participar da comuna. Ver a denúncia escalada, a de perfil e a de comuna. Suspender uma conta de teste e ver o login recusado. Reativar.
5. Suspender uma comuna de teste e conferir que ela some da busca e que o link direto não mostra o conteúdo. Reativar.
6. Confirmar que o fundador da comuna denunciada não vê essa denúncia na fila local.
