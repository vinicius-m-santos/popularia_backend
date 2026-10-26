<?php

declare(strict_types=1);

namespace App\Content;

use App\Entity\Comuna;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Endereço da comuna (c/<slug>), gerado do nome no servidor: minúsculas, sem acentos, só letras,
 * números e hífen. Como o nome não muda, o slug também não: links antigos continuam valendo.
 * Dois nomes que geram o mesmo slug ("Hortas ZL" e "hortas-zl") são o mesmo nome para a unicidade.
 */
final class ComunaSlug
{
    public const int MIN_LENGTH = 3;

    /** Endereços que poderiam se passar pela plataforma ou colidir com rotas do app. */
    public const array RESERVED = [
        'popularia', 'admin', 'administracao', 'moderacao', 'suporte', 'ajuda', 'equipe', 'oficial',
        'api', 'sistema', 'seguranca', 'contato', 'me', 'nova', 'novo', 'criar', 'editar', 'gerenciar',
    ];

    public static function fromName(string $name): string
    {
        $slug = (new AsciiSlugger('pt'))->slug($name)->lower()->toString();

        return trim(substr($slug, 0, Comuna::SLUG_MAX_LENGTH), '-');
    }

    /** Mensagem de erro para o campo "name", ou null se o slug serve. */
    public static function problem(string $slug): ?string
    {
        if (\strlen($slug) < self::MIN_LENGTH) {
            return 'Use um nome com pelo menos 3 letras ou números.';
        }
        if (\in_array($slug, self::RESERVED, true)) {
            return 'Esse nome não está disponível.';
        }

        return null;
    }

    /** Nome guardado de forma previsível: NFC, sem espaços nas pontas nem repetidos. */
    public static function normalizeName(string $name): string
    {
        return (string) preg_replace('/\s+/u', ' ', TextNormalizer::normalize($name));
    }
}
