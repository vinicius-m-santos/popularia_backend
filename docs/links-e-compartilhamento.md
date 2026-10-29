# Links e compartilhamento — 29/10/2026

Implementação no backend e em `../popularia_mobile`. Resultados dos checks em
[links-validacao.md](links-validacao.md). Nenhum deploy ou alteração remota foi feito.

## Arquitetura encontrada e decisões

| Item auditado | Estado encontrado / decisão |
| --- | --- |
| Mobile | Expo SDK 58, React Native 0.88, Expo Router; rotas em `src/app` |
| Rotas de detalhe | `/post/[id]`, `/pessoa/[id]`, `/comuna/[id]` nas pilhas Início, Explorar, Avisos e Perfil; `/biblioteca/documento/[id]` fora das abas |
| Notificações | `features/notifications/navigation.ts` resolvia post, perfil, comuna, Praça e pedidos de entrada; `PushRuntime` aguardava sessão, onboarding, termos e mensagem de boas-vindas |
| Web | Symfony já servia páginas legais em Twig. `popularia-site/index.html` é uma landing estática, sem consulta de recursos ou SSR; não foi criada outra aplicação web |
| Domínios | Os hosts reais documentados são `api.popularia.com.br` e `api-stg.popularia.com.br`. Não foi presumido um novo host de compartilhamento no domínio raiz |
| Publicações | UUID público persistente; autorização central em `ContentPolicy` |
| Perfis | UUID persistente, handle mutável; `UserRepository::findByHandleOrId` e API já aceitam UUID |
| Comunas | Slug único, de até 40 caracteres, imutável segundo a entidade `Comuna`; no mobile, o `id` de navegação corresponde ao slug |
| Documentos | UUID; `LibraryAccess::canRead` recusa visitantes anônimos, inclusive quando `isPublic=true`; download separado e autorizado |
| Share anterior | Próprio perfil tinha botão vazio. O menu “Copiar link” usava `popularia.app/pessoa/{handle}` até em posts e comentários. Não havia share externo de comuna/documento; `expo-sharing` já servia a abertura de PDFs autorizados |
| Expo/EAS | Scheme `populariamobile`; bundle e package `com.popularia.mobile`; projeto EAS `6e964283-951f-4a16-bf99-5a4d5236a338`, todos preservados |
| Associações | Não havia associated domains, intent filters de HTTPS, AASA ou assetlinks versionados |
| SEO/OG | Landing com metadata estática, sem imagem OG definida; não havia metadata por recurso. Agora os previews saem completos no HTML inicial do Symfony |

O backend reúne banco, políticas de acesso e renderização de previews em uma requisição.
Os novos caminhos convivem com `/api/v1` e páginas legais. Não há migration nem alteração
nos contratos JSON existentes.

## URLs canônicas e ambientes

| Recurso | Caminho | Justificativa |
| --- | --- | --- |
| Publicação | `/p/{uuid}` | Mesmo ID já usado pela discussão; não muda com o texto |
| Perfil | `/u/{uuid}` | Continua funcionando quando o handle muda; não cria um novo identificador |
| Comuna | `/c/{slug}` | Slug existente já é único, curto e imutável |
| Documento | `/d/{uuid}` | Referência à tela, nunca ao objeto do storage |

Produção: `https://api.popularia.com.br` + caminho.
Staging: `https://api-stg.popularia.com.br` + caminho.
Desenvolvimento: origem local acessível ao aparelho, como `http://192.168.0.10:8080`.

- Backend: `PUBLIC_LINK_ORIGIN`, centralizado em `App\Sharing\PublicLinks`. O Host da
  requisição não participa da canonical URL. Credenciais, query e caminhos na origem são
  recusados; em `APP_ENV=prod`, HTTP/localhost também são recusados.
- Mobile: `EXPO_PUBLIC_LINK_ORIGIN` em `features/links/destinations.ts`. Os perfis EAS
  staging/production já definem a origem. Em desenvolvimento, a ausência da variável usa
  o host de `EXPO_PUBLIC_API_URL`, removendo `/api/v1`; sem API, usa localhost.
- `app.config.js` valida builds distribuíveis contra o host da API do ambiente. Uma build
  staging não aceita URL de produção no parser, nem associa o host de produção.
- HTTP só é aceito pelo parser no desenvolvimento. Share distribuído usa HTTPS.
- Nunca são anexados tokens, dados de sessão ou query de autenticação. Alguns parâmetros
  de redes sociais (`utm_*` conhecidos, `fbclid`, `gclid`, `igshid`, `s`) podem entrar, mas
  são descartados antes de resolver rota/gerar um novo share. Queries não suportadas são
  recusadas, inclusive `token` e `access_token`.

Não alterar apenas uma das origens quando migrar de domínio: backend, EAS, associação e
build devem apontar para o mesmo host. Links já distribuídos precisam continuar servidos
no host anterior; a V1 mantém os hosts existentes.

## Resolver, share e navegação

`features/links/destinations.ts` é a fonte mobile de destinos: `post`, `profile`, `comuna`,
`document`, `square`, `join_requests`. Ela contém `destinationHref`, `canonicalUrl` e
`parsePopulariaUrl`. Apenas os primeiros quatro têm URL pública.

`notifications/navigation.ts` tornou-se um adaptador pequeno: usa o mesmo resolver,
preservando a pilha `/avisos` para o comportamento anterior de notificações. Praça e gestão
continuam destinos internos, com parâmetros anteriores. Não ganharam URL pública.

`share.ts` usa `React Native Share` sem dependência nova. Android recebe contexto curto +
URL no texto. iOS recebe contexto e campo `url`, sem duplicar o endereço no texto.
Erros de compartilhamento têm feedback, e dismiss nativo não é tratado como falha.

| UI | Ação |
| --- | --- |
| Post, no feed e discussão | Menu existente: Compartilhar publicação e Copiar link apontam para o post |
| Próprio perfil | Botão já existente passa a compartilhar o UUID da conta |
| Outro perfil | Menu existente: Compartilhar perfil e Copiar link |
| Comuna | Menu existente: Compartilhar comuna |
| Documento disponível | Menu existente: Compartilhar link; compartilha só referência, sem baixar/enviar PDF |
| Comentário | Retirado “Copiar link”, que antes apontava incorretamente para o autor; âncoras de comentário não fazem parte da V1 |

O `viewer.canShare` de documentos controla distribuição interna para comunas, não o direito
de conceder acesso por URL. Um leitor pode compartilhar a referência disponível; o destinatário
continua sujeito à autorização do backend. UUIDs locais de demonstração são recusados na geração de links públicos. Menus/botões mostram feedback quando o conteúdo não tem ID compartilhável.

### Aberto, background e cold start

```text
URL HTTPS / scheme suportado → +native-intent → intenção pendente → /abrir-link
                                                                    ↓
                            restauração → login → termos → onboarding → boas-vindas
                                                                    ↓
Push → revalidar aviso no backend → mesma intenção pendente → destinationHref → Router
```

- Expo Router entrega tanto URL inicial quanto URL com app aberto/background a
  `+native-intent.tsx`. O código usa esse mecanismo, sem um segundo listener de Linking.
- O destino fica em memória em `features/links/incoming.ts` antes de renderizar os fluxos
  de conta. Uma única posição atende links e resultados de push; a última intenção prevalece.
- O runtime existente, ainda chamado `PushRuntime`, é o único consumidor de navegação
  externa. Reutiliza os gates anteriores. A ponte é acessível antes do login; encaminha
  para os fluxos existentes sem perder o destino guardado.
- No app já autenticado, o runtime espera o Router confirmar `/abrir-link`; isso evita que
  o rewrite nativo sobrescreva uma navegação executada cedo demais. Após os gates, substitui
  a ponte pela tela final, sem deixar um loader na pilha de voltar.
- Push continua sendo guardado pelo sistema operacional antes de a conta estar pronta.
  Só o ID do aviso e o destinatário são confiados ao parser de payload; o destino vem da
  leitura autenticada do aviso. Leitura que termina após logout não navega; intenções push
  na posição pendente também conferem o ID da conta antes de abrir.
- A chegada de um link invalida o resultado de uma leitura antiga de push, inclusive o seu
  feedback de erro. A versão da intenção também ordena dois toques em push.
- URL externa inválida vai para `link-indisponivel`, com feedback e ação de voltar. Rotas
  internas e callbacks de OAuth continuam passando pelo Router.
- As telas reais fazem nova consulta/autorização; uma URL não cria acesso. Documento sem
  acesso oferece o estado “Acesso restrito”; publicação removida mostra o estado existente
  de indisponibilidade. Bloqueios, moderação e visibilidade continuam decididos pelo servidor.

A intenção é mantida durante a sessão do processo. Se o sistema encerrar o app durante
login/onboarding e não reenviar a URL no próximo lançamento, é preciso tocar no link outra
vez; não há persistência de destinos privados em disco nem deferred deep linking após instalação.

## Páginas web, Open Graph e privacidade

`GET|HEAD /p/{id}`, `/u/{id}`, `/c/{slug}`, `/d/{id}` renderizam
`templates/sharing/preview.html.twig`, com identidade Popularia, tipo, título/descrição,
conteúdo público limitado e botão para o scheme existente. Não dependem de JavaScript.

| Tipo | HTML/preview |
| --- | --- |
| Publicação pública e publicada | Título com autor, trecho sanitizado de até 180 caracteres, até 2.000 caracteres de texto no corpo; `og:type=article` |
| Perfil público ativo | Nome, handle e bio curta; `og:type=profile` |
| Comuna pública | Nome e descrição curta; não consulta/exibe posts internos; `og:type=website` |
| Documento | Preview genérico, inclusive para IDs inexistentes válidos, sem confirmar existência; o modelo atual exige conta para ler documentos |
| Perfil/comuna privada, post removido/moderado/inexistente, conta inativa | HTTP 404 com página/OG genéricos; nenhum título, bio ou trecho protegido |

Toda página tem canonical, `og:title/description/image/url/type`, site/locale e Twitter
card/title/description/image. A imagem padrão é uma cópia do símbolo existente do site em
`public/sharing/popularia.png`; card quadrado `summary`, sem redesign ou gerador dinâmico.
Não se usam avatares, capas, URLs temporárias de mídia ou storage em OG nesta V1.

`ContentPolicy::canViewPost(..., null)` define o teto anônimo de publicação. Quando há um
usuário identificado na requisição, sua autorização/bloqueio também é conferida. Mesmo um
membro logado não torna conteúdo privado visível em OG. Perfis verificam status, privacidade
e bloqueios do visitante identificado. Visitante anônimo não possui identidade à qual aplicar
um bloqueio entre duas contas; conteúdo deliberadamente público continua público.

`LibraryAccess::canRead(..., null)` hoje retorna `false`. A feature preserva essa decisão,
mesmo em PDFs com `isPublic=true`: não publica título/autor/descrição/coleção nem cria download
anônimo. Para previews ricos de PDF seria necessário definir expressamente uma política de
leitura anônima da biblioteca em outra alteração. O share atual aponta para a página/tela;
abrir o PDF continua passando pelo endpoint autorizado.

Texto é reduzido a texto simples, normalizado e escapado pelo Twig. Identificadores são
validados antes de consultar UUIDs. A origem não usa Host, query ou token da requisição;
o botão tem scheme/rota fixos e nunca executa redirect fornecido pelo usuário.

### Cache e crawlers

- HTML: `Cache-Control: no-store, private`, `X-Robots-Tag: noindex, noarchive`,
  `Referrer-Policy: no-referrer`, `nosniff` e CSP sem scripts, frames ou forms.
- Sem cache de entidades ou previews próprio, Redis ou lista pesada de posts. Leitura por
  identificador/slug e verificações de política existentes; documento genérico nem consulta DB.
- Remoção/privatização passa a valer na próxima requisição. O cache que WhatsApp/Telegram/
  Discord/Meta/X mantiverem fora da aplicação não pode ser apagado por esses headers.
- `noindex` não impede fetch de metadata. Não adicionar bloqueio de crawler ou desafio de
  WAF nesses caminhos, nas imagens e nas associações. As APIs privadas continuam protegidas.
- Não ativar cache “Everything” no proxy para HTML de recursos. Se houver cache externo
  anterior, removê-lo/purgá-lo na implantação.

## Universal Links e App Links

### Código entregue

- `app.config.js`: iOS `associatedDomains=[applinks:<host do ambiente>]`; Android `VIEW`,
  `autoVerify=true`, categorias `BROWSABLE/DEFAULT`, HTTPS e prefixos `/p/`, `/u/`, `/c/`, `/d/`.
  Preserva outras associações/filtros existentes, scheme, OAuth e identidade EAS.
- `GET|HEAD /.well-known/apple-app-site-association`: JSON sem redirect, app ID formado
  por prefixo Apple configurado + `com.popularia.mobile`, componentes limitados aos quatro
  caminhos, sem wildcard no domínio inteiro.
- `GET|HEAD /.well-known/assetlinks.json`: JSON sem redirect, package `com.popularia.mobile`,
  relação `delegate_permission/common.handle_all_urls`, fingerprints configurados.
- MIME `application/json`, `nosniff`, cache público curto de 300 segundos nas associações.
- Variáveis vazias/inválidas não autorizam assinatura alguma: AASA tem `details=[]` e
  assetlinks tem `[]`. HTTP 200 com listas vazias **não** significa associação pronta.

### Configuração externa restante

| Onde | Variável/ação | Valor e como obter | Como validar |
| --- | --- | --- | --- |
| Backend produção | `PUBLIC_LINK_ORIGIN` | `https://api.popularia.com.br` | Canonical/OG retornam esse host |
| Backend staging | `PUBLIC_LINK_ORIGIN` | `https://api-stg.popularia.com.br` | Links e OG não apontam para produção |
| Cada backend | `LINKS_APPLE_TEAM_ID` | Identificador Apple de 10 caracteres; Apple Developer → Membership → Team ID. Conferir prefixo de `application-identifier` da build assinada, sobretudo em contas legadas onde App ID Prefix pode diferir do Team ID; o valor deve produzir exatamente o app ID assinado | AASA contém `<prefixo>.com.popularia.mobile` |
| Cada backend Android | `LINKS_ANDROID_SHA256` | Um ou mais fingerprints SHA-256 separados por vírgula, formato `AA:BB:...` (32 octetos). Play Console → Integridade do app → **certificado de assinatura do app**, para builds Play. Para APK de teste/EAS, obter certificado do keystore correspondente | assetlinks contém cada certificado efetivamente instalado |
| EAS/Apple | Capability Associated Domains e provisioning atualizado | Gerar build com configuração deste commit; o EAS prepara entitlement, mas é necessário usar a conta Apple/provisioning correto | Entitlement assinado e associação no iPhone |
| EAS Android | Keystore real da build de teste | No diretório mobile: `npx eas-cli@latest credentials --platform android`; inspecionar/baixar o keystore e usar `keytool -list -v -keystore /caminho/keystore.jks -alias ALIAS`. Não confundir certificado de upload com assinatura Play | Comparar SHA-256 instalado com JSON |
| Domínio/proxy | Deploy backend nos hosts existentes, HTTPS válido e sem challenge/redirect nas associações | DNS/TLS/proxy reais; não é preciso criar novo domínio para esta V1 | `curl` externo, sem login, recebe JSON e HTML corretos |
| Mobile | Nova build instalada | `npx eas-cli@latest build --profile staging --platform all` ou perfil production quando for publicar | Universal/App Links exigem binário novo; OTA/export não muda entitlement/manifest |

Staging e produção preservam o mesmo package/bundle: instalar staging substitui o app
existente, como já ocorria. Cada build só associa o host do seu ambiente. Se for necessária
instalação simultânea, isso exige outro identificador/projeto e está fora desta task.

Não foram inventados IDs Apple, fingerprints, URLs de lojas ainda não documentadas ou
novos domínios. O botão web permite abrir o app existente; instalação e novo toque no link
continuam necessários quando ele não está instalado. Não houve build EAS assinada nem deploy.

## Roteiro exato de validação externa

Use recursos reais de staging: um post público e um removido, perfil público e privado,
comuna pública e privada, documento disponível e outro sem acesso para a conta de teste.
Substitua os valores de exemplo abaixo por IDs/slug existentes; não use tokens em URLs.

### HTTP e previews antes de instalar

```bash
LINK_ORIGIN=https://api-stg.popularia.com.br
curl -sS -D - "$LINK_ORIGIN/.well-known/apple-app-site-association"
curl -sS -D - "$LINK_ORIGIN/.well-known/assetlinks.json"
curl -sS -I "$LINK_ORIGIN/sharing/popularia.png"
curl -sS -D - "$LINK_ORIGIN/p/UUID_REAL"
curl -sS -A 'facebookexternalhit/1.1' "$LINK_ORIGIN/p/UUID_REAL"
curl -sS -A 'Twitterbot/1.0' "$LINK_ORIGIN/u/UUID_REAL"
curl -sS -A 'TelegramBot' "$LINK_ORIGIN/c/SLUG_REAL"
```

1. Associações: HTTP 200 direto, JSON, sem `Location`, sem login/WAF; conferir app ID e
   certificados reais, não apenas o status. Imagem: HTTP 200 e `image/png`.
2. HTML: canonical/OG absolutos no host de staging, dados públicos no source sem executar JS.
3. Privados/removidos: página genérica, sem texto/título restrito. Documento sempre genérico.
4. Abrir em browser sem app, em desktop e viewport estreita: página legível e botão de abrir.
5. Conferir que produção gera o mesmo formato com origem de produção, sem misturar bancos.

### iPhone

1. Publicar AASA com prefixo correto antes de instalar a nova build. Em Apple Developer,
   conferir que o App ID `com.popularia.mobile` suporta Associated Domains.
2. Instalar build staging assinada em iPhone de teste. A instalação/atualização faz o iOS
   consultar a associação; alterações de AASA podem sofrer cache do serviço Apple.
3. Enviar URL HTTPS de post em Mensagens/Notas e tocar: deve abrir a discussão. Repetir
   perfil, comuna e documento. Digitar a URL na barra do Safari ou tocar link do mesmo
   domínio no Safari pode manter navegação web; testar também a partir de Mensagens.
4. Repetir com app aberto, em background e encerrado. Encerrar sem revogar credenciais,
   tocar e confirmar que a restauração termina antes da tela de conteúdo.
5. Sair da conta, tocar um link, concluir login/termos/onboarding/mensagem pendentes: deve
   abrir o destino original uma vez. Repetir com conta sem acesso e conteúdo removido.
6. Teste auxiliar em simulador com build instalada:

```bash
xcrun simctl openurl booted 'https://api-stg.popularia.com.br/p/UUID_REAL'
xcrun simctl openurl booted 'populariamobile://c/SLUG_REAL'
```

O segundo comando confere scheme/parser, não valida Universal Links sozinho. Se o iOS
lembrar preferência por abrir no Safari, usar a opção de abrir no app ao pressionar o link
ou reinstalar a build de teste após verificar AASA/entitlements.

### Android

1. Publicar assetlinks com o SHA-256 da build realmente instalada. Instalar a nova build.
2. Conectar aparelho com ADB e executar:

```bash
adb shell pm verify-app-links --re-verify com.popularia.mobile
adb shell pm get-app-links com.popularia.mobile
adb shell am start -W -a android.intent.action.VIEW -c android.intent.category.BROWSABLE -d 'https://api-stg.popularia.com.br/p/UUID_REAL'
adb shell am force-stop com.popularia.mobile
adb shell am start -W -a android.intent.action.VIEW -c android.intent.category.BROWSABLE -d 'https://api-stg.popularia.com.br/d/UUID_REAL'
```

3. A verificação é assíncrona; repetir `get-app-links` após sua conclusão. Host deve aparecer
   como `verified`. Não forçar package no comando de abertura, pois isso mascararia falha
   de associação. Confirmar também “Abrir links compatíveis” nas configurações do aparelho.
4. Repetir as quatro classes de recurso com app aberto/background/encerrado, sessão
   restaurada, login pendente, privado/removido e usuário bloqueado, como no iPhone.
5. Repetir com build instalada pela Play se ela usar certificado diferente do APK EAS.

### Redes sociais e regressão de push

1. Usar as ações nativas de compartilhar post/perfil/comuna/documento. Confirmar contexto
   curto e URL HTTPS, nunca ID isolado ou URL assinada de PDF.
2. WhatsApp e Telegram: enviar para conversa de teste, aguardar preview e tocar. Discord:
   conferir embed. X/Meta: conferir title/description/card quando o serviço permitir.
3. Repetir link público sem app instalado, confirmando fallback web. Não presumir que
   todo browser interno de rede social respeitará abertura automática: ele pode exigir
   “Abrir no navegador”/“Abrir no app”, conforme a plataforma e preferência do usuário.
4. Privados e removidos não devem gerar preview rico. Alterar público para privado e
   buscar novamente com URL em um browser novo; caches de terceiros podem conservar
   previews antigos e precisam das ferramentas de reconsulta do respectivo serviço.
5. Tocar push válido, inválido, de conta anterior e de recurso revogado. Repetir cold start.
   Enquanto a leitura do aviso estiver pendente, abrir outro link: somente o link recente
   deve ser apresentado, sem uma segunda navegação tardia do push.

## Arquivos e limites da V1

Backend: `.env`, `src/Sharing/{PublicLinks,PreviewController,AssociationController}.php`,
`templates/sharing/preview.html.twig`, `public/sharing/popularia.png`,
`tests/Api/SharingTest.php`, `docs/links-e-compartilhamento.md` e `docs/links-validacao.md`.

Mobile: `app.config.js`, `eas.json`, `src/app/{_layout,+native-intent,abrir-link,link-indisponivel}.tsx`,
`src/features/links/{destinations,incoming,share}.ts`, `notifications/{navigation,push-runtime}`,
menus de `moderation/actions-sheet`, `profiles/profile-screen`, `comunas/components/comuna-hero`,
`library/document-screen` e respectivos testes. Os READMEs apontam para este relatório.

Sem novos pacotes, migration, outra aplicação web, encurtador, analytics, gerador OG,
refactor de autenticação/Router, DMs, Status ou followers/following. Sem deep link diferido
após instalar o app. OG padrão é estático, conteúdo de post no fallback é limitado e PDFs
não têm preview rico anônimo segundo a autorização atual. Não há botões de loja com IDs
inventados. Validação assinada/física e comportamento de cada rede social ficam no roteiro
externo; export e testes locais não comprovam essas associações.

Referências consultadas: [Expo SDK 58](https://docs.expo.dev/versions/v58.0.0/),
[Native intent](https://docs.expo.dev/router/advanced/native-intent/),
[Universal Links](https://docs.expo.dev/linking/ios-universal-links/),
[App Links](https://docs.expo.dev/linking/android-app-links/),
[React Native Share](https://reactnative.dev/docs/share). A integração também foi conferida
contra `getLinkingConfig`/`subscribe` da versão de Expo Router instalada.
