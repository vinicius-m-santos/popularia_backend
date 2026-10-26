# Moderação e autorização

O que já existe e o desenho das próximas etapas, para que denúncias, decisões e recursos entrem
sem reescrever a fundação.

## 1. Dois eixos de papel, independentes

| Eixo | Valores | Onde | Quem muda |
| --- | --- | --- | --- |
| Plataforma | `user`, `platform_moderator`, `admin` | `app_user.platform_role` → `ROLE_USER`, `ROLE_PLATFORM_MODERATOR`, `ROLE_ADMIN` (hierarquia em `security.yaml`) | Só `app:user:set-role`, auditado |
| Comuna | `member`, `moderator`, `founder` | `comuna_membership.role` | Hoje: dados da equipe. Depois: fundação/moderação da comuna, com registro |

Ser `moderator` de uma comuna **não dá nenhum poder na plataforma**, e vice-versa. O cliente nunca
informa papel: o servidor sempre o lê do banco a partir do token.

## 2. Regras já aplicadas no servidor

Centralizadas em `App\Content\ContentPolicy` (itens) e nas consultas de `PostRepository` (listas):

- Comuna privada: só quem participa lê as publicações; não dá para entrar sem aprovação (403).
- Publicar e comentar: conta ativa, e-mail confirmado, participar da comuna; autoria vem do token.
- Bloqueio nos dois sentidos: conteúdo, comentários (viram marcador `hidden`), perfil (404) e respostas.
- Conteúdo apagado pela autoria some (texto apagado do banco); conteúdo de conta suspensa não aparece.
- Publicação invisível para quem pede responde 404 (não confirma que existe).
- Suspensão da conta: vale em toda requisição (`UserChecker`) e encerra as sessões.

## 3. Limites de acesso a dados

| Dado | Própria pessoa | Outras pessoas | Moderação de comuna | Moderação da plataforma | Admin |
| --- | --- | --- | --- | --- | --- |
| Nome, @, bio, cidade | ✓ | ✓ | ✓ | ✓ | ✓ |
| E-mail | ✓ (`/me`) | — | — | — | via banco/console, auditado (sem endpoint) |
| Interesses | ✓ (`/me`) | — | — | — | — |
| Sessões | ✓ (sem IP) | — | — | — | — |
| IP / registro de segurança | — | — | — | — | ✓ com motivo, auditado |
| Documento civil | não coletado | | | | |

As futuras respostas de moderação devem usar `AuthorView` (id, @, nome) e a cópia do conteúdo
denunciado — nunca a entidade `User`.

## 4. Próximas etapas (modelo proposto)

Tabelas, na mesma linha do plano técnico:

| Tabela | Campos principais | Observação |
| --- | --- | --- |
| `report_case` | alvo (`post`, `comment`, `profile`, `comuna`), comuna responsável (vazio = plataforma), estado, contagem, cópia do conteúdo | Um caso aberto por alvo; a cópia preserva o que foi denunciado mesmo se editado/apagado |
| `report` | caso, quem denunciou, motivo (lista fechada), detalhe, protocolo | Uma denúncia por pessoa e alvo; limite diário |
| `moderation_action` | caso, comuna, responsável, ação (`keep`, `remove_content`, `restrict`, `revert`), motivo enviado à autoria, nota interna, data | **Só inserção** (mesmo gatilho da auditoria); "desfazer" é uma nova linha com `reverts_action_id` |
| `comuna_restriction` | comuna, conta, tipo (`mute`, `suspend`, `ban`), início, fim, ação de origem | Consultada antes de publicar/comentar (entra em `ContentPolicy::assertCanPostIn`) |
| `appeal` | ação contestada, autoria, texto, estado, quem decidiu | Recurso vai para a moderação da plataforma |

Regras de autorização planejadas:

- Fila e decisões de uma comuna: só `moderator`/`founder` **daquela** comuna (novo `ComunaModerationPolicy`).
- Denúncias de perfil, de comuna e sem comuna: `ROLE_PLATFORM_MODERATOR`.
- Ninguém decide caso sobre o próprio conteúdo; ninguém restringe a fundação da comuna.
- Toda ação exige motivo; a autoria vê o motivo e pode recorrer; o recurso é decidido por outra pessoa.
- Reverter uma ação é uma ação nova, registrada; nada é apagado.
- Comunas privadas seguem as regras gerais da plataforma.

Conteúdo removido pela moderação usa o estado `removed` (já previsto em `ContentStatus`), que as
consultas e a `ContentPolicy` já tratam como invisível.
