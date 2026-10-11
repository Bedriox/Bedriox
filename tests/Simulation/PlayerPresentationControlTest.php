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

use Bedriox\Api\Entity\Value\EmoteId;
use Bedriox\Api\Entity\Value\Motion;
use Bedriox\Server\Simulation\Event\EmotePerformed;
use Bedriox\Server\Simulation\Event\PlayerMotionChanged;
use Bedriox\Server\Simulation\Event\PlayerMoved;
use Bedriox\Server\Simulation\Event\PlayerPresentationChanged;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use PHPUnit\Framework\TestCase;

final class PlayerPresentationControlTest extends TestCase
{
    public function testRealPlayerSharedControlsMutateAuthoritativeStateAndPublishProjectionEvents(): void
    {
        $simulation = new WorldSimulation();
        self::assertTrue($simulation->enqueue((new SimulationCommandFactory())->join(
            'session',
            '00000000-0000-4000-8000-000000000001',
            'Viewer',
        )));
        $simulation->tick();
        $identity = '00000000-0000-4000-8000-000000000001';

        self::assertTrue($simulation->setPluginPlayerMotion($identity, new Motion(0.2, 0.3, -0.1)));
        self::assertTrue($simulation->playPluginPlayerEmote(
            $identity,
            new EmoteId('00000000-0000-4000-8000-000000000002'),
        ));
        self::assertTrue($simulation->setPluginPlayerRotation($identity, 90.0, -20.0));
        self::assertTrue($simulation->setPluginPlayerPresentation($identity, 'nameTag', 'Guide'));
        self::assertTrue($simulation->setPluginPlayerPresentation($identity, 'nameTagVisible', false));
        self::assertTrue($simulation->setPluginPlayerPresentation($identity, 'immobile', true));
        self::assertTrue($simulation->setPluginPlayerPresentation($identity, 'invisible', true));
        self::assertTrue($simulation->setPluginPlayerPresentation($identity, 'scale', 1.5));
        self::assertTrue($simulation->setPluginPlayerPresentation($identity, 'gravity', false));

        $events = $simulation->tick()->events;
        self::assertCount(1, self::events($events, PlayerMotionChanged::class));
        $emotes = self::events($events, EmotePerformed::class);
        self::assertCount(1, $emotes);
        self::assertTrue($emotes[0]->includeOwner);
        self::assertCount(1, self::events($events, PlayerMoved::class));
        self::assertCount(6, self::events($events, PlayerPresentationChanged::class));
        $snapshot = self::events($events, PlayerPresentationChanged::class)[5]->player;
        self::assertSame('Guide', $snapshot->nameTag);
        self::assertFalse($snapshot->nameTagVisible);
        self::assertTrue($snapshot->immobile);
        self::assertTrue($snapshot->invisible);
        self::assertSame(1.5, $snapshot->scale);
        self::assertFalse($snapshot->gravityEnabled);
    }

    /**
     * @template T of object
     * @param list<object> $events
     * @param class-string<T> $type
     * @return list<T>
     */
    private static function events(array $events, string $type): array
    {
        return array_values(array_filter($events, static fn(object $event): bool => $event instanceof $type));
    }
}
