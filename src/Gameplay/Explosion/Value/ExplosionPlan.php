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

namespace Bedriox\Server\Gameplay\Explosion\Value;

use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;

final readonly class ExplosionPlan
{
    /** @var list<BlockPosition> */
    public array $affectedBlocks;

    /** @var list<BlockPosition> */
    public array $ignitionCandidates;

    /**
     * @param list<BlockPosition> $affectedBlocks
     * @param list<BlockPosition> $ignitionCandidates
     */
    public function __construct(array $affectedBlocks, array $ignitionCandidates, public bool $truncated)
    {
        $this->affectedBlocks = self::validatePositions($affectedBlocks, 'affected');
        $this->ignitionCandidates = self::validatePositions($ignitionCandidates, 'ignition');
    }

    /**
     * @param array<mixed> $positions
     * @return list<BlockPosition>
     */
    private static function validatePositions(array $positions, string $label): array
    {
        if (!array_is_list($positions) || count($positions) > 4_096) {
            throw new InvalidArgumentException("Explosion {$label} positions must be a bounded list.");
        }
        $seen = [];
        foreach ($positions as $position) {
            if (!$position instanceof BlockPosition) {
                throw new InvalidArgumentException("Explosion {$label} positions must contain block positions.");
            }
            $key = $position->x . ':' . $position->y . ':' . $position->z;
            if (isset($seen[$key])) {
                throw new InvalidArgumentException("Explosion {$label} positions must be unique.");
            }
            $seen[$key] = true;
        }

        return $positions;
    }
}
