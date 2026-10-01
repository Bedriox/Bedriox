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

namespace Bedriox\Server\Entity\Ai\Goal;

use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiControl;
use Bedriox\Server\Entity\Ai\AiGoal;
use Bedriox\Server\Entity\Ai\AiMemoryStore;
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\Ai\AquaticAiWorldView;
use Bedriox\Server\Entity\Ai\AquaticNavigation;
use Bedriox\Server\Entity\Ai\AquaticSteering;
use Bedriox\Server\Entity\Ai\VanillaAiMemories;
use Bedriox\Server\Entity\AquaticRuntimeState;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

/** Bounded three-dimensional idle steering for water mobs. */
final readonly class AquaticWanderGoal implements AiGoal
{
    public function __construct(
        private string $identifier,
        private int $priority = 10,
        private float $speed = 0.08,
    ) {
        if (!is_finite($speed) || $speed <= 0.0 || $speed > 1.0) {
            throw new InvalidArgumentException('Aquatic wander speed is outside its supported bounds.');
        }
    }

    public function identifier(): string
    {
        return $this->identifier;
    }
    public function priority(): int
    {
        return $this->priority;
    }
    public function evaluationIntervalTicks(): int
    {
        return 10;
    }
    public function controls(): array
    {
        return [AiControl::MOVE];
    }
    public function canStart(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool
    {
        return $entity->isAlive() && !$memory->contains(VanillaAiMemories::wanderUntil(), $context->tick);
    }
    public function shouldContinue(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool
    {
        $until = $memory->get(VanillaAiMemories::wanderUntil(), $context->tick);

        return $entity->isAlive() && is_int($until) && $context->tick < $until;
    }
    public function start(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $inWater = !$context->world instanceof AquaticAiWorldView
            || $context->world->isTouchingWater($entity);
        $landNavigation = !$inWater
            && $entity instanceof AquaticRuntimeState
            && $entity->canNavigateOnLand();
        if (!$inWater && !$landNavigation) {
            $memory->put(VanillaAiMemories::wanderUntil(), $context->tick + 20);
            $memory->put(VanillaAiMemories::wanderMotionX(), 0.0);
            $memory->put(VanillaAiMemories::aquaticWanderMotionY(), 0.0);
            $memory->put(VanillaAiMemories::wanderMotionZ(), 0.0);
            return;
        }

        $seed = (($entity->getRuntimeId() * 1_103_515_245) ^ ($context->tick * 12_345)) & 0x7fffffff;
        [$x, $y, $z] = $this->chooseMotion($entity, $context, $seed, $inWater);
        $memory->put(VanillaAiMemories::wanderUntil(), $context->tick + 50 + ($seed % 41));
        $memory->put(VanillaAiMemories::wanderMotionX(), $x);
        $memory->put(VanillaAiMemories::aquaticWanderMotionY(), $landNavigation ? 0.0 : $y);
        $memory->put(VanillaAiMemories::wanderMotionZ(), $z);
    }

    public function tick(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $x = $memory->get(VanillaAiMemories::wanderMotionX(), $context->tick);
        $y = $memory->get(VanillaAiMemories::aquaticWanderMotionY(), $context->tick);
        $z = $memory->get(VanillaAiMemories::wanderMotionZ(), $context->tick);
        if (!is_float($x) || !is_float($y) || !is_float($z)) {
            return;
        }
        if ($context->world instanceof AquaticAiWorldView) {
            $inWater = $context->world->isTouchingWater($entity);
            $landNavigation = !$inWater
                && $entity instanceof AquaticRuntimeState
                && $entity->canNavigateOnLand();
            if (!$inWater && !$landNavigation) {
                AquaticSteering::stop($entity, $context->tick);
                return;
            }
            if ($inWater) {
                $position = $entity->internalPosition();
                $next = new Position(
                    $position->x + ($x * 8.0),
                    $position->y + ($y * 8.0),
                    $position->z + ($z * 8.0),
                );
                if (!AquaticNavigation::hasNavigableWaterAt($context->world, $entity, $next)) {
                    $replacement = AquaticNavigation::recoveryMotion(
                        $context->world,
                        $entity,
                        $this->speed,
                        (($entity->getRuntimeId() * 31) ^ $context->tick) & 0x7fffffff,
                    );
                    $x = $replacement->x;
                    $y = $replacement->y;
                    $z = $replacement->z;
                    $memory->put(VanillaAiMemories::wanderMotionX(), $x);
                    $memory->put(VanillaAiMemories::aquaticWanderMotionY(), $y);
                    $memory->put(VanillaAiMemories::wanderMotionZ(), $z);
                }
            }
        }
        AquaticSteering::motion($entity, $x, $y, $z, $context->tick, $y !== 0.0);
    }

    public function stop(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $memory->forget(VanillaAiMemories::wanderUntil());
        $memory->forget(VanillaAiMemories::wanderMotionX());
        $memory->forget(VanillaAiMemories::aquaticWanderMotionY());
        $memory->forget(VanillaAiMemories::wanderMotionZ());
        AquaticSteering::stop($entity, $context->tick);
    }

    /** @return array{float, float, float} */
    private function chooseMotion(AbstractMobEntity $entity, AiTickContext $context, int $seed, bool $inWater): array
    {
        $position = $entity->internalPosition();
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $angle = (($seed + ($attempt * 977)) % 6_283) / 1_000.0;
            $verticalUnit = $inWater ? (((($seed >> 5) + ($attempt * 37)) % 101) - 50) / 100.0 : 0.0;
            $horizontalScale = sqrt(max(0.25, 1.0 - ($verticalUnit * $verticalUnit)));
            $x = cos($angle) * $this->speed * $horizontalScale;
            $y = $verticalUnit * $this->speed * 0.65;
            $z = sin($angle) * $this->speed * $horizontalScale;
            if (!$inWater || !$context->world instanceof AquaticAiWorldView) {
                return [$x, $y, $z];
            }
            foreach ([30.0, 15.0, 7.5] as $lookAheadScale) {
                $lookAhead = new Position(
                    $position->x + ($x * $lookAheadScale),
                    $position->y + ($y * $lookAheadScale),
                    $position->z + ($z * $lookAheadScale),
                );
                if (AquaticNavigation::hasNavigableWaterAt($context->world, $entity, $lookAhead)) {
                    return [$x, $y, $z];
                }
            }
        }

        $recovery = AquaticNavigation::recoveryMotion($context->world, $entity, $this->speed, $seed);

        return [$recovery->x, $recovery->y, $recovery->z];
    }
}
