# Hashtags e tópicos V1

## Regras

- O servidor extrai hashtags do corpo NFC depois de `TextNormalizer`. `#` precisa começar o texto ou vir após um caractere que não seja letra, marca Unicode, número, `_`, `#` ou `@`. `abc#tema`, `email#teste`, `###` e `#` isolado são texto comum. Fragmentos de URLs HTTP(S) e `www.` não geram tópico.
- O nome aceita letras, marcas, números e `_` Unicode, desde que contenha ao menos uma letra ou número. Cada ocorrência tem de 1 a 50 pontos de código; uma publicação pode ter até 10 ocorrências, inclusive repetidas. Excesso rejeita a publicação inteira com erro 400. O texto original é preservado.
- A identidade é Unicode NFD sem marcas de acento combinante, recomposta em NFC e convertida a minúsculas. Assim `#Política`, `#politica` e `#POLÍTICA` têm a chave `politica`. A primeira grafia válida persistida define `display_name` e nunca é substituída por usos posteriores. A URL usa a chave normalizada, percent encoded quando necessário.
- Duas primeiras ocorrências concorrentes usam `INSERT ... ON CONFLICT (normalized_name) DO NOTHING`; a constraint única arbitra a disputa. A associação tem chave primária `(post_id, hashtag_id)`. A criação do post e dos vínculos ocorre na mesma transação.

## Dados existentes e ciclo de vida

Publicações anteriores à implantação não recebem associação automaticamente. A V1 indexa publicações novas; não há backfill SQL com parser divergente. O corpo dos posts não pode ser editado atualmente. Delete pela autoria apaga os vínculos; hard delete usa cascade da chave estrangeira. Remoção pela moderação preserva vínculos e restauração os reexibe, sem recriar a hashtag.

## Leitura

`GET /api/v1/topics/{slug}` retorna a grafia de exibição somente quando há ao menos uma publicação visível à pessoa. `GET /api/v1/topics/{slug}/posts?cursor=&limit=` retorna `PostPage` cronológica, com cursor UUID v7. A consulta seleciona IDs visíveis antes do limite e depois hidrata posts em lote, para que opções de enquete não ocupem vagas da página. O mesmo predicado de `PostRepository::applyVisibility` cobre status publicado, autoria ativa, perfil privado e follow aprovado, bloqueio bilateral e comuna não suspensa. A consulta acrescenta participação para comunas privadas. Não há contagem total.

O renderer mobile usa uma única tokenização: URL primeiro, menção por offsets de pontos de código do backend segundo, hashtag no texto livre por último. Comentários usam o mesmo renderer com hashtags desativadas. O modo demonstração deriva os tópicos dos posts fixos. A criação real invalida somente as query keys dos tópicos presentes no post.

Busca e Explore continuam encontrando o texto da hashtag no corpo, sem categoria nova de resultado ou seção de tendência. O link público `/t/{slug}` usa o resolver existente, e o preview genérico só aparece quando existe post acessível anonimamente. O backend inclui `/t/*` no Apple App Site Association; o Android inclui `/t/` no intent filter. Não há variável externa nova. É necessário aplicar `Version20261109120000` em cada ambiente, publicar o backend para atualizar a associação Apple e distribuir um novo build Android para incluir o intent filter.
