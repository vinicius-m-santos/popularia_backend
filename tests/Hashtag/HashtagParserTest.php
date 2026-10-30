<?php

declare(strict_types=1);

namespace App\Tests\Hashtag;

use App\Api\ApiProblem;
use App\Hashtag\HashtagParser;
use PHPUnit\Framework\TestCase;

final class HashtagParserTest extends TestCase
{
    public function testUnicodeBoundariesUrlsAndRepeatedSpellings(): void
    {
        $names = (new HashtagParser())->extract('(@bia) #Tecnologia (#tecnologia) #POLÍTICA #politica #SãoPaulo #2026 #東京 #किताब #___ abc#nada email#teste ### # Veja https://example.com/#fragment');
        self::assertSame(['tecnologia' => 'Tecnologia', 'politica' => 'POLÍTICA', 'saopaulo' => 'SãoPaulo', '2026' => '2026', '東京' => '東京', 'किताब' => 'किताब'], $names);
    }

    public function testLimitsRejectRatherThanAttachPartialTags(): void
    {
        $parser = new HashtagParser();
        try {
            $parser->extract('#'.str_repeat('a', 51));
            self::fail('Long hashtag accepted.');
        } catch (ApiProblem $error) {
            self::assertSame('/errors/hashtag-length', $error->getType());
        }
        try {
            $parser->extract(implode(' ', array_fill(0, 11, '#a')));
            self::fail('Eleven hashtags accepted.');
        } catch (ApiProblem $error) {
            self::assertSame('/errors/hashtag-limit', $error->getType());
        }
    }
}
