<?php

declare(strict_types=1);

namespace App\Legal;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/** HTML público sem cookies, login, JavaScript, analytics ou dependências externas. Markdown é a fonte única. */
final readonly class LegalController
{
    public function __construct(private Environment $twig, #[Autowire('%kernel.project_dir%')] private string $projectDir)
    {
    }

    #[Route('/{page}', name: 'legal_page', requirements: ['page' => 'privacidade|termos|regras|suporte|excluir-conta'], methods: ['GET'])]
    public function page(string $page): Response
    {
        $file = match ($page) {
            'privacidade' => 'privacy-policy',
            'termos' => 'terms',
            'regras' => 'community-guidelines',
            default => 'support',
        };
        $source = file_get_contents($this->projectDir.'/docs/legal/'.$file.'.md');
        if (false === $source) {
            throw new \RuntimeException('Legal document unavailable');
        }
        $blocks = [];
        foreach (preg_split('/\n\s*\n/', trim($source)) ?: [] as $paragraph) {
            $level = str_starts_with($paragraph, '## ') ? 2 : (str_starts_with($paragraph, '# ') ? 1 : 0);
            $blocks[] = ['level' => $level, 'text' => 0 === $level ? $paragraph : substr($paragraph, $level + 1)];
        }
        $response = new Response($this->twig->render('legal/page.html.twig', ['blocks' => $blocks, 'version' => LegalDocuments::VERSION, 'contact' => LegalDocuments::CONTACT]));
        $response->headers->set('Cache-Control', 'public, max-age=300, no-transform');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
