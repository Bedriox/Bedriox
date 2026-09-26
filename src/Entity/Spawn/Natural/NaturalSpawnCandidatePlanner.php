<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Spawn\Natural;

use Bedriox\Server\World\ChunkPosition;
use InvalidArgumentException;

final class NaturalSpawnCandidatePlanner
{
    public const int MAXIMUM_PLAYERS = 1_024;
    public const int MAXIMUM_CANDIDATES = 16_384;

    private int $playerCursor = 0;

    /**
     * @param array<int, mixed> $players
     * @return list<ChunkPosition>
     */
    public function plan(string $worldName, array $players, int $radius, int $worldSeed, int $tick): array
    {
        if ($worldName === '' || strlen($worldName) > 128 || preg_match('//u', $worldName) !== 1
            || !array_is_list($players) || count($players) > self::MAXIMUM_PLAYERS
            || $radius < 0 || $radius > 7 || $tick < 0) {
            throw new InvalidArgumentException('Natural-spawn candidate input is invalid.');
        }
        $eligible = [];
        foreach ($players as $player) {
            if (!$player instanceof NaturalSpawnPlayer) {
                throw new InvalidArgumentException('Natural-spawn candidate input contains an invalid player.');
            }
            if ($player->worldName !== $worldName) {
                continue;
            }
            $eligible[] = $player;
        }
        $playerCount = count($eligible);
        if ($playerCount === 0) {
            return [];
        }

        $diameter = ($radius * 2) + 1;
        $regionArea = $diameter * $diameter;
        $sampleCount = min($playerCount, intdiv(self::MAXIMUM_CANDIDATES, $regionArea));
        $start = $this->playerCursor % $playerCount;
        $deduplicated = [];
        for ($sampleOffset = 0; $sampleOffset < $sampleCount; ++$sampleOffset) {
            $playerIndex = ($start + $sampleOffset) % $playerCount;
            $player = $eligible[$playerIndex];
            $centerX = (int) floor($player->position->x / 16.0);
            $centerZ = (int) floor($player->position->z / 16.0);
            $rotation = self::positiveModulo(
                self::positiveModulo($worldSeed, $regionArea)
                    + self::positiveModulo($tick, $regionArea)
                    + self::positiveModulo($playerIndex, $regionArea),
                $regionArea,
            );
            for ($regionOffset = 0; $regionOffset < $regionArea; ++$regionOffset) {
                $rotated = ($rotation + $regionOffset) % $regionArea;
                $x = $centerX - $radius + intdiv($rotated, $diameter);
                $z = $centerZ - $radius + ($rotated % $diameter);
                $chunk = new ChunkPosition($x, $z);
                $deduplicated[$chunk->key()] = $chunk;
            }
        }
        $this->playerCursor = ($start + $sampleCount) % $playerCount;

        return array_values($deduplicated);
    }

    private static function positiveModulo(int $value, int $modulus): int
    {
        $remainder = $value % $modulus;

        return $remainder < 0 ? $remainder + $modulus : $remainder;
    }
}
