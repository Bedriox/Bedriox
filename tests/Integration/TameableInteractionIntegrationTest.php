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
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Vanilla\CatEntity;
use Bedriox\Server\Entity\Vanilla\WolfEntity;
use Bedriox\Server\Gameplay\Block\DropRandom;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Simulation\Event\TameAttemptPresented;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use PHPUnit\Framework\TestCase;

final class TameableInteractionIntegrationTest extends TestCase
{
    public function testOwnerCanStandASeatedWolfWhileStillHoldingItsTamingItem(): void
    {
        $identity = EntityUuid::random();
        $simulation = new WorldSimulation(entityAiEnabled: false);
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('owner', $identity, 'Owner')));
        $simulation->tick();
        $player = $simulation->authoritativePlayer($identity);
        self::assertNotNull($player);
        $player->inventory->replaceSlot(0, new InventoryStack('minecraft:bone', 1, 2));

        $spawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::WOLF,
            SpawnCause::COMMAND,
            'world',
            new Position(1.5, 64.0, 0.5),
        ));
        self::assertInstanceOf(WolfEntity::class, $spawn->entity);
        $spawn->entity->setOwnerUniqueId($identity);
        $spawn->entity->setSitting(true);

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'owner',
            $spawn->entity->getRuntimeId(),
            0,
            EntityInteractionType::INTERACT,
        )));
        $simulation->tick();

        self::assertFalse($spawn->entity->isSitting());
    }

    public function testSuccessfulCatTamePublishesVanillaFeedback(): void
    {
        $identity = EntityUuid::random();
        $simulation = new WorldSimulation(
            dropRandom: new SuccessfulTameRandom(),
            entityAiEnabled: false,
        );
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('owner', $identity, 'Owner')));
        $simulation->tick();
        $player = $simulation->authoritativePlayer($identity);
        self::assertNotNull($player);
        $player->inventory->replaceSlot(0, new InventoryStack('minecraft:cod', 1, 2));

        $spawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::CAT,
            SpawnCause::COMMAND,
            'world',
            new Position(1.5, 64.0, 0.5),
        ));
        self::assertInstanceOf(CatEntity::class, $spawn->entity);
        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'owner',
            $spawn->entity->getRuntimeId(),
            0,
            EntityInteractionType::INTERACT,
        )));

        $events = $simulation->tick()->events;
        $feedback = null;
        foreach ($events as $event) {
            if ($event instanceof TameAttemptPresented) {
                $feedback = $event;
                break;
            }
        }
        self::assertInstanceOf(TameAttemptPresented::class, $feedback);
        self::assertTrue($feedback->succeeded);
        self::assertTrue($spawn->entity->isTamed());
        self::assertTrue($spawn->entity->isSitting());
    }
}

final class SuccessfulTameRandom implements DropRandom
{
    private int $calls = 0;

    public function integer(int $minimum, int $maximum): int
    {
        ++$this->calls;

        return $minimum;
    }
}
