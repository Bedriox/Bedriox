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

use Bedriox\Api\Entity\EntityType;
use Bedriox\Api\Entity\LivingEntity;
use Bedriox\Api\Event\CancellableEvent;
use InvalidArgumentException;

/** Cancellable, adjustable child count before one living entity splits. */
final class EntitySplitEvent extends CancellableEvent
{
    public const int MAXIMUM_CHILDREN = 16;

    public function __construct(
        public readonly LivingEntity $entity,
        public readonly EntityType $childType,
        private int $childCount,
    ) {
        self::validateType($childType);
        self::validateCount($childCount);
    }

    public function childCount(): int
    {
        return $this->childCount;
    }

    public function setChildCount(int $childCount): void
    {
        $this->assertMutable();
        self::validateCount($childCount);
        $this->childCount = $childCount;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->childCount];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !is_int($state[1])) {
            throw new InvalidArgumentException('Invalid entity split event state.');
        }
        self::validateCount($state[1]);
        parent::replaceState($state[0]);
        $this->childCount = $state[1];
    }

    private static function validateCount(int $childCount): void
    {
        if ($childCount < 1 || $childCount > self::MAXIMUM_CHILDREN) {
            throw new InvalidArgumentException('Entity split child count must be between 1 and 16.');
        }
    }

    private static function validateType(EntityType $type): void
    {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $type->identifier()) !== 1) {
            throw new InvalidArgumentException('Entity split child type must have a canonical identifier.');
        }
    }
}
