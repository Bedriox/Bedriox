<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace Bedriox\Server\Entity\Vanilla\End;

use Bedriox\Api\World\BlockFace;
use Bedriox\Server\Simulation\Position;
use Closure;

/** Bounded loaded-terrain resolver for a Shulker's supporting surface. */
final readonly class ShulkerAttachmentResolver
{
    public const int SEARCH_RADIUS = 4;
    public const int MAXIMUM_CANDIDATES = 96;

    /**
     * @param Closure(int, int, int): bool $clear
     * @param Closure(int, int, int): bool $solid
     */
    public function resolve(Position $position, BlockFace $currentFace, Closure $clear, Closure $solid): ?ShulkerAttachment
    {
        $originX = (int) floor($position->x);
        $originY = (int) floor($position->y);
        $originZ = (int) floor($position->z);
        if ($clear($originX, $originY, $originZ)) {
            $face = $this->supportedFace($originX, $originY, $originZ, $currentFace, $solid);
            if ($face !== null) {
                return new ShulkerAttachment(new Position($originX + 0.5, (float) $originY, $originZ + 0.5), $face);
            }
        }

        $inspected = 0;
        for ($radius = 1; $radius <= self::SEARCH_RADIUS; ++$radius) {
            for ($y = -$radius; $y <= $radius; ++$y) {
                for ($z = -$radius; $z <= $radius; ++$z) {
                    for ($x = -$radius; $x <= $radius; ++$x) {
                        if (max(abs($x), abs($y), abs($z)) !== $radius || ++$inspected > self::MAXIMUM_CANDIDATES) {
                            continue;
                        }
                        $candidateX = $originX + $x;
                        $candidateY = $originY + $y;
                        $candidateZ = $originZ + $z;
                        if (!$clear($candidateX, $candidateY, $candidateZ)) {
                            continue;
                        }
                        $face = $this->supportedFace($candidateX, $candidateY, $candidateZ, BlockFace::DOWN, $solid);
                        if ($face !== null) {
                            return new ShulkerAttachment(
                                new Position($candidateX + 0.5, (float) $candidateY, $candidateZ + 0.5),
                                $face,
                            );
                        }
                    }
                }
            }
        }

        return null;
    }

    /** @param Closure(int, int, int): bool $solid */
    private function supportedFace(int $x, int $y, int $z, BlockFace $preferred, Closure $solid): ?BlockFace
    {
        $faces = [$preferred];
        foreach (BlockFace::cases() as $face) {
            if ($face !== $preferred) {
                $faces[] = $face;
            }
        }
        foreach ($faces as $face) {
            [$offsetX, $offsetY, $offsetZ] = self::offset($face);
            if ($solid($x + $offsetX, $y + $offsetY, $z + $offsetZ)) {
                return $face;
            }
        }

        return null;
    }

    /** @return array{int, int, int} */
    private static function offset(BlockFace $face): array
    {
        return match ($face) {
            BlockFace::DOWN => [0, -1, 0],
            BlockFace::UP => [0, 1, 0],
            BlockFace::NORTH => [0, 0, -1],
            BlockFace::SOUTH => [0, 0, 1],
            BlockFace::WEST => [-1, 0, 0],
            BlockFace::EAST => [1, 0, 0],
        };
    }
}
