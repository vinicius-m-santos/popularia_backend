<?php

declare(strict_types=1);

namespace App\Sharing;

use App\Api\ApiProblem;
use App\Content\ContentPolicy;
use App\Entity\Post;
use App\Library\ObjectStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Capa pública de uma publicação para cartões de compartilhamento.
 *
 * Mesma visibilidade da prévia: só serve o arquivo quando a publicação é visível
 * anonimamente. A rota é estável porque o cartão OG é buscado por robôs, e URL assinada
 * expiraria antes da leitura.
 */
final readonly class PostImageController
{
    public function __construct(private EntityManagerInterface $em, private ContentPolicy $policy, private ObjectStorage $storage)
    {
    }

    #[Route('/p/{id}/image', name: 'share_post_image', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET', 'HEAD'])]
    public function __invoke(string $id): Response
    {
        $post = Uuid::isValid($id) ? $this->em->find(Post::class, Uuid::fromString($id)) : null;
        if (!$post instanceof Post || !$this->policy->canViewPost($post, null)) {
            throw ApiProblem::notFound();
        }
        $media = $post->media;
        // Vídeo não vira og:image: o rasterizador do cartão precisa de uma imagem.
        if (null === $media || 'image' !== $media->kind) {
            throw ApiProblem::notFound();
        }
        try {
            $stream = $this->storage->read($media->storageKey);
        } catch (\Throwable) {
            throw ApiProblem::notFound();
        }

        // Imagem pequena: carregar em memória mantém a resposta testável e evita stream preso.
        $content = (string) stream_get_contents($stream);
        fclose($stream);

        return new Response($content, 200, [
            'Content-Type' => $media->mimeType,
            'Content-Length' => (string) strlen($content),
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
