<?php

declare(strict_types=1);

namespace App\Api\Post;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Auth\AdminAudit;
use App\Content\ContentPolicy;
use App\Content\TextNormalizer;
use App\Enum\AdminAuditAction;
use App\Repository\PostRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<PostUpdateInput, PostView> */
final readonly class PostUpdateProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private ContentPolicy $policy,
        private PostRepository $posts,
        private PostViews $views,
        private EntityManagerInterface $em,
        private AdminAudit $audit,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PostView
    {
        $actor = $this->currentUser->get();
        $id = (string) ($uriVariables['id'] ?? '');
        $post = Uuid::isValid($id) ? $this->posts->find(Uuid::fromString($id)) : null;
        if (null === $post || !$this->policy->canViewPost($post, $actor)) {
            throw ApiProblem::notFound('Publicação não encontrada.');
        }
        if (!$this->policy->canDeletePost($post, $actor)) {
            throw ApiProblem::forbidden('/errors/not-the-author', 'Só quem publicou pode editar esta publicação.');
        }
        $body = TextNormalizer::normalize($data->body);
        $title = null === $data->title ? null : TextNormalizer::normalize($data->title);
        if ('' === $body && (null === $title || '' === $title)) {
            throw ApiProblem::badRequest('/errors/empty-post', 'Escreva alguma coisa para salvar a publicação.');
        }

        $post->edit($body, '' === $title ? null : $title);
        $this->em->flush();
        $this->audit->record($actor, AdminAuditAction::UpdatePost, $post->getAuthor()->getId(), '', [
            'post_id' => $post->getId()->toRfc4122(),
            'fields' => 'body,title',
        ]);

        return $this->views->one($post, $actor);
    }
}
