# Exclusão de conta — decisão e operação do beta

`POST /api/v1/me/account-deletion`: identidade vem do bearer; não recebe userId. Confirmação literal `EXCLUIR`, limite de 5 tentativas/15min por conta e prova de senha ou ID token recente (até 10 minutos) de identidade já vinculada. Social login não exige criar senha. Havendo Apple vinculada, Apple deve ser a prova e fornecer authorizationCode recente para revogação. `APPLE_TEAM_ID`, `APPLE_KEY_ID`, `APPLE_PRIVATE_KEY` (p8 PEM, secret somente backend) e `APPLE_BUNDLE_ID=com.popularia.mobile` são necessários; não colocar chave no mobile/EAS público.

Apple: troca código no `/auth/token`, confere sub e revoga refresh token no `/auth/revoke`, sem persistir códigos/tokens do provider. Falha/configuração ausente retorna erro sem confirmar exclusão local. Recuperar confirmação Apple e repetir após resolver falha; autorização externa já revogada e transação local revertida pode exigir nova autorização. Senha local continua possível para contas sem Apple vinculada. Google é apenas OIDC, sem acesso offline ou refresh token do Google para revogar no servidor.

## Política escolhida

Priorizar apagar conteúdo pessoal e manter estrutura coletiva; não preservar texto de posts/comentários/mensagens próprios sob a justificativa genérica de histórico. Não há grace period. Não se promete anonimização irreversível: UUID e vínculos de registros de segurança/auditoria continuam tecnicamente correlacionáveis; antigos detalhes de auditoria podem conter handle. O perfil residual não autentica, não aparece em busca e não mostra e-mail/handle/nome anterior.

| Categoria | Ação após confirmação |
|---|---|
| Conta/perfil | Estado `deleted`; e-mail e handle aleatórios, nome genérico; senha, verificação, bio, cidade, interesses, preferências e aceite removidos; papel volta a user |
| Sessões/códigos/identidades | Apagar auth_session (refresh cascade verificada), account_token e user_auth_identity; access tokens falham imediatamente |
| Follows/blocks | Remover nos dois sentidos |
| Posts/comentários | Apagar body, marcar deleted; retirar assunto e referência PDF de posts próprios; ajustar contagem dos comentários em posts de terceiros; preservar respostas alheias |
| Praça | Apagar body/sticker, marcar deleted, manter replyTo/estrutura; avançar revisões inclusive quando remover reação em mensagem de terceiro; remover reações próprias e de mensagens apagadas, presença/leitura; remover createdBy da Praça |
| Notificações | Apagar da própria conta e as que têm a conta como actor; destinos são revalidados pelo serviço |
| Comunas ativas próprias | Recusar com 409 e slugs; transferir com aceite existente ou arquivar; nunca apagar coletivo silenciosamente |
| Comunas arquivadas próprias | Preservar coletivo, owner nulo; política atual admite coletivo arquivado sem proprietário ativo |
| Memberships, pedidos, transfers | Remover e ajustar member_count; shares dependentes da participação removidas |
| PDFs pessoais | Remover distribuições e saves de todos; apagar título/descrição/nome original, retirar publicação pública; shells para FKs; marcar arquivo purge_pending |
| PDFs de origem Comuna | Preservar documento/objeto/coleção e gestão da Comuna; responsible=null na resposta, nome original genérico; o PDF pode conter nome no próprio conteúdo — contato para exercício de direitos |
| Salvos e coleções pessoais | Remover saves próprios; apagar nomes/descrições pessoais das coleções residuais |
| Denúncias | Remover denúncias enviadas; casos de terceiros podem permanecer para apuração sem restabelecer perfil |
| Segurança, auditoria, evidence hold | Preservar sob política própria; 180 dias configurados para security_event com purge, exceções justificadas; auditoria sem expiração automática é pendência a resolver |
| Logs/Brevo/Sentry/backups | Retenção do fornecedor, não eliminação instantânea. Operador deve atender pedidos nos fornecedores e confirmar prazos; não há sincronização automática de exclusão com todo fornecedor |

A implementação enumera as remoções explicitamente em uma transação PostgreSQL. Cascades só completam vínculos conhecidos (refresh e shares de memberships), cobertos por teste. Não há broker novo. Transação deixa a conta inutilizável; S3 é limpo depois por rotina idempotente. Triggers impedem novas inserções por contas excluídas, inclusive requisições concorrentes que esperaram o lock. O sistema deve devolver erro e repetir operações em caso de deadlock; nunca reconhecer como concluída uma transação revertida.

## Arquivos e cleanup

`php bin/console app:library:purge --dry-run`, depois `php bin/console app:library:purge`: até 100 objetos por execução; executa `storage.delete` antes de marcar purged, usando lock/skip locked. Falha de storage deixa recibo elegível para retry. Purge_pending de conta excluída é imediato; deleted individual tem espera de 24h; uploads interrompidos sem documento têm 24h. Nunca apagar objeto com documento published/removed. Documentos removed ficam para apuração e precisam de prazo confirmado. Dry-run percorre objetos distintos (corrigido: antes contava o mesmo objeto até 100 vezes).

Rodar diariamente em cada ambiente, com alerta de falha e repetição até zerar backlog. Não prometer prazo máximo de eliminação física sem monitorar job. O backend pode fornecer bytes por proxy; não existe URL permanente pública. Cópias baixadas por terceiros ficam fora do controle do serviço.

## Recadastro e restauração

Mesmo e-mail/Google/Apple, após exclusão, cria outra conta: outro UUID, onboarding/aceite novos e dados vazios. Não há restauração por e-mail. Apple pode não retornar novamente nome/e-mail, inclusive após revogação; a arquitetura admite identificador técnico temporário e exige onboarding. Cadastro social simultâneo pode retornar conflito e exigir nova tentativa; não fundir dados automaticamente.

Antes de recuperar backup, levantar contas encerradas depois do snapshot (contas deleted/updated_at na base atual e registros operacionais de pedidos), repetir a limpeza correspondente no banco restaurado e objetos e revogar sessões antes de reabrir tráfego. Ainda não existe ledger externo automático de exclusões; perder a base atual junto com o backup exige reconciliação manual dos pedidos. Essa limitação bloqueia promessas de recuperação sem risco de ressuscitar dados.

## Validação

Testes de API criam contas, sessões múltiplas, Google identity, follow/block, post, comentário e resposta, Comuna/membership, Praça e reply/reação/presença, notificação e PDFs pessoal/coletivo/save. Excluem por senha, verificam tokens/login, campos apagados, conta invisível, coletivo preservado, responsible oculto e purge no storage em memória. Testes separados: senha/confirmacão erradas, ownership ativo/arquivado, aceite jurídico e validação real de assinatura/JWKS. S3/Brevo/Apple/Google em rede e instalação limpa exigem teste de build real; não se confundem com stubs.

## Pedidos recebidos fora do aplicativo

O recurso web `/excluir-conta` permite iniciar pedido pelo canal real de suporte. O operador confirma identidade por meio proporcional (por exemplo, controle do e-mail previamente associado), resolve ownership e, se houver Apple, orienta e confirma a revogação manual prevista na TN3194. Não pedir senha, token ou código de reset por e-mail. Registrar protocolo e resultado da verificação no atendimento, com acesso restrito.

Depois, operador autorizado usa `php bin/console app:account:delete HANDLE "protocolo e motivo da verificação" --identity-verified`. Há confirmação interativa destrutiva; nunca automatizar a verificação com base só no remetente declarado. `--apple-access-revoked` só pode ser usado depois da confirmação real no atendimento. A política de dados é exatamente o mesmo AccountDeletionService do app, e a ação do suporte é auditada. Esta ferramenta não dá bypass remoto, acesso de reviewer ou privilégio dentro do aplicativo.
