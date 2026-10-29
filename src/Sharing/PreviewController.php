<?php

declare(strict_types=1);

namespace App\Sharing;

use App\Content\ContentPolicy;
use App\Entity\Comuna;
use App\Entity\Post;
use App\Entity\User;
use App\Repository\UserBlockRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

/** Only anonymously readable data is rendered; identified viewers also respect blocks. */
final readonly class PreviewController
{
    public function __construct(private EntityManagerInterface $em, private ContentPolicy $policy, private PublicLinks $links, private Environment $twig, private CurrentUser $currentUser, private UserBlockRepository $blocks)
    {
    }

    #[Route('/{type}/{id}', name: 'share_preview', requirements: ['type' => 'p|u|c|d', 'id' => '[A-Za-z0-9-]{1,100}'], methods: ['GET', 'HEAD'])]
    public function __invoke(string $type, string $id): Response
    {
        $viewer = $this->currentUser->find();
        $id = strtolower($id);
        $title = 'Conteúdo no Popularia';
        $description = 'Abra no Popularia para verificar a disponibilidade e o acesso a este conteúdo.';
        $body = '';
        $public = false;
        if ('p' === $type && Uuid::isValid($id)) {
            $post = $this->em->find(Post::class, Uuid::fromString($id));
            if (null !== $post && $this->policy->canViewPost($post, null) && $this->policy->canViewPost($post, $viewer)) {
                $public = true;
                $title = 'Publicação de '.$post->getAuthor()->getDisplayName().' — Popularia';
                $body = self::plain($post->getBody(), 2000);
                $description = self::plain($post->getBody(), 180) ?: 'Uma publicação no Popularia.';
            }
        } elseif ('u' === $type && Uuid::isValid($id)) {
            $user = $this->em->find(User::class, Uuid::fromString($id));
            if (null !== $user && $user->isActive() && !$user->isPrivate() && !$this->blocks->isBlockedEitherWay($viewer, $user)) {
                $public = true;
                $title = $user->getDisplayName().' (@'.$user->getHandle().') — Popularia';
                $description = self::plain($user->getBio(), 180) ?: 'Perfil público no Popularia.';
            }
        } elseif ('c' === $type && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $id)) {
            $comuna = $this->em->getRepository(Comuna::class)->findOneBy(['slug' => $id]);
            if (null !== $comuna && $comuna->isPublic()) {
                $public = true;
                $title = $comuna->getName().' — Popularia';
                $description = self::plain($comuna->getDescription(), 180) ?: 'Uma comuna no Popularia.';
            }
        }
        // LibraryAccess::canRead explicitly denies anonymous viewers, even for isPublic.
        // Never expose a PDF title, storage key, signed download URL or existence here.
        if ('d' === $type && Uuid::isValid($id)) {
            $title = 'Documento no Popularia';
            $description = 'Entre no Popularia para consultar este documento conforme suas permissões de acesso.';
        }
        $url = $this->links->url($type, $id);
        $response = new Response($this->twig->render('sharing/preview.html.twig', [
            'title' => self::plain($title, 160), 'description' => $description, 'body' => $body,
            'canonical' => $url, 'image' => $this->links->origin.'/sharing/popularia.png',
            'appUrl' => 'populariamobile://'.$type.'/'.rawurlencode($id), 'public' => $public,
            'kindLabel' => ['p' => 'Publicação', 'u' => 'Perfil', 'c' => 'Comuna', 'd' => 'Documento'][$type],
            'ogType' => $public ? ['p' => 'article', 'u' => 'profile', 'c' => 'website', 'd' => 'website'][$type] : 'website',
        ]), $public || ('d' === $type && Uuid::isValid($id)) ? 200 : 404);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, noarchive');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");

        return $response;
    }

    private static function plain(string $text, int $limit): string
    {
        $text = html_entity_decode(strip_tags($text), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/[\s\p{C}]+/u', ' ', $text) ?? '');

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1).'…' : $text;
    }
}
