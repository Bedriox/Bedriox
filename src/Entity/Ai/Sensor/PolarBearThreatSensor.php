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
use Bedriox\Server\Entity\Ai\PlayerIdentityAiWorldView;
use Bedriox\Server\Entity\Ai\VanillaAiMemories;
use Bedriox\Server\Entity\Vanilla\PolarBearEntity;

/** Resolves a cub's escape threat or an adult's exact anger target, never an arbitrary player. */
final readonly class PolarBearThreatSensor implements AiSensor
{
    public function identifier(): string
    {
        return 'bedriox:polar_bear_threat';
    }

    public function intervalTicks(): int
    {
        return 5;
    }

    public function sense(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        if (!$entity instanceof PolarBearEntity || !$context->world instanceof PlayerIdentityAiWorldView) {
            $memory->forget(VanillaAiMemories::nearestPlayer());

            return;
        }

        if ($entity->isBaby()) {
            $target = $context->world->nearestPlayer($entity, 16.0);
        } else {
            $targetId = $entity->getRemainingAngerTicks() > 0 ? $entity->getAngerTargetUniqueId() : null;
            $target = $targetId === null ? null : $context->world->playerByIdentity($targetId);
        }

        if ($target === null || !$target->damageable || $target->worldName !== $entity->getWorldName()) {
            $memory->forget(VanillaAiMemories::nearestPlayer());

            return;
        }

        $memory->put(VanillaAiMemories::nearestPlayer(), $target, $context->tick + 20);
    }
}
