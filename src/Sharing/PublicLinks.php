<?php

declare(strict_types=1);

namespace App\Sharing;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class PublicLinks
{
    public string $origin;

    public function __construct(#[Autowire('%env(PUBLIC_LINK_ORIGIN)%')] string $origin, #[Autowire('%kernel.environment%')] string $environment = 'dev')
    {
        $parts = parse_url($origin);
        if (false === $parts || !isset($parts['host']) || !in_array($parts['scheme'] ?? '', ['https', 'http'], true) || (isset($parts['user']) || isset($parts['pass'])) || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new \InvalidArgumentException('PUBLIC_LINK_ORIGIN deve ser uma origem HTTP(S), sem caminho ou credenciais.');
        }
        if ('prod' === $environment && ('https' !== $parts['scheme'] || in_array($parts['host'], ['localhost', '127.0.0.1', '::1'], true))) {
            throw new \InvalidArgumentException('PUBLIC_LINK_ORIGIN deve usar o host HTTPS público neste ambiente.');
        }
        $this->origin = rtrim($origin, '/');
    }

    public function url(string $type, string $id): string
    {
        return $this->origin.'/'.$type.'/'.rawurlencode(strtolower($id));
    }
}
