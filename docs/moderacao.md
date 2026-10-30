# Moderação e autorização

O que está implementado: papéis, criação e gestão de comunas, denúncias e moderação V1.
O mapa da V1 está em [moderacao-denuncias-v1.md](moderacao-denuncias-v1.md).

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
  Proibido em comunas arquivadas e em comunas suspensas pela plataforma (409 `/errors/comuna-suspended`).
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
| Criar uma comuna | `comuna.created` | `{comuna_id, slug, visibility}` |
| Editar descrição/regras | `comuna.details_updated` | `{comuna_id, slug, fields}` |
| Arquivar | `comuna.archived` | `{comuna_id, slug}` |
| Excluir | `comuna.deleted` | `{comuna_id, slug, name}` |
| Remover membro | `comuna.member_removed` | `{comuna_id, slug, role}` |
| Nomear moderador | `comuna.moderator_appointed` | `{comuna_id, slug}` |
| Dispensar moderador | `comuna.moderator_dismissed` | `{comuna_id, slug}` |
| Propor transferência | `comuna.ownership_transfer_proposed` | `{comuna_id, slug}` |
| Aceitar transferência | `comuna.ownership_transferred` | `{comuna_id, slug, from}` |
| Recusar transferência | `comuna.ownership_transfer_declined` | `{comuna_id, slug}` |
| Cancelar transferência | `comuna.ownership_transfer_cancelled` | `{comuna_id, slug}` |
| Transferência pela equipe | `comuna.ownership_transferred` | `{comuna_id, slug, from}` |
| Remover publicação | `comuna.post_removed` | `{comuna_id, post_id}` |
| Remover comentário | `comuna.comment_removed` | `{comuna_id, comment_id}` |

O log é **só de inserção** (gatilho PostgreSQL recusa UPDATE, DELETE e TRUNCATE).
O `details` inclui sempre o `comuna_id` e o `slug`, que permitem rastrear ações mesmo depois
que a comuna é excluída.

## 6. Conta do fundador suspensa ou excluída

**Suspensão:** a propriedade da comuna é mantida. O conteúdo do fundador some das listas
enquanto a conta está suspensa, mas a estrutura da comuna fica. A equipe pode transferir a
propriedade com `app:comuna:transfer-owner`. O comando `app:user:set-status` avisa quais
comunas ativas a conta possui.

**Exclusão de conta:** o fluxo próprio agora recusa enquanto houver Comunas ativas sob propriedade da pessoa. Ela pode transferi-las ou arquivá-las antes. Comunas arquivadas permanecem, com owner nulo; a conta residual é descaracterizada para preservar integridade e auditoria. Não se usa DELETE direto no User. Ver [account-deletion.md](account-deletion.md).

## 7. Denúncias e moderação V1

Implementado em cima de `content_report` e de `admin_audit_log`. Não há `report_case`,
`moderation_action`, restrição temporária nem recurso. O desenho antigo dessa seção não foi construído.

- Alvos: `post`, `comment`, `profile`, `comuna`.
- Motivos fechados em `ReportReason`. A descrição opcional fica em `reason` (até 800 caracteres, texto simples).
- Escopo `comuna` para publicação e comentário de comuna. Escopo `platform` para perfil, comuna, publicação pessoal e denúncia escalada.
- A denúncia de uma comuna não fica visível para a moderação dessa comuna (`comuna_id` nulo).
- Deduplicação só enquanto a denúncia está `pending` ou `reviewing`. Depois de resolvida, uma nova é aceita.
- Auto-denúncia: 409 `/errors/cannot-report-self`.
- Limite: 20 denúncias por hora por conta, no limiter `report` já existente. Sem Redis novo.
- Fila local: `GET /comunas/{slug}/content-reports`. Fila global: `GET /moderation/reports` (`ROLE_PLATFORM_MODERATOR`).
- Decisão: `POST /moderation/reports/{id}/decision`, com trava da linha. A segunda decisão fecha com 409.
- Moderador de comuna não suspende conta. Conteúdo do fundador continua fora do alcance da moderação local; a plataforma pode remover.
- Suspensão de conta reutiliza `AccountStatus::Suspended` e revoga as sessões. Não há prazo automático.
- Comuna suspensa é `ComunaStatus::Suspended`, distinto de `archived`. Some da leitura, da busca e do preview, e não aceita conteúdo novo. Os dados ficam.
- Remoção de conteúdo usa `ContentStatus::Removed`. Restaurar volta a `published` sem recriar pin, XP ou aviso.
- Comentário removido continua na árvore, com o texto público trocado por “Comentário removido pela moderação.”
- O histórico é o `admin_audit_log`, só de inserção. A resposta da denúncia não inclui quem denunciou.
- Praça e biblioteca mantêm as filas que já tinham. Não há push por denúncia.

Papel de plataforma continua só em `app:user:set-role`. Moderador de comuna não recebe esse papel.
