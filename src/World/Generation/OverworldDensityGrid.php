<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Generation;

/**
 * Compact paired density samples for one chunk-sized generation region.
 *
 * Base density describes the uncarved terrain volume. Carved density describes the
 * final solid volume. Storing both in flat arrays avoids duplicating nested PHP-array
 * structures while allowing water and aquifers to make different decisions.
 */
final readonly class OverworldDensityGrid
{
    private const int HORIZONTAL_SIZE = 5;
    private const int VERTICAL_SIZE = 49;
    private const int VALUE_COUNT = self::HORIZONTAL_SIZE * self::HORIZONTAL_SIZE * self::VERTICAL_SIZE;

    /**
     * @param list<float> $base
     * @param list<float> $carved
     */
    public function __construct(
        private array $base,
        private array $carved,
    ) {
        if (count($base) !== self::VALUE_COUNT || count($carved) !== self::VALUE_COUNT) {
            throw new \InvalidArgumentException('Density grids must contain exactly ' . self::VALUE_COUNT . ' values.');
        }
    }

    /** @return array{float, float, float, float, float, float, float, float} */
    public function baseCorners(int $x, int $y, int $z): array
    {
        return $this->corners($this->base, $x, $y, $z);
    }

    /** @return array{float, float, float, float, float, float, float, float} */
    public function carvedCorners(int $x, int $y, int $z): array
    {
        return $this->corners($this->carved, $x, $y, $z);
    }

    /**
     * @param list<float> $values
     * @return array{float, float, float, float, float, float, float, float}
     */
    private function corners(array $values, int $x, int $y, int $z): array
    {
        if ($x < 0 || $x >= 4 || $z < 0 || $z >= 4 || $y < 0 || $y >= 48) {
            throw new \OutOfBoundsException('Density cell is outside the sampled region.');
        }

        return [
            $values[self::index($x, $y, $z)], $values[self::index($x + 1, $y, $z)],
            $values[self::index($x, $y, $z + 1)], $values[self::index($x + 1, $y, $z + 1)],
            $values[self::index($x, $y + 1, $z)], $values[self::index($x + 1, $y + 1, $z)],
            $values[self::index($x, $y + 1, $z + 1)], $values[self::index($x + 1, $y + 1, $z + 1)],
        ];
    }

    private static function index(int $x, int $y, int $z): int
    {
        return (($x * self::HORIZONTAL_SIZE) + $z) * self::VERTICAL_SIZE + $y;
    }
}
