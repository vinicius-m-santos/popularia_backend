<?php

declare(strict_types=1);

namespace App\Api;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Erro de negócio respondido como application/problem+json (RFC 9457).
 *
 * O campo "type" é estável e é por ele que o app decide o que mostrar; "title" e "detail"
 * são textos para pessoas. Nunca coloque dados internos ou pessoais na mensagem.
 */
final class ApiProblem extends \RuntimeException implements ProblemExceptionInterface, HttpExceptionInterface
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly int $status,
        private readonly string $type,
        private readonly string $title,
        private readonly ?string $detail = null,
        private readonly array $headers = [],
    ) {
        parent::__construct($detail ?? $title);
    }

    public static function invalidCredentials(): self
    {
        return new self(401, '/errors/invalid-credentials', 'E-mail ou senha incorretos.');
    }

    public static function invalidRefreshToken(): self
    {
        return new self(401, '/errors/invalid-refresh-token', 'Sua sessão terminou. Entre novamente.');
    }

    public static function unauthorized(): self
    {
        return new self(401, '/errors/unauthorized', 'É preciso entrar na conta.');
    }

    public static function accountInactive(): self
    {
        return new self(403, '/errors/account-inactive', 'Esta conta está suspensa.', 'Se você acha que houve um engano, fale com a equipe da Popularia.');
    }

    public static function emailNotVerified(): self
    {
        return new self(403, '/errors/email-not-verified', 'Confirme seu e-mail para continuar.', 'Enviamos um código para o seu e-mail no cadastro.');
    }

    public static function invalidCode(): self
    {
        return new self(400, '/errors/invalid-code', 'Código inválido ou expirado.', 'Confira o código ou peça um novo.');
    }

    public static function forbidden(string $type, string $title, ?string $detail = null): self
    {
        return new self(403, $type, $title, $detail);
    }

    public static function notFound(string $title = 'Não encontrado.'): self
    {
        return new self(404, '/errors/not-found', $title);
    }

    public static function conflict(string $type, string $title, ?string $detail = null): self
    {
        return new self(409, $type, $title, $detail);
    }

    public static function badRequest(string $type, string $title, ?string $detail = null): self
    {
        return new self(400, $type, $title, $detail);
    }

    public static function tooManyRequests(int $retryAfterSeconds): self
    {
        return new self(
            429,
            '/errors/too-many-requests',
            'Muitas tentativas. Aguarde um pouco e tente de novo.',
            null,
            ['Retry-After' => (string) max(1, $retryAfterSeconds)],
        );
    }

    public static function serviceUnavailable(): self
    {
        return new self(503, '/errors/service-unavailable', 'Serviço temporariamente indisponível.');
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getDetail(): ?string
    {
        return $this->detail;
    }

    public function getInstance(): ?string
    {
        return null;
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /** Resposta pronta, para quem está fora do fluxo do API Platform (firewall, rotas inexistentes). */
    public function toResponse(): JsonResponse
    {
        $headers = $this->headers + ['Content-Type' => 'application/problem+json'];
        if (401 === $this->status) {
            $headers['WWW-Authenticate'] = 'Bearer';
        }

        $response = new JsonResponse(
            array_filter([
                'type' => $this->type,
                'title' => $this->title,
                'detail' => $this->detail,
                'status' => $this->status,
            ], static fn (mixed $v): bool => null !== $v),
            $this->status,
            $headers,
        );

        return $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }
}
