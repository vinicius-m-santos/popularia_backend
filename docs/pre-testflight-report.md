# Relatório pré-TestFlight — 27/10/2026

**Código preparado e backend validado no staging; o Popularia ainda não foi distribuído nem instalado via TestFlight.** Apple Developer aguarda efetivação. Google Play foi expressamente adiado pelo responsável. Mudanças estão nos repositórios backend e mobile, sem commit/push ou publicação de produção nesta tarefa. O staging foi implantado por upload do workspace; persistir/revisar o código no Git antes de um próximo autodeploy para não retornar à versão antiga de main.

## Implementado

- Fontes únicas de Política de Privacidade, Termos, Regras e Suporte em `docs/legal`; HTML público nas rotas `/privacidade`, `/termos`, `/regras`, `/suporte`, `/excluir-conta`, incluído na imagem Docker. Corrigida ofuscação do e-mail pelo Cloudflare sem depender de JavaScript.
- Nome do controlador e contato confirmados: Vinicius Matheus dos Santos, vinicius.m.dos.s@gmail.com. Beta 18+ confirmado pelo responsável, com declaração no aceite.
- Links no cadastro/Conta; aceite de versão/timestamp/usuário antes de criar UGC; opção de gerenciar saída sem aceitar novo contrato. Textos não fingem ser parecer jurídico nem ocultam retenções ainda não confirmadas.
- Exclusão com confirmação digitada, alerta destrutivo, senha ou identidade social própria recente; Apple vinculada exige revogação. Serviço transacional enumera dados, invalida todas as sessões/identidades, apaga conteúdo pessoal e preserva estrutura coletiva. CLI auditado atende pedidos recebidos pela página pública após verificação humana.
- Praça preserva replies e avança revisões após apagar texto/reação. Comunas ativas exigem transferência ou arquivo; arquivadas continuam sem owner. PDFs pessoais perdem acesso e vão a cleanup; coletivos ficam com a Comuna, sem mostrar responsável excluído. Falha de storage mantém recibo para retry.
- Denúncias reais de perfil/post/comentário, bloqueio de servidor e desbloqueio em Conta; decisões mínimas por console, reutilizando relatórios/auditoria. Filtro contra repostagem idêntica já removida no mesmo espaço, inclusive PDF por checksum, sem painel novo ou classificador ideológico.
- Corrigidos bugs nos validadores Google/Apple: API JWT removida, resultado booleano invertido, montagem de chave RSA e exigência indevida de nbf. Testes criptográficos reais complementam stubs HTTP.
- Identificadores existentes preservados. EAS com contador remoto e profile store `testflight-staging`; validação de API/environment, nome Beta em staging, scheme Google iOS por ambiente, botão Apple oficial e pacote alinhado ao Expo 58.
- Permissões desnecessárias bloqueadas, Face ID automático removido, ATS restrito; assets atuais preservados e auditados.
- Redução de contexto pessoal em logs/Sentry, remoção de request/extras/breadcrumbs, sanitização de mensagens de banco e de destinatários/erros de e-mail. Replay e tracing continuam desabilitados.
- Inventário, matrizes das lojas, metadados mínimos, procedimento de review e checklist preparados.

## Validado automaticamente

Backend: PHPUnit completo (**214 testes, 1.833 asserções**), incluindo migrations em banco limpo, exclusão/recadastro, senha/social/ownership, Praça/Biblioteca, retry de storage, reports, política/rotas públicas e privacidade de logs. PHPStan sem erros; CS Fixer check sem diferenças; container Symfony production válido; OpenAPI exportado e teste de contrato incluído; `git diff --check` nos dois repos.

Mobile: TypeScript e lint passaram; suíte normal Jest passou com **171 testes em 23 suites**, incluindo proteção contra exclusão acidental e conta social sem senha. Expo Doctor passou **20/20**. Perfis foram avaliados com cenários válidos e misturas HTTP/ambiente rejeitadas. Prebuild iOS/Android isolado gerou manifests/entitlements; Info.plist final sem usage descriptions desnecessárias, ATS restrito e scheme staging correto. Assets: tamanhos, modos RGB/RGBA e bounding box inspecionados.

Ressalvas: `doctrine:schema:validate` aprova o mapping, mas a comparação integral continua indicando diferenças preexistentes de índices/constraints especiais, inclusive FKs compostas de Praça/Biblioteca que o ORM não representa. A inspeção `--dump-sql` foi somente leitura; **não aplicar schema:update**, que removeria proteções. A nova migration é aplicada pela suíte e não gerou diferença dos novos campos jurídicos. O diagnóstico Jest `--detectOpenHandles` identificou timers do SDK Sentry; o SDK foi isolado nos testes. Um teste existente de Explorar apresentou timeout intermitente de 5s durante a auditoria, inclusive nesse modo de instrumentação; passou isolado e na execução final da suíte normal (171/171). Não foi aumentado timeout nem usado forceExit para esconder falha. A instabilidade de tempo fica registrada para acompanhamento.

Auditoria npm: quatro entradas high na cadeia de tooling (expo/@expo/cli via zod e node-forge); não foi forçado downgrade Expo 58→54 sugerido pelo audit. Revisão compatível pendente, sem afirmar que os avisos provam vulnerabilidade no bundle nativo. Detalhes no runbook.

## Validado em build real

Backend foi construído/implantado na Railway em **staging**, deployment final `bd391b5b-9f5e-4ca8-8f4e-caf624d5dec4` com status `SUCCESS`, e suas rotas testadas por HTTPS. Privacidade/Termos/Regras/Suporte/Exclusão responderam 200, com contato em texto, viewport e ausência de localhost. Conta descartável de staging: cadastro 202, login 200, exclusão 204, access antigo 401, refresh antigo 401 e login antigo 401. Conta do teste foi excluída. Isso não valida entrega de e-mail: a allowlist staging continua valendo.

**Nenhuma IPA/APK foi instalada ou validada em aparelho.** Prebuild/config não equivalem a build assinada. Ícone/splash no aparelho, popups de permissão, Google/Apple reais, revogação Apple, R2/Brevo ponta a ponta, Sentry nativo e coleta de SDKs ainda precisam da build. Não há credenciais reais de reviewer criadas nesta tarefa.

## Compliance Apple

Requisitos oficiais consultados em 2026: UGC 1.2, login 4.8, privacidade/exclusão 5.1.1, TestFlight interno/externo, SDK mínimo e privacy manifests. Matriz preparada a partir do sistema; campos de SDK/retensão ainda exigem confirmação. Não houve preenchimento ou envio no App Store Connect e não se declara aprovação de review.

Interno do TestFlight permite usuários ASC com acesso (até 100); externo tem Beta App Review inicial e até 10.000 testers. `distribution=internal` do EAS é instalação direta/ad hoc, não TestFlight interno. Material comercial de lançamento não é bloqueio do primeiro teste interno. Fontes oficiais em [store-compliance.md](store-compliance.md).

## Compliance Google

Preparação documentada: criação de conta, exclusão in-app e recurso web externo, aceite antes de UGC, report/block, Data Safety, contato/segurança infantil e questionários. Internal testing exclusivo tem dispensa atual de Data Safety; closed/open/production exigem formulário. Regra consultada para contas pessoais novas após 13/11/2023: 12 testers continuamente por 14 dias no closed test e pedido de acesso à produção; tipo/data da futura conta Popularia não foram confirmados.

Package preservado; Play App Signing, SHA-1 dos clientes Google, AAB/API 36+, bibliotecas 16 KB e instalação ainda precisam ser verificados na fase Android. Não houve publicação nem configuração presumida de conta Google Play.

## Pendências de informação jurídica

Controlador, contato e 18+ já confirmados. Faltam bases de tratamento por finalidade, especialmente dados políticos/sindicais; contratos/transferências internacionais; prazos de auditoria imutável, evidence hold, conteúdo removido, suporte, Sentry/Brevo/proxy/backups; responsável por jobs e atendimento. Confirmar textos finais. Telefone/endereço, se exigidos pelas lojas, devem ser fornecidos privadamente pelo titular, sem inventar pessoa jurídica/CNPJ/foro.

## Pendências Apple Developer/App Store Connect

Efetivar Developer, aceitar contratos, confirmar Team/App ID e capability, criar/verificar app ASC, obter ascAppId e signing/provisioning EAS. Configurar p8/Team/Key ID somente no backend para revogação Apple. Gerar IPA com Xcode 26+/SDK iOS 26+, conferir privacy manifests/reasons/assinaturas e export compliance; preparar conta normal/descartável de review, contato privado e metadados mínimos. Fornecer credenciais apenas em campos privados, nunca senha de administrador.

## Pendências Google Play

Aguardam fase posterior por decisão do responsável: conta e verificações, tipo/data e requisito específico do Console, registro do app, assinatura, clientes OAuth Android, internal/closed tracks, Data Safety, rating/público-alvo e contato/procedimento de Child Safety Standards. Verificar também o caso de exclusão Android de conta vinculada à Apple: confirmação nativa Apple é iOS; suporte externo atende iniciação sem reinstalar. Revalidar políticas na época da submissão.

## Bloqueadores para TestFlight interno

Apple Developer ativo e configuração/signing/ASC; build store iOS realmente gerada e instalada; chave de revogação e fluxos Apple/Google reais; confirmação de retenções/backups/jobs e dos textos para o grupo real; allowlist de e-mail para testers. O backend de staging e seus links já estão disponíveis para essa validação. Se escolher profile production, antes é necessário deployar o código/API/legal em produção: **os cinco links production ainda retornaram 404**, embora o health responda 200.

## Bloqueadores para TestFlight externo

Itens internos, revisão final dos textos e práticas de dados; declarações Apple verificadas com SDK nativo/fornecedores; operação efetiva de moderação/suporte; conta e dados normais de reviewer; questionários/contatos e Beta App Review. Validar que o filtro mínimo e o atendimento dão cobertura adequada ao beta real. Publicar/verificar URLs finais de produção antes de usá-las como metadados finais. Não expandir confiando só em testes automatizados ou prebuild.

## Pode esperar até lançamento público

Screenshots comerciais perfeitos, vídeo, copy final, ASO/keywords, press kit, muitas localizações e página de lançamento elaborada. Um painel grande de moderação, ads/analytics de marketing, KYC e permissões para features futuras não foram introduzidos.

Próximas ações operacionais e comandos em [beta-release-runbook.md](beta-release-runbook.md); checklist em [pre-testflight-checklist.md](pre-testflight-checklist.md); inventário em [data-inventory.md](data-inventory.md); política de dados pós-exclusão em [account-deletion.md](account-deletion.md).
