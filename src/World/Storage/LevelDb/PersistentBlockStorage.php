<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Storage\LevelDb;

use Bedriox\Data\PersistentBlockState;
use InvalidArgumentException;

/** Immutable persistent block palette with Bedriox's in-memory YZX index order. */
final readonly class PersistentBlockStorage
{
    public const int ENTRY_COUNT = 4096;

    /** @var list<PersistentBlockState> */
    private array $palette;

    /** @var list<int> */
    private array $indices;

    /**
     * @param list<mixed> $palette
     * @param list<int>   $indices
     */
    public function __construct(array $palette, array $indices)
    {
        if ($palette === [] || count($palette) > self::ENTRY_COUNT) {
            throw new InvalidArgumentException('Persistent block palette must contain between 1 and 4096 states.');
        }
        if (count($indices) !== self::ENTRY_COUNT) {
            throw new InvalidArgumentException('Persistent block storage must contain exactly 4096 indices.');
        }
        foreach ($palette as $state) {
            if (!$state instanceof PersistentBlockState) {
                throw new InvalidArgumentException('Persistent block palette contains an invalid state.');
            }
        }
        foreach ($indices as $index) {
            if ($index < 0 || $index >= count($palette)) {
                throw new InvalidArgumentException('Persistent block storage contains an out-of-range palette index.');
            }
        }
        $this->palette = $palette;
        $this->indices = $indices;
    }

    public static function uniform(PersistentBlockState $state): self
    {
        return new self([$state], array_fill(0, self::ENTRY_COUNT, 0));
    }

    /** @return list<PersistentBlockState> */
    public function palette(): array
    {
        return $this->palette;
    }

    public function indexAt(int $x, int $y, int $z): int
    {
        return $this->indices[self::memoryOffset($x, $y, $z)];
    }

    public function stateAt(int $x, int $y, int $z): PersistentBlockState
    {
        return $this->palette[$this->indexAt($x, $y, $z)];
    }

    /** @return list<int> */
    public function indices(): array
    {
        return $this->indices;
    }

    private static function memoryOffset(int $x, int $y, int $z): int
    {
        if ($x < 0 || $x > 15 || $y < 0 || $y > 15 || $z < 0 || $z > 15) {
            throw new InvalidArgumentException('Persistent storage coordinates must be between 0 and 15.');
        }
        return $x + ($z << 4) + ($y << 8);
    }
}
