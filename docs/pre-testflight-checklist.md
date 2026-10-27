# Checklist pré-TestFlight — Popularia

Estado em 27/10/2026. Código preparado; **ainda não há IPA instalada/validada nem distribuição TestFlight**. Apple Developer aguarda efetivação, conforme Vinicius. Google Play fica para a próxima fase. Marcar uma verificação de código não significa teste em aparelho nem aprovação da loja.

## Código e configuração

- [x] Preservar bundle/package `com.popularia.mobile`, slug `popularia_mobile`, scheme `populariamobile` e projeto EAS existente.
- [x] Nome Popularia; staging identifica Popularia Beta.
- [x] Profiles production/staging/testflight-staging com API e Sentry coerentes, HTTPS e validação contra troca de ambiente.
- [x] Auto incremento de buildNumber/versionCode pelo EAS; versão de produto 1.0.0 preservada.
- [x] Corrigir scheme Google iOS por ambiente e incompatibilidade do pacote Apple com Expo 58.
- [x] Capability Apple gerada; botão oficial com verificação de disponibilidade.
- [x] Ícone/splash existentes auditados, sem redesenho ou P antigo; iOS opaco e Android com foreground transparente central.
- [x] Permissões desnecessárias bloqueadas; Face ID automático removido; sem GPS/ATT/push/câmera/microfone.
- [x] Textos legais e suporte em fonte Markdown única, HTML responsivo sem login/cookies/trackers.
- [x] Responsável real e contato confirmados; beta 18+ confirmado.
- [x] Links acessíveis no cadastro, antes do aceite e em Conta.
- [x] Aceite registra versão/timestamp/usuário; servidor impede criar UGC sem aceite.
- [x] Conta existente pode recusar aceite e gerenciar saída; arquivar/transferir para excluir não exige novo aceite.
- [x] Exclusão no app com texto digitado, alerta final e prova de senha ou identidade social vinculada.
- [x] Sessões/tokens/identidades revogados/removidos; sem ressuscitar dados no recadastro.
- [x] Política por dado, ownership, Praça, PDFs pessoais/coletivos e cleanup documentados e implementados.
- [x] Denúncia real de perfil/post/comentário e bloqueio; decisões operacionais auditadas; Praça/Biblioteca reutilizadas.
- [x] Filtro mínimo contra repostagem idêntica removida no mesmo espaço; limites documentados.
- [x] Sanitização adicional de logs/Sentry; replay/tracing desativados.
- [x] Inventário, matrizes Apple/Google, metadados mínimos e procedimento de conta de review preparados.
- [x] Atendimento externo de exclusão tem comando operacional auditado, confirmação e verificação humana obrigatórias.

## Antes do primeiro TestFlight interno

- [ ] Apple Developer efetivado; contratos aceitos pelo titular.
- [ ] Confirmar Team, App ID, capability Apple, certificados/provisioning EAS e registro App Store Connect.
- [ ] Definir `ascAppId`/dados de submit reais, sem adivinhar IDs.
- [ ] Configurar chave p8/Team/Key ID no backend para revogação Apple; validar login e exclusão reais Apple.
- [ ] Conferir audiences Google do backend, consent screen e contas autorizadas; testar login Google iOS.
- [x] Backend staging implantado e páginas anônimas HTTPS 200 com contato visível; produção continua pendente.
- [ ] Se escolher profile production: deployar backend e verificar seus URLs antes da build; hoje as páginas production retornam 404.
- [ ] Confirmar bases legais/retenções do beta e aprovar os textos com o controlador.
- [ ] Confirmar backup, prazo, restauração/reconciliação de exclusões e jobs de cleanup monitorados.
- [ ] Confirmar envio/recebimento Brevo em conta real de tester; ajustar allowlist staging.
- [ ] Gerar IPA store com testflight-staging ou production, inicializando contador remoto se necessário.
- [ ] Confirmar Xcode 26+/SDK iOS 26+ nos logs EAS e privacy manifests/reasons/assinaturas dos SDKs no archive.
- [ ] Conferir export compliance no App Store Connect, app/build processado e testers internos com acesso.
- [ ] Instalar no iPhone: ícone, splash, nome, API, auth, PDF, links, aceite, logout e exclusão.
- [ ] Capturar evento Sentry controlado sem conteúdo/segredos; confirmar SDK nativo, IDs/contextos e retenção.
- [ ] Verificar permissões em instalação limpa e artefato final; prebuild não substitui essa prova.

## Antes de TestFlight externo

- [ ] Concluir itens internos e publicar política/termos/regras/suporte sem pendências materiais.
- [ ] Responsável por atendimento de denúncias, segurança infantil, direitos e suporte definido e disponível.
- [ ] Validar cobertura do filtro/denúncia/bloqueio/remoção com conteúdo real e explicar limites ao reviewer.
- [ ] Preencher beta description, feedback email, contato privado e What to Test do runbook.
- [ ] Criar e conferir conta normal de review e segunda conta descartável; guardar credenciais só em campo privado/cofre.
- [ ] Preencher privacidade Apple com matriz conferida contra evento nativo e fornecedores; nada de “não coletamos dados”.
- [ ] Responder questionário etário/conteúdo; 18+ de produto não é classificação oficial.
- [ ] Revisar os avisos npm do tooling com patches compatíveis, sem downgrade forçado do Expo.
- [ ] Enviar primeiro build externo para Beta App Review e aguardar aprovação.

## Google Play — depois do primeiro build iOS

- [ ] Confirmar conta (pessoal/organização, data de criação, verificações exigidas).
- [ ] Criar app com package existente; configurar EAS upload key e Play App Signing; registrar SHA-1 correto no Google.
- [ ] Validar exclusão no Android de conta vinculada à Apple: hoje a prova Apple nativa só roda no iOS; recurso externo/suporte permite iniciar pedido sem reinstalar, mas UX deve ser verificada antes do track Android.
- [ ] Gerar AAB, testar manifest mesclado/API 36+, bibliotecas nativas 16 KB e instalação limpa.
- [ ] Configurar internal testing; exclusivo nesse track tem dispensa atual de Data Safety.
- [ ] Antes de closed/open/production: Data Safety, URLs públicas, app access, público-alvo, content rating, ads=ausentes e Child Safety Standards/contato/procedimento.
- [ ] Se conta pessoal criada após 13/11/2023: conferir regra exibida no Console; documentação consultada pede 12 testers continuamente por 14 dias no closed test e solicitação de acesso à produção.
- [ ] Não publicar produção nesta tarefa.

## Pode esperar até lançamento público

- Screenshots comerciais finais, vídeo, copy/ASO/keywords, press kit, localizações extensas e site de lançamento elaborado.
- Não confundir esses itens com privacidade pública, exclusão funcional ou assinatura/build instalável.

Referências e respostas: [requisitos e matrizes](store-compliance.md), [runbook](beta-release-runbook.md), [inventário](data-inventory.md), [exclusão](account-deletion.md). Resultados técnicos e limitações: [relatório](pre-testflight-report.md).
