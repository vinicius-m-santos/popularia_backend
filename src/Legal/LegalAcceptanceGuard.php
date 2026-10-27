<?php

declare(strict_types=1);

namespace App\Legal;

use App\Api\ApiProblem;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;

/** Aceite é obrigatório antes de criar/alterar UGC, inclusive por clientes antigos. */
final readonly class LegalAcceptanceGuard
{
    public function __construct(private Security $security)
    {
    }

    #[AsEventListener]
    public function __invoke(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->isMethodSafe() || !preg_match('~^/api/v1/((posts|comments|comunas|squares|library)(/|$)|me(?:/complete-onboarding)?$)~', $request->getPathInfo()) || 'DELETE' === $request->getMethod()) {
            return;
        }
        if (preg_match('~^/api/v1/comunas/[^/]+/(archive|ownership-transfer(?:/(?:accept|decline))?)$~', $request->getPathInfo())) {
            return; // Resolver responsabilidade coletiva e sair não exige aceitar novo contrato.
        }
        $user = $this->security->getUser();
        if ($user instanceof User && !$user->hasAcceptedLegal()) {
            throw ApiProblem::forbidden('/errors/legal-acceptance-required', 'Leia e aceite os Termos e as Regras da Comunidade antes de publicar.');
        }
    }
}
