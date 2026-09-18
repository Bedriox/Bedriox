<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Generation;

use InvalidArgumentException;

/** Deterministic smooth value-noise source with stable negative-coordinate handling. */
final readonly class SeededNoise
{
    private const int UNIT = 65_536;
    private const int PRIME = 2_147_483_647;

    public function __construct(private int $seed) {}

    /** Returns a linearly interpolated value in approximately [-32768, 32767]. */
    public function sample2d(int $x, int $z, int $scale, int $salt = 0): int
    {
        if ($scale < 1 || $scale > 4096) {
            throw new InvalidArgumentException('Noise scale must be between 1 and 4096.');
        }
        $x0 = self::floorDiv($x, $scale);
        $z0 = self::floorDiv($z, $scale);
        $tx = self::fade(intdiv(($x - $x0 * $scale) * self::UNIT, $scale));
        $tz = self::fade(intdiv(($z - $z0 * $scale) * self::UNIT, $scale));
        $a = self::lerp($this->lattice($x0, $z0, $salt), $this->lattice($x0 + 1, $z0, $salt), $tx);
        $b = self::lerp($this->lattice($x0, $z0 + 1, $salt), $this->lattice($x0 + 1, $z0 + 1, $salt), $tx);

        return self::lerp($a, $b, $tz);
    }

    /** Returns normalized multi-octave noise in approximately [-32768, 32767]. */
    public function fractal2d(
        int $x,
        int $z,
        int $scale,
        int $octaves,
        int $persistencePercent,
        int $salt = 0,
    ): int {
        if ($octaves < 1 || $octaves > 8 || $persistencePercent < 1 || $persistencePercent > 100) {
            throw new InvalidArgumentException('Fractal noise parameters are outside the supported bounds.');
        }
        $weighted = 0;
        $totalWeight = 0;
        $weight = 1_024;
        for ($octave = 0; $octave < $octaves; ++$octave) {
            $weighted += $this->sample2d($x, $z, max(1, $scale), $salt + $octave * 97) * $weight;
            $totalWeight += $weight;
            $scale = max(1, intdiv($scale, 2));
            $weight = max(1, intdiv($weight * $persistencePercent, 100));
        }

        return intdiv($weighted, $totalWeight);
    }

    /** Returns smoothly interpolated three-dimensional noise in approximately [-32768, 32767]. */
    public function sample3d(int $x, int $y, int $z, int $scale, int $salt = 0): int
    {
        if ($scale < 1 || $scale > 4096) {
            throw new InvalidArgumentException('Noise scale must be between 1 and 4096.');
        }
        $x0 = self::floorDiv($x, $scale);
        $y0 = self::floorDiv($y, $scale);
        $z0 = self::floorDiv($z, $scale);
        $tx = self::fade(intdiv(($x - $x0 * $scale) * self::UNIT, $scale));
        $ty = self::fade(intdiv(($y - $y0 * $scale) * self::UNIT, $scale));
        $tz = self::fade(intdiv(($z - $z0 * $scale) * self::UNIT, $scale));

        $x00 = self::lerp($this->lattice3d($x0, $y0, $z0, $salt), $this->lattice3d($x0 + 1, $y0, $z0, $salt), $tx);
        $x10 = self::lerp($this->lattice3d($x0, $y0 + 1, $z0, $salt), $this->lattice3d($x0 + 1, $y0 + 1, $z0, $salt), $tx);
        $x01 = self::lerp($this->lattice3d($x0, $y0, $z0 + 1, $salt), $this->lattice3d($x0 + 1, $y0, $z0 + 1, $salt), $tx);
        $x11 = self::lerp($this->lattice3d($x0, $y0 + 1, $z0 + 1, $salt), $this->lattice3d($x0 + 1, $y0 + 1, $z0 + 1, $salt), $tx);
        $z0Value = self::lerp($x00, $x01, $tz);
        $z1Value = self::lerp($x10, $x11, $tz);

        return self::lerp($z0Value, $z1Value, $ty);
    }

    public function chance(int $x, int $y, int $z, int $salt, int $outOf): int
    {
        if ($outOf < 1) {
            throw new InvalidArgumentException('Chance divisor must be positive.');
        }

        return $this->hash($x, $y, $z, $salt) % $outOf;
    }

    private function lattice(int $x, int $z, int $salt): int
    {
        return ($this->hash($x, 0, $z, $salt) % self::UNIT) - 32_768;
    }

    private function lattice3d(int $x, int $y, int $z, int $salt): int
    {
        return ($this->hash($x, $y, $z, $salt) % self::UNIT) - 32_768;
    }

    private function hash(int $x, int $y, int $z, int $salt): int
    {
        $mixed = (($x % 1_000_003) * 73_856_093)
            + (($y % 1_000_033) * 19_349_663)
            + (($z % 1_000_037) * 83_492_791)
            + (($this->seed % 1_000_081) * 15_485_863)
            + (($salt % 1_000_099) * 49_979_687);
        $mixed %= self::PRIME;
        if ($mixed < 0) {
            $mixed += self::PRIME;
        }
        $mixed = ($mixed * 48_271 + 12_820_163) % self::PRIME;
        $mixed = ($mixed * 16_807 + 2_011_073) % self::PRIME;

        return $mixed;
    }

    private static function lerp(int $a, int $b, int $t): int
    {
        return $a + intdiv(($b - $a) * $t, self::UNIT);
    }

    private static function fade(int $value): int
    {
        $squared = intdiv($value * $value, self::UNIT);

        return intdiv($squared * (3 * self::UNIT - 2 * $value), self::UNIT);
    }

    private static function floorDiv(int $value, int $divisor): int
    {
        $quotient = intdiv($value, $divisor);

        return $value < 0 && $value % $divisor !== 0 ? $quotient - 1 : $quotient;
    }
}
