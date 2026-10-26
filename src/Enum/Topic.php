<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Assuntos que a pessoa pode escolher como interesse. Os códigos são os mesmos do app
 * (src/types/models.ts, TopicId). Interesses são dado privado: só aparecem em /me.
 */
enum Topic: string
{
    case Pol = 'pol';
    case Trab = 'trab';
    case Sind = 'sind';
    case Eco = 'eco';
    case Fem = 'fem';
    case Anti = 'anti';
    case Lgbt = 'lgbt';
    case Edu = 'edu';
    case Saude = 'saude';
    case Clima = 'clima';
    case Comun = 'comun';
    case Cult = 'cult';
    case Hist = 'hist';
    case Livros = 'livros';
    case Tec = 'tec';
    case Games = 'games';
    case Esp = 'esp';
    case Humor = 'humor';

    /** @return array{label: string, shortLabel: string, group: string} */
    public function describe(): array
    {
        return match ($this) {
            self::Pol => ['label' => 'Política brasileira', 'shortLabel' => 'Política', 'group' => 'Política e direitos'],
            self::Trab => ['label' => 'Trabalho e direitos trabalhistas', 'shortLabel' => 'Trabalho', 'group' => 'Política e direitos'],
            self::Sind => ['label' => 'Sindicatos e organização popular', 'shortLabel' => 'Sindicatos', 'group' => 'Política e direitos'],
            self::Eco => ['label' => 'Economia e desigualdade', 'shortLabel' => 'Economia', 'group' => 'Política e direitos'],
            self::Fem => ['label' => 'Feminismo', 'shortLabel' => 'Feminismo', 'group' => 'Política e direitos'],
            self::Anti => ['label' => 'Antirracismo', 'shortLabel' => 'Antirracismo', 'group' => 'Política e direitos'],
            self::Lgbt => ['label' => 'Direitos LGBTQIA+', 'shortLabel' => 'LGBTQIA+', 'group' => 'Política e direitos'],
            self::Edu => ['label' => 'Educação', 'shortLabel' => 'Educação', 'group' => 'Bem comum'],
            self::Saude => ['label' => 'Saúde pública', 'shortLabel' => 'Saúde', 'group' => 'Bem comum'],
            self::Clima => ['label' => 'Meio ambiente e clima', 'shortLabel' => 'Clima', 'group' => 'Bem comum'],
            self::Comun => ['label' => 'Projetos comunitários e voluntariado', 'shortLabel' => 'Comunitário', 'group' => 'Bem comum'],
            self::Cult => ['label' => 'Cultura, música e arte', 'shortLabel' => 'Cultura', 'group' => 'Cultura e ideias'],
            self::Hist => ['label' => 'História e filosofia', 'shortLabel' => 'História', 'group' => 'Cultura e ideias'],
            self::Livros => ['label' => 'Livros e clubes de leitura', 'shortLabel' => 'Livros', 'group' => 'Cultura e ideias'],
            self::Tec => ['label' => 'Tecnologia e ciência', 'shortLabel' => 'Tecnologia', 'group' => 'Cultura e ideias'],
            self::Games => ['label' => 'Games', 'shortLabel' => 'Games', 'group' => 'Dia a dia'],
            self::Esp => ['label' => 'Esportes', 'shortLabel' => 'Esportes', 'group' => 'Dia a dia'],
            self::Humor => ['label' => 'Humor e cotidiano', 'shortLabel' => 'Humor', 'group' => 'Dia a dia'],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
