<?php

declare(strict_types=1);

namespace App\Auth;

use App\Entity\User;
use App\Repository\UserRepository;

/**
 * Handle inicial para contas sociais: duas palavras em minúsculas, no espírito dos
 * nomes automáticos do Reddit. Sem UUID nem sequência de letras e números.
 */
final readonly class HandleGenerator
{
    private const array ADJECTIVES = [
        'brave', 'calm', 'clever', 'cozy', 'curious', 'daring', 'eager', 'gentle',
        'happy', 'honest', 'kind', 'lively', 'lucky', 'mellow', 'nimble', 'quiet',
        'rapid', 'shy', 'sleepy', 'sunny', 'swift', 'tender', 'witty', 'young',
    ];

    private const array NOUNS = [
        'badger', 'beetle', 'cactus', 'comet', 'coral', 'falcon', 'fern', 'fox',
        'harbor', 'jellyfish', 'koala', 'lemur', 'lotus', 'meadow', 'moth', 'otter',
        'panda', 'pebble', 'pigeon', 'river', 'sparrow', 'tiger', 'turtle', 'willow',
    ];

    public function __construct(private UserRepository $users)
    {
    }

    public function generate(): string
    {
        return $this->generateAvailable($this->users->handleExists(...));
    }

    /** @param callable(string): bool $handleExists */
    public function generateAvailable(callable $handleExists): string
    {
        for ($attempt = 0; $attempt < 30; ++$attempt) {
            $base = self::ADJECTIVES[random_int(0, count(self::ADJECTIVES) - 1)]
                .self::NOUNS[random_int(0, count(self::NOUNS) - 1)];
            $candidate = $attempt < 12 ? $base : $base.random_int(10, 99);

            if (strlen($candidate) <= User::HANDLE_MAX_LENGTH && !$handleExists($candidate)) {
                return $candidate;
            }
        }

        // Extremamente improvável; ainda é um nome legível, não um hash.
        do {
            $candidate = 'popularia'.random_int(1000, 9999);
        } while ($handleExists($candidate));

        return $candidate;
    }
}
