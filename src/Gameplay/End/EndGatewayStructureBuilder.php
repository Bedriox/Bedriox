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

namespace Bedriox\Server\Gameplay\End;

use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\BlockPosition;
use Closure;
use LogicException;

/** Builds paired gateway cages and a bounded safe outer arrival platform. */
final readonly class EndGatewayStructureBuilder
{
    /** @var Closure(BlockPosition, InternalBlockStateId): void */
    private Closure $setBlock;

    /** @param Closure(BlockPosition, InternalBlockStateId): void $setBlock */
    public function __construct(
        private BlockStateRegistry $blocks,
        Closure $setBlock,
    ) {
        $this->setBlock = $setBlock;
    }

    public function build(EndGatewayLink $link): void
    {
        $bedrock = $this->state('minecraft:bedrock');
        $gateway = $this->state('minecraft:end_gateway');
        $endStone = $this->state('minecraft:end_stone');
        $air = $this->state('minecraft:air');
        $this->cage($link->inner, $bedrock, $gateway);
        $this->cage($link->outer, $bedrock, $gateway);

        for ($x = -2; $x <= 2; ++$x) {
            for ($z = -2; $z <= 2; ++$z) {
                ($this->setBlock)(new BlockPosition($link->outer->x + $x, $link->outer->y - 1, $link->outer->z + $z), $endStone);
                if ($x !== 0 || $z !== 0) {
                    ($this->setBlock)(new BlockPosition($link->outer->x + $x, $link->outer->y + 1, $link->outer->z + $z), $air);
                }
            }
        }
        ($this->setBlock)($link->outer, $gateway);
    }

    private function cage(BlockPosition $position, InternalBlockStateId $bedrock, InternalBlockStateId $gateway): void
    {
        for ($offset = -2; $offset <= 2; ++$offset) {
            ($this->setBlock)(new BlockPosition($position->x + $offset, $position->y, $position->z), $bedrock);
            ($this->setBlock)(new BlockPosition($position->x, $position->y + $offset, $position->z), $bedrock);
        }
        ($this->setBlock)($position, $gateway);
    }

    private function state(string $identifier): InternalBlockStateId
    {
        foreach ($this->blocks->states() as $state) {
            if ($state->identifier() === $identifier && $state->properties() === []) {
                return $this->blocks->internalId($state);
            }
        }
        foreach ($this->blocks->states() as $state) {
            if ($state->identifier() === $identifier) {
                return $this->blocks->internalId($state);
            }
        }

        throw new LogicException("Required End gateway block '$identifier' is not registered.");
    }
}
