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

use Bedriox\Api\Entity\Capability\Angerable;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiMemoryStore;
use Bedriox\Server\Entity\Ai\AiSensor;
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\Ai\PlayerIdentityAiWorldView;
use Bedriox\Server\Entity\Ai\VanillaAiMemories;

final readonly class AngerTargetSensor implements AiSensor
{
    public function __construct(private string $identifier, private int $intervalTicks = 5) {}

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
        if (!$entity instanceof Angerable || !$context->world instanceof PlayerIdentityAiWorldView) {
            return;
        }
        $targetId = $entity->getAngerTargetUniqueId();
        $target = $targetId === null ? null : $context->world->playerByIdentity($targetId);
        if ($target === null || !$target->damageable || $target->worldName !== $entity->getWorldName()) {
            $memory->forget(VanillaAiMemories::nearestPlayer());

            return;
        }
        $memory->put(VanillaAiMemories::nearestPlayer(), $target, $context->tick + ($this->intervalTicks * 2));
    }
}
