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

namespace Bedriox\Server\Tests\Integration;

use Bedriox\Api\Entity\EntityInteractionType;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Vanilla\CowEntity;
use Bedriox\Server\Entity\Vanilla\MooshroomEntity;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use PHPUnit\Framework\TestCase;

final class MooshroomInteractionIntegrationTest extends TestCase
{
    public function testAdultMooshroomShearingConsumesDurabilityDropsMushroomsAndTransforms(): void
    {
        $simulation = new WorldSimulation(entityAiEnabled: false);
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('player', 'identity', 'Player')));
        $simulation->tick();
        $player = $simulation->authoritativePlayer('identity');
        self::assertNotNull($player);
        $player->inventory->replaceSlot(0, new InventoryStack('minecraft:shears', 1, 1));
        $spawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::MOOSHROOM,
            SpawnCause::COMMAND,
            'world',
            new Position(1.5, 64.0, 0.5),
        ));
        self::assertInstanceOf(MooshroomEntity::class, $spawn->entity);

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'player',
            $spawn->entity->getRuntimeId(),
            0,
            EntityInteractionType::INTERACT,
        )));
        $simulation->tick();

        self::assertNull($simulation->entityRuntime()->registry()->getByRuntimeId($spawn->entity->getRuntimeId()));
        self::assertNotEmpty(array_filter(
            $simulation->entityRuntime()->registry()->all(),
            static fn(object $entity): bool => $entity instanceof CowEntity,
        ));
        self::assertSame(1, $player->inventory->selectedStack()?->damage);
    }

    public function testBabyMooshroomCannotBeSheared(): void
    {
        $simulation = new WorldSimulation(entityAiEnabled: false);
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('player', 'identity', 'Player')));
        $simulation->tick();
        $player = $simulation->authoritativePlayer('identity');
        self::assertNotNull($player);
        $player->inventory->replaceSlot(0, new InventoryStack('minecraft:shears', 1, 1));
        $spawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::MOOSHROOM,
            SpawnCause::COMMAND,
            'world',
            new Position(1.5, 64.0, 0.5),
        ));
        self::assertInstanceOf(MooshroomEntity::class, $spawn->entity);
        $spawn->entity->setBaby(true);

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'player',
            $spawn->entity->getRuntimeId(),
            0,
            EntityInteractionType::INTERACT,
        )));
        $simulation->tick();

        self::assertSame($spawn->entity, $simulation->entityRuntime()->registry()->getByRuntimeId($spawn->entity->getRuntimeId()));
        self::assertSame(0, $player->inventory->selectedStack()?->damage);
    }
}
