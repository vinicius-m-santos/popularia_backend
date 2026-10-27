# Requisitos das lojas e matrizes — consulta em 27/10/2026

Somente documentação oficial. Não foram preenchidos formulários nem foi feita submissão às lojas. A conta Apple Developer aguarda efetivação, conforme o responsável. Google Play não participa do primeiro build; tipo/data de criação da conta futura são desconhecidos.

## Apple: requisitos que afetam o Popularia

[App Review Guidelines](https://developer.apple.com/app-store/review/guidelines/): 1.2 exige filtro de conteúdo inadequado, denúncia com atendimento, bloqueio e contato publicado para UGC. 4.8 afeta o login Google: manter opção equivalente de privacidade, aqui Sign in with Apple. 5.1.1 exige política acessível e exclusão iniciada no app. O beta externo também passa por avaliação; não presumir dispensa por ser TestFlight.

Estado: denúncia persistida para posts/comentários/perfis adicionada reutilizando ContentReport, bloqueio real no servidor integrado ao menu, filas/remoções já existentes na Praça/Biblioteca preservadas, operação mínima de decisões por console; filtro impede repostagem textual idêntica já removida no mesmo espaço e PDF por checksum no mesmo âmbito. Não é classificador universal: conteúdo novo/variações exigem revisão humana e resposta efetiva. Antes de expandir o beta, demonstrar a operação desses controles ao reviewer e avaliar se a cobertura é suficiente com dados reais.

[Exclusão Apple](https://developer.apple.com/help/app-review/guideline-reference/5-1-1-account-deletion): excluir de verdade, não apenas desativar; confirmação e verificação proporcionais são possíveis. O fluxo implementa remoção de dados pessoais, com referências residuais e exceções descritas na política. [TN3194](https://developer.apple.com/documentation/technotes/tn3194-handling-account-deletions-and-revoking-tokens-for-sign-in-with-apple) fundamenta a revogação de autorização Apple. O Markdown oficial foi lido via HTTPS após falha de renderização da ferramenta. Teste real de revogação ainda depende de Apple Developer ativo.

[TestFlight overview](https://developer.apple.com/help/app-store-connect/test-a-beta-version/testflight-overview/): interno admite até 100 usuários App Store Connect com acesso; externo até 10.000 testers. Primeiro build externo é enviado para review; builds posteriores podem exigir menos revisão. Builds expiram após 90 dias. Interno precisa app/build processado, signing, export compliance e testers com função apropriada; não precisa terminar marketing/screenshot de lançamento. Ver [informações de teste](https://developer.apple.com/help/app-store-connect/test-a-beta-version/provide-test-information/).

## Google: diferenças e fase posterior

[Account deletion](https://support.google.com/googleplay/android-developer/answer/13327111): além da iniciação no app, fornecer recurso web para pedir exclusão sem reinstalar. `/excluir-conta` explica o pedido pelo canal confirmado e o fluxo nativo; a equipe confirma identidade e executa a mesma política. Não basta congelar conta. Declarar retenções justificadas e atender dados em fornecedores.

[UGC](https://support.google.com/googleplay/android-developer/answer/9876937): aceite dos Termos/regras antes de criar UGC; definições de abuso, denúncia de conteúdo e usuários e bloqueio. O servidor agora impede publicação sem aceite atual, inclusive de clientes antigos; tela tem links antes da confirmação e alternativa de sair/excluir. Regras proíbem pornografia e exploração, não há feed NSFW neste beta.

[User Data](https://support.google.com/googleplay/android-developer/answer/10144311): política pública, ativa e acessível; refletir SDKs e uso efetivo. Publicar HTML sem login e sem PDF/gate geográfico. [Data Safety](https://support.google.com/googleplay/android-developer/answer/10787469): declarar servidor e SDKs; só internal testing está dispensado do formulário enquanto exclusivo nesse track. Closed/open/production precisam da declaração. “Service provider” pode se enquadrar em exceção de compartilhamento; isso não elimina a coleta nem autoriza afirmar que ninguém externo processa dados.

[Novas contas pessoais](https://support.google.com/googleplay/android-developer/answer/14151465): se criada depois de 13/11/2023, o requisito consultado é closed test com pelo menos 12 testers inscritos continuamente por 14 dias, seguido de pedido de acesso à produção. Não aplicar automaticamente à conta Popularia: tipo/data e exigência exibida no Console ainda precisam ser confirmados. Internal test não substitui esse closed test.

[Child Safety Standards](https://support.google.com/googleplay/android-developer/answer/14747720): redes sociais têm obrigações próprias, mesmo quando o público é adulto. As regras públicas proíbem exploração/abuso sexual infantil; denúncias in-app e contato já existem. Antes de Google Play, designar o contato de segurança infantil no Console e documentar triagem, remoção de CSAM e comunicação às autoridades/entidades competentes de acordo com a lei. Não declarar adesão operacional sem responsável e procedimento estabelecidos.

## Apple App Privacy — respostas preparatórias

Referência: [App Privacy Details](https://developer.apple.com/app-store/app-privacy-details/). “Vinculado” inclui UUID pseudônimo. Finalidades principais: App Functionality, Product Personalization, segurança dentro da funcionalidade; diagnóstico em App Functionality (reduzir falhas). Não marcar “não coletamos dados”. Não marcar tracking publicitário: não há ligação entre serviços para ads/medição de ads ou data broker implementada.

| Categoria do formulário | Coleta real e finalidade | Vinculado | Tracking | Processadores | Retenção |
|---|---|---|---|---|---|
| Contact Info: Name, Email | Conta, exibição e recuperação | Sim | Não | Railway, Google/Apple, Brevo para e-mail | Até excluir + retenção do provider a confirmar |
| Identifiers: User ID | UUID, @ e sub social para sessão/acesso | Sim | Não | Railway, Google/Apple, Sentry UUID | Sessão/conta; referência técnica residual/auditoria |
| Sensitive Info | Interesses, opiniões/conteúdo político e possível filiação sindical | Sim | Não | Railway; R2 quando no PDF | Conta/conteúdo; coletivos e exceções conforme política |
| User Content: Other User Content | Posts, comentários, Comunas, mensagens, PDF | Sim | Não | Railway/R2 | Exclusão pessoal; coletiva sob gestão da Comuna |
| User Content: Customer Support | Reports e e-mails de suporte | Sim quando identificável | Não | Railway/Brevo/provedor do e-mail confirmado | Casos e suporte: prazo a confirmar |
| Usage Data: Product Interaction | Follow, reactions, membership, leitura/presença, saves | Sim | Não | Railway | Enquanto necessário; remover na exclusão |
| Diagnostics: Crash Data, Other Diagnostic Data | Falha, plataforma, versão, request ID; App Functionality | Sim pelo UUID enviado | Não | Sentry, Railway | Retenção Sentry/Railway a confirmar |
| Other Data Types | Eventos de segurança, IP sem derivar localização | Sim quando há conta | Não | Railway; proxies observam tráfego | 180 dias configurados; holds justificados |
| Device ID/Performance Data | Não há ads ID; SDK nativo pode gerar ID de instalação/contextos | **Confirmar evento nativo antes de finalizar** | Não previsto | Sentry/SDKs | A confirmar |
| Precise/Coarse Location, Contacts, Audio, Health, Payments | Sem coleta funcional dessas categorias | — | — | — | — |

PDF não implica automaticamente marcar Photos/Videos ou Audio: não existe upload de galeria, câmera ou voz, mas o conteúdo dos arquivos pode conter informações pessoais. Confirmar categorias efetivas do uso do beta. Não confundir cidade digitada manualmente com GPS; avaliar “Other User Content” e informações de perfil segundo a definição exibida no formulário. Proxies podem derivar localização do IP para sua operação: verificar uso contratado antes da resposta definitiva.

## Google Data Safety — respostas preparatórias

| Tipo Google | Coletado | Obrigatório/opcional | Finalidade | Compartilhado na definição Play | Retenção/exclusão |
|---|---|---|---|---|---|
| Personal info: Name, Email, User IDs | Sim | Conta/identificação necessárias; bio/cidade opcionais | Account management, App functionality | Verificar exceção de processador em contratos | Conta e exceções do inventário |
| Personal info: Political/religious beliefs, Other info | Pode estar em interesses/UGC/participações | Interesse/conteúdo por escolha | Personalization, App functionality | Mesma avaliação contratual | Pessoal removido; coletiva/safety conforme política |
| Messages: Other in-app messages | Sim, Praça | Participação opcional | App functionality | Outras pessoas recebem conforme ação/contexto; aplicar definições de transferência iniciada pelo usuário | Texto próprio apagado; shell de conversa |
| Files and docs | Sim, PDFs | Upload opcional | App functionality | R2 operacional; compartilhamento de usuário conforme acesso | Pessoal sem acesso imediato + purge; coletiva preservada |
| App activity: Other UGC, App interactions | Sim | Conforme funcionalidade usada | App functionality, Personalization, prevenção de abuso | Não declarar venda/ads | Interações removidas na exclusão |
| App info and performance: Crash logs, Diagnostics | Sim se Sentry habilitado | Operacional; não há controle individual no app hoje | Analytics técnico/funcionamento | Processamento operacional, verificar exceção | Retenção ainda a confirmar |
| Device or other IDs | Confirmar SDK em evento nativo | Não concluir “não” só por não ter AD_ID | Diagnóstico se presente | A verificar | A verificar |
| GPS/contacts/photos/audio/financial/health | Sem função coletora atual | Não solicitados | — | — | — |

Respostas gerais: conta pode ser criada por e-mail/senha e Google/Apple (Apple no iOS); exclusão pode ser solicitada no app e pela URL pública. App→API usa HTTPS. Não declarar revisão de segurança independente, criptografia ponta a ponta ou coleta somente efêmera: banco e objetos persistem. Não inferir “criptografado em repouso” sem confirmar fornecedor; a pergunta sobre trânsito é distinta. Os campos de SDK pendentes impedem envio definitivo do formulário.

## Classificação e público

Beta **18+ por decisão de produto**, com declaração explícita no aceite. Isso não é uma classificação oficial. Preencher [questionário Apple](https://developer.apple.com/help/app-store-connect/manage-app-information/set-an-app-age-rating/) e [Google/IARC](https://support.google.com/googleplay/android-developer/answer/9859655): UGC, interação entre pessoas, discussões políticas/sociais, linguagem potencialmente intensa, links/PDFs e mecanismos de controle. Não há ads, compras, jogos de azar, conteúdo sexual promovido, GPS ou chat anônimo aleatório implementados. Frequência/intensidade real de temas maduros deve ser avaliada com o conteúdo disponibilizado aos testers. Não fornecer respostas “never” só porque o criador não escreveu o UGC.

## LGPD

[Lei 13.709/2018 oficial](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm): observar finalidade/necessidade, transparência e direitos; dados políticos/sindicais exigem avaliação específica de dados sensíveis. Documentos são preparação técnica, não parecer jurídico. O controlador deve confirmar bases, retenções, transferências e tratamento de dados sensíveis antes de ampliar o beta. Não adicionar consentimento genérico para tentar legalizar todo tratamento.

## SDKs, assinatura e requisitos técnicos atuais

[Apple SDK mínimo](https://developer.apple.com/news/upcoming-requirements/?id=04282026a): desde 28/04/2026, upload exige Xcode 26+ e SDK iOS/iPadOS 26+. Verificar a imagem e os logs EAS da primeira IPA; deployment target iOS 16.4 visto no prebuild é a versão mínima de instalação, não o SDK usado para compilar. Não confundir os dois. [SDKs terceiros](https://developer.apple.com/support/third-party-SDK-requirements/): conferir privacy manifests/reasons e assinaturas dos SDKs exigidos no archive final, inclusive cadeia Google Sign-In e React Native. Isso não foi validado sem Pods/IPA.

[Google target API](https://support.google.com/googleplay/android-developer/answer/11926878): a política consultada exige Android 16/API 36+ para novos apps/updates desde 31/08/2026. O Expo Modules Core instalado tem default 36, mas o valor efetivo deve ser confirmado no AAB/manifest final. [Páginas de memória 16 KB](https://developer.android.com/guide/practices/page-sizes): há exigência para apps com código nativo; a página consultada informa bloqueio de updates incompatíveis a partir de 01/02/2027. Conferir todos os `.so` e executar em ambiente 16 KB antes do track Android; não supor compatibilidade apenas pela versão do Expo. Revalidar datas e requisitos quando iniciar Google Play.
