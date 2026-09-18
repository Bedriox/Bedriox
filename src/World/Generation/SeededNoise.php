<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Generation;

use InvalidArgumentException;

/** Small deterministic integer value-noise source with stable negative-coordinate handling. */
final readonly class SeededNoise
{
    public function __construct(private int $seed) {}

    /** Returns a linearly interpolated value in approximately [-32768, 32767]. */
    public function sample2d(int $x, int $z, int $scale, int $salt = 0): int
    {
        if ($scale < 1 || $scale > 4096) {
            throw new InvalidArgumentException('Noise scale must be between 1 and 4096.');
        }
        $x0 = self::floorDiv($x, $scale);
        $z0 = self::floorDiv($z, $scale);
        $tx = intdiv(($x - $x0 * $scale) * 65_536, $scale);
        $tz = intdiv(($z - $z0 * $scale) * 65_536, $scale);
        $a = self::lerp($this->lattice($x0, $z0, $salt), $this->lattice($x0 + 1, $z0, $salt), $tx);
        $b = self::lerp($this->lattice($x0, $z0 + 1, $salt), $this->lattice($x0 + 1, $z0 + 1, $salt), $tx);

        return self::lerp($a, $b, $tz);
    }

    public function chance(int $x, int $y, int $z, int $salt, int $outOf): int
    {
        if ($outOf < 1) {
            throw new InvalidArgumentException('Chance divisor must be positive.');
        }

        $mixed = ($x * 734_287) + ($y * 912_931) + ($z * 42_349) + ($this->seed * 31) + ($salt * 101);
        $mixed %= 2_147_483_647;
        if ($mixed < 0) {
            $mixed += 2_147_483_647;
        }

        return $mixed % $outOf;
    }

    private function lattice(int $x, int $z, int $salt): int
    {
        $mixed = (($x % 65_536) * 1_103)
            + (($z % 65_536) * 9_176)
            + (($this->seed % 65_536) * 6_121)
            + (($salt % 65_536) * 7_919);
        $mixed %= 65_536;
        if ($mixed < 0) {
            $mixed += 65_536;
        }
        $mixed = ($mixed * 25_173 + 13_849) % 65_536;

        return $mixed - 32_768;
    }

    private static function lerp(int $a, int $b, int $t): int
    {
        return $a + intdiv(($b - $a) * $t, 65_536);
    }

    private static function floorDiv(int $value, int $divisor): int
    {
        $quotient = intdiv($value, $divisor);

        return $value < 0 && $value % $divisor !== 0 ? $quotient - 1 : $quotient;
    }
}
