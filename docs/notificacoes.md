# Notificações in-app e push — relatório da implementação

Investigação e implementação: 29/10/2026. Código em `popularia_backend` e `popularia_mobile`.
Este documento descreve o que foi implementado e o que ainda exige configuração externa.

## 1. Arquitetura encontrada

O backend Symfony 7.4/API Platform/Doctrine/PostgreSQL já tinha `Notification`,
`NotificationType`, `NotificationCreator`, referências a recursos, `readAt`, chave única de
identidade de evento, autorização nas consultas e paginação por cursor UUID v7.
Follow, comentário/resposta, resposta na Praça, pedidos de entrada e transferências de
propriedade já criavam avisos na transação da ação. Follows privados já distinguiam pedido
pendente de relação aprovada. Não havia Messenger, push provider, devices ou worker de push.

O mobile Expo SDK 58 já tinha Avisos com `PopulariaList`/FlashList, `usePaginatedData`,
filtros, avatar, timestamp, unread visual, retry, refresh, paginação, leitura individual/todas
e contador compartilhado por conta. O projeto usa TanStack Query para conteúdo e mantém
algumas listas no hook paginado existente. A sessão usa access token em memória, refresh no
SecureStore, rotação e revogação no servidor. Rotas internas e resolução de destinos de
notificação já existiam no Expo Router. Não havia `expo-notifications`; o Android bloqueava
`POST_NOTIFICATIONS`. O projeto EAS já estava identificado no `app.json`.

Apoiar/ecoar publicações ainda são interações locais, sem evento persistido no backend.
Apoio a comentário é real. Menções de usuários não têm representação estruturada. Participar
ou ativar o sino local de uma comuna não representa uma assinatura de conteúdo no servidor.

## 2. Arquitetura final

`NotificationCreator` continua persistindo junto à ação social, sem HTTP externo e sem flush
próprio. Uma coluna `push_queued_at` transforma as notificações confirmadas em fonte durável
para a fila. A migration marca o histórico anterior como já processado: instalar o worker
não dispara o histórico antigo.

`PushWorker` prepara entregas em `push_delivery`, uma por notificação/dispositivo, na própria
transação de preparação. Só depois chama `ExpoPushProvider`. A preparação, envio e recibos
são lotes limitados; uma trava advisory PostgreSQL impede workers concorrentes de enviar a
mesma fila. A conexão libera a trava se o processo morrer. Não há Redis, Messenger ou serviço
adicional de broker. Nada na criação de uma publicação aguarda push.

O comando suporta execução única para cron e modo contínuo para um serviço worker. Cada ciclo
processa até 100 notificações, 100 entregas e 100 recibos. A V1 permite até 20 dispositivos
ativos por conta. Há índices para destinatário/unread, notificações pendentes, devices,
status/data da entrega e unicidade de evento/entrega. `read-all` agora usa UPDATE com
subconsulta, sem carregar todos os IDs em PHP.

## 3. Eventos e destinatários

| Tipo | Destinatário/regra |
| --- | --- |
| `follow` | Pessoa seguida; em perfil privado, apenas após aprovação |
| `comment` | Autoria da publicação |
| `reply` | Autoria do comentário pai; prevalece sobre `comment` para a mesma pessoa/evento |
| `comment_support` (novo) | Autoria do comentário apoiado; um aviso por ator/comentário durante toda a vida do recurso |
| `square_reply` | Autoria da mensagem respondida na Praça, respeitando acesso à comuna |
| `join_requested` | Moderação/proprietário habilitados a analisar o pedido |
| `join_approved` / `join_rejected` | Solicitante |
| `ownership_proposed` | Destinatário da proposta |
| `ownership_accepted` | Proprietário anterior e novo proprietário; confirmação própria sem ator |
| `ownership_declined` | Quem propôs |
| `ownership_cancelled` | Quem receberia a proposta |

Ações próprias, contas inativas e bloqueios bilaterais não geram avisos sociais.
Todos os tipos acima podem gerar push, conforme preferências, acesso, devices e leitura.
Apoios têm no máximo um evento diferente com push por destinatário em cinco minutos; os
avisos únicos permanecem no histórico. Outros eventos diretos não são agregados nesta V1.
Não se envia push por cada publicação criada em uma comuna.

## 4. Devices e preferências

`push_device`: UUID aleatório de instalação, conta, sessão autenticada, token Expo único,
plataforma, registro/atualização, desativação e hash de segredo de revogação. O token precisa
ser recuperável para envio; não é exposto por DTO de usuário nem logado pelo worker.
O UUID e o segredo ficam no SecureStore; o servidor guarda apenas SHA-256 do segredo.

PUT atualiza/rotaciona o token e associa à sessão atual. O mesmo token não pode ficar em duas
contas: sua transferência remove o registro anterior, inclusive entregas antigas por cascade.
Recibos usam hash do token e destinatário original para não desativar registro rotacionado ou
transferido. Logout/revogação/expiração de sessão impedem envio; remoção de permissão do
sistema também desativa a instalação ao voltar ao app.

`notification_preference`: categorias de push por conta (`interactions`, `follows`, `comunas`,
`followed_content`). As três primeiras são habilitadas por padrão; lista vazia desativa push.
O histórico in-app é independente. A categoria de conteúdo seguido fica reservada, sem
fan-out implementado. Não foi adicionada uma tela completa de preferências.

Logout tenta desregistrar antes de encerrar a sessão, zera badge e limpa notificações já
apresentadas no aparelho. Antes da request, persiste uma revogação pendente com segredo de
capacidade restrita. Se estiver offline, ela é reenviada sem autenticação na próxima abertura
ou retorno ao foreground, inclusive na tela de login. Uma revogação velha não desativa uma
nova associação com segredo diferente. Registro de token fica bloqueado durante o logout.
Não é possível avisar o servidor enquanto o aparelho está sem rede; pushes já aceitos pelo
provider também não podem ser recolhidos. O payload contém apenas atividade genérica e IDs,
e o toque nunca libera acesso sem sessão/autorização.

Excluir a conta apaga preferências explicitamente; apagar suas sessões/notificações remove
devices e entregas por FK. A retenção/purge de sessões existente continua removendo registros
órfãos de instalações antigas.

## 5. Endpoints, migration e job

Prefixo `/api/v1`. Os endpoints `/me` usam a identidade autenticada, sem user ID no input.

| Método | Caminho | Contrato |
| --- | --- | --- |
| GET | `/me/notifications` | `{items, nextCursor}`; `cursor`, `limit` 1–50, padrão 20 |
| GET | `/me/notifications/unread-count` | `{unreadCount}` |
| GET | `/me/notifications/{id}` | Aviso e destino revalidados |
| PUT | `/me/notifications/{id}/read` | Leitura idempotente, preserva primeira data |
| PUT | `/me/notifications/read-all` | Marca histórico visível e devolve contador |
| PUT | `/me/push-devices/{id}` | `{token, platform: "ios"|"android", revocationSecret}` → `{id}` |
| DELETE | `/me/push-devices/{id}` | Desativa instalação própria; 204 idempotente |
| POST | `/push-devices/{id}/revocation` | `{revocationSecret}`; capacidade apenas de desativação; sempre 204 para inputs válidos |
| GET/PUT | `/me/notification-preferences` | `{pushCategories: string[]}` |

Registro e revogação têm limites de taxa no PostgreSQL. UUID, formato/tamanho do token,
plataforma, segredo e categorias são validados. O endpoint público de revogação não permite
ler dados, registrar devices ou navegar; segredo errado tem a mesma resposta de inexistente.

Migration nova: `Version20261101130000`. Cria as três tabelas e a coluna/índice parcial da fila.
Foi colocada após a maior versão de migration que já existia no projeto.

Job novo: `app:notifications:deliver`, com `--limit`, `--watch` e `--interval`.

```sh
# Aplicar no ambiente escolhido antes de publicar o mobile novo:
php bin/console doctrine:migrations:migrate --no-interaction --env=prod

# Serviço worker com a mesma imagem/release e DATABASE_URL da API:
php bin/console app:notifications:deliver --watch --interval=5 --limit=100 --env=prod --no-debug

# Alternativa cron, a cada minuto (menor vazão):
php bin/console app:notifications:deliver --limit=100 --env=prod --no-debug
```

Ativar um destes modos é uma configuração operacional pendente; não foi alterada hospedagem
remota nesta task. Não execute o job em um ambiente de testes apontando para produção.

## 6. Foreground, background e killed

- Foreground: handler suprime banner/som/lista do sistema; listener atualiza unread, sinaliza
  revalidação da lista de Avisos e invalida as queries de detalhe `post`. Não duplica o aviso
  com um segundo toast de recebimento.
- Background/killed: payload visível, som padrão e canal Android `social`; Expo encaminha a
  APNs/FCM. Não há tarefa JS de background ou silent push nesta V1.
- Toque: lê a resposta nativa inicial ou listener de resposta. Espera restauração de sessão,
  onboarding, mensagem de login e aceite jurídico. Confere destinatário do payload, busca/lê
  o aviso autenticado e navega somente pelo destino retornado pelo servidor. Resposta
  processada é limpa; toques repetidos da mesma entrega são ignorados.
- Volta ao app: revalida contador, lista, permissão e registro; alterações de token nativo
  também solicitam novo token Expo e atualização no backend.

Permissão só é solicitada pela ação contextual em Avisos. Negativa oferece abrir ajustes;
web/Expo Go não tentam registrar push. Negar não impede usar a central in-app.

## 7. Navegação e privacidade

A resolução existente em `features/notifications/navigation.ts` foi reutilizada: post abre
sua discussão na pilha de Avisos, follow abre perfil, Praça abre a Praça, pedido abre gestão
da comuna e demais eventos abrem comuna. Não há URLs arbitrárias no payload.
Comentários ainda não forçam scroll para um comentário fora da página carregada.

Listagem, contador, leitura individual e worker usam a mesma regra de visibilidade. Conteúdo
removido/moderado, ator inativo, bloqueio bilateral, perda de participação privada e perda de
acesso a post de perfil privado suprimem o aviso. A regra de perfil privado foi ampliada nesta
task. Os endpoints dos recursos continuam autorizando a navegação. 403/404/410 apresentam
feedback sem derrubar o app. Revalida-se imediatamente antes de enviar; mudanças posteriores
à aceitação pelo provider não podem recolher pushes, por isso não enviamos nomes, trechos ou
nomes de comunas privadas. O payload usa somente `notificationId` e `recipientId`, além do
badge e de uma mensagem genérica por tipo.

## 8. Deduplicação e falhas

Índice `(recipient_id, type, event_key)` continua sendo a identidade do evento. O creator
ignora repetições já persistidas e inserções pendentes no mesmo UnitOfWork. Follows continuam
serializados pelas travas existentes; retry da mesma relação/pedido não cria outro aviso.
Apoio usa `commentId:actorId`: desfazer/refazer não produz spam.

A fila tem índice único `(notification_id, device_id)`; marcar a notificação como preparada
acontece junto às inserções. Tickets aceitos nunca são reenviados apenas porque ainda não
há recibo. Falhas transitórias de transporte/429/5xx usam backoff (30, 60, 120, 240 segundos),
até cinco tentativas. Credenciais/payload inválidos falham sem loop infinito.
`DeviceNotRegistered` desativa o token correspondente em ticket ou recibo.

Recibos são consultados após 15 minutos, em lotes; ausência/falha consulta novamente por hora,
com limite de tentativas. Entregas terminais com mais de sete dias são apagadas em lotes.
Eventos com mais de um dia não acordam dispositivos: permanecem no in-app. TTL no provider:
uma hora. Devices registrados depois do evento não recebem backlog anterior.

Não há garantia de exatamente uma apresentação no SO: crash entre aceite externo e gravação
do ticket, ou resposta de rede ambígua, pode levar a repetição externa. In-app e abertura
continuam deduplicados. Garantia end-to-end mais forte depende de suporte do provider.

## 9. Cache, unread e badge

Preservados `PopulariaList`, hook paginado, filtros, refresh/retry, paginação e design.
O contador usa `queryClient.fetchQuery` com chave `['notifications', accountId, 'unread']`,
compartilhando requests simultâneas. Leituras são otimistas: removem unread e decrementam badge
imediatamente; falha restaura e revalida. Leituras simultâneas do mesmo ID compartilham a
promise. Responses antigas não atualizam uma conta nova. Consultas de contador em andamento
são canceladas antes de uma leitura; unmount elimina queries e timers desta identidade.

Push/foreground incrementam uma revisão do contexto; a lista já montada revalida em segundo
plano sem loader global. Não foi criada outra arquitetura de lista ou de bottom sheet.
O badge dentro do app e o ícone seguem o mesmo contador; zero também é gravado no SO.
O payload atualiza o badge ao entregar push. Leitura feita em outro aparelho sincroniza o
ícone ao voltar ao app ou no próximo push; não há silent push só para zerar badges remotos.
Android depende também do launcher para exibir badge numérico.

## 10. Testes e observabilidade

Testes HTTP reais usam PostgreSQL de teste e migrations do zero. `PushNotificationTest` usa
`MockHttpClient` e nunca envia push real. Cobre múltiplos devices, rotação/transferência,
autorização, exclusão, preferências, dedup, unread, acesso revogado, payload, tickets, recibos,
falha/retry, token inválido e revogação offline. `NotificationTest` já cobre follow,
comentário/resposta, ações próprias, paginação, read/read-all, bloqueios e rollback.

Jest cobre tela, loading/empty/error/retry, paginação, leituras, contador, troca de conta,
cache, permissão, canal Android, foreground, abertura killed, resposta/toque, dedup,
revogação offline, token refresh e payload para outra conta.

Logs do worker contêm ID da entrega, classificação sanitizada do erro e decisão de retry;
não contêm token, texto privado ou corpo bruto do provider. Erro inesperado de job retorna
exit code 1 e passa pelo logger existente (com redação/Sentry já configurados). Falhas
esperadas do provider são registradas sem interferir nas ações sociais. `push_delivery`
permite inspecionar `status`, `attempts`, `error_code`, `ticket`, `available_at` sem expor tokens.

Resultados finais de checks: ver `notificacoes-validacao.md`.

## 11. Configuração externa restante

Nenhum secret foi inventado. A configuração em código está pronta, mas não foi atestada por
push real em dispositivos nesta execução.

1. Backend: aplicar migration no ambiente, publicar a release e ativar o worker descrito acima.
   API e worker precisam usar o mesmo banco e mesmas variáveis. Permitir HTTPS de saída para
   `https://exp.host/--/api/v2/push/`.
2. Expo: confirmar acesso ao projeto EAS `6e964283-951f-4a16-bf99-5a4d5236a338` e suas credenciais.
   Se ativar a segurança de envio no Expo, configurar `EXPO_PUSH_ACCESS_TOKEN` somente no backend
   e no worker; o provider já aceita essa variável opcional. Nunca usar `EXPO_PUBLIC_` para ela.
3. iOS: habilitar Push Notifications no App ID `com.popularia.mobile`, configurar a chave APNs
   da equipe Apple no EAS (`npx eas-cli@latest credentials --platform ios`) e gerar nova build.
   O config plugin `expo-notifications` prepara os entitlements nativos; não é uma alteração
   entregável só por OTA. Não é necessário ativar background remote notifications para os
   pushes visíveis desta V1.
4. Android: configurar um app Firebase para `com.popularia.mobile`, obter `google-services.json`
   e configurar uma variável EAS de arquivo `GOOGLE_SERVICES_JSON` com o caminho ao arquivo.
   `app.config.js` já lê esse caminho em `android.googleServicesFile`. Cadastrar a service
   account FCM v1 nas credenciais Android do projeto EAS
   (`npx eas-cli@latest credentials --platform android`). O arquivo da service account fica
   nas credenciais, não no bundle ou repositório. Gerar nova build Android com o plugin.
5. Gerar/instalar builds de desenvolvimento ou distribuição com os ambientes/API existentes:
   `npx eas-cli@latest build --profile testflight-staging --platform ios` e
   `npx eas-cli@latest build --profile staging --platform android`. Não validar push remoto
   com Expo Go. O repositório continua sem diretórios nativos manuais.
6. Atualizar as declarações de privacidade das lojas para token de dispositivo/Expo/APNs/FCM,
   finalidade de funcionalidade e ausência de tracking; inventário local foi atualizado.
   Como staging substitui o mesmo bundle/package instalado, sair da conta antes de alternar
   builds/ambientes. A tarefa preserva os identificadores existentes.

Referências consultadas: [Expo Notifications SDK 58](https://docs.expo.dev/versions/v58.0.0/sdk/notifications/),
[configuração APNs/FCM/EAS](https://docs.expo.dev/push-notifications/push-notifications-setup/),
[tickets, recibos e retry](https://docs.expo.dev/push-notifications/sending-notifications/).

## 12. Roteiro curto em iPhone e Android

1. Instalar nova build com credenciais e API migrada; deixar worker ativo. Entrar com B,
   abrir Avisos e ativar notificações. Repetir negando a permissão e depois habilitando pelos
   ajustes; negar deve manter o in-app funcionando.
2. Em A, seguir B, comentar no post de B, responder comentário de B e apoiar um comentário.
   Com B em foreground: contador/lista atualizam, sem banner duplicado. Abrir aviso: unread
   e badge caem imediatamente; destino correto. Repetir apoio/desfazer/refazer: um só aviso.
3. Colocar B em background e gerar outro evento; validar som/canal/banner. Tocar e conferir
   discussão/perfil. Encerrar B, gerar evento e tocar: restauração/login precede navegação.
4. Fazer A responder post de comuna privada de B; antes de rodar o worker, remover acesso
   ou bloquear A/remover conteúdo. Não entregar push nem manter aviso acessível. Repetir
   revogação após entrega: toque deve dar feedback de indisponibilidade.
5. Entrar com B em dois aparelhos, validar entrega em ambos; sair de um, conferir que só o
   outro recebe. Sair offline, reabrir com rede sem login e confirmar revogação pendente.
   Entrar como C no mesmo telefone e verificar que avisos de B não abrem na conta C.
6. Ler um/todos, voltar ao feed e confirmar badge zero, inclusive ícone (quando suportado).
   Desativar categoria `follows` pela API: follow permanece no in-app sem push.
7. Android: validar canal `social`, permissão Android 13+, FCM e build release. iPhone:
   validar autorização normal/provisional, badge e APNs em TestFlight/distribuição.

## 13. Limitações deliberadas e escala

Sem @mentions por parsing de texto, DMs, Status, Universal Links públicos, Open Graph,
compartilhamento ou refactor geral. Sem notificar apoios/eco ainda locais de posts. Pedido de
follow privado não é representado como follow aceito; mantém a central de pedidos existente.
Sem tela completa de categorias, agregação textual de vários atores ou atualização silenciosa
de badge em todos os aparelhos. Push em web não faz parte da V1.

Conteúdo seguido exige assinatura persistida, cursor de publicações, seleção por relevância,
janela de digest e orçamento de frequência por conta/comuna. A categoria está preparada,
mas publicar não faz N inserts/requests para cada membro. O caminho de escala é um produtor
assíncrono paginado de candidatos, com dedup e preferências antes de enfileirar. Para maior
vazão geral: medir lag/erros/tentativas, particionar claims por destinatário, trocar a trava
única por leases/claims e manter os limites do Expo. A fila V1 já isola requests sociais e
não exige reescrever os emissores para essa evolução.
