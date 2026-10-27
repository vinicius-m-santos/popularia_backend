# Praça V1

## Auditoria e integração

Referência de produto: `design/Popularia Praça.dc.html`, traduzida para React Native; o HTML não foi reutilizado como implementação.

- Backend: Symfony 7.4, API Platform 4.4, Doctrine ORM 3.7, PostgreSQL 18. Entidades persistidas não são recursos públicos; operações usam DTOs, providers e processors em `src/Api`.
- Autenticação existente: bearer access token, refresh token, sessão revogável, conta ativa e verificação de e-mail. Nenhum novo mecanismo de login.
- `ComunaMembership` representa participação aceita. Pedidos privados ficam separados em `ComunaJoinRequest`. `founder` é o papel técnico de proprietário (`Comuna.owner`); `moderator` e `member` mantêm suas regras atuais.
- `ContentPolicy`, `ComunaModerationPolicy`, repositories de participação/bloqueios, `RateLimitGuard`, `AdminAudit` e `NotificationCreator` são reutilizados.
- Bloqueios são bilaterais, como Feed/Busca. Metadados públicos de comunas continuam nos endpoints existentes; dados internos da Praça são autorizados separadamente.
- A remoção de conteúdo e a auditoria já existiam no backend. A denúncia/fila mobile anterior era local, sem persistência no servidor. `ContentReport` introduz a referência persistente necessária, e a tela de moderação existente passa a consumir a fila real de mensagens da Praça quando conectada à API. Publicações/perfis continuam com seu comportamento anterior.
- Notificações internas já possuem referência a conteúdo, filtro de acesso em toda consulta, contagem e leitura. A Praça estende esse mesmo mecanismo.
- Não havia infraestrutura realtime nem upload de mídia. Rate limits já usam o PostgreSQL compartilhado; não foi acrescentado serviço externo.
- Mobile: Expo SDK 58, React Native 0.88, Expo Router, cliente HTTP com refresh compartilhado, `useAsyncData`/`usePaginatedData`, `Screen`, `KeyboardView`, `BottomSheet`, `AppText`, avatares, ícones e tokens existentes. Busca/Explorar já usam cursor UUID v7.

## Plano de implementação por camadas

1. Entidades, constraints e migration compatíveis com PostgreSQL existente.
2. Políticas/consultas em lote e escritas transacionais reutilizando governança.
3. Recursos API Platform, notificações e fila persistente.
4. Cliente mobile, sincronização/leitura, lista virtualizada e composer.
5. Gestos, figurinhas, participação, navegação e gestão pela comuna.
6. Testes, contrato OpenAPI, verificações e documentação.

## Domínio e invariantes

- `Square`: UUID v7 próprio, `comuna` nullable, título nullable, estado `active|closed`, criação/atualização/fechamento, criador e versão de alterações. Toda Comuna persistida recebe automaticamente sua Praça na mesma transação de criação; não existe operação pública para criá-la posteriormente. Entidades independentes têm acesso negado por padrão até haver política própria.
- Uma única Praça estrutural por Comuna. Comuna ativa → Praça `active`; Comuna arquivada → Praça `closed`, preservando a mesma identidade e todo o histórico. Owner/moderador não altera esse lifecycle diretamente. Índice único permite múltiplas praças futuras com `comuna_id NULL`.
- `SquareMessage`: autoria, texto OU figurinha, reply opcional, criação, edição reservada, remoção, estado de conteúdo, revisão e `clientId` de idempotência. Limite de texto: **2.000 caracteres**, como comentários. Normalização NFC e regras existentes de texto livre; React Native renderiza texto, sem interpretar HTML.
- CHECK de payload no banco recusa conteúdo vazio, texto + figurinha e texto excessivo. Status, versão e cores também têm CHECKs.
- FK composta garante que replies pertençam à mesma Praça; usa `SET NULL` limitado à referência opcional, compatível com [PostgreSQL 18](https://www.postgresql.org/docs/18/ddl-constraints.html#DDL-CONSTRAINTS-FK), sem anular a Praça em um eventual expurgo. Só se responde a mensagem disponível; não há threads.
- `SquareReaction`: conjunto limitado, unicidade por mensagem/pessoa/emoji. PUT e DELETE expressam o estado desejado, evitando um toggle ambíguo quando há retry de rede.
- `SquareVisit`: uma linha por Praça/pessoa para presença e cursor de leitura. FK composta protege o pertencimento do último ID lido.
- `StickerPack` e `Sticker`: pacote global Popularia e pacote próprio da comuna. Exclusão desativa a figurinha; mensagens antigas continuam renderizando a referência histórica.
- `ContentReport`: referência ao alvo, comuna, denunciante, motivo e decisão; sem snapshot de conteúdo. Uma denúncia por pessoa/alvo. A fila nunca expõe a identidade de quem denunciou.

Escritas de Praça travam primeiro a comuna e depois a Praça, na mesma ordem dos fluxos de governança existentes. Assim, arquivamento da Comuna/Praça, remoção de membro, mudança de papel e escrita não passam por decisões concorrentes inconsistentes. `clientId` e reações têm constraints únicas. Cada alteração de mensagem recebe uma revisão exclusiva da Praça sob trava. A exclusão de comuna considera mensagens de outras pessoas e conteúdo moderado, como já fazia com publicações e comentários.

## Lifecycle estrutural e migration

Toda Comuna possui uma Praça criada automaticamente. `ComunaSquareLifecycle` participa do `onFlush` do Doctrine: ao persistir uma Comuna, inclui sua Praça e o pacote da Comuna no mesmo commit/transação. Não há criação lazy em GET/listagem, nem trigger novo. Escritas administrativas em SQL que contornem o Doctrine devem preservar as mesmas invariantes; o preenchimento legado é feito pela migration, não por uma leitura da API. A constraint única de `square.comuna_id` impede a segunda Praça. A criação/arquivamento pela governança já usa transação e trava da Comuna; falha ao gravar a Praça desfaz a alteração da Comuna.

Ao arquivar a Comuna, o mesmo flush sincroniza `Square.status = closed`, usa `Comuna.archivedAt` em `closedAt` e avança a versão da Praça. O histórico e suas referências não são apagados. Não existe reativação de Comuna pelo app hoje, e nenhum fluxo novo foi criado. A sincronização está preparada para limpar `closedAt` e retornar a `active` se esse lifecycle for introduzido na Comuna futuramente.

`active|closed` e `closedAt` continuam no domínio Praça. Além de representar o encerramento da Praça de uma Comuna arquivada, o lifecycle pertence à entidade Praça porque tipos independentes/temporários futuros poderão ser encerrados sem serem apagados. `Square.close/reopen` reservam essa transição para entidades com `comuna = null` e recusam a alteração direta de uma Praça estrutural. A V1 não expõe endpoints de criação ou lifecycle independente.

`Version20261027000000`:

- preenche apenas Comunas sem Praça, com UUID próprio; ativas recebem `active`, arquivadas recebem `closed`;
- usa `Comuna.createdAt` para a criação e `archivedAt` para o encerramento; quando o encerramento legado é desconhecido, usa o horário da migration;
- usa owner, depois founder mais antigo, como `createdBy`. Em Comunas da plataforma sem autoria conhecida, mantém `null`, sem inventar conta/autoria; a FK passa a aceitar esse caso;
- preserva Praça, IDs, criação/autoria existentes e todos os dados associados; normaliza somente lifecycle inconsistente, `closedAt`, atualização e versão;
- normaliza ativa + closed para active/null e arquivada + active para closed/archivedAt; não altera Praças independentes;
- preenche pacotes da Comuna ausentes sem alterar pacotes/figurinhas existentes;
- usa `uuidv7()` nativo do [PostgreSQL 18 já configurado](https://www.postgresql.org/docs/18/functions-uuid.html), sem extensão nova;
- estabiliza as Comunas com lock transacional durante o preenchimento e pode ser reaplicada sem duplicar entidades ou avançar versões novamente. Não apaga/recria tabelas;
- no rollback, preserva Praças e conteúdo. Restaura NOT NULL apenas se todas as autorias forem conhecidas; caso contrário recusa o rollback para impedir perda de dados/autoria fictícia. O preenchimento e a normalização de dados não são desfeitos.

No deploy, suspenda as escritas de Comuna enquanto aplica a migration e troca todas as instâncias antigas: código anterior ainda permitia Comunas sem Praça e lifecycle manual. Nenhuma escrita deve voltar ao código antigo após o preenchimento.

As operações públicas `PUT /comunas/{slug}/square` e `PATCH /squares/{id}` foram removidas; GET permanece. As permissões de moderação/figurinhas não conferem poder para mudar lifecycle.

## Autorização e privacidade

| Situação | Comportamento |
| --- | --- |
| Não membro de pública | Lê, vê presença e reações; precisa participar para enviar/responder |
| Pública: reagir/denunciar | Permitido a quem tem acesso; reagir exige e-mail confirmado |
| Não membro de privada, inclusive pedido pendente | Somente identificação pública da comuna e estado de entrada; sem histórico, preview, autores, presença, figurinhas, versão, unread ou estado interno |
| Membro aceito | Lê; envia/responde com e-mail confirmado e Praça/comuna ativas |
| Autor | Exclui própria mensagem, sem remover conteúdo alheio |
| Owner/moderador | Gerencia figurinhas, vê/decide denúncias e modera conforme a política existente; o lifecycle da Praça é controlado pela Comuna |
| Praça fechada ou comuna arquivada | Histórico autorizado permanece disponível; interações e novas escritas são recusadas |

Moderadores não removem conteúdo do proprietário, conforme o Feed. A autoria apaga seu próprio conteúdo; moderação registra motivo no `AdminAudit`. O payload removido fica inacessível na API, inclusive em replies, previews, reações e notificações.

Bloqueios/suspensão ocultam autoria, texto, figurinha e reações das mensagens afetadas. Replies passam a referência neutra. Reações de pessoas bloqueadas saem das contagens; presença/listas/unread/ranking/previews também excluem essas pessoas. Mensagens seguem como marcadores neutros para preservar o contexto do histórico.

Toda consulta sensível verifica acesso no servidor antes de serializar. Os endpoints existentes de membership e join request são usados sem duplicação. O polling revalida o estado aceito, permitindo desbloquear a tela após aprovação por outro aparelho. Em 401/403/404 o mobile limpa o histórico e metadados carregados. Conteúdo já recebido só pode ser retirado da tela após nova comunicação com o servidor; falha de rede não permite revogar retroativamente bytes já recebidos.

## Histórico, sincronização e desempenho

Decisão V1: **polling incremental REST**, sem Redis, WebSocket, Mercure, broker ou push.

- Abertura carrega até 30 mensagens recentes; API aceita `limit=1..50`. Ordem de histórico: UUID v7 decrescente, estável, com `cursor` exclusivo para anteriores.
- `after=changeCursor` busca revisões posteriores em ordem crescente; inclui alteração de reação, exclusão e remoção de mensagens antigas. `hasMoreChanges` instrui drenar a sequência em páginas, sem pular alterações.
- Revisões são atribuídas sob trava e ficam na mesma transação da mensagem/reação. Não dependem de timestamps de precisão insuficiente nem de uma sequência reservada antes do commit.
- Enquanto a tela está em foco e o app ativo, consulta a cada 3 segundos. Backlog usa intervalo de 100 ms entre páginas; falhas usam recuo até 15 segundos.
- Até 200 `refreshIds` autorizados revalidam o trecho carregado, replies e contagens. Isso propaga bloqueios e suspensão sem exigir um barramento de eventos. Há custo limitado adicional por polling, deliberado na escala inicial; as consultas são em lote.
- A lista é `FlatList` invertida, com keys estáveis, renderização virtualizada, linhas memoizadas e referências preservadas para itens inalterados. Janela em memória limitada a 200 mensagens. Ao paginar além disso, preserva o trecho antigo e oferece retorno às recentes. Ao reduzir a janela recente, reajusta o cursor para não deixar lacunas.
- A posição é preservada ao carregar anteriores. Mensagens novas não deslocam quem está lendo histórico; ficam no contador. Tocar no contador busca as recentes e vai ao final.
- O envio otimista usa um `clientId` estável por payload/retry; polling e resposta HTTP reconciliam o mesmo envio. Falha mantém o texto no composer. Travas locais evitam duplo submit e requests repetidos de paginação/polling.
- Reações são otimistas e reconciliadas com a resposta autorizada. Respostas antigas não podem restaurar texto removido ou acesso privado após uma atualização mais nova.
- Timers/listeners são limpos no blur/unmount; background pausa atividade. A volta ao foreground revalida acesso e recentes.
- Índices cobrem histórico, revisões, atividade, presença, leitura por pessoa, reactions e fila. Previews, participação, reações e unread são calculados em lote; não há chamada por Praça/mensagem na listagem.

A API separa conteúdo e cursor de alterações do transporte. Um transporte realtime futuro pode entregar os mesmos IDs/revisões e manter REST para histórico e recuperação.

## Presença

Heartbeat ao entrar e a cada aproximadamente 30 segundos. UPSERT só atualiza `seen_at` se a renovação anterior tiver mais de 30 segundos. Presença expira logicamente após **90 segundos**, mesmo sem logout/saída perfeitos. Uma linha por pessoa evita contagem de duas sessões como duas pessoas; não representa número de membros. Não há tarefa de expiração obrigatória: consultas filtram a janela temporal. A linha continua útil para leitura, sem acúmulo de eventos de heartbeat.

Contagem aproximada e até 12 perfis públicos são retornados após autorização e filtros bilaterais. Visitantes de públicas contam como presença. Membros removidos de privada deixam de ser contados imediatamente.

## Leitura e unread

Somente `PUT /squares/{id}/read` avança o último ID lido, de forma monotônica. Buscar histórico/polling não marca leitura por si só. O mobile avança quando o conteúdo recente está visível com a tela em foco/app ativo; não avança durante leitura do histórico ou backlog. Uma Praça aberta no final é considerada lida até a mensagem recente exibida, sem read receipts individuais.

Unread conta mensagens publicadas de outras pessoas após esse ID, respeitando bloqueios e conta ativa. Sem leitura anterior, conta o histórico disponível. O cálculo da listagem é feito em uma consulta em lote, não com requests/consultas por cartão. O separador inicial “NOVAS MENSAGENS” usa o cursor existente antes de avançar a leitura.

## Descoberta

“Suas Praças” usa memberships aceitas, incluindo privadas autorizadas e histórico fechado. “Acontecendo agora” contém apenas públicas ativas descobríveis, excluindo as já listadas como suas.

Ranking simples: `min(presença recente, 5) + min(mensagens na última hora, 4) + 2 se houver afinidade com interesses`. Empate por UUID da Praça. O total histórico de membros não entra; os pesos saturam para não favorecer indefinidamente as maiores comunas. Ordenação é aplicada a todas as candidatas antes do limite, com cursor do item de ranking. Como o ranking é vivo, mudanças de atividade podem reposicionar cartões entre páginas; o mobile deduplica por ID e a atualização recomeça a listagem. Essa dinâmica não afeta a paginação estável do histórico.

Sem Praças próprias, a tela mostra públicas e “Encontre sua comunidade” com navegação real para Explorar. A CTA futura “Sugerir uma Praça” foi omitida.

## Figurinhas e interações mobile

V1 simbólica, como a referência: símbolo permitido, frase de até 24 caracteres e cor existente. Até 40 figurinhas ativas por comuna. Pacote Popularia sem gestão por usuários; pacote da comuna só por owner/moderador, com auditoria. `assetKey` reserva a referência futura a armazenamento oficial; não aceita upload, URL arbitrária ou base64. Não há editor de imagens.

Long press abre barra compacta de reações junto à mensagem; `+` abre ações válidas. Swipe à direita só captura movimento horizontal claro, com deslocamento limitado e threshold de 64 dp. Scroll vertical continua com a lista. Responder também está disponível nas ações de acessibilidade/menu. Sem nova dependência háptica. Tocar reply vai à mensagem se estiver na janela carregada; fora dela explica a indisponibilidade daquele trecho.

Composer usa fonte/tokens existentes, texto multilinha limitado, contexto cancelável de reply, emoji leve e seletor de pacotes. `KeyboardView` preserva o padrão iOS/Android já adotado; safe area inferior é aplicada no composer e retirada quando o teclado abre. Não foi criado projeto nativo manualmente.

## Notificações e navegação

Só resposta direta gera `square_reply` na central existente. Sem notificação por mensagem comum, reação ou heartbeat, e sem push. A central revalida acesso, bloqueios, autoria ativa, mensagem e original antes de mostrar quote/contar unread/navegar.

Barra: **Início · Explorar · + · Praças · Perfil**. `+ → Publicação / Comuna` permanece. Avisos saiu da barra e fica no sino com contador no Início, com botão de voltar e suas rotas preservadas em `/avisos`. A conversa fica na pilha raiz, sem barra inferior, dando lugar ao composer. A Comuna oferece acesso à sua Praça existente; a gestão mantém figurinhas e denúncias, sem criação, fechamento ou reabertura manual.

## Endpoints

Prefixo comum `/api/v1`; todos exigem bearer válido. IDs de Praça/mensagem são UUIDs próprios. Inputs desconhecidos são recusados pelo padrão da API.

| Método | Rota | Finalidade |
| --- | --- | --- |
| GET | `/squares?section=mine|discover&cursor=&limit=` | Listagem paginada |
| GET | `/comunas/{slug}/square` | Obter a Praça criada automaticamente com a Comuna |
| GET | `/squares/{id}` | Metadados autorizados e lifecycle derivado da Comuna |
| GET | `/squares/{id}/messages?cursor=&limit=&after=&refreshIds=` | Histórico ou alterações; cursor e after são exclusivos |
| POST | `/squares/{id}/messages` | Texto ou sticker, replyToId opcional, clientId obrigatório |
| DELETE | `/squares/{id}/messages/{messageId}` | Excluir própria mensagem |
| POST | `/squares/{id}/messages/{messageId}/removal` | Remover pela moderação, com reason |
| PUT / DELETE | `/squares/{id}/messages/{messageId}/reactions/{emoji}` | Adicionar/remover reação própria idempotentemente |
| POST | `/squares/{id}/messages/{messageId}/reports` | Denúncia persistida, com reason |
| GET / PUT | `/squares/{id}/presence` | Contagem/preview ou heartbeat |
| PUT | `/squares/{id}/read` | Avançar leitura com messageId |
| GET | `/squares/{id}/sticker-packs` | Pacotes autorizados |
| POST | `/squares/{id}/stickers` | Criar figurinha simbólica da comuna |
| DELETE | `/squares/{id}/stickers/{stickerId}` | Desativar figurinha da comuna |
| GET | `/comunas/{slug}/reports?cursor=&limit=` | Fila da moderação |
| PATCH | `/comunas/{slug}/reports/{reportId}` | Decidir dismissed ou removed |

Envios: 30/minuto por conta; reações/denúncias: 120/minuto. Usa o rate limiter compartilhado existente. Retornos seguem `application/problem+json`, incluindo 403 privado/sem membership, 404 alvo indisponível/fora da Praça, 409 fechado/arquivado, 422 payload inválido e 429 com Retry-After.

## Validação e execução

`SquareTest`, `StructuralSquareTest` e `StructuralSquareMigrationTest` cobrem criação automática, atomicidade/rollback, unicidade, preenchimento/normalização do legado e preservação de referências, além dos fluxos HTTP/DB autorizados e recusados, privacidade, participação/pedidos existentes, replies, reações idempotentes, autoria/moderação/denúncia, bloqueios, paginação/alterações, unread, presença, fechamento, figurinhas e limite de envio. O teste OpenAPI inclui as rotas novas. Mobile tem testes de estado, hook e UI para fluxos críticos e corridas.

Na revalidação deste ajuste de lifecycle, o host tem PHP 8.1.2, enquanto o projeto exige 8.4+ e PHPUnit exige 8.4.1+. O daemon Docker também recusou acesso. Portanto **não foi possível executar PHPUnit (incluindo a suíte existente), PHPStan, CS Fixer, migration/validação de schema nem a exportação Symfony de OpenAPI**. Sintaxe foi analisada com a gramática PHP 8.4 do parser disponível. `docs/openapi.json` teve as operações obsoletas retiradas como atualização provisória do contrato. A geração real foi tentada com `docker compose exec -T php composer openapi`, mas o daemon recusou a conexão. O snapshot permanece **não validado** até regenerar/comparar pelo exporter real em PHP 8.4+. Não interpretar essas verificações bloqueadas como aprovadas.

Antes de instalar a V1 para teste integrado, executar no ambiente habitual PHP/PostgreSQL:

```bash
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php php bin/console doctrine:schema:validate
docker compose exec php php bin/phpunit
docker compose exec php composer stan
docker compose exec php composer cs
docker compose exec php composer openapi
```

Resultados mobile: **147 testes passaram em 18 suítes**, incluindo quatro novos testes mobile deste ajuste (Praça automática, histórico arquivado, erro recuperável e gestão sem lifecycle manual); TypeScript e lint passaram. A exportação de bundles Android/iOS e leitura da configuração Expo também funcionaram. `expo install --check` informou dependências compatíveis, com a ressalva da própria CLI de que a validação offline é limitada. `expo-doctor` não executou: não está instalado/em cache e o npm offline retornou `ENOTCACHED`. `git diff --check` passou nos dois repositórios.

Comandos mobile: `npm test -- --runInBand`, `npm run typecheck`, `npm run lint`, exportação Expo para Android/iOS e `git diff --check`. A exportação de bundles não instala APK/IPA nem substitui validação visual do teclado, safe area, gesto e posição da lista em celulares reais.

Para o teste com duas contas: configurar a API real no mobile e aplicar as migrations; criar/usar uma Comuna, cuja Praça já existe; entrar com duas contas participantes e conversar. Arquivar a Comuna como owner deve encerrar a Praça, preservar o histórico autorizado e impedir novas interações. A migração inicial semeia o pacote Popularia; a migration estrutural preenche as Praças e pacotes ausentes das Comunas existentes.

## Evolução futura — não implementada

### Praças independentes

Local, regional, nacional, temática, emergencial, oficial, de evento ou temporária. Poderão ser criadas sob demanda, iniciar `active`, ser encerradas independentemente mantendo o histórico, ter prazo de vida e eventualmente ser reabertas conforme política. Exigem política própria de acesso/governança e descoberta; a relação nullable e a identidade própria preservam essa possibilidade. Nenhum desses tipos/fluxos foi implementado neste ajuste.

### Solicitação de Praça

Usuário → solicitação → administração Popularia → aprovação/rejeição. Sem dashboard administrativo agora, não existe solicitação fake nem botão morto.

### Mobilização

Mensagens/ações estruturadas: “Preciso de ajuda”, “Posso ajudar”, “Informação” e “Organização”; estados como ajuda sendo organizada e resolvido.

### Mídia futura

Imagens, áudio e vídeo, depois da infraestrutura oficial de assets, autorização e moderação correspondente. Sem arquivos/localização nesta V1.
