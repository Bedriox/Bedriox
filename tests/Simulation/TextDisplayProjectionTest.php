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

namespace Bedriox\Server\Tests\Simulation;

use Bedriox\Api\Display\TextDisplay;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Position as ApiPosition;
use Bedriox\Api\World\World as ApiWorld;
use Bedriox\Api\World\WorldDimension;
use Bedriox\Server\Plugin\Display\DisplayChange;
use Bedriox\Server\Plugin\Display\DisplayRegistry;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Simulation\Event\CommandRejected;
use Bedriox\Server\Simulation\Event\TextDisplayActorRemoved;
use Bedriox\Server\Simulation\Event\TextDisplayActorSpawned;
use Bedriox\Server\Simulation\Event\TextDisplayActorUpdated;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use PHPUnit\Framework\TestCase;

final class TextDisplayProjectionTest extends TestCase
{
    public function testTargetedVisibilityChangesRemoveAndRestoreTheDisplay(): void
    {
        [$simulation, $registry, $player] = self::simulation();
        $display = $registry->forOwner('Example')->createText(self::position(), 'Welcome');
        self::assertCount(1, self::events($simulation->tick()->events, TextDisplayActorSpawned::class));

        $display->getVisibility()->hideFrom($player);
        $hidden = self::events($simulation->tick()->events, TextDisplayActorRemoved::class);
        self::assertCount(1, $hidden);
        self::assertSame(['viewer'], $hidden[0]->recipientSessionIds);

        $display->getVisibility()->showTo($player);
        $shown = self::events($simulation->tick()->events, TextDisplayActorSpawned::class);
        self::assertCount(1, $shown);
        self::assertSame(['viewer'], $shown[0]->recipientSessionIds);
    }

    public function testMovementAcrossViewDistanceRemovesAndRestoresTheDisplay(): void
    {
        [$simulation, $registry] = self::simulation();
        $registry->forOwner('Example')->createText(self::position(), 'Welcome')->setViewDistance(8);
        $simulation->tick();

        self::assertTrue($simulation->enqueuePluginTeleport(
            '00000000-0000-4000-8000-000000000001',
            new Position(100.0, 64.0, 0.0),
        ));
        self::assertCount(
            1,
            self::events($simulation->tick()->events, TextDisplayActorRemoved::class),
        );

        self::assertTrue($simulation->enqueuePluginTeleport(
            '00000000-0000-4000-8000-000000000001',
            new Position(0.0, 64.0, 0.0),
        ));
        self::assertCount(
            1,
            self::events($simulation->tick()->events, TextDisplayActorSpawned::class),
        );
    }

    public function testChunkDeliveryControlsProjectionAndInteractionAdmission(): void
    {
        [$simulation, $registry] = self::simulation();
        $display = $registry->forOwner('Example')->createText(self::position(), 'Welcome');
        $simulation->tick();
        $display->enableInteraction();
        $updated = self::events($simulation->tick()->events, TextDisplayActorUpdated::class);
        self::assertCount(1, $updated);
        $interactionRuntimeId = $updated[0]->interactionRuntimeId;
        self::assertIsInt($interactionRuntimeId);

        $simulation->synchronizeDeliveredChunkVisibility('viewer', ['0:0' => false]);
        $removed = $simulation->reconcileDisplayVisibilityForSession('viewer', ['0:0' => true]);
        self::assertCount(1, self::events($removed, TextDisplayActorRemoved::class));

        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->attack('viewer', $interactionRuntimeId, 0)));
        $rejected = self::events($simulation->tick()->events, CommandRejected::class);
        self::assertCount(1, $rejected);
        self::assertSame('target_unavailable', $rejected[0]->reason);

        $simulation->synchronizeDeliveredChunkVisibility('viewer', ['0:0' => true]);
        $shown = $simulation->reconcileDisplayVisibilityForSession('viewer', ['0:0' => true]);
        self::assertCount(1, self::events($shown, TextDisplayActorSpawned::class));
    }

    /** @return array{WorldSimulation, DisplayRegistry, Player} */
    private static function simulation(): array
    {
        $simulation = new WorldSimulation();
        $registry = new DisplayRegistry(
            new PluginOwnershipRegistry(),
            onChange: static function (
                TextDisplay $display,
                DisplayChange $change,
                ?Player $player,
            ) use ($simulation): void {
                $simulation->displayChanged($display, $change, $player);
            },
        );
        $simulation->setDisplayRegistry($registry);
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join(
            'viewer',
            '00000000-0000-4000-8000-000000000001',
            'Viewer',
        )));
        $simulation->tick();
        $simulation->synchronizeDeliveredChunkVisibility('viewer', ['0:0' => true]);
        $player = $simulation->pluginPlayers()[0] ?? null;
        self::assertInstanceOf(Player::class, $player);

        return [$simulation, $registry, $player];
    }

    private static function position(): ApiPosition
    {
        return new ApiPosition(
            0.5,
            65.0,
            0.5,
            0.0,
            0.0,
            new ApiWorld('world', 1),
            WorldDimension::OVERWORLD,
        );
    }

    /**
     * @template T of object
     * @param list<object> $events
     * @param class-string<T> $type
     * @return list<T>
     */
    private static function events(array $events, string $type): array
    {
        return array_values(array_filter(
            $events,
            static fn(object $event): bool => $event instanceof $type,
        ));
    }
}
