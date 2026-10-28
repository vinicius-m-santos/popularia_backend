# Storage — Arquivos do Popularia

## Provider

**Cloudflare R2** (S3-compatible, SigV4).

Motivos: sem custo de egress, preço baixo por GB, API S3 totalmente compatível (incluindo `response-content-disposition` e `response-content-type` em URLs assinadas), integração simples com Railway via variáveis de ambiente.

## Arquitetura

```
PostgreSQL                    Cloudflare R2
─────────────────────         ─────────────────────────────
metadata do arquivo           bytes reais do PDF
storageKey                    objeto sob o prefixo library/
checksum (SHA-256)
byteSize, pageCount
mimeType, status
owner, permissions
lifecycle (soft delete)
```

O filesystem do container Railway é **efêmero**. Nenhum arquivo permanente depende dele.

## Abstração

```php
interface ObjectStorage {
    public function put(string $key, string $path, string $checksum): void;
    public function read(string $key): mixed;   // stream resource
    public function signedUrl(string $key, int $ttl): string;
    public function delete(string $key): void;
}
```

Implementações:
- `S3Storage` — produção/staging, SigV4 nativo sem SDK externo
- `LocalFilesystemStorage` — desenvolvimento local (`APP_ENV=dev`), arquivos privados em `var/library/` e downloads por URL HMAC temporária
- `MemoryStorage` — testes unitários/integração, estado estático

A aplicação nunca acopla regras de negócio ao SDK específico do provider. O filesystem local é somente uma conveniência de desenvolvimento; staging e produção continuam usando S3.

## Buckets e Isolamento

| Ambiente   | Bucket (nome sugerido) | Credencial  |
|------------|------------------------|-------------|
| staging    | `popularia-staging`    | separada    |
| production | `popularia-production` | separada    |

Nunca compartilhar bucket nem credenciais entre os dois ambientes.

Guarda contra misconfiguration: se `LIBRARY_S3_ENVIRONMENT=production` e `APP_ENV!=prod` (ou vice-versa), a aplicação recusa na primeira requisição de storage com erro claro nos logs.

## Upload

Fluxo: **Mobile → API → validação/autorização → Object Storage**

O backend valida o arquivo antes de enviar ao storage. Um documento só se torna utilizável após confirmação de que o objeto existe e passou por todas as validações.

### Idempotência
O campo `clientId` (gerado pelo mobile, UUID v7) garante que um retry com o mesmo arquivo não crie um segundo documento nem um segundo objeto no storage.

### Validação (PdfValidator)
- Arquivo presente e sem erro de upload
- Tamanho entre 1 byte e **25 MB**
- Extensão `.pdf`
- MIME `application/pdf` via libmagic
- Magic bytes `%PDF-x.x`
- Estrutura válida via `qpdf --check`
- Pelo menos 1 página via `qpdf --show-npages`

Não executa conteúdo do PDF. Não depende exclusivamente do `Content-Type` do cliente.

### Limites consistentes entre camadas

| Camada                | Limite  |
|-----------------------|---------|
| Caddy (request body)  | 27 MB   |
| PHP `upload_max_filesize` | 25 MB |
| PHP `post_max_size`   | 27 MB   |
| PdfValidator          | 25 MB   |

## Download e URLs Assinadas

Fluxo: **Mobile → API → autoriza → signed URL (TTL 300s) → Object Storage**

O backend é a única autoridade de autorização. A URL assinada só é gerada após `LibraryAccess::assertRead` passar com sucesso.

**TTL**: 300 segundos (5 minutos). Suficiente para iniciar o download. Curto o suficiente para tornar revogação prática.

**Headers presentes na URL assinada** (response overrides do R2/S3):
- `Content-Type: application/pdf`
- `Content-Disposition: attachment; filename="documento.pdf"`

**Headers presentes no redirect 302**:
- `Cache-Control: private, no-store, max-age=0`
- `Pragma: no-cache`

Não armazenar signed URL no banco. Não reutilizar signed URL como permissão permanente. O nome original do arquivo não é exposto na URL de download.

## Storage Key

Controlada pelo servidor, nunca pelo cliente:

```
library/<uuid-v7>.pdf
```

Exemplos futuros (não implementados ainda):
```
avatars/<uuid-v7>.jpg
stickers/<uuid-v7>.webp
square-media/<uuid-v7>.webp
```

O cliente nunca pode escolher nem adivinhar a key para sobrescrever outro objeto.

## Autorização e Revogação

O acesso é verificado no banco a cada pedido de URL assinada. Mudanças de permissão têm efeito imediato para **novas** requisições.

Limitação natural: uma URL já emitida pode permanecer válida até seu TTL expirar (≤ 5 min). Não há invalidação instantânea de URLs já entregues — isso é inerente ao modelo de signed URLs. O TTL curto torna esse risco pequeno.

Casos em que acesso cessa:
- Documento excluído (soft delete) ou removido por moderação
- Compartilhamento com Comuna removido
- Usuário sai ou é removido da Comuna
- Documento deixa de ser público
- Usuário bloqueado
- Documento marcado como moderado

## Posts, Shares e Saves

**Não criam cópia física.** São relações no banco.

- `Post.libraryDocumentId` → referência ao documento
- `LibraryShare` → compartilhamento com uma Comuna
- `LibrarySave` → bookmark do usuário

Nenhum desses cria um novo objeto no storage. O mesmo `storageKey` serve todos os contextos.

## Checksum e Integridade

SHA-256 do arquivo calculado em `PdfValidator.validate()` e armazenado em `LibraryFile.checksum`.

Usos:
- Detectar mudança de arquivo num retry do mesmo `clientId`
- Validar integridade na camada de storage (`MemoryStorage` verifica na escrita)
- Base para deduplicação futura (não implementada — não impede dois usuários de enviarem o mesmo documento)

O DB tem `CHECK (checksum ~ '^[a-f0-9]{64}$')`.

## Lifecycle do Documento

```
LibraryFile.status:
  pending → ready      (upload bem-sucedido e documento criado)
  pending → failed     (erro durante upload ou transação final)
  failed  → pending    (retry do mesmo clientId)
  pending/failed → purged  (app:library:purge após 24h sem documento final)

LibraryDocument.status:
  published → deleted  (dono excluiu via DELETE /library/documents/{id})
  published → removed  (moderação removeu)
```

Triggers no banco garantem imutabilidade dos campos físicos após `status=ready`.

## Arquivos Temporários no Backend

O PHP salva o upload em `/tmp` automaticamente (PHP tmpfile). O `PdfValidator` lê do path temporário do Symfony (`UploadedFile::getPathname()`). Após a requisição, o PHP remove o temporário automaticamente.

Não existem outros arquivos temporários criados pelo backend fora desse mecanismo.

## Upload Interrompido

Interrupções no upload ou na transação final:
1. `LibraryFile.status` fica em `pending` ou `failed`
2. Nenhum `LibraryDocument` é criado para esse arquivo
3. O objeto no storage fica órfão temporariamente
4. `app:library:purge` limpa após 24h

O documento interrompido **nunca aparece** na Biblioteca enquanto `status != ready`.

## Arquivos Órfãos e Garbage Collection

Comando: `php bin/console app:library:purge [--dry-run]`

Critérios de elegibilidade:
- `library_file.status IN ('pending', 'failed')`
- `library_file.created_at < NOW() - 24h`
- Não existe `library_document` referenciando o arquivo

Executa em batches de até 100 objetos por execução. Idempotente — tolera objeto já ausente no storage.

**Sempre executar `--dry-run` antes de ativar automação em produção.**

### Soft Delete e Retenção

Documentos soft-deleted (`status=deleted` ou `status=removed`) continuam com o objeto físico no storage. O acesso ao objeto é negado pelo backend (a lógica de autorização recusa para documentos não-publicados).

Atualização pré-beta (27/10/2026): documentos `deleted` ficam elegíveis ao purge após 24h. PDFs pessoais de contas excluídas entram em `purge_pending` imediatamente. O purge nunca apaga arquivo ainda referenciado por documento `published` ou `removed`. Conteúdo `removed` tem retenção para apuração ainda pendente de prazo; não prometer expiração automática. Ver [exclusão de conta](account-deletion.md).

## Agendamento do Purge

Usar cron do Railway ou Railway Job Service:

```
Comando: php /app/bin/console app:library:purge
Frequência: diária (ex.: 03:00 UTC)
Ambiente: staging E production (separados)
```

Configurar no dashboard Railway → Service → Jobs (ou via `railway.toml` quando suportado).

Evitar duas execuções concorrentes: o comando usa `FOR UPDATE SKIP LOCKED` para serializar sem bloquear.

## Variáveis de Ambiente

| Variável                 | Obrigatória em prod | Descrição                                      |
|--------------------------|---------------------|------------------------------------------------|
| `LIBRARY_S3_ENDPOINT`    | ✓                   | Ex.: `https://account.r2.cloudflarestorage.com` |
| `LIBRARY_S3_BUCKET`      | ✓                   | Nome do bucket (sem `s3://`)                   |
| `LIBRARY_S3_REGION`      | ✓                   | `auto` para R2                                 |
| `LIBRARY_S3_ACCESS_KEY`  | ✓                   | Access Key ID (não compartilhar com mobile)    |
| `LIBRARY_S3_SECRET_KEY`  | ✓                   | Secret Access Key (secret)                     |
| `LIBRARY_S3_PATH_STYLE`  | —                   | `0` para R2 (virtual-hosted); `1` para MinIO local |
| `LIBRARY_S3_ENVIRONMENT` | ✓                   | `staging` ou `production` (guarda contra swap acidental) |

Não colocar credenciais no Git, `.env` versionado, mobile ou documentação.

O mobile **nunca** recebe `LIBRARY_S3_ACCESS_KEY` nem `LIBRARY_S3_SECRET_KEY`. Signed URLs temporárias não são equivalentes a distribuir credenciais do provider.

## Railway — Configuração por Ambiente

### Staging
```
LIBRARY_S3_ENDPOINT=https://<account>.r2.cloudflarestorage.com
LIBRARY_S3_BUCKET=popularia-staging
LIBRARY_S3_REGION=auto
LIBRARY_S3_ACCESS_KEY=<staging-key>
LIBRARY_S3_SECRET_KEY=<staging-secret>
LIBRARY_S3_PATH_STYLE=0
LIBRARY_S3_ENVIRONMENT=staging
```

### Production
```
LIBRARY_S3_ENDPOINT=https://<account>.r2.cloudflarestorage.com
LIBRARY_S3_BUCKET=popularia-production
LIBRARY_S3_REGION=auto
LIBRARY_S3_ACCESS_KEY=<production-key>
LIBRARY_S3_SECRET_KEY=<production-secret>
LIBRARY_S3_PATH_STYLE=0
LIBRARY_S3_ENVIRONMENT=production
```

## CORS

Cloudflare R2 permite configurar CORS por bucket. Para o fluxo atual (mobile → API → signed URL → R2), o mobile precisa conseguir fazer GET para a URL assinada.

React Native nativo não tem restrições de CORS como browser, mas ao adicionar frontend web futuro será necessário configurar CORS no bucket. Configuração mínima recomendada quando necessário:

```json
[
  {
    "AllowedOrigins": ["https://app.popularia.com.br"],
    "AllowedMethods": ["GET"],
    "AllowedHeaders": ["*"],
    "MaxAgeSeconds": 300
  }
]
```

Não usar `*` em `AllowedOrigins` para buckets privados.

## Privacidade e Metadata

Não armazenar nos metadados do objeto S3/R2:
- Nome original do arquivo (fica somente no banco como `original_filename`)
- ID do usuário ou email
- Título do documento
- Nome da Comuna

O `storageKey` não é autorização — conhecer a key não permite acessar o objeto (bucket é privado).

## Cache

Documentos privados: `Cache-Control: private, no-store` no redirect 302 do backend.
Signed URLs: TTL curto (300s) — providers/CDNs não devem cachear respostas a URLs temporárias.
Não adicionar CDN customizada agora.

## Logs e Observabilidade

O backend loga via Monolog. Eventos a observar nos logs:
- `serviceUnavailable` do S3Storage: falha de conectividade ou credenciais
- Exceção no `storage->put` durante upload: arquivo em `failed`
- Exceção no `storage->delete` durante cleanup: registrado, não bloqueia

Para observar uso e custo: Cloudflare R2 dashboard → Usage → Storage/Requests por bucket.

Métricas relevantes:
- GB armazenados por bucket
- Número de objetos
- Requisições PUT/GET/DELETE

## Segurança

- Bucket **privado por padrão** — nenhum objeto tem URL pública permanente
- Credenciais com permissão mínima (somente operações no bucket correspondente)
- Backend é a única autoridade de acesso; object storage não conhece lógica de negócio
- `qpdf` valida estrutura do PDF antes de armazenar (sandbox com `prlimit`)
- Sem execução de conteúdo do PDF no servidor
- Sem antivírus/malware scanning (limitação documentada; manter isolamento seguro)

## Evolução Futura

A abstração `ObjectStorage` e o padrão de `storageKey` com prefixo permitem adicionar futuramente:

```
avatars/<uuid>.jpg
stickers/<uuid>.webp
square-media/<uuid>.webp
```

Sem reescrever domínio ou trocar de provider. Cada tipo de asset usará o mesmo `S3Storage` com keys em namespaces diferentes.

Não implementar nenhum desses agora.

## Ações Manuais Necessárias

### Cloudflare R2

1. Criar conta R2 (em Cloudflare, ativar R2 na conta)
2. Criar bucket **`popularia-staging`** (região: automática)
3. Criar bucket **`popularia-production`** (região: automática)
4. Criar API Token de staging com permissão somente em `popularia-staging`:
   - Object Read & Write
   - Copiar: Access Key ID e Secret Access Key
5. Criar API Token de production com permissão somente em `popularia-production`:
   - Object Read & Write
   - Copiar: Access Key ID e Secret Access Key
6. Anotar o endpoint: `https://<account-id>.r2.cloudflarestorage.com`

### Railway — Staging

7. No dashboard Railway → Environment **staging** → backend service → Variables, definir:
   ```
   LIBRARY_S3_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
   LIBRARY_S3_BUCKET=popularia-staging
   LIBRARY_S3_REGION=auto
   LIBRARY_S3_ACCESS_KEY=<staging-key>
   LIBRARY_S3_SECRET_KEY=<staging-secret>
   LIBRARY_S3_PATH_STYLE=0
   LIBRARY_S3_ENVIRONMENT=staging
   ```

### Railway — Production

8. No dashboard Railway → Environment **production** → backend service → Variables, definir:
   ```
   LIBRARY_S3_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
   LIBRARY_S3_BUCKET=popularia-production
   LIBRARY_S3_REGION=auto
   LIBRARY_S3_ACCESS_KEY=<production-key>
   LIBRARY_S3_SECRET_KEY=<production-secret>
   LIBRARY_S3_PATH_STYLE=0
   LIBRARY_S3_ENVIRONMENT=production
   ```

### Agendamento do Purge

9. No Railway → cada ambiente → adicionar cron/job:
   - Comando: `php /app/bin/console app:library:purge`
   - Frequência: `0 3 * * *` (03:00 UTC, diariamente)
   - Executar primeiro com `--dry-run` para validar antes de ativar

### Validação

10. Após configurar staging, fazer upload de um PDF pelo app e verificar:
    - Objeto criado no bucket `popularia-staging` (R2 dashboard)
    - Download retorna 302 e o redirect abre o PDF corretamente
    - Usuário sem acesso recebe 404 (não chega ao storage)
    - Após revogar acesso, nova tentativa de download recebe 404
