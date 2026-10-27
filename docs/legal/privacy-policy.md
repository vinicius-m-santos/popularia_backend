# Política de Privacidade do Popularia

Versão 2026-10-27. Texto preparado para o beta, sujeito à confirmação das pendências de retenção e à revisão do responsável. Não constitui parecer jurídico.

## Quem responde pelos dados

O controlador do Popularia é Vinicius Matheus dos Santos. Para suporte, privacidade e exercício de direitos: vinicius.m.dos.s@gmail.com. O beta se destina a pessoas com 18 anos ou mais.

## O que tratamos e para quê

Tratamos e-mail, identificador interno, nome de exibição, nome de usuário (@), bio, cidade informada manualmente, interesses escolhidos e preferências de privacidade. Esses dados permitem criar sua conta, organizar o perfil, recomendar conteúdo e controlar o acesso. A senha, quando existe, é armazenada como hash; não guardamos sua senha do Google ou da Apple.

Também tratamos publicações, comentários, mensagens e respostas na Praça, reações, participações e funções em Comunas, pedidos de entrada, transferências de propriedade, regras e descrições de Comunas, documentos PDF e seus metadados, coleções, compartilhamentos e itens salvos. Seguidores, bloqueios, denúncias, avisos internos e leitura/presença recente permitem oferecer as interações, proteger a comunidade e mostrar atividades relevantes. A presença é limitada à Praça; a cidade do perfil não é obtida por GPS.

Conteúdo, interesses e participações podem revelar opiniões políticas, filiação sindical, crenças ou outros dados sensíveis. Evite divulgar informações desnecessárias suas ou de terceiros. A publicidade de um conteúdo não afasta seus direitos. As bases e salvaguardas para dados sensíveis, inclusive a possibilidade de consentimento específico quando necessário, precisam ser confirmadas pelo controlador antes da ampliação do beta.

## Quem vê o conteúdo

Seu nome e @ identificam suas interações. O perfil pode exibir bio e cidade. E-mail, senha, interesses escolhidos, sessões e lista de bloqueios não fazem parte do perfil público. Perfil privado restringe o acesso conforme as permissões do serviço; não equivale a tornar secretas todas as suas interações em espaços públicos.

Comunas públicas e privadas têm permissões diferentes. Conteúdo de Comunas privadas fica restrito aos participantes autorizados. A Praça acompanha o acesso da Comuna. Sua presença recente e leitura são usadas no funcionamento da Praça. Moderadores da Comuna podem gerenciar participações e conteúdo, mas não recebem seu e-mail, senha ou IP por essa função.

Na Biblioteca, a origem do documento é pessoal ou de uma Comuna. Há documentos privados, para seguidores, membros, moderadores ou visitantes e documentos disponibilizados na Biblioteca Pública. Compartilhar um documento não altera sua origem. Links ou referências a um PDF não dão acesso automaticamente: o servidor confere a permissão no download. Pessoas autorizadas podem baixar cópias; não conseguimos apagar cópias mantidas fora do serviço.

## Autenticação, mensagens e segurança

No login com Google ou Apple, validamos a identidade fornecida e tratamos o identificador do provedor e o e-mail quando disponibilizado, inclusive e-mail privado da Apple. Não associamos contas diferentes automaticamente só por coincidência de e-mail. Os tokens de sessão do Popularia são guardados como hash no servidor, com validade e revogação; o aplicativo nativo usa armazenamento seguro para manter a sessão.

Enviamos e-mails transacionais de confirmação, recuperação e segurança. Não há newsletter ou publicidade implementada. Não há push remoto nesta versão: os avisos são internos ao aplicativo.

Para segurança, registramos eventos de autenticação, identificadores de conta/sessão, data, endereço IP e detalhes limitados do evento. Registros administrativos permitem apurar mudanças de permissão, remoções e acessos a dados sensíveis. Não é necessário fornecer documento civil, contatos, GPS, câmera ou microfone para usar as funcionalidades atuais.

## Fornecedores e observabilidade

Railway hospeda API e banco PostgreSQL. Os PDFs ficam em armazenamento privado Cloudflare R2. Cloudflare também participa da entrega/proteção da API por proxy, processando tráfego e metadados técnicos de conexão. Brevo processa e-mails transacionais. Google e Apple processam seus próprios fluxos de autenticação. Expo/EAS participa da construção e distribuição do aplicativo. Apple/TestFlight e, futuramente, Google Play processam distribuição e feedback conforme seus próprios avisos. Sentry recebe diagnóstico de erros quando habilitado: versão do app, plataforma/aparelho, falha, metadados técnicos e identificador interno, sem enviar intencionalmente senha, tokens ou conteúdo de conversas/PDFs. Replay e rastreamento de desempenho estão desabilitados.

Esses fornecedores podem processar dados fora do Brasil. O controlador deve confirmar as condições contratuais, regiões e mecanismos aplicáveis às transferências internacionais. Usamos processamento operacional para prestar e proteger o serviço; não há venda de dados, anúncios ou rastreamento publicitário implementados. Nem todo processamento por fornecedor é considerado “compartilhamento” nos formulários das lojas: as declarações seguem as definições de cada loja.

## Retenção e exclusão

Enquanto sua conta existir, mantemos os dados necessários às funcionalidades e à segurança. Você pode iniciar a exclusão em Conta → Excluir conta, confirmar a decisão e autenticar novamente. Contas Google/Apple não precisam criar senha local para isso. Contas vinculadas à Apple exigem confirmação com Apple para revogar a autorização. Não há período de arrependimento.

Após a confirmação, todas as sessões e credenciais locais/sociais são removidas, o perfil é descaracterizado e os textos de suas publicações, comentários e mensagens são apagados. Marcadores técnicos sem texto/autoria visível preservam a estrutura de respostas. Seguidores, bloqueios, presenças, reações próprias, notificações da/relacionadas à conta, salvos, participações e denúncias que você enviou são removidos. Uma referência técnica residual permanece para integridade de conversas, auditoria e documentos coletivos; ela não permite entrar no serviço e não conserva seu e-mail ou @ anterior. Isso não equivale à eliminação de todos os registros históricos de segurança.

Documentos pessoais deixam de ser acessíveis, inclusive suas distribuições em Comunas. Os objetos PDF entram na limpeza física; sua conclusão depende da rotina operacional de cleanup. Documentos cuja origem é uma Comuna permanecem sob a gestão dela, com o responsável excluído oculto na interface; o próprio arquivo pode mencionar a autoria. Você pode gerir o conteúdo que controla antes de sair e solicitar análise de dados pessoais em documentos coletivos pelo contato acima. Para excluir uma conta proprietária de Comunas ativas, primeiro transfira a propriedade ou arquive-as. Comunas arquivadas e conteúdo de outras pessoas não são apagados junto com sua conta.

PDFs excluídos individualmente ficam elegíveis para limpeza após 24 horas; PDFs pessoais de contas excluídas ficam elegíveis imediatamente. Documentos removidos por moderação têm retenção independente para apuração; o prazo deve ser confirmado antes da ampliação do beta. Eventos de segurança usam atualmente 180 dias, sujeitos à execução da rotina e a preservação justificada de evidências. A auditoria administrativa é imutável na implementação atual e não tem expiração automática; a necessidade e o prazo devem ser revisados pelo controlador. Logs operacionais e Sentry têm retenção do fornecedor ainda pendente de confirmação.

Backups podem conservar dados removidos até expirarem. A retenção e o teste de restauração precisam ser confirmados pelo responsável antes de receber dados reais no ambiente escolhido. Não prometemos apagar instantaneamente cópias em backups. Uma restauração exige reconciliar exclusões posteriores ao backup antes de reabrir o serviço.

## Seus direitos e proteção

Você pode solicitar confirmação do tratamento, acesso, correção, informação sobre uso/fornecedores, revisão de dados desnecessários, portabilidade quando aplicável, eliminação ou anonimização, oposição e revogação de consentimento quando essa for a base utilizada. Escreva ao contato acima. Podemos pedir confirmação proporcional de identidade e explicar restrições legais ou técnicas; não envie senha ou token por e-mail.

Adotamos HTTPS, controle de acesso no servidor, hashes de credenciais, limites de requisições e armazenamento privado. Nenhuma medida torna um sistema infalível. Investigamos incidentes e adotamos as comunicações aplicáveis. Solicitações legais e preservações de evidências devem ter justificativa, acesso restrito e duração revisada; não são autorização para conservar todo conteúdo indefinidamente.

## Alterações

Publicaremos alterações e a versão vigente nesta página. Mudanças relevantes serão comunicadas pelo serviço; quando necessário, haverá novo aceite ou autorização específica. Os Termos e as Regras da Comunidade são documentos separados, acessíveis na mesma área legal do aplicativo.
