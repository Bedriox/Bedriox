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
use Bedriox\Api\Entity\Value\MountSeat;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Protocol\Packet\ActorLinkType;
use Bedriox\Protocol\Packet\ActorMetadataVector3;
use Bedriox\Protocol\Packet\PlayerActorMetadata;
use Bedriox\Protocol\Packet\SetActorDataPacket;
use Bedriox\Protocol\Packet\SetActorLinkPacket;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Vanilla\PigEntity;
use Bedriox\Server\Runtime\BedrockWorldEventPacketEncoder;
use Bedriox\Server\Simulation\Event\ActorDismounted;
use Bedriox\Server\Simulation\Event\ActorMounted;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Collision\PlayerCollisionShape;
use PHPUnit\Framework\TestCase;

final class MountingIntegrationTest extends TestCase
{
    public function testSaddledPigInteractionMountsAndVehicleExitDismountsAuthoritatively(): void
    {
        $simulation = new WorldSimulation(entityAiEnabled: false);
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('one', 'identity-one', 'One')));
        $simulation->tick();

        $spawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::PIG,
            SpawnCause::COMMAND,
            'world',
            new Position(1.5, 64.0, 0.5),
        ));
        self::assertInstanceOf(PigEntity::class, $spawn->entity);
        $spawn->entity->setSaddled(true);

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'one',
            $spawn->entity->getRuntimeId(),
            0,
            EntityInteractionType::INTERACT,
        )));
        $mounted = self::event($simulation->tick()->events, ActorMounted::class);
        self::assertInstanceOf(ActorMounted::class, $mounted);
        self::assertSame($spawn->entity, $simulation->mountedVehicle('identity-one'));

        $packets = (new BedrockWorldEventPacketEncoder())->encode($mounted, []);
        self::assertCount(2, $packets);
        self::assertInstanceOf(SetActorDataPacket::class, $packets[0]->packet);
        $seatOffset = null;
        $flags = null;
        foreach ($packets[0]->packet->metadata as $metadata) {
            if ($metadata->id === 56) {
                $seatOffset = $metadata->value;
            }
            if (PlayerActorMetadata::isFlags($metadata)) {
                $flags = $metadata->value;
            }
        }
        self::assertInstanceOf(ActorMetadataVector3::class, $seatOffset);
        self::assertEqualsWithDelta(
            $spawn->entity->mountedPassengerOffsetY(MountSeat::DRIVER, PlayerCollisionShape::HEIGHT, true),
            $seatOffset->y,
            0.000_001,
        );
        self::assertEqualsWithDelta(1.85, $seatOffset->y, 0.01);
        self::assertIsInt($flags);
        self::assertSame(0, $flags & (1 << 2));
        self::assertInstanceOf(SetActorLinkPacket::class, $packets[1]->packet);
        self::assertSame(ActorLinkType::Rider, $packets[1]->packet->link->type);

        self::assertTrue($simulation->enqueue($commands->dismountPlayer('one')));
        $dismounted = self::event($simulation->tick()->events, ActorDismounted::class);
        self::assertInstanceOf(ActorDismounted::class, $dismounted);
        self::assertNull($simulation->mountedVehicle('identity-one'));

        $packets = (new BedrockWorldEventPacketEncoder())->encode($dismounted, []);
        self::assertCount(2, $packets);
        self::assertInstanceOf(SetActorLinkPacket::class, $packets[0]->packet);
        self::assertSame(ActorLinkType::Remove, $packets[0]->packet->link->type);
    }

    public function testEntityControllerUsesTheSameBoundedRelationshipRegistry(): void
    {
        $simulation = new WorldSimulation(entityAiEnabled: false);
        $passenger = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::COW,
            SpawnCause::PLUGIN,
            'world',
            new Position(0.0, 64.0, 0.0),
        ))->entity;
        $vehicle = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::PIG,
            SpawnCause::PLUGIN,
            'world',
            new Position(1.0, 64.0, 0.0),
        ))->entity;
        self::assertNotNull($passenger);
        self::assertNotNull($vehicle);

        $passenger->getController()->mount($vehicle, MountSeat::PASSENGER_1);
        self::assertSame($vehicle, $passenger->getVehicle());
        self::assertTrue($vehicle->hasPassengers());
        self::assertCount(1, $vehicle->getPassengers());

        $passenger->getController()->dismount();
        self::assertNull($passenger->getVehicle());
        self::assertFalse($vehicle->hasPassengers());
    }

    /**
     * @template T of object
     * @param list<object> $events
     * @param class-string<T> $type
     * @return T|null
     */
    private static function event(array $events, string $type): ?object
    {
        foreach ($events as $event) {
            if ($event instanceof $type) {
                return $event;
            }
        }

        return null;
    }
}
