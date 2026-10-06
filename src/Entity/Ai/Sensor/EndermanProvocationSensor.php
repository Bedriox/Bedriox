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
use Bedriox\Server\Entity\Ai\EndermanAwareAiWorldView;
use Bedriox\Server\Entity\Ai\VanillaAiMemories;
use Bedriox\Server\Entity\Vanilla\EndermanEntity;

final readonly class EndermanProvocationSensor implements AiSensor
{
    private const int ANGER_TICKS = 600;

    public function identifier(): string
    {
        return 'bedriox:enderman_provocation';
    }

    public function intervalTicks(): int
    {
        return 5;
    }

    public function sense(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        if (!$entity instanceof EndermanEntity || !$context->world instanceof EndermanAwareAiWorldView) {
            return;
        }
        if ($entity->getRemainingAngerTicks() > 0) {
            $targetId = $entity->getAngerTargetUniqueId();
            $target = $targetId === null ? null : $context->world->playerByIdentity($targetId);
            if ($target === null || !$target->damageable || $target->worldName !== $entity->getWorldName()) {
                $memory->forget(VanillaAiMemories::nearestPlayer());

                return;
            }
            $memory->put(VanillaAiMemories::nearestPlayer(), $target, $context->tick + 10);

            return;
        }
        $target = $context->world->nearestPlayerProvokingEnderman($entity, 64.0);
        if ($target === null || !$target->damageable) {
            $memory->forget(VanillaAiMemories::nearestPlayer());

            return;
        }
        $entity->setAngerTargetUniqueId($target->playerId, self::ANGER_TICKS);
        $memory->put(VanillaAiMemories::nearestPlayer(), $target, $context->tick + 10);
    }
}
