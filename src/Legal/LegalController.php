<?php

declare(strict_types=1);

namespace App\Legal;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final readonly class LegalController
{
    /** @var array<string, list<array{level: int, text: string}>> */
    private const array PAGES = [
        'privacidade' => [
            ['level' => 1, 'text' => 'Política de Privacidade'],
            ['level' => 2, 'text' => 'Dados e finalidade'],
            ['level' => 0, 'text' => 'Tratamos os dados informados na conta e o conteúdo publicado para operar perfis, Comunas, Praças e Bibliotecas, controlar acesso e proteger a comunidade.'],
            ['level' => 2, 'text' => 'Seus direitos'],
            ['level' => 0, 'text' => 'Você pode pedir acesso, correção, informação ou eliminação dos seus dados pelo suporte.'],
        ],
        'termos' => [
            ['level' => 1, 'text' => 'Termos de Uso'],
            ['level' => 0, 'text' => 'O Popularia reúne pessoas em perfis, Comunas, Praças e Bibliotecas. Proteja suas credenciais e publique apenas conteúdo que você pode compartilhar.'],
            ['level' => 0, 'text' => 'Você mantém os direitos sobre seu conteúdo e autoriza seu uso limitado à operação do serviço.'],
        ],
        'regras' => [
            ['level' => 1, 'text' => 'Regras da Comunidade'],
            ['level' => 0, 'text' => 'Debata ideias sem atacar pessoas. Não aceitamos discriminação, ameaças, assédio, fraude, spam ou incentivo à violência.'],
            ['level' => 0, 'text' => 'Respeite dados pessoais, autoria, licenças e as regras adicionais de cada Comuna.'],
        ],
        'suporte' => [
            ['level' => 1, 'text' => 'Suporte'],
            ['level' => 0, 'text' => 'Fale com a Popularia pelo endereço abaixo. Não envie senha, código de recuperação ou token.'],
        ],
        'excluir-conta' => [
            ['level' => 1, 'text' => 'Excluir a conta'],
            ['level' => 0, 'text' => 'No aplicativo, abra Configurações, escolha Exclusão de conta e siga a confirmação apresentada.'],
            ['level' => 0, 'text' => 'Se não conseguir acessar o aplicativo, fale com o suporte pelo endereço abaixo.'],
        ],
    ];

    public function __construct(private Environment $twig)
    {
    }

    #[Route('/{page}', name: 'legal_page', requirements: ['page' => 'privacidade|termos|regras|suporte|excluir-conta'], methods: ['GET'])]
    public function __invoke(string $page): Response
    {
        $response = new Response($this->twig->render('legal/page.html.twig', [
            'blocks' => self::PAGES[$page],
            'contact' => LegalDocuments::CONTACT,
            'version' => LegalDocuments::VERSION,
        ]));
        $response->setPublic();
        $response->setMaxAge(3600);
        $response->headers->set('Cache-Control', $response->headers->get('Cache-Control').', no-transform');

        return $response;
    }
}
