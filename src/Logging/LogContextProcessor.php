<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Para todos os logs: acrescenta o request_id e apaga valores de chaves sensíveis
 * (senhas, tokens, códigos, e-mails, cabeçalhos de autenticação), mesmo se alguém as
 * passar no contexto por engano. Não acrescenta IP: IPs só ficam no registro de segurança.
 */
#[AsMonologProcessor]
final class LogContextProcessor implements ProcessorInterface
{
    private const string SENSITIVE_KEYS = '/pass(word)?|token|secret|authorization|cookie|(^|_)code$|e-?mail|cpf/i';

    public function __construct(
        private readonly RequestStack $requests,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;
        $requestId = $this->requests->getMainRequest()?->attributes->get(RequestIdSubscriber::ATTRIBUTE);
        if (\is_string($requestId)) {
            $extra['request_id'] = $requestId;
        }

        return $record->with(context: self::redact($record->context), extra: $extra);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private static function redact(array $data, int $depth = 0): array
    {
        foreach ($data as $key => $value) {
            if (\is_string($key) && 1 === preg_match(self::SENSITIVE_KEYS, $key)) {
                $data[$key] = '[redacted]';
            } elseif (\is_array($value) && $depth < 5) {
                $data[$key] = self::redact($value, $depth + 1);
            }
        }

        return $data;
    }
}
