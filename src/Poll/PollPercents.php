<?php

declare(strict_types=1);

namespace App\Poll;

/**
 * Percentuais inteiros que somam 100 quando há votos, e 0 quando não há.
 * O resto da divisão vai para a maior fração; empate fica com a opção mais votada
 * e, persistindo, com a primeira. A mesma regra está no app, para o voto otimista.
 */
final class PollPercents
{
    /**
     * @param list<int> $counts
     *
     * @return list<int>
     */
    public static function of(array $counts): array
    {
        $total = array_sum($counts);
        $size = \count($counts);
        if ($total <= 0 || 0 === $size) {
            return array_fill(0, $size, 0);
        }

        $floors = array_fill(0, $size, 0);
        $remainders = array_fill(0, $size, 0);
        $assigned = 0;
        foreach ($counts as $index => $count) {
            $floors[$index] = intdiv($count * 100, $total);
            $remainders[$index] = ($count * 100) % $total;
            $assigned += $floors[$index];
        }

        $order = array_keys($counts);
        usort($order, static function (int $a, int $b) use ($remainders, $counts): int {
            if ($remainders[$a] !== $remainders[$b]) {
                return $remainders[$b] <=> $remainders[$a];
            }
            if ($counts[$a] !== $counts[$b]) {
                return $counts[$b] <=> $counts[$a];
            }

            return $a <=> $b;
        });

        $left = 100 - $assigned;
        for ($i = 0; $i < $left; ++$i) {
            ++$floors[$order[$i]];
        }

        return array_values($floors);
    }
}
