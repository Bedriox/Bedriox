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

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Protocol\Packet\ActorFlag;
use Bedriox\Protocol\Packet\ActorMetadata;
use Bedriox\Protocol\Packet\BlockPosition;
use Bedriox\Protocol\Packet\EndCrystalActorMetadata;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vanilla\End\EndCrystalEntity;
use Bedriox\Server\Runtime\BedrockLivingActorProjector;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class EndCrystalActorMetadataProjectionTest extends TestCase
{
    public function testProjectsFlooredBeamTargetAndZeroClearingSentinel(): void
    {
        $crystal = new EndCrystalEntity(
            EntityUuid::random(),
            1,
            'end',
            new Position(0.5, 70.0, 0.5),
        );
        $projector = new BedrockLivingActorProjector();

        self::assertEquals(
            new BlockPosition(0, 0, 0),
            self::metadata($projector->metadata($crystal), EndCrystalActorMetadata::BLOCK_TARGET)->value,
        );

        $crystal->configureEncounterState(true, true, new Position(-2.1, 128.9, 5.8), true);
        $flags = self::metadata($projector->metadata($crystal), 0)->value;
        self::assertIsInt($flags);
        self::assertNotSame(
            0,
            $flags & ActorFlag::ShowBottom->mask(),
        );
        self::assertEquals(
            new BlockPosition(-3, 128, 5),
            self::metadata($projector->metadata($crystal), EndCrystalActorMetadata::BLOCK_TARGET)->value,
        );
    }

    /** @param list<ActorMetadata> $metadata */
    private static function metadata(array $metadata, int $id): ActorMetadata
    {
        foreach ($metadata as $entry) {
            if ($entry->id === $id) {
                return $entry;
            }
        }
        self::fail("Missing actor metadata {$id}.");
    }
}
