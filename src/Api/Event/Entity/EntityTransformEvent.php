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
use Bedriox\Api\Entity\Value\EntityTransformReason;
use Bedriox\Api\Event\CancellableEvent;
use InvalidArgumentException;

/** Cancellable, adjustable entity replacement before authoritative commit. */
final class EntityTransformEvent extends CancellableEvent
{
    public function __construct(
        public readonly LivingEntity $entity,
        private EntityType $targetType,
        public readonly EntityTransformReason $reason,
    ) {
        self::validateType($targetType);
    }

    public function targetType(): EntityType
    {
        return $this->targetType;
    }

    public function setTargetType(EntityType $targetType): void
    {
        $this->assertMutable();
        self::validateType($targetType);
        $this->targetType = $targetType;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->targetType];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !$state[1] instanceof EntityType) {
            throw new InvalidArgumentException('Invalid entity transform event state.');
        }
        self::validateType($state[1]);
        parent::replaceState($state[0]);
        $this->targetType = $state[1];
    }

    private static function validateType(EntityType $type): void
    {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $type->identifier()) !== 1) {
            throw new InvalidArgumentException('Entity transform target must have a canonical identifier.');
        }
    }
}
