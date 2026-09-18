<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\World\Generation;

use Bedriox\Server\World\Generation\SeededNoise;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SeededNoiseTest extends TestCase
{
    public function testSmoothNoiseIsDeterministicBoundedAndContinuousAcrossNegativeCoordinates(): void
    {
        $first = new SeededNoise(-7_431);
        $second = new SeededNoise(-7_431);
        $previous = null;
        for ($x = -80; $x <= 80; ++$x) {
            $value = $first->fractal2d($x, -17, 96, 4, 53, 11);
            self::assertSame($value, $second->fractal2d($x, -17, 96, 4, 53, 11));
            self::assertGreaterThanOrEqual(-32_768, $value);
            self::assertLessThanOrEqual(32_767, $value);
            if ($previous !== null) {
                self::assertLessThan(4_000, abs($value - $previous));
            }
            $previous = $value;
        }
    }

    public function testThreeDimensionalChannelsAreSeedAndSaltSeparated(): void
    {
        $noise = new SeededNoise(99);
        $base = $noise->sample3d(-31, 14, 72, 32, 5);

        self::assertSame($base, $noise->sample3d(-31, 14, 72, 32, 5));
        self::assertNotSame($base, $noise->sample3d(-31, 14, 72, 32, 6));
        self::assertNotSame($base, (new SeededNoise(100))->sample3d(-31, 14, 72, 32, 5));
    }

    public function testFractalParametersAreBounded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SeededNoise(0))->fractal2d(0, 0, 32, 0, 50);
    }
}
