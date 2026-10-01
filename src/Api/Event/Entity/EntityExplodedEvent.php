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
use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\BlockPosition;
use Bedriox\Api\World\Position;
use InvalidArgumentException;

/** Immutable observation after one authoritative entity explosion commits. */
final class EntityExplodedEvent extends Event implements PostEvent
{
    public const int MAXIMUM_AFFECTED_BLOCKS = 4_096;
    public const int MAXIMUM_AFFECTED_ENTITIES = 256;

    /** @var list<BlockPosition> */
    public readonly array $affectedBlocks;

    /** @var list<Entity|Player> */
    public readonly array $affectedEntities;

    /**
     * @param list<BlockPosition> $affectedBlocks
     * @param list<Entity|Player> $affectedEntities
     */
    public function __construct(
        public readonly Entity $entity,
        public readonly Position $position,
        public readonly float $radius,
        public readonly bool $brokeBlocks,
        public readonly float $fireChance,
        array $affectedBlocks,
        array $affectedEntities,
    ) {
        $position->validate();
        if (!is_finite($radius) || $radius <= 0.0 || $radius > EntityExplosionPrimeEvent::MAXIMUM_RADIUS) {
            throw new InvalidArgumentException('Committed explosion radius is invalid.');
        }
        if (!is_finite($fireChance) || $fireChance < 0.0 || $fireChance > 1.0) {
            throw new InvalidArgumentException('Committed explosion fire chance is invalid.');
        }
        $this->affectedBlocks = self::validateBlocks($affectedBlocks);
        $this->affectedEntities = self::validateEntities($affectedEntities);
    }

    /**
     * @param array<mixed> $blocks
     * @return list<BlockPosition>
     */
    private static function validateBlocks(array $blocks): array
    {
        if (!array_is_list($blocks) || count($blocks) > self::MAXIMUM_AFFECTED_BLOCKS) {
            throw new InvalidArgumentException('Explosion affected blocks must be a bounded list.');
        }
        $seen = [];
        foreach ($blocks as $block) {
            if (!$block instanceof BlockPosition) {
                throw new InvalidArgumentException('Explosion affected blocks must contain block positions.');
            }
            $key = $block->x . ':' . $block->y . ':' . $block->z;
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Explosion affected blocks must be unique.');
            }
            $seen[$key] = true;
        }

        return $blocks;
    }

    /**
     * @param array<mixed> $entities
     * @return list<Entity|Player>
     */
    private static function validateEntities(array $entities): array
    {
        if (!array_is_list($entities) || count($entities) > self::MAXIMUM_AFFECTED_ENTITIES) {
            throw new InvalidArgumentException('Explosion affected entities must be a bounded list.');
        }
        $seen = [];
        foreach ($entities as $entity) {
            if (!$entity instanceof Entity && !$entity instanceof Player) {
                throw new InvalidArgumentException('Explosion affected entities must contain public actor views.');
            }
            $key = $entity instanceof Player ? 'player:' . strtolower($entity->uuid) : 'entity:' . $entity->getUniqueId();
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Explosion affected entities must be unique.');
            }
            $seen[$key] = true;
        }

        return $entities;
    }
}
