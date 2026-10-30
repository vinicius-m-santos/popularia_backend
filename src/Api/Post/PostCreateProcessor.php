<?php

declare(strict_types=1);

namespace App\Api\Post;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Api\ApiProblem;
use App\Content\ContentPolicy;
use App\Content\TextNormalizer;
use App\Entity\ComunaEvent;
use App\Entity\Poll;
use App\Entity\Post;
use App\Enum\Topic;
use App\Mention\MentionBinder;
use App\Notification\NotificationCreator;
use App\Repository\ComunaRepository;
use App\Security\CurrentUser;
use App\Security\RateLimitGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/** @implements ProcessorInterface<PostInput, PostView> */
final readonly class PostCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private ComunaRepository $comunas,
        private ContentPolicy $policy,
        private \App\Content\RemovedContentFilter $filter,
        private \App\Library\LibraryAccess $library,
        private PostViews $views,
        private RateLimitGuard $rateLimit,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private MentionBinder $mentions,
        private NotificationCreator $notifications,
        private \App\Repository\ComunaEventRepository $events,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PostView
    {
        return $this->em->getConnection()->transactional(function () use ($data): PostView {
            $author = $this->currentUser->get();
            $comuna = null;
            if (null !== $data->comunaId) {
                $comuna = $this->comunas->find(Uuid::fromString($data->comunaId)) ?? throw ApiProblem::notFound('Comuna não encontrada.');
            }
            if (null !== $comuna && null !== $data->topic) {
                throw ApiProblem::badRequest('/errors/inherited-topic', 'O assunto é herdado da comuna.');
            }
            $this->policy->assertCanPostIn($comuna, $author);
            $this->rateLimit->consume('content_creation', $author->getUserIdentifier());

            $this->filter->assertText($data->body, $comuna);
            $body = TextNormalizer::normalize($data->body);
            $post = new Post($author, $comuna, $body, $this->clock->now());
            $post->setTopic(null === $data->topic ? null : Topic::from($data->topic));
            if (null !== $data->documentId) {
                $document = $this->library->find($data->documentId);
                $this->em->refresh($document, \Doctrine\DBAL\LockMode::PESSIMISTIC_READ);
                $this->library->assertRead($document, $author);
                $post->libraryDocument = $document;
            }
            if (null !== $data->mediaId) {
                if (!Uuid::isValid($data->mediaId)) {
                    throw ApiProblem::badRequest('/errors/post-media', 'Mídia inválida.');
                }
                $media = $this->em->find(\App\Entity\PostMedia::class, Uuid::fromString($data->mediaId), \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
                if (null === $media || !$media->uploadedBy->getId()->equals($author->getId()) || 'ready' !== $media->status) {
                    throw ApiProblem::notFound('Mídia não encontrada.');
                }
                $alreadyAttached = $this->em->getRepository(Post::class)->findOneBy(['media' => $media]);
                if (null !== $alreadyAttached) {
                    throw ApiProblem::conflict('/errors/post-media-attached', 'Esta mídia já foi publicada.');
                }
                $post->media = $media;
            }
            if (null !== $data->poll) {
                $this->attachPoll($post, $data->poll);
            }
            if (null !== $data->eventId) {
                $this->attachEvent($post, $data->eventId, $author);
            }
            $mentions = $this->mentions->attach($body, $data->mentions, $post);
            $this->em->persist($post);
            foreach ($mentions as $mention) {
                $this->em->persist($mention);
            }
            $this->notifications->mentionedInPost($post, array_map(static fn ($mention) => $mention->getMentionedUser(), $mentions));
            $this->em->flush();

            return $this->views->one($post, $author);
        });
    }

    private function attachEvent(Post $post, string $eventId, \App\Entity\User $author): void
    {
        if (!Uuid::isValid($eventId)) {
            throw ApiProblem::badRequest('/errors/event-link', 'Evento inválido.');
        }
        $event = $this->events->find(Uuid::fromString($eventId));
        $comuna = $post->getComuna();
        if (!$event instanceof ComunaEvent || null === $comuna || !$event->getComuna()->getId()->equals($comuna->getId()) || !$this->policy->canReadComuna($comuna, $author)) {
            throw ApiProblem::badRequest('/errors/event-link', 'Esse evento não pertence a esta comuna.');
        }
        $post->attachEvent($event);
    }

    private function attachPoll(Post $post, PollInput $input): void
    {
        $question = TextNormalizer::normalize($input->question);
        $labels = [];
        $seen = [];
        foreach ($input->options as $index => $option) {
            $label = TextNormalizer::normalize($option->label);
            $key = mb_strtolower($label);
            if (isset($seen[$key])) {
                throw new ValidationException(new ConstraintViolationList([new ConstraintViolation('As opções precisam ser diferentes.', null, [], $input, 'poll.options['.$index.'].label', $option->label)]));
            }
            $seen[$key] = true;
            $labels[] = $label;
            $this->filter->assertText($label, $post->getComuna());
        }
        $this->filter->assertText($question, $post->getComuna());
        new Poll($post, $question, $labels);
    }
}
