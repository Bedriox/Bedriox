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

use Bedriox\Server\Simulation\ArmSwingSource;
use Bedriox\Server\Simulation\Event\ArmSwung;
use Bedriox\Server\Simulation\Event\CommandRejected;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use PHPUnit\Framework\TestCase;

final class ArmSwingSimulationTest extends TestCase
{
    public function testMissedSwingIsPeerOnlyAndBoundedToOnePerTick(): void
    {
        $world = new WorldSimulation();
        $commands = new SimulationCommandFactory();
        self::assertTrue($world->enqueue($commands->join('sender', 'sender-identity', 'Sender')));
        self::assertTrue($world->enqueue($commands->join('peer', 'peer-identity', 'Peer')));
        $world->tick();

        self::assertTrue($world->enqueue($commands->swingArm('sender', ArmSwingSource::Missed)));
        $accepted = $world->tick()->events;
        self::assertCount(1, $accepted);
        self::assertInstanceOf(ArmSwung::class, $accepted[0]);
        self::assertSame(ArmSwingSource::Missed, $accepted[0]->source);
        self::assertSame(['peer'], $accepted[0]->recipients());

        self::assertTrue($world->enqueue($commands->swingArm('sender', ArmSwingSource::Missed)));
        self::assertTrue($world->enqueue($commands->swingArm('sender', ArmSwingSource::Missed)));
        $bounded = $world->tick()->events;
        self::assertCount(2, $bounded);
        self::assertInstanceOf(ArmSwung::class, $bounded[0]);
        self::assertInstanceOf(CommandRejected::class, $bounded[1]);
        self::assertSame('arm_swing_rate', $bounded[1]->reason);
    }

    public function testPluginSwingUsesTheSameAuthoritativePeerProjection(): void
    {
        $world = new WorldSimulation();
        $commands = new SimulationCommandFactory();
        self::assertTrue($world->enqueue($commands->join('sender', 'sender-identity', 'Sender')));
        self::assertTrue($world->enqueue($commands->join('peer', 'peer-identity', 'Peer')));
        $world->tick();

        self::assertTrue($world->enqueuePluginArmSwing('sender-identity'));
        $events = $world->tick()->events;

        self::assertCount(1, $events);
        self::assertInstanceOf(ArmSwung::class, $events[0]);
        self::assertSame(ArmSwingSource::Plugin, $events[0]->source);
        self::assertSame(['peer'], $events[0]->recipients());
    }
}
