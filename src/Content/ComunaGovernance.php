<?php

declare(strict_types=1);

namespace App\Content;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Api\ApiProblem;
use App\Auth\AdminAudit;
use App\Entity\Comment;
use App\Entity\Comuna;
use App\Entity\ComunaJoinRequest;
use App\Entity\ComunaMembership;
use App\Entity\ComunaOwnershipTransfer;
use App\Entity\Post;
use App\Entity\User;
use App\Enum\ComunaRole;
use App\Enum\ComunaVisibility;
use App\Enum\JoinRequestStatus;
use App\Enum\OwnershipTransferStatus;
use App\Gamification\GamificationAction;
use App\Gamification\GamificationService;
use App\Notification\NotificationCreator;
use App\Repository\ComunaJoinRequestRepository;
use App\Repository\ComunaMembershipRepository;
use App\Repository\ComunaOwnershipTransferRepository;
use App\Repository\ComunaRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Tudo o que muda uma comuna, sua participação ou sua propriedade, sempre no servidor:
 * cada operação abre uma transação, trava as linhas que decidem a regra (a comuna e, quando
 * o limite de comunas está em jogo, a conta), confere permissões e estado de novo sob a trava,
 * aplica a mudança e grava a auditoria (ator, comuna, ação, data; sem dados pessoais além do ID).
 *
 * Regras de criação: conta ativa, e-mail confirmado, no máximo Comuna::MAX_ACTIVE_PER_OWNER
 * comunas ativas como proprietária. Arquivar ou excluir libera uma vaga imediatamente.
 */
final readonly class ComunaGovernance
{
    public function __construct(
        private EntityManagerInterface $em,
        private ComunaRepository $comunas,
        private ComunaMembershipRepository $memberships,
        private ComunaJoinRequestRepository $joinRequests,
        private ComunaOwnershipTransferRepository $transfers,
        private UserRepository $users,
        private ComunaModerationPolicy $policy,
        private AdminAudit $audit,
        private ClockInterface $clock,
        private NotificationCreator $notifications,
        private ComunaPins $pins,
        private GamificationService $gamification,
    ) {
    }

    /**
     * Motivo pelo qual $user não pode criar uma comuna agora (null = pode). Mesmas regras que
     * create() confere sob trava; aqui é só para informar o app antes de abrir o formulário.
     */
    public function whyCannotCreate(User $user): ?ApiProblem
    {
        if (!$user->isActive()) {
            return ApiProblem::accountInactive();
        }
        if (!$user->isEmailVerified()) {
            return ApiProblem::emailNotVerified();
        }
        if ($this->comunas->countActiveOwnedBy($user) >= Comuna::MAX_ACTIVE_PER_OWNER) {
            return self::limitReached();
        }

        return null;
    }

    /**
     * @param list<array{title: string, body: string}> $rules
     * @param list<string>                             $topics
     */
    public function create(
        User $owner,
        string $name,
        string $description,
        ComunaVisibility $visibility,
        string $place,
        string $tone,
        array $topics,
        array $rules,
    ): Comuna {
        $name = ComunaSlug::normalizeName($name);
        $slug = ComunaSlug::fromName($name);
        if (null !== $problem = ComunaSlug::problem($slug)) {
            throw self::nameViolation($problem, $name);
        }

        try {
            return $this->transactional(function () use ($owner, $name, $slug, $description, $visibility, $place, $tone, $topics, $rules): Comuna {
                // Trava a conta: duas criações simultâneas da mesma pessoa passam uma de cada vez
                // pelas conferências abaixo (o banco ainda recusa a 4ª comuna ativa por gatilho).
                $this->users->lock($owner);
                if (null !== $problem = $this->whyCannotCreate($owner)) {
                    throw $problem;
                }
                if ($this->comunas->slugExists($slug)) {
                    throw self::nameViolation('Já existe uma comuna com esse nome.', $name);
                }

                $now = $this->clock->now();
                $comuna = new Comuna($slug, $name, TextNormalizer::normalize($description), trim($place), $visibility, $tone, array_values(array_unique($topics)), (int) $now->format('Y'), $now, $owner);
                foreach ($rules as $rule) {
                    $comuna->addRule(TextNormalizer::normalize($rule['title']), TextNormalizer::normalize($rule['body']));
                }
                $owner->recordComunaCreation($now);
                // ComunaSquareLifecycle inclui Praça e pacote neste mesmo flush/transação.
                $this->em->persist($comuna);
                $this->em->persist(new ComunaMembership($comuna, $owner, ComunaRole::Founder, $now));
                $this->em->flush();
                $this->comunas->adjustMemberCount($comuna, +1);
                $this->record($owner, 'comuna.created', $comuna, null, ['visibility' => $visibility->value]);

                return $comuna;
            });
        } catch (UniqueConstraintViolationException) {
            // Outra pessoa criou o mesmo endereço entre a conferência e a gravação.
            throw self::nameViolation('Já existe uma comuna com esse nome.', $name);
        } catch (DriverException $e) {
            if (str_contains($e->getMessage(), 'comuna_owner_limit_reached')) {
                throw self::limitReached();
            }
            throw $e;
        }
    }

    /** @param list<array{title: string, body: string}>|null $rules */
    public function updateDetails(Comuna $comuna, User $actor, ?string $description, ?array $rules, ?string $topic = null): void
    {
        $this->transactional(function () use ($comuna, $actor, $description, $rules, $topic): void {
            $this->comunas->lock($comuna);
            $this->policy->assertOwner($comuna, $actor);
            $this->policy->assertNotArchived($comuna);
            $changed = [];
            if (null !== $topic) {
                $comuna->changeTopic($topic);
                $changed[] = 'topic';
            }
            if (null !== $description) {
                $comuna->changeDescription(TextNormalizer::normalize($description));
                $changed[] = 'description';
            }
            if (null !== $rules) {
                $comuna->replaceRules(array_map(static fn (array $r) => [
                    'title' => TextNormalizer::normalize($r['title']),
                    'body' => TextNormalizer::normalize($r['body']),
                ], $rules));
                $changed[] = 'rules';
            }
            $this->em->flush();
            $this->record($actor, 'comuna.details_updated', $comuna, null, ['fields' => implode(',', $changed)]);
        });
    }

    /**
     * Arquiva (definitivo pelo app). Pedidos de entrada e proposta de transferência em aberto são
     * encerrados. Ninguém é removido: membros continuam lendo o histórico, e a comuna privada
     * continua fechada para quem não participava.
     */
    public function archive(Comuna $comuna, User $actor): void
    {
        $this->transactional(function () use ($comuna, $actor): void {
            $this->comunas->lock($comuna);
            $this->policy->assertOwner($comuna, $actor);
            if ($comuna->isArchived()) {
                return;
            }
            $now = $this->clock->now();
            // O mesmo flush sincroniza status/closedAt da Praça estrutural.
            $comuna->archive($now);
            foreach ($this->joinRequests->findBy(['comuna' => $comuna, 'status' => JoinRequestStatus::Pending]) as $request) {
                $request->close(JoinRequestStatus::Cancelled, $now);
            }
            $this->transfers->findPending($comuna)?->close(OwnershipTransferStatus::Cancelled, $now);
            $this->em->flush();
            $this->record($actor, 'comuna.archived', $comuna);
        });
    }

    /**
     * Exclusão definitiva, só quando a pessoa proprietária é o único membro e não há conteúdo
     * de outras pessoas nem conteúdo removido pela moderação (ver ComunaRepository). As condições
     * são conferidas com a comuna travada, então uma entrada simultânea não passa despercebida.
     *
     * Sai junto (FK ON DELETE CASCADE): regras, a participação da proprietária, as publicações e
     * comentários dela nesta comuna, pedidos de entrada e propostas de transferência. Fica: a
     * auditoria (sem FK para a comuna) e os registros de segurança (ligados a contas, não a comunas).
     * O slug e a vaga no limite ficam livres para uma nova comuna imediatamente.
     */
    public function delete(Comuna $comuna, User $actor): void
    {
        $this->transactional(function () use ($comuna, $actor): void {
            $this->comunas->lock($comuna);
            $this->policy->assertOwner($comuna, $actor);
            if ($comuna->isArchived()) {
                throw ApiProblem::conflict('/errors/comuna-archived', 'Comunas arquivadas não podem ser excluídas pelo app.', 'Se precisar, fale com a equipe da Popularia.');
            }
            if ($this->memberships->countOthers($comuna, $actor) > 0) {
                throw ApiProblem::conflict('/errors/comuna-has-members', 'Esta comuna tem outros membros e não pode ser excluída.', 'Você pode arquivá-la: o histórico fica preservado, ninguém é removido e nada novo pode ser publicado.');
            }
            if ($this->comunas->hasContentBlockingDeletion($comuna, $actor)) {
                throw ApiProblem::conflict('/errors/comuna-has-content', 'Esta comuna tem publicações ou comentários de outras pessoas e não pode ser excluída.', 'Você pode arquivá-la: o histórico fica preservado e nada novo pode ser publicado.');
            }
            $this->record($actor, 'comuna.deleted', $comuna, null, ['name' => $comuna->getName()]);
            $this->em->remove($comuna);
            $this->em->flush();
        });
    }

    /** Entrar numa comuna pública (idempotente). */
    public function join(Comuna $comuna, User $user): ComunaMembership
    {
        return $this->transactional(function () use ($comuna, $user): ComunaMembership {
            $this->comunas->lock($comuna);
            $membership = $this->memberships->findMembership($comuna, $user);
            if (null !== $membership) {
                return $membership;
            }
            $this->policy->assertNotArchived($comuna);
            if (!$comuna->isPublic()) {
                throw ApiProblem::forbidden('/errors/comuna-requires-approval', 'Esta comuna é privada.', 'Peça para entrar: a moderação da comuna aprova ou recusa (PUT /comunas/{slug}/join-request).');
            }
            $now = $this->clock->now();
            $this->gamification->prepare($user, $now);
            $membership = new ComunaMembership($comuna, $user, ComunaRole::Member, $now);
            $this->em->persist($membership);
            $this->em->flush();
            $this->comunas->adjustMemberCount($comuna, +1);
            $this->gamification->record($user, GamificationAction::ComunaJoined, $comuna->getId(), $now);

            return $membership;
        });
    }

    /** Sair (idempotente). A pessoa proprietária não sai: transfere ou arquiva antes. */
    public function leave(Comuna $comuna, User $user): void
    {
        $this->transactional(function () use ($comuna, $user): void {
            $this->comunas->lock($comuna);
            $membership = $this->memberships->findMembership($comuna, $user);
            if (null === $membership) {
                return;
            }
            if (ComunaRole::Founder === $membership->getRole() || $comuna->isOwnedBy($user)) {
                throw ApiProblem::conflict('/errors/founder-cannot-leave', 'Quem é proprietário da comuna não pode sair por aqui.', 'Transfira a comuna para outra pessoa ou arquive-a antes.');
            }
            $this->endMembership($comuna, $membership);
        });
    }

    /** Pedido de entrada numa comuna privada (idempotente enquanto estiver em aberto). */
    public function requestToJoin(Comuna $comuna, User $user): ComunaJoinRequest
    {
        return $this->transactional(function () use ($comuna, $user): ComunaJoinRequest {
            $this->comunas->lock($comuna);
            $this->policy->assertNotArchived($comuna);
            if ($comuna->isPublic()) {
                throw ApiProblem::conflict('/errors/join-request-not-needed', 'Esta comuna é pública: dá para entrar direto.');
            }
            if (null !== $this->memberships->findMembership($comuna, $user)) {
                throw ApiProblem::conflict('/errors/already-member', 'Você já participa desta comuna.');
            }
            $request = $this->joinRequests->findPending($comuna, $user);
            if (null === $request) {
                $request = new ComunaJoinRequest($comuna, $user, $this->clock->now());
                $this->em->persist($request);
                $this->notifications->joinRequested($request);
                $this->em->flush();
            }

            return $request;
        });
    }

    public function cancelJoinRequest(Comuna $comuna, User $user): void
    {
        $this->transactional(function () use ($comuna, $user): void {
            $this->comunas->lock($comuna);
            $this->joinRequests->findPending($comuna, $user)?->close(JoinRequestStatus::Cancelled, $this->clock->now());
            $this->em->flush();
        });
    }

    /** Aprova (cria a participação) ou recusa um pedido. Só a moderação da comuna. */
    public function decideJoinRequest(Comuna $comuna, User $actor, string $requestId, bool $approve): ComunaJoinRequest
    {
        return $this->transactional(function () use ($comuna, $actor, $requestId, $approve): ComunaJoinRequest {
            $this->comunas->lock($comuna);
            $this->policy->assertModerator($comuna, $actor);
            $this->policy->assertNotArchived($comuna);
            $request = $this->joinRequests->findInComuna($comuna, $requestId) ?? throw ApiProblem::notFound('Pedido não encontrado.');
            if (!$request->isPending()) {
                throw ApiProblem::conflict('/errors/join-request-closed', 'Este pedido já foi decidido ou retirado.');
            }
            $now = $this->clock->now();
            $requester = $request->getUser();
            if ($approve && $requester->isActive()) {
                if (null === $this->memberships->findMembership($comuna, $requester)) {
                    $this->gamification->prepare($requester, $now);
                    $this->em->persist(new ComunaMembership($comuna, $requester, ComunaRole::Member, $now));
                    $this->em->flush();
                    $this->comunas->adjustMemberCount($comuna, +1);
                    $this->gamification->record($requester, GamificationAction::ComunaJoined, $comuna->getId(), $now);
                }
                $request->close(JoinRequestStatus::Approved, $now);
            } else {
                $request->close(JoinRequestStatus::Rejected, $now);
            }
            $this->em->flush();
            $action = JoinRequestStatus::Approved === $request->getStatus() ? 'comuna.join_request_approved' : 'comuna.join_request_rejected';
            $this->record($actor, $action, $comuna, $requester);
            $this->notifications->joinDecided($request, $actor);

            return $request;
        });
    }

    /**
     * Remove alguém da comuna. A moderação remove membros comuns; o proprietário remove também
     * quem modera. Ninguém remove o proprietário. Vale também em comunas arquivadas (segurança).
     * O acesso a conteúdo privado acaba na hora: toda leitura confere a participação no banco.
     */
    public function removeMember(Comuna $comuna, User $actor, User $target): void
    {
        $this->transactional(function () use ($comuna, $actor, $target): void {
            $this->comunas->lock($comuna);
            $this->policy->assertModerator($comuna, $actor);
            $membership = $this->memberships->findMembership($comuna, $target) ?? throw ApiProblem::notFound('Esta pessoa não participa da comuna.');
            if (ComunaRole::Founder === $membership->getRole() || $comuna->isOwnedBy($target)) {
                throw ApiProblem::forbidden('/errors/cannot-remove-owner', 'Ninguém pode remover quem é proprietário da comuna.');
            }
            if ($target->getId()->equals($actor->getId())) {
                throw ApiProblem::badRequest('/errors/cannot-remove-self', 'Para sair da comuna, use "Sair".');
            }
            if (ComunaRole::Moderator === $membership->getRole() && !$comuna->isOwnedBy($actor)) {
                throw ApiProblem::forbidden('/errors/not-comuna-owner', 'Só quem é proprietário remove alguém da moderação.');
            }
            $this->endMembership($comuna, $membership);
            $this->record($actor, 'comuna.member_removed', $comuna, $target, ['role' => $membership->getRole()->value]);
        });
    }

    /** Nomeia (true) ou tira (false) alguém da moderação. Só o proprietário; idempotente. */
    public function setModerator(Comuna $comuna, User $actor, User $target, bool $moderator): ComunaMembership
    {
        return $this->transactional(function () use ($comuna, $actor, $target, $moderator): ComunaMembership {
            $this->comunas->lock($comuna);
            $this->policy->assertOwner($comuna, $actor);
            if ($moderator) {
                $this->policy->assertNotArchived($comuna);
            }
            $membership = $this->memberships->findMembership($comuna, $target)
                ?? throw ApiProblem::conflict('/errors/target-not-member', 'Esta pessoa não participa da comuna.');
            if (ComunaRole::Founder === $membership->getRole()) {
                throw ApiProblem::forbidden('/errors/cannot-change-owner-role', 'O papel de quem é proprietário só muda por transferência.');
            }
            $role = $moderator ? ComunaRole::Moderator : ComunaRole::Member;
            if ($membership->getRole() !== $role) {
                $membership->changeRole($role);
                $this->em->flush();
                $this->record($actor, $moderator ? 'comuna.moderator_appointed' : 'comuna.moderator_dismissed', $comuna, $target);
            }

            return $membership;
        });
    }

    /** Propõe passar a comuna para outro membro. Nada muda até o aceite. Substitui uma proposta anterior. */
    public function proposeTransfer(Comuna $comuna, User $actor, User $target): ComunaOwnershipTransfer
    {
        return $this->transactional(function () use ($comuna, $actor, $target): ComunaOwnershipTransfer {
            $this->comunas->lock($comuna);
            $this->policy->assertOwner($comuna, $actor);
            $this->policy->assertNotArchived($comuna);
            if ($target->getId()->equals($actor->getId())) {
                throw ApiProblem::badRequest('/errors/transfer-to-self', 'A comuna já é sua.');
            }
            if (null === $this->memberships->findMembership($comuna, $target) || !$target->isActive()) {
                throw ApiProblem::conflict('/errors/target-not-member', 'Só dá para transferir para alguém que participa da comuna.');
            }
            $current = $this->transfers->findPending($comuna);
            if (null !== $current && $current->getToUser()->getId()->equals($target->getId())) {
                return $current;
            }
            $now = $this->clock->now();
            if (null !== $current) {
                $current->close(OwnershipTransferStatus::Cancelled, $now);
                $this->notifications->ownershipChanged($current);
                // O índice parcial só aceita uma proposta em aberto: fecha a antiga antes de gravar a nova.
                $this->em->flush();
            }
            $transfer = new ComunaOwnershipTransfer($comuna, $actor, $target, $now);
            $this->em->persist($transfer);
            $this->notifications->ownershipChanged($transfer);
            $this->em->flush();
            $this->record($actor, 'comuna.ownership_transfer_proposed', $comuna, $target);

            return $transfer;
        });
    }

    public function cancelTransfer(Comuna $comuna, User $actor): void
    {
        $this->transactional(function () use ($comuna, $actor): void {
            $this->comunas->lock($comuna);
            $this->policy->assertOwner($comuna, $actor);
            $transfer = $this->transfers->findPending($comuna);
            if (null !== $transfer) {
                $transfer->close(OwnershipTransferStatus::Cancelled, $this->clock->now());
                $this->notifications->ownershipChanged($transfer);
                $this->em->flush();
                $this->record($actor, 'comuna.ownership_transfer_cancelled', $comuna, $transfer->getToUser());
            }
        });
    }

    /**
     * Resposta de quem recebeu a proposta. No aceite, a propriedade passa de uma vez (mesma
     * transação): comuna.owner, o papel "founder" da nova pessoa e "member" da anterior.
     */
    public function respondToTransfer(Comuna $comuna, User $recipient, bool $accept): ComunaOwnershipTransfer
    {
        return $this->transactional(function () use ($comuna, $recipient, $accept): ComunaOwnershipTransfer {
            $this->comunas->lock($comuna);
            $transfer = $this->transfers->findPending($comuna);
            if (null === $transfer || !$transfer->getToUser()->getId()->equals($recipient->getId())) {
                throw ApiProblem::notFound('Nenhuma transferência pendente para você nesta comuna.');
            }
            $now = $this->clock->now();
            if (!$accept) {
                $transfer->close(OwnershipTransferStatus::Declined, $now);
                $this->notifications->ownershipChanged($transfer);
                $this->em->flush();
                $this->record($recipient, 'comuna.ownership_transfer_declined', $comuna, $transfer->getFromUser());

                return $transfer;
            }
            if (!$recipient->isEmailVerified()) {
                throw ApiProblem::emailNotVerified();
            }
            // A proposta só vale se quem propôs ainda é o proprietário e a comuna está ativa.
            if (!$comuna->isOwnedBy($transfer->getFromUser()) || $comuna->isArchived()) {
                throw ApiProblem::conflict('/errors/transfer-stale', 'Esta proposta não vale mais.');
            }
            $this->changeOwner($comuna, $recipient);
            $transfer->close(OwnershipTransferStatus::Accepted, $now);
            $this->notifications->ownershipChanged($transfer);
            $this->em->flush();
            $this->record($recipient, 'comuna.ownership_transferred', $comuna, $transfer->getFromUser(), ['from' => $transfer->getFromUser()->getId()->toRfc4122()]);

            return $transfer;
        });
    }

    /**
     * Transferência feita pela equipe da plataforma (comando app:comuna:transfer-owner), por
     * exemplo quando a conta proprietária foi suspensa. Mesmas garantias da transferência
     * aceita no app, com motivo registrado na auditoria.
     */
    public function transferByPlatform(Comuna $comuna, User $newOwner, string $reason): void
    {
        $this->transactional(function () use ($comuna, $newOwner, $reason): void {
            $this->comunas->lock($comuna);
            if ($comuna->isOwnedBy($newOwner)) {
                return;
            }
            $previous = $comuna->getOwner();
            $this->changeOwner($comuna, $newOwner);
            $this->transfers->findPending($comuna)?->close(OwnershipTransferStatus::Cancelled, $this->clock->now());
            $this->em->flush();
            $this->audit->record(null, 'comuna.ownership_transferred', $newOwner->getId(), $reason, [
                'comuna_id' => $comuna->getId()->toRfc4122(),
                'slug' => $comuna->getSlug(),
                'from' => $previous?->getId()->toRfc4122(),
            ]);
        });
    }

    /** Remove uma publicação da comuna (moderação). O texto fica guardado, sem exibição. */
    public function removePost(Post $post, User $actor, string $reason): void
    {
        $comuna = $post->getComuna() ?? throw ApiProblem::forbidden('/errors/not-comuna-moderator', 'Publicações em perfis não são moderadas por comunas.');
        if ($comuna->isSuspended()) {
            throw ComunaModerationPolicy::suspended();
        }
        $this->transactional(function () use ($post, $comuna, $actor, $reason): void {
            $this->assertCanRemoveContent($comuna, $actor, $post->getAuthor());
            if (!$post->isPublished()) {
                return;
            }
            $post->removeByModeration();
            $this->pins->release($post);
            $this->em->flush();
            $this->record($actor, 'comuna.post_removed', $comuna, $post->getAuthor(), ['post_id' => $post->getId()->toRfc4122()], $reason);
        });
    }

    public function removeComment(Comment $comment, User $actor, string $reason): void
    {
        $comuna = $comment->getPost()->getComuna() ?? throw ApiProblem::forbidden('/errors/not-comuna-moderator', 'Conversas em perfis não são moderadas por comunas.');
        if ($comuna->isSuspended()) {
            throw ComunaModerationPolicy::suspended();
        }
        $this->transactional(function () use ($comment, $comuna, $actor, $reason): void {
            $this->assertCanRemoveContent($comuna, $actor, $comment->getAuthor());
            if (!$comment->isPublished()) {
                return;
            }
            $comment->removeByModeration();
            $this->em->flush();
            $this->record($actor, 'comuna.comment_removed', $comuna, $comment->getAuthor(), ['comment_id' => $comment->getId()->toRfc4122()], $reason);
        });
    }

    private function assertCanRemoveContent(Comuna $comuna, User $actor, User $author): void
    {
        $membership = $this->policy->assertModerator($comuna, $actor);
        if (!$this->policy->canRemoveContentBy($comuna, $membership, $author)) {
            throw ApiProblem::forbidden('/errors/cannot-moderate-content', 'Você não pode remover este conteúdo.', 'O próprio conteúdo se apaga pela autoria; o conteúdo de quem é proprietário só sai por decisão do proprietário ou da equipe.');
        }
    }

    /**
     * Troca o proprietário. Trava a conta que recebe (o limite de comunas ativas vale para ela
     * também) e muda os papéis em duas etapas gravadas na ordem certa: o banco aceita só um
     * "founder" por comuna, então a pessoa anterior vira "member" antes da nova virar "founder".
     */
    private function changeOwner(Comuna $comuna, User $newOwner): void
    {
        $newMembership = $this->memberships->findMembership($comuna, $newOwner);
        if (null === $newMembership || !$newOwner->isActive()) {
            throw ApiProblem::conflict('/errors/target-not-member', 'A nova pessoa proprietária precisa participar da comuna.');
        }
        $this->users->lock($newOwner);
        if (!$comuna->isArchived() && $this->comunas->countActiveOwnedBy($newOwner) >= Comuna::MAX_ACTIVE_PER_OWNER) {
            throw self::limitReached();
        }
        $previous = $comuna->getOwner();
        $previousMembership = null === $previous ? null : $this->memberships->findMembership($comuna, $previous);
        $previousMembership?->changeRole(ComunaRole::Member);
        $this->em->flush();
        $newMembership->changeRole(ComunaRole::Founder);
        $comuna->changeOwner($newOwner);
        $this->em->flush();
    }

    /** Fim de uma participação: some a linha, o total diminui e uma proposta para a pessoa cai. */
    private function endMembership(Comuna $comuna, ComunaMembership $membership): void
    {
        $transfer = $this->transfers->findPending($comuna);
        if (null !== $transfer && $transfer->getToUser()->getId()->equals($membership->getUser()->getId())) {
            $transfer->close(OwnershipTransferStatus::Cancelled, $this->clock->now());
        }
        $this->em->remove($membership);
        $this->em->flush();
        $this->comunas->adjustMemberCount($comuna, -1);
    }

    /**
     * Executa $operation numa transação e grava o que ficou pendente. Diferente de
     * EntityManager::wrapInTransaction, uma recusa (ApiProblem) só desfaz a transação, sem
     * fechar o EntityManager para o resto da requisição.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function transactional(callable $operation): mixed
    {
        return $this->em->getConnection()->transactional(function () use ($operation): mixed {
            $result = $operation();
            $this->em->flush();

            return $result;
        });
    }

    /** @param array<string, scalar|null> $details */
    private function record(User $actor, string $action, Comuna $comuna, ?User $target = null, array $details = [], string $reason = ''): void
    {
        $this->audit->record($actor, $action, $target?->getId(), $reason, [
            'comuna_id' => $comuna->getId()->toRfc4122(),
            'slug' => $comuna->getSlug(),
        ] + $details);
    }

    private static function limitReached(): ApiProblem
    {
        return ApiProblem::conflict(
            '/errors/comuna-limit-reached',
            \sprintf('Cada pessoa pode ter até %d comunas ativas.', Comuna::MAX_ACTIVE_PER_OWNER),
            'Arquive uma comuna ou transfira a propriedade dela para liberar uma vaga.',
        );
    }

    private static function nameViolation(string $message, string $name): ValidationException
    {
        return new ValidationException(new ConstraintViolationList([new ConstraintViolation($message, null, [], null, 'name', $name)]));
    }
}
