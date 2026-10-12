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

namespace Bedriox\Api\Event\Entity;

use Bedriox\Api\Entity\Entity;
use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\World\BlockPosition;
use Bedriox\Api\World\Position;
use InvalidArgumentException;

/** Cancellable impact plan after an entity explosion is calculated and before it changes the world. */
final class EntityExplodeEvent extends CancellableEvent
{
    /** @var list<BlockPosition> */
    private array $affectedBlocks;

    /**
     * @param list<BlockPosition> $affectedBlocks
     */
    public function __construct(
        public readonly Entity $entity,
        public readonly Position $position,
        public readonly float $radius,
        array $affectedBlocks,
        private float $dropYield,
    ) {
        $position->validate();
        if (!is_finite($radius) || $radius <= 0.0 || $radius > EntityExplosionPrimeEvent::MAXIMUM_RADIUS) {
            throw new InvalidArgumentException('Explosion impact radius is invalid.');
        }
        self::validateYield($dropYield);
        $this->affectedBlocks = self::validateBlocks($affectedBlocks);
    }

    /** @return list<BlockPosition> */
    public function affectedBlocks(): array
    {
        return $this->affectedBlocks;
    }

    /** @param list<BlockPosition> $affectedBlocks */
    public function setAffectedBlocks(array $affectedBlocks): void
    {
        $this->assertMutable();
        $this->affectedBlocks = self::validateBlocks($affectedBlocks);
    }

    public function dropYield(): float
    {
        return $this->dropYield;
    }

    public function setDropYield(float $dropYield): void
    {
        $this->assertMutable();
        self::validateYield($dropYield);
        $this->dropYield = $dropYield;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->affectedBlocks, $this->dropYield];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 3 || !is_bool($state[0]) || !is_array($state[1])
            || (!is_int($state[2]) && !is_float($state[2]))) {
            throw new InvalidArgumentException('Invalid entity-explode event state.');
        }
        $yield = (float) $state[2];
        self::validateYield($yield);
        parent::replaceState($state[0]);
        $this->affectedBlocks = self::validateBlocks($state[1]);
        $this->dropYield = $yield;
    }

    /**
     * @param array<mixed> $blocks
     * @return list<BlockPosition>
     */
    private static function validateBlocks(array $blocks): array
    {
        if (!array_is_list($blocks) || count($blocks) > EntityExplodedEvent::MAXIMUM_AFFECTED_BLOCKS) {
            throw new InvalidArgumentException('Explosion affected blocks must be a bounded list.');
        }
        $seen = [];
        $validated = [];
        foreach ($blocks as $block) {
            if (!$block instanceof BlockPosition) {
                throw new InvalidArgumentException('Explosion affected blocks must contain block positions.');
            }
            $key = $block->x . ':' . $block->y . ':' . $block->z;
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Explosion affected blocks must be unique.');
            }
            $seen[$key] = true;
            $validated[] = $block;
        }

        return $validated;
    }

    private static function validateYield(float $dropYield): void
    {
        if (!is_finite($dropYield) || $dropYield < 0.0 || $dropYield > 1.0) {
            throw new InvalidArgumentException('Explosion drop yield must be between zero and one.');
        }
    }
}
