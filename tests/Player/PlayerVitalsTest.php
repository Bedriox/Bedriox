<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Player;

use Bedriox\Server\Player\PlayerVitals;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PlayerVitalsTest extends TestCase
{
    public function testNutritionClampsAndExhaustionChargesSaturationBeforeFood(): void
    {
        $vitals = new PlayerVitals(food: 10.0, saturation: 2.0, exhaustion: 0.0);
        self::assertTrue($vitals->addNutrition(20.0, 20.0));
        self::assertSame(20.0, $vitals->food);
        self::assertSame(20.0, $vitals->saturation);

        self::assertTrue($vitals->exhaust(6.0));
        self::assertSame(19.0, $vitals->saturation);
        self::assertSame(2.0, $vitals->exhaustion);
        self::assertTrue($vitals->exhaust(6.0));
        self::assertSame(17.0, $vitals->saturation);
        self::assertSame(0.0, $vitals->exhaustion);
        self::assertSame(20.0, $vitals->food);
    }

    public function testInvalidPersistentNutritionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PlayerVitals(exhaustion: 4.0);
    }
}
