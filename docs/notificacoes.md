# Notificações internas

`NotificationCreator` persiste notificações na mesma transação da ação. Não faz flush próprio,
não envia mensagens externas e não depende do mobile. Nenhuma notificação é entregue antes do
commit. Uma camada futura de push poderá consumir as notificações confirmadas; ainda não há
tokens de dispositivos, permissões do sistema, polling, filas ou entrega push.

`Notification` contém destinatário, ator opcional, tipo, chave do evento, referências às entidades,
criação e `readAt`. Não armazena cópias do comentário ou de outros conteúdos privados. A migration
`Version20261026210000` cria `notification` e `user_follow`. O follow, antes local no mobile, agora
é persistido para permitir o fluxo real entre contas.

## Ações e destinatários

| Tipo | Ação confirmada | Destinatário |
| --- | --- | --- |
| `follow` | Nova relação de follow | Pessoa seguida |
| `comment` | Comentário, incluindo resposta a outra pessoa | Autoria da publicação |
| `reply` | Resposta direta | Autoria do comentário pai |
| `join_requested` | Novo pedido para comuna privada | Proprietário/moderadores ativos, conforme os papéis atuais |
| `join_approved` / `join_rejected` | Decisão do pedido | Solicitante |
| `ownership_proposed` | Nova proposta de transferência | Pessoa que recebe a proposta |
| `ownership_accepted` | Aceite e troca de propriedade | Proprietário anterior e novo proprietário |
| `ownership_declined` | Recusa | Quem propôs |
| `ownership_cancelled` | Retirada explícita ou substituição da proposta | Quem receberia a proposta |

Não há notificação de ações próprias. A confirmação da nova propriedade é uma confirmação sem
ator. Se o autor do comentário pai também é o autor da publicação, recebe apenas `reply` pelo
mesmo evento. Repetir PUT de follow/pedido/proposta pendente não gera outro aviso. As operações
usam as travas/transações existentes; follow serializa pela conta que age. O índice único de
`(recipient_id, type, event_key)` impede duplicidade adicional no banco. Excluir e seguir outra
vez, ou cancelar e fazer outro pedido, são ações novas e podem gerar novos avisos.

## API (`/api/v1`)

Todos os endpoints exigem sessão e usam exclusivamente o usuário do token:

| Método | Caminho | Resultado |
| --- | --- | --- |
| GET | `/me/notifications` | `{items, nextCursor}`, UUID v7 decrescente, `limit` 1–50 (padrão 20) |
| GET | `/me/notifications/unread-count` | `{unreadCount}` |
| GET | `/me/notifications/{id}` | Aviso próprio e destino revalidados |
| PUT | `/me/notifications/{id}/read` | Aviso revalidado e lido; repetição conserva a data original |
| PUT | `/me/notifications/read-all` | Marca todo o histórico próprio como lido; retorna `{unreadCount}` |

Follow: GET/PUT/DELETE `/users/{handle}/follow`. A ação também aceita UUID, como as autorias
usadas pelo mobile. GET `/users/{handle}` aceita UUID; GET `/users/{handle}/posts` pagina apenas
as publicações visíveis para quem consulta. Autoseguir é proibido no endpoint, na entidade e
por constraint no banco.

## Privacidade e navegação

Listagem, contador e leitura individual usam a mesma consulta de visibilidade. Bloqueios nos
dois sentidos, contas inativas, comentário/publicação removidos e perda de acesso a comuna
privada retiram o aviso da resposta e do contador. Comentários não ficam copiados nos avisos.
Exclusões físicas eliminam os avisos relacionados por FK. Nenhuma regra do recurso é dispensada
ao navegar: seus endpoints continuam autorizando a leitura normalmente.

Pedidos pendentes só aparecem enquanto o destinatário continua podendo analisá-los. Propostas
de propriedade pendentes só aparecem enquanto continuam válidas. Arquivar encerra as pendências
conforme o fluxo existente; os avisos dessas pendências somem. O histórico que continua autorizado
para leitura permanece visível em comunas arquivadas.

A recusa de entrada aponta para a página/preview pública da comuna, já existente: não libera
publicações privadas. Um destino que ficou indisponível responde 404, inclusive para um ID de
notificação de outra pessoa. O mobile revalida ao tocar, mostra feedback e atualiza a lista se
o acesso mudou. Comentários abrem a discussão; esta versão não força rolagem até um comentário
fora da página carregada.

## Mobile e teste manual

`features/notifications/api.ts` converte o contrato. A central reutiliza `usePaginatedData`,
componentes/tokens existentes e as rotas da aba Avisos. O contexto de notificações compartilha
contador e leituras confirmadas, isolados por conta. O sino atualiza ao trocar de aba e ao
entrar/atualizar a central; leituras bem-sucedidas atualizam o contador localmente. Zero não
mostra badge; 1–99 mostram o número; acima disso, `99+`. Não há atualização em segundo plano.

Com a API configurada, aplique a migration antes de testar. Use A e B em aparelhos/sessões
diferentes: A segue B, comenta no post de B e responde ao comentário de B; abra Avisos em B a
cada ação. Teste depois pedido em comuna privada e decisão do proprietário/moderador, além de
proposta/aceite/recusa de ownership. Verifique leitura individual/todas, refresh e o badge.
Após bloquear o ator ou remover uma participação privada, recarregue os avisos e tente abrir
um item carregado antes da mudança para verificar a recusa elegante.

Validação: `php bin/phpunit`, `composer stan`, `composer cs`; no mobile,
`npm test -- --runInBand`, `npm run typecheck`, `npm run lint`.
