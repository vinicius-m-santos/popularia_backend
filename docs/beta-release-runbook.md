# Identidade, builds e operação do beta

Auditoria em 27/10/2026. Não mudar IDs já vinculados apenas por estética.

| Identidade | Estado confirmado |
|---|---|
| Nome | Popularia; build staging exibe Popularia Beta |
| iOS bundle / Android package | `com.popularia.mobile`, preservado |
| Expo slug / scheme | `popularia_mobile` / `populariamobile`, preservados |
| EAS | `@vinima/popularia_mobile`, project `6e964283-951f-4a16-bf99-5a4d5236a338`; conta autenticada; histórico consultado sem builds |
| Railway | projeto popularia `6624ce0b-7afa-46f7-9175-3c0a16cc121b`; backend `cd37a6e2-8cb7-48b1-b259-a805fc73cd0c` |
| Production | `https://api.popularia.com.br/api/v1`; env `cb33d918-d8c9-4ab8-9bca-c65296e50956` |
| Staging | `https://api-stg.popularia.com.br/api/v1`; env `d629a12c-1a48-4fb3-be76-93f3fc742d3a` |
| Apple | Apple Developer aguarda efetivação; Team ID, key, signing, App ID/capability e app ASC não confirmados |
| Google Play | Adiado por decisão do responsável; conta e signing Play não auditados |

`DEFAULT_URI` remoto ainda usava hostname Railway nos dois ambientes na consulta inicial; ajustar ao custom domain antes de gerar links absolutos. Confirmação/reset atuais usam códigos digitados, não links, portanto não há link de reset para localhost no e-mail. Scheme existente continua compatível. Não foram criados universal links nem Android App Links: os links legais abrem HTTPS no navegador.

## OAuth

| Ambiente | Google Web client | Google iOS client |
|---|---|---|
| Staging | `1009324469949-t3kdg7iie98v9aalj9ln4bh9b71fjub7.apps.googleusercontent.com` | `1009324469949-g0jt9vfoalp1pl4q1c3ro2c020m4694o.apps.googleusercontent.com` |
| Production | `1009324469949-l9m7r4dcbni1596nercrpko8upqm7v4m.apps.googleusercontent.com` | `1009324469949-a7n9k5v7ddbnf6vgk57nejesv67ij0h3.apps.googleusercontent.com` |

`app.config.js` deriva `iosUrlScheme` do client iOS do profile; a configuração estática anterior usava sempre o client production inclusive em staging. Não muda os registros OAuth. Confirmar `GOOGLE_CLIENT_ID` do backend com o Web client do ambiente e os consent screen/test users no Google Cloud. Para Android, obter SHA-1 da chave EAS e, depois, da chave App Signing do Play e registrar clientes apropriados para o mesmo package. Não tratar upload key e app signing key como iguais.

Apple usa audience `com.popularia.mobile`, capability Sign in with Apple e entitlement gerado. Backend precisa de Team ID/Key ID/p8 para revogar autorização ao excluir. O botão Apple deve seguir apresentação exigida pela Apple; usar componente nativo e validar disponibilidade em iOS físico. Não prometer que provider já funciona ponta a ponta só por ter ID no eas.json.

## Perfis e versão

`version` segue `1.0.0` como versão de produto já existente. EAS usa `cli.appVersionSource=remote` e `autoIncrement=true` em builds distribuíveis para iOS buildNumber/Android versionCode. Na primeira build, confirmar/inicializar versão remota se necessário com `eas build:version:set`; nunca retroceder contador entre staging/store, que compartilham o mesmo app ID.

| Profile | Distribuição | Backend | Uso |
|---|---|---|---|
| development | developmentClient/internal | desenvolvimento explícito | Metro/dev; não é build de loja |
| staging | internal | staging | APK/instalação direta; iOS exige ad hoc e dispositivos |
| testflight-staging | store | staging | IPA para TestFlight; internal do TestFlight não significa distribution internal do EAS |
| production | store | production | Beta com backend production, e publicação posterior somente por decisão do responsável |

Profiles escolhem environments EAS development/preview/production. `EXPO_PUBLIC_APP_ENV`, API e Sentry são conferidos na avaliação da config para build distribuível; erro se API/environment divergir. Staging exibe nome diferente e não usa produção. Mesmo bundle/package significa que as builds substituem umas às outras; não há dois apps paralelos. SecureStore pode manter token do ambiente anterior: servidor de outro ambiente rejeita o token e usuário precisa entrar novamente. Não criar novo bundle agora sem revisar registros Apple/Google/EAS.

Comandos, a partir do mobile:

```bash
npm run typecheck
npm run lint
npm test -- --runInBand
npx expo-doctor
npx eas-cli@latest build --profile testflight-staging --platform ios
# Depois que a build estiver concluída e revisada:
npx eas-cli@latest submit --profile testflight-staging --platform ios
```

`submit` precisa do app App Store Connect correto (`ascAppId` quando conhecido), contratos e credenciais. Nenhum ID de ASC/Team fictício foi gravado. Não usar auto-submit antes das validações no aparelho. `eas.json` mantém submit vazio até confirmar esses valores; o CLI pode solicitar os dados. Variáveis EXPO_PUBLIC são públicas; `SENTRY_AUTH_TOKEN` e chaves Apple/backend nunca devem receber esse prefixo.

## Assets e permissões

Assets integrados em `assets/branding` já usam o símbolo de pessoas/raios, sem antigo P. Ícone iOS 1024×1024 RGB opaco; foreground Android e splash 1024×1024 RGBA; favicon 256×256 RGB. Foreground ocupa bounding box (230,265)-(781,775), dentro da área central de segurança. Fundo creme #F4ECDD; estilo light já definido, sem variantes dark inventadas. O kit irmão mais antigo tem moldura e escala diferentes; preservados os assets integrados mais recentes, sem redesenhar/substituir a marca.

Prebuild isolado gerou Info.plist, entitlements e AndroidManifest para inspecionar, sem colocar diretórios nativos no repo. ATS: NSAllowsArbitraryLoads=false. Release Android não tem usesCleartextTraffic=true; permissões de arquivos legadas e de câmera/microfone/GPS/contatos/galeria/ads/push foram bloqueadas explicitamente. Internet é necessária para API/storage; conferir manifest mesclado do artefato final, pois o prebuild não substitui o merge Gradle de dependências. Permissões debug de Metro não são prova de permissão release.

SecureStore adicionava NSFaceIDUsageDescription por padrão: plugin agora usa faceIDPermission=false; app não usa requireAuthentication/biometria. Seleção pontual de PDF pelo document picker não exige acesso amplo à galeria; downloads/sharing usam cache do app. Não há pedidos de GPS, ATT, câmera, microfone, contatos ou notificações no primeiro launch. Push é futuro. `expo-apple-authentication` foi alinhado ao SDK 58 após a auditoria detectar versão 8 incompatível.

## Conta e dados de review

1. Reservar endereço real controlado pelo responsável e criar conta normal no ambiente da build pelo cadastro do app; confirmar e-mail pelo código. Não usar administrador, bypass ou seed dev em produção.
2. Aceitar documentos/18+ e completar perfil neutro de teste. Criar uma Comuna pública “Review Popularia” pelo fluxo normal e um post explicativo sem dados de pessoas reais.
3. Com segunda conta normal controlada, entrar nessa Comuna, comentar, escrever/responder na Praça e disponibilizar um PDF de autoria/licença apropriada. Opcionalmente uma Comuna privada com ambas as contas para testar restrição, sem convidar testers reais para ela.
4. Conferir login e acesso: perfil, feed, Comuna, Praça, Biblioteca, denúncia/bloqueio, Conta/Legal. Fornecer credenciais apenas no campo privado App Review Information/App access do Console.
5. Para testar exclusão, fornecer segunda conta descartável que não seja owner ativo. A conta principal de review não deve ser apagada pelos testes internos antes do review. Se reviewer a apagar, recriar como conta nova e atualizar credenciais privadas.
6. Reset pelo fluxo normal de código por e-mail controlado; encerra sessões existentes. Não colocar senha em docs, Git, logs ou descrição pública. Registrar ambiente e responsável no cofre operacional.

## Metadados mínimos prontos

Nome: Popularia. Categoria candidata Apple: Social Networking; Google: Social (confirmar no Console). Descrição beta: “Comunidades, conversas em Praças e compartilhamento de PDFs na Biblioteca. Beta para maiores de 18 anos.”

What to Test: “Teste cadastro/e-mail e login Google/Apple, Comunas públicas/privadas, posts e comentários, Praça com respostas/reações, upload/leitura de PDF, links legais e suporte. Em Conta, teste logout e exclusão com a conta descartável. Informe versão, aparelho e passos pelo feedback TestFlight.”

Feedback email e suporte: vinicius.m.dos.s@gmail.com. Controller/review contact name: Vinicius Matheus dos Santos. Telefone/endereço, quando exigidos no Console: **PENDENTE — fornecer privadamente**, não inventar.

URLs finais previstas na infraestrutura real: `https://api.popularia.com.br/privacidade`, `/termos`, `/regras`, `/suporte`, `/excluir-conta`. São rotas do próprio backend, sem depender do domínio raiz ainda sem site. Só cadastrá-las nas lojas depois de deploy, HTTP 200 e teste anônimo externo. Em staging, as mesmas rotas usam `https://api-stg.popularia.com.br`.

Review notes: “Beta 18+. Login obrigatório para interações e conteúdo com controle de acesso. Conta normal de review e conta descartável fornecidas no campo privado; sem permissões administrativas. Documentos em Conta/Sobre e antes do aceite. Exclusão em Conta/Excluir conta exige confirmação e reautenticação; Comunas ativas próprias podem ser transferidas ou arquivadas. Conteúdo de Comuna privada só é visível a participantes. Denúncias são atendidas pela equipe.” Ajustar notas à build realmente enviada.

## Moderação operacional do beta

`php bin/console app:reports:review` lista até 100 denúncias pendentes, com tipo/ID/motivo. `app:reports:review UUID removed "motivo"` remove post/comment ou suspende perfil (revoga sessões); dismissed encerra sem remoção. Ação auditada. Não exibir terminal contendo dados de denúncias em stream público. Praça/Biblioteca seguem endpoints e ferramentas existentes; não foi criado painel novo.

Definir quem verifica a fila e o e-mail todos os dias e cobre urgências; não prometer 24h de resposta se não houver operação para isso. Reports não são apenas locais no mobile real. Bloqueio usa servidor; desbloqueio fica em Conta. Filtro de repostagem não detecta todo abuso novo, variações ou conteúdo dentro de um PDF; manualmente moderar e ajustar durante o beta. Para denúncias de exploração infantil, restringir acesso, preservar somente o exigido legalmente e seguir procedimento de comunicação competente confirmado pelo responsável.

## Transformações do proxy

Na validação HTTPS do staging, o Cloudflare ofuscava e-mails e injetava script incompatível com o CSP sem JavaScript. As páginas legais agora enviam Cache-Control com `no-transform` e comentários `email_off`, sem mudar configurações globais do domínio. Revalidar contato em texto e mailto após deploy. Referência: [Cloudflare Email Address Obfuscation](https://developers.cloudflare.com/waf/tools/scrape-shield/email-address-obfuscation/).

## Auditoria de dependências npm

A instalação compatível do pacote Apple foi concluída; `expo-doctor` passou 20/20. A auditoria npm consultada reporta quatro entradas high na cadeia de ferramentas: expo/@expo/cli via zod e node-forge (GHSA-mrhx-c7xc-m3w5 e GHSA-86w9-cpqp-85rv). Não foi aplicado `npm audit fix --force`: a sugestão recebida faria downgrade de Expo 58 para 54.0.38. Revisar patches compatíveis do tooling antes da expansão externa. Isso não é prova de vulnerabilidade executável no bundle mobile nem de coleta por SDK; a exposição final precisa ser avaliada separadamente.
