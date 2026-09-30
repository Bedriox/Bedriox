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

namespace Bedriox\Server\Entity\Ai\Sensor;

use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiMemoryStore;
use Bedriox\Server\Entity\Ai\AiSensor;
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\Ai\TargetAwareAiWorldView;
use Bedriox\Server\Entity\Ai\VanillaAiMemories;
use InvalidArgumentException;

final readonly class TemptingPlayerSensor implements AiSensor
{
    public function __construct(
        private string $identifier,
        private int $intervalTicks,
        private float $radius,
        private string $itemIdentifier,
        private int $memoryTicks,
    ) {
        if ($identifier === '' || strlen($identifier) > 128
            || $intervalTicks < 1 || $intervalTicks > 1_200
            || !is_finite($radius) || $radius <= 0.0 || $radius > 128.0
            || $memoryTicks < $intervalTicks || $memoryTicks > 1_200
            || strlen($itemIdentifier) > 128
            || preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $itemIdentifier) !== 1) {
            throw new InvalidArgumentException('Tempting-player sensor bounds are invalid.');
        }
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function intervalTicks(): int
    {
        return $this->intervalTicks;
    }

    public function sense(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        if (!$context->world instanceof TargetAwareAiWorldView) {
            $memory->forget(VanillaAiMemories::temptingPlayer());

            return;
        }
        $target = $context->world->nearestPlayerHolding($entity, $this->radius, $this->itemIdentifier);
        if ($target === null) {
            $memory->forget(VanillaAiMemories::temptingPlayer());

            return;
        }
        $memory->put(VanillaAiMemories::temptingPlayer(), $target, $context->tick + $this->memoryTicks);
    }
}
