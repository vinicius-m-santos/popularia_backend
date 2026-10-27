# Biblioteca V1

Implementação de PDFs duráveis em bibliotecas pessoais, de comunas e no índice público. Feed e salvos referenciam documentos; não armazenam cópias de arquivos nem concedem permissão.

## Auditoria e referência

Referência de produto: `Popularia_ Rede social brasileira.html`, localizada em `/mnt/c/Users/vinim/Downloads/`. O HTML é um bundle; seu template foi decodificado para inspeção em `/tmp`, sem copiar HTML/CSS para a aplicação. Foram conferidos Biblioteca Pública no Explorar, abas de perfil/comuna, coleções, cartões, detalhe, acesso, seleção para publicação e envio em quatro etapas. O limite de **25 MB** vem desse Artifact.

Estado encontrado antes da implementação:

- Symfony 7.4, API Platform 4.4, Doctrine ORM 3.7, PostgreSQL 18 e PHP mínimo 8.4. Recursos de saída separados das entidades; entradas próprias; erros `ApiProblem`; autenticação por token opaco conferido a cada requisição.
- `ContentPolicy`, `ComunaModerationPolicy` e `ComunaGovernance` já centralizavam leitura, participação aprovada, owner/moderador, bloqueios bilaterais, escrita e arquivamento. Não havia banimento persistente nem visibilidade mutável de comunas pelo app. Uma remoção de membro permite nova entrada segundo as regras atuais.
- Perfis eram públicos e o follow era imediato. Não havia estado de seguidor aprovado nem privacidade de perfil. Foi necessário acrescentar esse suporte para que “seguidores” não fosse uma promessa sem autorização correspondente.
- `Topic` já era a taxonomia oficial de 18 assuntos, publicada por `/topics`. Reutilizada integralmente.
- Feed/post eram textuais na API; imagem, chamado e enquete no composer tinham ações de demonstração/futuras. O `+` oferecia publicação e comuna. Essas entradas foram preservadas.
- Explorar já buscava no servidor com `popularia_search_text`, `unaccent`, `pg_trgm` e cursor UUID v7. As extensões não são recriadas.
- Havia remoção lógica de conteúdo, auditoria administrativa e, nas alterações existentes da Praça, `ContentReport` com fila persistida. A Biblioteca reutiliza essas estruturas; a fila da Praça passou a filtrar o seu tipo de alvo.
- Notificações guardam referências e reavaliam acesso. Biblioteca não introduz notificações de upload, compartilhamento ou save.
- Não existia upload/storage real para imagens ou arquivos, adaptador S3, autenticação de arquivos ou antivírus. PHP e Caddy limitavam corpos a 1 MB. Havia Docker/FrankenPHP e configuração genérica de produção, sem configuração de bucket Railway no projeto.
- Mobile: Expo SDK 58, Router, cliente com refresh compartilhado, hooks de dados e paginação, paleta creme/tinta/vermelho, Archivo/Archivo Narrow/IBM Plex Mono. Reutilizados `Screen`, `AppText`, `PillButton`, `TextField`, `ChipRow`, `SingleTopicField`, `StateView`, `BottomSheet` e abas. A opção sem ScrollView no BottomSheet evita aninhar listas virtualizadas no seletor de documentos.
- Alterações preexistentes da Praça nos dois repositórios foram preservadas.

## Modelo e invariantes

| Registro | Responsabilidade |
| --- | --- |
| `library_file` | Chave S3 privada, uploader, nome original, MIME PDF, bytes, páginas, SHA-256 e recibo de envio (`client_id`). Estados pending/ready/failed/purged. |
| `library_document` | Título, descrição, Topic opcional, coleção obrigatória, origem pessoal **ou** comuna, acesso original, opt-in público, status e remoção. |
| `library_collection` | Origem pessoal **ou** comuna, nome, descrição, criador e datas. Sem subcoleções. |
| `library_share` | Referência única documento/comuna, vinculada à participação que permitiu compartilhar. Estado published/removed. |
| `library_save` | Referência única usuário/documento; nenhum arquivo e nenhuma autorização histórica. |
| `post.library_document_id` | Referência ao documento original, opcional. |

A origem e o arquivo de um documento são imutáveis. FKs compostas mantêm coleção e documento na mesma origem. Um trigger também impede substituir os campos físicos de um arquivo finalizado. O checksum é indexado, mas não é único: pessoas diferentes podem enviar PDFs idênticos em contextos próprios. Não há deduplicação física entre uploads independentes nesta V1.

O opt-in `is_public` é independente do acesso original e dos compartilhamentos normalizados. Nenhuma alteração de perfil, comuna, post ou bookmark o habilita implicitamente.

## Autorização e metadados

`LibraryAccess` concentra as expressões SQL usadas nas listas, busca, detalhe e arquivo. As consultas aplicam autorização antes de paginação. `LibraryViews` revalida os documentos em lote após a hidratação e produz DTOs restritos. Entidades nunca são serializadas diretamente.

Leitura exige conta autenticada, documento publicado, arquivo pronto, uploader ativo e ausência de bloqueio bilateral com o uploader, de maneira consistente com a autoria no Feed. A regra também vale para PDFs públicos e compartilhados amplamente.

Qualquer concessão atual válida permite abrir:

- Biblioteca pessoal: owner ou seguidores **aprovados**, se o acesso original for followers.
- Comuna pública: visitantes para visitors; participação atual para members; coordenação atual para moderators.
- Comuna privada: participação atual; moderators exige coordenação. A regra considera a visibilidade atual, mesmo se ela vier a mudar fora da API V1.
- Compartilhamento pessoal: visitantes de comuna pública ou participantes de comuna privada, enquanto a participação de quem compartilhou continuar válida.
- Biblioteca Pública: opt-in explícito, independente das demais concessões.

“Só você” descreve o acesso na biblioteca pessoal; outras distribuições explicitamente escolhidas continuam independentes. “Público” significa qualquer conta autorizada a usar o Popularia, não internet anônima.

**Só owner/moderador da comuna pode habilitar ou retirar o opt-in público de um documento originado nela.** Membros aprovados podem enviar documentos e criar coleções; quem enviou pode editar os próprios envios enquanto participa. Gestão também cabe à coordenação. O nível “Só coordenação” só pode ser escolhido pela coordenação. Coleções são geridas pelo criador participante ou pela coordenação.

Em documento de comuna pública restrito a membros, visitantes podem receber somente título, identificação pública da comuna e estado bloqueado. Não recebem descrição, coleção, uploader, páginas, bytes, distribuições, nome original ou chave de storage. A busca desse cartão só considera o título seguro: descrição, Topic e coleção não podem funcionar como canais indiretos de descoberta.

Documentos pessoais restritos, de comunas privadas, bloqueados ou removidos não ganham metadados por aparecerem em posts/salvos. Posts recebem um cartão neutro quando o documento não está autorizado. Detalhes sem autorização respondem 404, salvo o cartão mínimo autorizado da comuna pública. Salvos preservam a referência indisponível e permitem removê-la.

Somente gestores recebem a lista das distribuições do documento. Coleções e seus contadores são filtrados por acesso; no índice público contam somente documentos públicos. Compartilhar um documento público de comuna privada exporta conscientemente a identificação daquela origem/coleção no documento autorizado; não abre a biblioteca interna ou seus outros materiais.

### Perfis privados

`app_user.is_private` e `user_follow.approved` foram acrescentados sem mudar a aprovação dos seguidores existentes. Novos follows de perfil privado ficam pendentes. A própria pessoa aprova/recusa em Conta. Voltar a público aprova os pedidos pendentes explicitamente, conforme o Artifact; os PDFs continuam no alcance configurado no documento. Feed, detalhe e busca de posts de perfil também respeitam a aprovação, para que o novo estado privado não exponha publicações por outra entrada. Comunas continuam com suas regras próprias.

## Upload e storage

Limite: **25 MiB (26.214.400 bytes)**, apresentado como 25 MB. É o limite do Artifact e comporta cartilhas/atas/estudos com a memória de 256 MB atual. PHP aceita 25M por arquivo e 27M por POST; Caddy aceita o envelope até 27MB somente no POST de PDF, mantendo 1MB para as outras rotas. O endpoint aceita um PDF por envio e metadados de até 16 KB.

O fluxo mobile tem arquivo/informações/Topic, coleção, disponibilização e revisão, seguido de sucesso. Criação de coleção ocorre sem abandonar o envio. Há progresso real de transporte e mensagem de validação ao concluir os bytes, erro, retry, cancelamento e interrupção ao ir para background. A seleção e os metadados permanecem no retry.

O backend valida extensão, MIME informado, MIME detectado por `finfo`, assinatura `%PDF-`, tamanho, conteúdo não vazio e estrutura com **qpdf**. Conta páginas com qpdf e calcula SHA-256. Cada processo de validação tem limite de 20 segundos e 256 MiB de memória virtual, usando timeout/prlimit do sistema, para limitar PDFs de alta complexidade. Arquivos corrompidos ou que exigem senha são recusados. Não há extração de texto, OCR, previews ou execução do conteúdo enviado. qpdf é dependência do Docker, não uma stack de processamento de documentos.

Storage é `ObjectStorage` com implementação S3 REST/SigV4 para GET/PUT/DELETE privados. Não havia storage equivalente para reaproveitar. Endpoint, bucket e credenciais existem somente no backend. A implementação suporta o estilo virtual-hosted atual do Railway e path-style para buckets antigos. Não há base64 no PostgreSQL, bucket público, URL permanente ou filesystem de container como armazenamento final.

Não há antivírus instalado no projeto. A validação estrutural **não substitui** análise de malware: PDFs podem conter recursos ativos para o leitor do aparelho. O bucket é isolado/privado; a aplicação não executa conteúdo e não renderiza PDFs dentro da API. Essa é uma limitação conhecida desta V1.

### Finalização e falhas

Não se cria documento final antes de validar e guardar o objeto. Um recibo `library_file` pending é persistido antes do PUT para permitir limpar objetos após crash. Depois do PUT, coleção, participação e permissões são conferidas novamente sob transação e o documento é criado com o arquivo ready. Em falha, tenta-se eliminar o objeto e mantém-se recibo failed para limpeza/retry.

`clientId` UUID identifica o envio por uploader e tem constraint única. Refresh de token, retry de rede e resposta perdida retornam o mesmo documento quando já finalizado. Escolher outro arquivo gera novo clientId. Uploads concorrentes do mesmo recibo retornam conflito enquanto o primeiro está em andamento.

Execute **a cada hora** `php bin/console app:library:purge`: elimina até 100 objetos pending/failed sem documento, parados há 24 horas. O registro vira purged; o mesmo recibo pode ser reutilizado por um retry. Se o storage falhar, o comando falha e preserva o recibo para tentar de novo. Arquivos finais e evidências de moderação não são apagados por essa limpeza.

## Abrir, cache e revogação

`GET /library/documents/{id}/file` reautoriza e entrega bytes pelo backend. Não retorna signed URL reutilizável. Essa escolha cumpre a revogação em **novas requisições** sem esperar a expiração de uma URL S3. Não há URL de download no banco ou nos DTOs.

Rotas da Biblioteca e salvos usam `Cache-Control: private, no-store`, variam por Authorization e não entregam ETag/Last-Modified. O mobile baixa um temporário novo a cada abertura, com token/refresh do cliente existente, e usa o sistema para abrir/compartilhar. Remove o temporário ao terminar/retornar, com espera limitada para o leitor consumir o URI. Não usa arquivos anteriores como fallback após recusa.

Revogação não recolhe bytes já recebidos, downloads em andamento nem cópias deliberadamente exportadas para outros apps. Fazer isso exigiria DRM, que está fora do escopo. Não há modo de leitura offline persistente dentro do Popularia.

## Coleções, lifecycle e moderação

Coleção é obrigatória e sem subcoleções. Criação, edição, visualização, navegação e movimentação de documento usam a origem atual. Exclusão é recusada se houver documentos, inclusive registros preservados por exclusão/moderação; não existe exclusão em cascata de PDFs. Esse comportamento conserva o histórico e é explicado na mensagem de conflito.

Excluir o original marca deleted: some da busca e das bibliotecas; posts e salvos ficam indisponíveis; FKs permanecem. Moderação usa removed, responsável, motivo, data e `AdminAudit`. A exclusão física de PDFs finais fica para uma política posterior de retenção/evidências; o comando de limpeza cobre apenas envios não finalizados.

Remover distribuição não remove original, outros compartilhamentos ou opt-in público. Moderação de comuna retira somente a distribuição de um PDF pessoal; o autor não pode recolocá-lo pela mesma associação enquanto a remoção estiver registrada. Retirar conteúdo originado na comuna remove o documento daquela origem. Aplicam-se os limites de moderação já existentes, inclusive conteúdo do owner.

Sair/ser removido da comuna apaga as distribuições pessoais vinculadas àquela membership por FK, sem apagar o documento pessoal. Reentrar não restaura compartilhamentos antigos. PDFs originados na comuna continuam pertencendo à comuna; quem saiu perde as concessões que dependiam de membership.

Comuna arquivada continua legível segundo as permissões atuais. Não recebe uploads, coleções novas, edição comum ou novos compartilhamentos. Remoções administrativas e retirada de distribuições continuam possíveis. A presença de documentos impede exclusão definitiva da comuna para preservar referências; coleções vazias podem acompanhar a exclusão de uma comuna elegível.

Denúncias usam `ContentReport`, sem snapshots de PDFs ou identidade de denunciantes nas saídas. A fila existente de moderação mobile inclui a seção Documentos. A plataforma tem operações protegidas para listar/decidir denúncias e remover documentos públicos/denunciados, sem novo dashboard. A Biblioteca não gera notificações em massa.

## Busca, filtros e paginação

Biblioteca Pública é uma entrada editorial no Explorar. `GET /search` mantém Pessoas/Comunas/Publicações e acrescenta `documents` ao grupo Tudo; “Ver todos” abre a busca da Biblioteca, sem acrescentar um chip global obrigatório.

Busca parcial no servidor por título, descrição, nome da coleção, responsável/@, nome da comuna e nome oficial do Topic. Usa a normalização existente sem diferenciar acentos/caixa e escapa curingas. Não há tags livres, full-text do PDF ou ranking por downloads/visualizações.

Filtros: origem all/people/comunas, Topic oficial, coleção e ordenação recent/relevance. Relevância só prioriza título exato, prefixo e correspondência de título, com recência como desempate. Não inventa popularidade. Índices cobrem origem, estado, público, Topic, coleção, relações, checksum, limpeza e busca normalizada.

Documentos, coleções, busca e salvos são paginados, padrão 20 e máximo 50. Cursores de recência e salvos usam UUID v7 do registro correspondente; relevância usa `r<grupo>_<uuid>`. O cliente repete o cursor sem interpretá-lo. Hidratação em lote preserva a ordem e revalida acesso. Listas mobile de documentos são virtualizadas. Save não recarrega a biblioteca inteira.

## Contrato de API

Todos os caminhos abaixo têm prefixo `/api/v1` e exigem autenticação.

| Operação | Caminho |
| --- | --- |
| Listar/buscar documentos | `GET /library/documents` |
| Enviar/finalizar atomicamente | `POST /library/documents` multipart: `file`, `metadata` JSON |
| Detalhar/editar/excluir | `GET/PATCH/DELETE /library/documents/{id}` |
| Obter arquivo autorizado | `GET /library/documents/{id}/file` |
| Salvar/remover dos salvos | `PUT/DELETE /library/documents/{id}/saved` |
| Compartilhar/retirar da comuna | `PUT/DELETE /library/documents/{id}/comunas/{slug}` |
| Salvos | `GET /me/saved-documents` |
| Listar/criar coleções | `GET/POST /library/collections` |
| Detalhar/editar/excluir coleção vazia | `GET/PATCH/DELETE /library/collections/{id}` |
| Denunciar/remover | `POST /library/documents/{id}/reports`, `POST /library/documents/{id}/removal` |
| Fila/decisão | `GET /library/reports?comuna=slug`, `PATCH /library/reports/{id}` |
| Privacidade e seguidores | `PATCH /me` com `isPrivate`; `GET /me/follow-requests`; `PUT/DELETE /me/follow-requests/{id}` |

Listas: `scope=public|personal|comuna`, `owner=<uuid>` para biblioteca pessoal, `comuna=<slug>`, `q`, `topic`, `origin=all|people|comunas`, `sort=recent|relevance`, `collection`, `cursor`, `limit`. Coleções aceitam os filtros de contexto e paginação.

Metadados de upload: `title`, `description`, `collectionId`, `topic`, `access`, `isPublic`, `sharedWith` (slugs, até 20), `clientId`. PATCH de documento não aceita arquivo; usa os mesmos campos de metadados pertinentes, `clearTopic` para retirar assunto e operações de distribuição explícitas para comunas. Publicar usa `POST /posts` com `documentId` opcional, e corpo opcional quando há PDF. O cliente nunca envia ownership, estado ou storage key.

## Configurar e validar

No backend Railway, associar variáveis ao bucket privado:

| Variável | Valor |
| --- | --- |
| `LIBRARY_S3_ENDPOINT` | Referência `ENDPOINT` do bucket, ex.: `https://t3.storageapi.dev` |
| `LIBRARY_S3_BUCKET` | Referência `BUCKET` do bucket, não seu nome de exibição |
| `LIBRARY_S3_REGION` | Referência `REGION`, usualmente `auto` |
| `LIBRARY_S3_ACCESS_KEY` | Referência `ACCESS_KEY_ID` |
| `LIBRARY_S3_SECRET_KEY` | Referência `SECRET_ACCESS_KEY` |
| `LIBRARY_S3_PATH_STYLE` | `0` para virtual-hosted; `1` somente se as credenciais do bucket antigo exigirem |

As variáveis vazias em `.env` não são credenciais de produção. O app retorna indisponibilidade para arquivos se o bucket não estiver configurado. Nenhum bucket externo foi provisionado por esta alteração. Reutilizar o Dockerfile de produção, que inclui qpdf, executar migrations no deploy e configurar o comando de limpeza como cron. Fora do Docker é preciso instalar qpdf, coreutils/util-linux (timeout/prlimit) e PHP 8.4 com as extensões do projeto.

No mobile, instalar com `npx expo install expo-document-picker expo-file-system expo-sharing --npm`, atualizar o lockfile pelo npm e gerar development build quando necessário. A declaração das dependências foi adicionada, mas a instalação nesta sessão encontrou DNS indisponível; seleção/compartilhamento nativos não puderam ser exercitados em aparelho.

Checks de backend: migrations no banco de testes, PHPUnit, PHPStan, CS Fixer, exportação OpenAPI e teste de contrato, `git diff --check`. O bootstrap de PHPUnit recria somente o banco de **testes** usando migrations. Em desenvolvimento, não apontar o banco de testes para produção.

Checks mobile: Jest, `tsc --noEmit`, `expo lint`, `expo install --check`, `expo-doctor`, configuração/export conforme disponibilidade, `git diff --check`. Testes de módulos nativos usam doubles explícitos; não substituem o teste em aparelho com as dependências instaladas.

### Limitações de validação desta sessão

- PHP disponível: 8.1.2. O projeto e PHPUnit exigem PHP 8.4; migrations, PHPUnit, PHPStan, CS Fixer e exportação/snapshot OpenAPI não puderam executar. O Docker também recusou acesso ao socket. A especificação versionada anterior não foi apresentada como regenerada.
- Os arquivos PHP foram analisados sintaticamente com o parser instalado (`nikic/php-parser`, versão de sintaxe mais recente), mas isso **não equivale** a executar Symfony, Doctrine, PHPUnit ou PHPStan.
- npm/Packagist não resolveram DNS. Novos módulos nativos não foram instalados; o lockfile completo e o build/export precisam ser validados após restaurar a instalação. Não foi removida a verificação de versões nem simulada instalação.
- Não houve teste de integração contra um bucket Railway real, credenciais de produção, deploy ou teste em aparelho. Esses pontos permanecem necessários antes de considerar a V1 validada de ponta a ponta.

## Evolução

A separação arquivo/documento/distribuição/post permite outros formatos e leitura interna no futuro. Nesta V1 só existe PDF: sem EPUB, Office, links-documento, OCR, IA, anotações, editor, versões, subcoleções, tags livres, marketplace ou DRM. Um leitor interno, antivírus, retenção final e outros formatos exigirão decisões e validações próprias.

Referências oficiais consultadas: [Expo 58 DocumentPicker](https://docs.expo.dev/versions/v58.0.0/sdk/document-picker/), [FileSystem](https://docs.expo.dev/versions/v58.0.0/sdk/filesystem-legacy/), [Sharing](https://docs.expo.dev/versions/v58.0.0/sdk/sharing/), [Railway Storage Buckets](https://docs.railway.com/storage-buckets).
