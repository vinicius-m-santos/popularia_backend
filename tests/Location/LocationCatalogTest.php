<?php

declare(strict_types=1);

namespace App\Tests\Location;

use App\Location\LocationCatalog;
use PHPUnit\Framework\TestCase;

final class LocationCatalogTest extends TestCase
{
    public function testStatesAndMunicipalitiesComeFromBundledIbgeData(): void
    {
        $catalog = new LocationCatalog();
        $states = $catalog->states();
        self::assertCount(27, $states);
        self::assertContains('SP', array_column($states, 'abbreviation'));

        $saoPaulo = array_values(array_filter($catalog->municipalities('35'), static fn (array $row): bool => '3550308' === $row['id']));
        self::assertSame('São Paulo', $saoPaulo[0]['name']);
        self::assertTrue($catalog->hasMunicipality('35', '3550308'));
        self::assertFalse($catalog->hasMunicipality('35', '9999999'));
    }

    public function testInvalidStateIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new LocationCatalog())->municipalities('SP');
    }
}
