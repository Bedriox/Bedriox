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
use Bedriox\Server\Entity\Vanilla\Nether\PiglinEntity;

/** Keeps ordinary Piglins neutral to players wearing at least one gold armor piece. */
final readonly class PiglinNearestPlayerSensor implements AiSensor
{
    public function identifier(): string
    {
        return 'bedriox:piglin_nearest_attackable_player';
    }

    public function intervalTicks(): int
    {
        return 10;
    }

    public function sense(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        if ($entity instanceof PiglinEntity && $entity->isAdmiring()) {
            $memory->forget(VanillaAiMemories::nearestPlayer());

            return;
        }
        if (!$context->world instanceof TargetAwareAiWorldView) {
            return;
        }
        $target = $context->world->nearestPlayer($entity, 16.0);
        if ($target !== null && !$target->wearingGoldArmor) {
            $memory->put(VanillaAiMemories::nearestPlayer(), $target, $context->tick + 30);
        }
    }
}
