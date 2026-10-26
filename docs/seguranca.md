# Segurança e privacidade

Este documento descreve o que está implementado no backend e por quê. Onde há uma decisão
jurídica pendente, ela está marcada como tal: **nada aqui é uma garantia de conformidade legal**.

## 1. Contas

- **Sem CPF nem documento civil.** O cadastro pede e-mail, senha, @, nome e, opcionalmente, bio,
  cidade e interesses. A arquitetura não impede uma verificação de identidade futura (seria uma
  tabela própria, com acesso restrito), mas nada disso é coletado agora.
- **Senha**: hash com o algoritmo `auto` do Symfony (rehash automático no login quando a
  recomendação muda). Mínimo de 8 caracteres, sem regras de composição; até 4096.
- **E-mail** guardado em minúsculas; único. **@** (handle): `^[a-z0-9._]{3,20}$`, único, com lista reservada
  (`popularia`, `admin`, `moderacao`, …).
- **Interesses são privados**: só aparecem em `GET /me`. Podem revelar opinião política ou orientação sexual.
- **Papel na plataforma** (`user`, `platform_moderator`, `admin`) e **estado** (`active`, `suspended`) só
  mudam pelos comandos `app:user:set-role` e `app:user:set-status`, sempre com motivo e auditoria.
  A API recusa qualquer campo desconhecido no corpo (400), então o cliente não consegue mandar `platformRole`.

## 2. Sessões e tokens

| Item | Como é |
| --- | --- |
| Access token | 43 caracteres aleatórios (~256 bits), validade de 15 min (`ACCESS_TOKEN_TTL`). No banco, só o SHA-256. |
| Refresh token | Igual, validade de 30 dias (`REFRESH_TOKEN_TTL`), **girado a cada uso**. No banco, só o SHA-256. |
| Sessão | Uma por aparelho (`auth_session`), com rótulo opcional ("iPhone"). Sem IP, sem identificador de aparelho, sem fingerprinting. |
| Logout | `POST /auth/logout` encerra a sessão atual; `POST /auth/logout-all` encerra todas; `DELETE /me/sessions/{id}` encerra uma. O app também apaga os tokens locais. |
| Reuso de refresh token | Apresentar um refresh token já girado encerra a sessão inteira (dono e invasor perdem o acesso), registra `refresh_token_reuse_detected` com conta, sessão e IP, e responde 401. Duas renovações simultâneas com o mesmo token também contam como reuso (atualização atômica `rotated_at IS NULL`). |
| Recuperação de senha | Encerra **todas** as sessões da conta. Troca de senha logada encerra as outras e mantém a atual. |
| Conta suspensa | Não entra e não usa tokens já emitidos (o estado é conferido a cada requisição). As sessões são encerradas ao suspender. |

### Por que access token opaco, e não JWT

O pedido permitia JWT. Escolhemos um token opaco conferido no banco porque:

1. A conta precisa ser carregada em toda requisição de qualquer jeito, para aplicar suspensões no
   servidor (com JWT, o bundle usual também recarrega a conta do banco). O custo extra é uma busca por
   índice único no hash.
2. Logout, "sair de todos os aparelhos", reuso de refresh token e suspensão passam a valer **na hora**,
   sem a janela de até 15 minutos de um JWT já emitido e sem precisar de lista de revogação.
3. Não há chave de assinatura para gerar, guardar e girar, nem dependência extra.

Se um dia a API precisar validar tokens sem acesso ao banco (vários serviços), dá para trocar o
`AccessTokenHandler` por um de JWT sem mudar o resto.

### No aparelho

- Refresh token no `expo-secure-store` (Keychain/Keystore, `WHEN_UNLOCKED_THIS_DEVICE_ONLY`).
  Access token só na memória. Nada em AsyncStorage. Na versão web (só desenvolvimento) o refresh
  token fica só na memória.
- No iOS, itens do Keychain podem sobreviver à desinstalação. Apagar a sessão na primeira abertura
  depois de reinstalar fica como melhoria (exigiria um marcador fora do Keychain).

## 3. Códigos por e-mail

Verificação de e-mail e recuperação de senha usam **códigos de 6 dígitos** (fáceis de digitar no celular):

- guardados como **HMAC-SHA256** (chave `APP_SECRET`), presos à conta e à finalidade;
- **uso único**; emitir um novo fecha os anteriores;
- validade: 24 h (verificação) e 30 min (recuperação);
- **5 tentativas erradas** fecham o código;
- protegidos também por limite de taxa (abaixo).

A confirmação do e-mail **não bloqueia** entrar e ler. Publicar e comentar exigem e-mail confirmado
(403 `/errors/email-not-verified`). Concluir uma recuperação de senha confirma o e-mail.

## 4. Não revelar quem tem conta

- Cadastro com e-mail já usado: mesma resposta 202; quem é dono do e-mail recebe um aviso.
- Login: mesma resposta para e-mail inexistente e senha errada; com e-mail inexistente o servidor
  calcula um hash do mesmo jeito, para o tempo de resposta não denunciar.
- Recuperação: sempre 202 com a mesma mensagem.
- E-mails são enviados **depois** da resposta HTTP (`kernel.terminate`), então o tempo de resposta
  não muda conforme a conta existe ou não.
- O @ é público por natureza: "@ em uso" é informado (422 e `GET /handles/{handle}/availability`, com limite de taxa).

Limite conhecido: depois de um cadastro, o app entra com o mesmo e-mail e senha. Se o login falhar,
quem tentou fica sabendo que algo não bate — o mesmo que aconteceria tentando entrar direto.

## 5. Limites de taxa

Guardados no PostgreSQL (tabela `cache_items`), valem entre instâncias. Chaves hasheadas (sem e-mail
ou IP em texto). Respostas 429 com `Retry-After`; cada estouro gera `rate_limit_exceeded`.

| Fluxo | Limite | Chave |
| --- | --- | --- |
| Login | 30 / 15 min | IP |
| Login | 5 / 15 min (zera ao acertar) | IP + e-mail |
| Troca de senha logada | 5 / 15 min | conta |
| Cadastro | 10 / hora | IP |
| Confirmar e-mail | 10 / 15 min | conta |
| Reenviar código | 3 / 15 min | conta |
| Pedir recuperação | 10 / hora | IP |
| Pedir recuperação | 3 / hora, **silencioso** (deixa de enviar, resposta igual) | e-mail |
| Confirmar recuperação | 20 / 15 min | IP |
| Renovar sessão | 60 / min | IP |
| Checar @ | 60 / min | IP |
| Publicar e comentar | 30 / 10 min | conta |

Os limites por IP são generosos porque muitas pessoas no Brasil compartilham IP (CGNAT de operadoras
móveis). Ajuste em `config/packages/framework.yaml`. Sem CAPTCHA nem pontuação de risco nesta etapa;
dá para acrescentá-los no mesmo ponto (`RateLimitGuard` e os processors de autenticação).

## 6. IP do cliente

- O IP vem de `Request::getClientIp()`. Sem `TRUSTED_PROXIES`, é o IP da conexão (`REMOTE_ADDR`);
  `X-Forwarded-For` enviado pelo cliente é **ignorado**.
- Atrás de um balanceador, liste só os IPs/faixas dele em `TRUSTED_PROXIES`. Nunca `0.0.0.0/0`.
- O IP **não** aparece em perfil, feed, respostas comuns, ferramentas de moderação nem nos logs operacionais.
  Só fica no registro de segurança. Sem geolocalização.

## 7. Três tipos de registro

| | Logs operacionais | Registro de segurança | Evidências preservadas |
| --- | --- | --- | --- |
| Onde | Monolog, JSON em stderr (prod) / `var/log` (dev) | Tabela `security_event` | `security_event` + `evidence_hold` |
| Para quê | Diagnóstico e alertas | Investigar invasões, abuso, fraude | Incidente com fundamento para preservar |
| IP | Não | Sim | Sim |
| Quem vê | Equipe de operação | Só `admin`, com motivo, **auditado** | Só `admin`, auditado |
| Retenção | Definida na hospedagem | `SECURITY_EVENT_RETENTION_DAYS` (rotina diária) | Até a preservação ser encerrada |

**Nunca registrados**: senhas, tokens, códigos, cabeçalho `Authorization`, corpo das requisições,
e-mail digitado num login que falhou, CPF. O `LogContextProcessor` ainda apaga do contexto de log
qualquer chave com nome sensível (`password`, `token`, `secret`, `email`, `code`…), por segurança extra.
Cada requisição tem um `X-Request-Id` presente nos logs e na resposta.

**Eventos registrados**: cadastro (e tentativa com e-mail existente), login com sucesso e com falha
(motivo: conta inexistente ou senha errada), login bloqueado por suspensão, sessão encerrada (e o motivo),
todas as sessões encerradas, reuso de refresh token, e-mail confirmado e falhas de confirmação, pedido,
falha e conclusão de recuperação, troca de senha, estouro de limite de taxa, mudança de estado da conta.

## 8. Acesso administrativo e auditoria

- `GET /admin/security-events?userId=&reason=` exige `ROLE_ADMIN` e um **motivo** (mínimo de 10 caracteres).
  A auditoria é gravada **antes** da leitura.
- `POST /admin/evidence-holds` (com justificativa) e `POST /admin/evidence-holds/{id}/release` (com motivo).
- Mudanças de papel e de estado pelos comandos de console também vão para a auditoria (`actorLabel = console`).
- `admin_audit_log` é **só de inserção**: um gatilho no PostgreSQL recusa `UPDATE`, `DELETE` e `TRUNCATE`.
- Moderadores (da plataforma ou de comunas) não têm acesso a esses endpoints (403), nem a e-mail, IP ou sessões.

## 9. Retenção e expurgo

`php bin/console app:security:purge` (rodar uma vez por dia):

- apaga eventos de segurança mais antigos que `SECURITY_EVENT_RETENTION_DAYS`, **exceto** os de contas
  com preservação de evidências ativa;
- apaga sessões encerradas ou sem uso há mais de 30 dias (e seus refresh tokens);
- apaga códigos de e-mail vencidos há mais de 7 dias.

> **O prazo de produção precisa de validação jurídica antes do lançamento.** O valor padrão (180 dias)
> é provisório e não foi definido por análise legal. Pontos a avaliar com assessoria jurídica, sem
> pretensão de esgotá-los: o Marco Civil da Internet (Lei 12.965/2014, art. 15) pode obrigar certos
> provedores de aplicação a guardar registros de acesso por um prazo mínimo, conforme a forma jurídica
> da mantenedora; a LGPD (Lei 13.709/2018) exige finalidade, necessidade e prazo definidos; pedidos de
> autoridades e preservação judicial têm regras próprias. A política final (prazo, quem acessa, como se
> responde a requisições) deve ser escrita, publicada na política de privacidade e refletida aqui.

## 10. Outras proteções

- Respostas com campos explícitos por público: `Me` (própria conta) ≠ `PublicProfile` ≠ `AuthorView`.
  Testes conferem as chaves exatas das respostas.
- Erros sem detalhes internos em produção (testado com debug desligado). Swagger UI só em desenvolvimento.
- Cabeçalhos: `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY`,
  sem `Server`. Corpo de requisição limitado a 1 MB.
- Texto livre: sem caracteres de controle, normalizado em NFC; o app não renderiza HTML. Consultas sempre
  parametrizadas (Doctrine).
- CORS só para origens configuradas (o app nativo não usa CORS). Sem cookies: não há superfície de CSRF.
- Banco com restrições de defesa em profundidade (sem autobloqueio, contadores não negativos, unicidades).
- `composer audit` faz parte de `composer check`.

## 11. Pendências conhecidas

- TLS fica a cargo do proxy/balanceador em produção (o contêiner fala HTTP).
- Sem 2FA, passkeys, CAPTCHA ou aviso de novo login (a arquitetura permite acrescentar).
- Verificação de senha vazada (k-anonimato) não foi ativada para não depender de serviço externo.
- Exclusão e exportação de dados da conta (LGPD) ainda não implementadas.
- Limpeza das sessões do Keychain após reinstalação no iOS.
