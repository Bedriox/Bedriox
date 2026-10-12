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

namespace Bedriox\Server\World\Environment\Fluid;

use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;

/** Resolves bounded, loaded-terrain-only water currents from canonical liquid depth. */
final readonly class FluidCurrentResolver
{
    private const int MAXIMUM_BODY_BLOCKS = 32;

    public function __construct(private FluidWorldView $world) {}

    public function currentAt(Position $position, float $bodyHeight, float $bodyWidth = 0.25): FluidCurrent
    {
        if (!is_finite($bodyHeight) || $bodyHeight <= 0.0 || $bodyHeight > self::MAXIMUM_BODY_BLOCKS
            || !is_finite($bodyWidth) || $bodyWidth <= 0.0 || $bodyWidth > self::MAXIMUM_BODY_BLOCKS) {
            throw new InvalidArgumentException('Fluid-current body dimensions are outside their supported range.');
        }
        $halfWidth = $bodyWidth / 2.0;
        $minimumX = (int) floor($position->x - $halfWidth);
        $maximumX = (int) floor($position->x + $halfWidth - 0.000_001);
        $minimumY = (int) floor($position->y);
        $maximumY = (int) floor($position->y + $bodyHeight - 0.000_001);
        $minimumZ = (int) floor($position->z - $halfWidth);
        $maximumZ = (int) floor($position->z + $halfWidth - 0.000_001);
        $x = $y = $z = 0.0;
        for ($blockX = $minimumX; $blockX <= $maximumX; ++$blockX) {
            for ($blockZ = $minimumZ; $blockZ <= $maximumZ; ++$blockZ) {
                for ($blockY = $minimumY; $blockY <= $maximumY; ++$blockY) {
                    $origin = new BlockPosition($blockX, $blockY, $blockZ);
                    $cell = $this->world->cellAt($origin);
                    $fluid = $cell?->fluid;
                    if ($fluid?->type !== FluidType::WATER
                        || $position->y >= $blockY + $fluid->height()
                        || $position->y + $bodyHeight <= $blockY) {
                        continue;
                    }
                    $current = $this->flowAt($origin, $fluid);
                    $x += $current->x;
                    $y += $current->y;
                    $z += $current->z;
                }
            }
        }
        $length = sqrt(($x * $x) + ($y * $y) + ($z * $z));

        return $length <= 0.000_001
            ? FluidCurrent::still()
            : new FluidCurrent($x / $length, $y / $length, $z / $length);
    }

    private function flowAt(BlockPosition $origin, FluidState $fluid): FluidCurrent
    {
        $x = 0.0;
        $z = 0.0;
        $decay = self::effectiveDecay($fluid);
        foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$offsetX, $offsetZ]) {
            $sidePosition = new BlockPosition($origin->x + $offsetX, $origin->y, $origin->z + $offsetZ);
            $side = $this->world->cellAt($sidePosition);
            if ($side === null) {
                return FluidCurrent::still();
            }
            $sideDecay = self::waterDecay($side);
            if ($sideDecay < 0) {
                if (!$side->replaceable) {
                    continue;
                }
                $below = $this->world->cellAt(new BlockPosition($sidePosition->x, $sidePosition->y - 1, $sidePosition->z));
                if ($below === null) {
                    return FluidCurrent::still();
                }
                $sideDecay = self::waterDecay($below);
                if ($sideDecay >= 0) {
                    $difference = $sideDecay - ($decay - 8);
                    $x += $offsetX * $difference;
                    $z += $offsetZ * $difference;
                }
                continue;
            }
            $difference = $sideDecay - $decay;
            $x += $offsetX * $difference;
            $z += $offsetZ * $difference;
        }

        $y = 0.0;
        if ($fluid->falling) {
            foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$offsetX, $offsetZ]) {
                $side = $this->world->cellAt(new BlockPosition(
                    $origin->x + $offsetX,
                    $origin->y,
                    $origin->z + $offsetZ,
                ));
                $above = $this->world->cellAt(new BlockPosition(
                    $origin->x + $offsetX,
                    $origin->y + 1,
                    $origin->z + $offsetZ,
                ));
                if ($side === null || $above === null) {
                    return FluidCurrent::still();
                }
                if (!$side->replaceable || !$above->replaceable) {
                    [$x, $z] = self::normalizedHorizontal($x, $z);
                    $y = -6.0;
                    break;
                }
            }
        }
        $length = sqrt(($x * $x) + ($y * $y) + ($z * $z));
        if ($length <= 0.000_001) {
            return FluidCurrent::still();
        }

        return new FluidCurrent($x / $length, $y / $length, $z / $length);
    }

    private static function effectiveDecay(FluidState $fluid): int
    {
        return $fluid->falling ? 0 : $fluid->depth;
    }

    private static function waterDecay(FluidCell $cell): int
    {
        return $cell->fluid?->type === FluidType::WATER ? self::effectiveDecay($cell->fluid) : -1;
    }

    /** @return array{float, float} */
    private static function normalizedHorizontal(float $x, float $z): array
    {
        $length = hypot($x, $z);

        return $length <= 0.000_001 ? [0.0, 0.0] : [$x / $length, $z / $length];
    }
}
