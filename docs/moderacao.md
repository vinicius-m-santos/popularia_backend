# Moderação e autorização

O que está implementado: papéis, criação e gestão de comunas.
O desenho das próximas etapas (denúncias, restrições e recursos) está na última seção.

## 1. Dois eixos de papel, independentes

| Eixo | Valores | Onde | Quem muda |
| --- | --- | --- | --- |
| Plataforma | `user`, `platform_moderator`, `admin` | `app_user.platform_role` → `ROLE_USER`, `ROLE_PLATFORM_MODERATOR`, `ROLE_ADMIN` (hierarquia em `security.yaml`) | Só `app:user:set-role`, auditado |
| Comuna | `member`, `moderator`, `founder` | `comuna_membership.role` | Fundador da comuna (via API) ou a equipe (via `app:comuna:transfer-owner`) |

Ser `moderator` de uma comuna **não dá nenhum poder na plataforma**, e vice-versa. O cliente nunca
informa papel: o servidor sempre o lê do banco a partir do token.

## 2. Regras já aplicadas no servidor

Centralizadas em `App\Content\ContentPolicy` (itens), `App\Content\ComunaModerationPolicy` (permissões
por papel dentro da comuna) e nas consultas de `PostRepository` (listas):

- **Acesso:** comuna privada: só quem participa lê as publicações e a lista de membros; entrada depende
  de pedido aprovado pelo fundador ou pela moderação (403 `/errors/not-a-member`).
- **Publicar e comentar:** conta ativa, e-mail confirmado, participar da comuna; autoria vem do token.
  Proibido em comunas arquivadas.
- **Bloqueio nos dois sentidos:** conteúdo, comentários (viram marcador `hidden`), perfil (404) e respostas.
- **Conteúdo apagado pela autoria** some (texto apagado do banco); conteúdo de conta suspensa não aparece.
- **Conteúdo removido pela moderação** mantém o texto para revisão interna (`status = removed`, `ContentStatus`);
  o texto não é exibido na leitura pública.
- **Publicação invisível** para quem pede responde 404 (não confirma que existe).
- **Suspensão da conta:** vale em toda requisição (`UserChecker`) e encerra as sessões.

## 3. Criação e gestão de comunas

### Criar uma comuna

Pré-condições, todas conferidas no servidor:

| Condição | Erro |
| --- | --- |
| Conta ativa | 403 `/errors/account-inactive` |
| E-mail confirmado | 403 `/errors/email-not-verified` |
| Até 3 comunas ativas como fundador | 409 `/errors/comuna-limit-reached` |
| Nome único → slug único | 422 `violations.name` |

Não há cooldown entre criações. Arquivar ou excluir libera imediatamente a vaga no limite
de 3 comunas ativas; receber a propriedade de uma comuna também respeita esse limite.
O campo `last_comuna_created_at` permanece apenas como histórico, sem bloquear novas criações.

A concorrência é tratada assim:
- A linha da conta é travada com `SELECT … FOR UPDATE` antes da inserção.
- Um gatilho PostgreSQL (`comuna_enforce_owner_limit`) impede que uma segunda transação
  simultânea ultrapasse o limite mesmo que ambas passem pelo lock em paralelo.
- Uma violação de unicidade do nome é capturada e retornada como 422 no campo `name`.

Quem cria vira fundador. O nome é **imutável** (o slug é derivado dele na criação).
A visibilidade também não muda depois da criação.

**Cota (GET /me/comuna-quota):** retorna `activeOwned`, `limit`, `intervalDays`, `nextCreationAt`,
`canCreate` e `blockedBy` (o tipo do erro que recusaria a criação agora). O app usa isso para
explicar a situação antes do formulário.
Por compatibilidade, `intervalDays` é sempre `0` e `nextCreationAt` é sempre `null`.

### Papéis dentro de uma comuna

| Ação | Fundador | Moderador | Membro |
| --- | --- | --- | --- |
| Ler publicações (pública) | ✓ | ✓ | ✓ |
| Ler publicações (privada) | ✓ | ✓ | ✓ |
| Publicar e comentar | ✓ | ✓ | ✓ |
| Ver lista completa de membros | ✓ | ✓ | ✓ (só membros) |
| Remover membros comuns | ✓ | ✓ | — |
| Remover moderadores | ✓ | — | — |
| Nomear/dispensar moderadores | ✓ | — | — |
| Editar descrição e regras | ✓ | — | — |
| Aprovar/rejeitar pedidos de entrada (privada) | ✓ | ✓ | — |
| Arquivar a comuna | ✓ | — | — |
| Excluir a comuna | ✓ (só sem outros membros ou conteúdo bloqueante) | — | — |
| Propor transferência de propriedade | ✓ | — | — |
| Remover conteúdo de outros membros | ✓ | ✓ | — |
| Remover conteúdo do fundador | ✓ (de si mesmo) | — | — |

**Limites dos moderadores:** não editam os dados da comuna, não gerenciam outros moderadores,
não arquivam nem excluem, não propõem transferência. Seu escopo é operacional: remoção de membros
comuns e de conteúdo. Ninguém (nem o fundador) remove o próprio fundador como membro.

Implementado em `App\Content\ComunaModerationPolicy`, com os métodos:

- `assertOwner(usuario, membership, slug)` → 403 `/errors/not-comuna-owner`
- `assertModerator(usuario, membership, slug)` → 403 `/errors/not-comuna-moderator`
- `assertNotArchived(comuna)` → 409 `/errors/comuna-archived`
- `permissions(comuna, membership)` → mapa de flags `can*` (enviado em `viewer.permissions`)

### Remoção de membros

Remoção é simples: a pessoa sai da comuna e fica livre para entrar novamente em comunas públicas.
Não há sistema de banimento nesta versão.

Restrições:
- O fundador não pode ser removido (403 `/errors/cannot-remove-owner`).
- Ninguém se remove via endpoint de remoção de membro (400 `/errors/cannot-remove-self`);
  para sair, usa-se `DELETE /comunas/{slug}/membership`.
- Ao ser removida, uma transferência de propriedade pendente para essa pessoa é cancelada.

### Arquivamento

O arquivamento é **irreversível** pelo app (só a equipe pode desfazer via banco, se necessário).

Efeitos:
- A comuna passa para o estado `archived`.
- Nenhuma nova publicação, comentário, adesão ou pedido de entrada é aceito.
- Membros anteriores continuam vendo o conteúdo. Comunas privadas continuam privadas após o arquivo.
- Pedidos de entrada e transferências pendentes são cancelados.
- Remoção de membros e dispensa de moderadores ainda são possíveis.
- O slot de "fundador ativo" é liberado (a cota do fundador diminui em 1).

### Exclusão permanente

Pré-condições conferidas no servidor sob trava pessimista:

| Condição | Erro |
| --- | --- |
| Comunas arquivadas não podem ser excluídas | 409 `/errors/comuna-archived` |
| Outras pessoas ainda são membros | 409 `/errors/comuna-has-members` |
| Há conteúdo bloqueante (post ou comentário de outra pessoa não deletado, ou qualquer conteúdo removido por moderação) | 409 `/errors/comuna-has-content` |

Registros de auditoria e eventos de segurança são **mantidos**: o slug e o ID da comuna ficam no
campo `details` dos registros, mesmo depois que a linha da `comunas` é apagada.

A exclusão só é possível via app quando o fundador é o único membro e não há conteúdo bloqueante.
Para comunas com outros membros, a única saída pelo app é o arquivamento.
A equipe pode excluir pelo banco em casos excepcionais (com auditoria manual).

### Transferência de propriedade

1. O fundador propõe (`PUT /comunas/{slug}/ownership-transfer`) informando o handle do membro destinatário.
2. O destinatário aceita ou recusa (`POST /comunas/{slug}/ownership-transfer/accept|decline`).
3. A transferência é atômica: as duas linhas de membership são atualizadas na mesma transação.
   Nunca há dois fundadores ao mesmo tempo (garantido também por índice único parcial no banco).
4. O destinatário precisa ter e-mail confirmado para aceitar.
5. O destinatário precisa ainda ser membro quando aceitar (verificado sob trava).
6. O destinatário não pode ter 3 comunas ativas como fundador (verificado sob trava).
7. Só uma transferência por comunidade pode estar pendente ao mesmo tempo (índice único parcial).

A equipe pode transferir pelo console (`app:comuna:transfer-owner <slug> <handle> "<motivo>"`)
sem necessidade de aceite — para casos de conta suspensa ou inativa.

### Conteúdo removido pela moderação

- `POST /posts/{id}/removal` e `POST /comments/{id}/removal`: disponíveis para fundadores e
  moderadores da comuna onde a publicação foi feita.
- O texto é mantido internamente (estado `removed`) para fins de auditoria e como conteúdo
  bloqueante para a exclusão da comuna.
- Moderadores não podem remover o conteúdo do fundador, nem o próprio.

## 4. Limites de acesso a dados

| Dado | Própria pessoa | Outras pessoas | Moderação de comuna | Moderação da plataforma | Admin |
| --- | --- | --- | --- | --- | --- |
| Nome, @, bio, cidade | ✓ | ✓ | ✓ | ✓ | ✓ |
| E-mail | ✓ (`/me`) | — | — | — | via banco/console, auditado (sem endpoint) |
| Interesses | ✓ (`/me`) | — | — | — | — |
| Sessões | ✓ (sem IP) | — | — | — | — |
| IP / registro de segurança | — | — | — | — | ✓ com motivo, auditado |
| Lista completa de membros de uma comuna | membros | — | membros | — | via banco |
| Documento civil | não coletado | | | | |

A moderação de uma comuna não tem acesso ao e-mail, IP, sessões nem ao papel na plataforma
de nenhum membro. Só vê o handle, nome de exibição, papel na comuna e data de entrada.

## 5. Auditoria das ações de gestão

As seguintes ações ficam no `admin_audit_log` (ação → `actor`, `action`, `targetUserId`, `reason`, `details`):

| Ação | `action` | `details` |
| --- | --- | --- |
| Criar uma comuna | `comunaCreated` | `{comunaId, slug, name, visibility}` |
| Editar descrição/regras | `comunaUpdated` | `{comunaId, slug}` |
| Arquivar | `comunaArchived` | `{comunaId, slug}` |
| Excluir | `comunaDeleted` | `{comunaId, slug, name}` |
| Remover membro | `memberRemoved` | `{comunaId, slug, handle, role}` |
| Nomear moderador | `moderatorAppointed` | `{comunaId, slug, handle}` |
| Dispensar moderador | `moderatorDismissed` | `{comunaId, slug, handle}` |
| Propor transferência | `ownershipTransferProposed` | `{comunaId, slug, toHandle}` |
| Aceitar transferência | `ownershipTransferAccepted` | `{comunaId, slug, fromHandle}` |
| Recusar transferência | `ownershipTransferDeclined` | `{comunaId, slug, fromHandle}` |
| Cancelar transferência | `ownershipTransferCancelled` | `{comunaId, slug}` |
| Transferência pela equipe | `ownershipTransferredByPlatform` | `{comunaId, slug, fromHandle, toHandle}` |
| Remover publicação | `postRemoved` | `{comunaId, postId}` |
| Remover comentário | `commentRemoved` | `{comunaId, commentId}` |

O log é **só de inserção** (gatilho PostgreSQL recusa UPDATE, DELETE e TRUNCATE).
O `details` inclui sempre o `comunaId` e o `slug`, que permitem rastrear ações mesmo depois
que a comuna é excluída.

## 6. Conta do fundador suspensa ou excluída

**Suspensão:** a propriedade da comuna é mantida. O conteúdo do fundador some das listas
enquanto a conta está suspensa, mas a estrutura da comuna fica. A equipe pode transferir a
propriedade com `app:comuna:transfer-owner`. O comando `app:user:set-status` avisa quais
comunas ativas a conta possui.

**Exclusão de conta:** a exclusão de uma conta que ainda possui comunas é **bloqueada** pelo banco
(FK `RESTRICT` na coluna `owner_id` da tabela `comunas`). A conta só pode ser excluída depois que
todas as suas comunas forem arquivadas ou a propriedade for transferida.

## 7. Próximas etapas (desenho proposto — denúncias, restrições e recursos)

Tabelas, na mesma linha do plano técnico:

| Tabela | Campos principais | Observação |
| --- | --- | --- |
| `report_case` | alvo (`post`, `comment`, `profile`, `comuna`), comuna responsável (vazio = plataforma), estado, contagem, cópia do conteúdo | Um caso aberto por alvo; a cópia preserva o que foi denunciado mesmo se editado/apagado |
| `report` | caso, quem denunciou, motivo (lista fechada), detalhe, protocolo | Uma denúncia por pessoa e alvo; limite diário |
| `moderation_action` | caso, comuna, responsável, ação (`keep`, `remove_content`, `restrict`, `revert`), motivo enviado à autoria, nota interna, data | **Só inserção** (mesmo gatilho da auditoria); "desfazer" é uma nova linha com `reverts_action_id` |
| `comuna_restriction` | comuna, conta, tipo (`mute`, `suspend`, `ban`), início, fim, ação de origem | Consultada antes de publicar/comentar (entra em `ContentPolicy::assertCanPostIn`) |
| `appeal` | ação contestada, autoria, texto, estado, quem decidiu | Recurso vai para a moderação da plataforma |

Regras de autorização planejadas:

- Fila e decisões de uma comuna: só `moderator`/`founder` **daquela** comuna.
- Denúncias de perfil, de comuna e sem comuna: `ROLE_PLATFORM_MODERATOR`.
- Ninguém decide caso sobre o próprio conteúdo; ninguém restringe o fundador da comuna.
- Toda ação exige motivo; a autoria vê o motivo e pode recorrer; o recurso é decidido por outra pessoa.
- Reverter uma ação é uma ação nova, registrada; nada é apagado.
- Comunas privadas seguem as regras gerais da plataforma.

Conteúdo removido pela moderação usa o estado `removed` (já previsto em `ContentStatus`), que as
consultas e a `ContentPolicy` já tratam como invisível.
