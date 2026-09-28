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
use Bedriox\Api\Event\Entity\EntityInteractEvent;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Plugin\Event\EventDispatcher;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Bedriox\Server\Simulation\Event\CommandRejected;
use Bedriox\Server\Simulation\Event\EntityInteracted;
use Bedriox\Server\Simulation\PluginGameplayEventBridge;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use PHPUnit\Framework\TestCase;
use Throwable;

final class EntityInteractionIntegrationTest extends TestCase
{
    public function testTypedInteractionIsAcceptedValidatedAndCancellableBeforeMutation(): void
    {
        $dispatcher = new EventDispatcher(
            new EntityInteractionPluginRuntimeControl(),
            new PluginExecutionContext(),
            new PluginActionBuffer(),
            new PluginOwnershipRegistry(),
        );
        $observed = [];
        $dispatcher->register(
            'EntityInteractionTest',
            EntityInteractEvent::class,
            static function (EntityInteractEvent $event) use (&$observed): void {
                $observed[] = $event->interaction;
            },
        );
        $simulation = new WorldSimulation(
            pluginEvents: new PluginGameplayEventBridge($dispatcher),
            entityAiEnabled: false,
        );
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('one', 'identity-one', 'One')));
        $simulation->tick();

        $spawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::COW,
            SpawnCause::COMMAND,
            'world',
            new Position(1.5, 64.0, 0.5),
        ));
        self::assertNotNull($spawn->entity);
        $runtimeId = $spawn->entity->getRuntimeId();

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'one',
            $runtimeId,
            0,
            EntityInteractionType::ITEM_INTERACT,
        )));
        $accepted = self::event($simulation->tick()->events, EntityInteracted::class);
        self::assertInstanceOf(EntityInteracted::class, $accepted);
        self::assertSame($runtimeId, $accepted->targetRuntimeActorId);
        self::assertSame(EntityInteractionType::ITEM_INTERACT, $accepted->interaction);
        self::assertSame([EntityInteractionType::ITEM_INTERACT], $observed);

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'one',
            $runtimeId,
            1,
            EntityInteractionType::INTERACT,
        )));
        $invalidSlot = self::event($simulation->tick()->events, CommandRejected::class);
        self::assertInstanceOf(CommandRejected::class, $invalidSlot);
        self::assertSame('selected_slot', $invalidSlot->reason);
        self::assertSame([EntityInteractionType::ITEM_INTERACT], $observed);

        $dispatcher->register(
            'EntityInteractionTest',
            EntityInteractEvent::class,
            static function (EntityInteractEvent $event): void {
                $event->cancel();
            },
        );
        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'one',
            $runtimeId,
            0,
            EntityInteractionType::INTERACT,
        )));
        $cancelled = self::event($simulation->tick()->events, CommandRejected::class);
        self::assertInstanceOf(CommandRejected::class, $cancelled);
        self::assertSame('plugin_cancelled', $cancelled->reason);
        self::assertSame(
            [EntityInteractionType::ITEM_INTERACT, EntityInteractionType::INTERACT],
            $observed,
        );
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

final class EntityInteractionPluginRuntimeControl implements PluginRuntimeControl
{
    public function isEnabled(string $plugin): bool
    {
        return $plugin === 'EntityInteractionTest';
    }

    public function version(string $plugin): string
    {
        return '1.0.0';
    }

    public function disableAfterFailure(string $plugin, Throwable $failure, ?PluginExecutionFrame $frame): void {}
}
