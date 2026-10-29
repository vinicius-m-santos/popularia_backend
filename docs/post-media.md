# Mídia de publicações

Imagens e vídeos de publicações usam o mesmo bucket privado da Biblioteca, sob o prefixo `post-media/`, sem integrar a mídia à Biblioteca de PDFs.

- imagem: JPEG, PNG ou WebP estático, até 10 MiB e 40 megapixels;
- vídeo: MP4 ou MOV, H.264/HEVC e áudio opcional AAC/MP3, até 30 segundos e 100 MiB;
- o backend usa `finfo`, decodificação de imagem e `ffprobe` como autoridade;
- `clientId` torna o upload idempotente; `POST /posts` apenas anexa o `mediaId` pronto;
- cada mídia pode pertencer a no máximo uma publicação;
- os bytes ficam privados e o feed recebe uma URL assinada de 5 minutos, somente após a autorização do post;
- storage keys e URLs assinadas nunca são persistidas no cliente ou expostas como permissão permanente.

Execute `php bin/console app:post-media:purge` a cada hora. O comando remove, em lotes de 100, uploads falhos ou marcados para limpeza e uploads prontos que continuam sem publicação depois de 24 horas.

O contêiner inclui `ffprobe` via pacote `ffmpeg`. Caddy mantém JSON em 1 MB, PDF em 27 MB e reserva 106 MB apenas para `POST /api/v1/post-media`. PHP aceita o envelope de 102 MiB; os validadores específicos preservam os limites menores por formato.
