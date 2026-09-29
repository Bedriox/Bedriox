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

use Bedriox\Api\World\Particle\ParticleType;
use Bedriox\Api\World\Particle\SimpleParticle;
use Bedriox\Server\Simulation\Event\CommandRejected;
use Bedriox\Server\Simulation\Event\ParticleSpawned;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\WorldSimulation;
use PHPUnit\Framework\TestCase;

final class ParticleSimulationTest extends TestCase
{
    public function testParticleRequestBecomesAnAuthoritativePresentationEvent(): void
    {
        $simulation = new WorldSimulation();
        $particle = new SimpleParticle(ParticleType::FLAME);

        self::assertTrue($simulation->enqueuePluginParticle(
            'example',
            new Position(1.5, 65.0, -2.5),
            $particle,
            null,
        ));
        $tick = $simulation->tick();

        self::assertCount(1, $tick->events);
        self::assertInstanceOf(ParticleSpawned::class, $tick->events[0]);
        self::assertSame($particle, $tick->events[0]->particle);
        self::assertSame([], $tick->events[0]->recipients());
    }

    public function testPerPluginParticleWorkIsBoundedEachTick(): void
    {
        $simulation = new WorldSimulation();
        $particle = new SimpleParticle(ParticleType::SMOKE);
        for ($index = 0; $index < 257; ++$index) {
            self::assertTrue($simulation->enqueuePluginParticle(
                'example',
                new Position(0.5, 65.0, 0.5),
                $particle,
                null,
            ));
        }

        $tick = $simulation->tick();
        $rejections = array_values(array_filter(
            $tick->events,
            static fn($event): bool => $event instanceof CommandRejected,
        ));

        self::assertCount(256, array_filter(
            $tick->events,
            static fn($event): bool => $event instanceof ParticleSpawned,
        ));
        self::assertCount(1, $rejections);
        self::assertSame('particle_budget', $rejections[0]->reason);
    }
}
