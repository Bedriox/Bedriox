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

use Bedriox\Api\Entity\Value\SlimeSize;
use Bedriox\Api\Entity\Value\WoolColor;
use Bedriox\Protocol\Packet\ActorFlag;
use Bedriox\Protocol\Packet\ActorMetadata;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vanilla\CreeperEntity;
use Bedriox\Server\Entity\Vanilla\MagmaCubeEntity;
use Bedriox\Server\Entity\Vanilla\SheepEntity;
use Bedriox\Server\Entity\Vanilla\SkeletonEntity;
use Bedriox\Server\Entity\Vanilla\SlimeEntity;
use Bedriox\Server\Entity\Vanilla\SpiderEntity;
use Bedriox\Server\Runtime\BedrockLivingActorProjector;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class LandMobActorMetadataTest extends TestCase
{
    public function testSheepProjectsColorShearedBabyAndBabyDimensions(): void
    {
        $sheep = new SheepEntity(
            EntityUuid::random(),
            1,
            'world',
            new Position(0.0, 64.0, 0.0),
            baby: true,
            woolColor: WoolColor::RED,
        );
        $projector = new BedrockLivingActorProjector();
        $metadata = $projector->metadata($sheep);
        self::assertSame(14, self::integer($metadata, 3));
        self::assertNotSame(0, self::integer($metadata, 0) & ActorFlag::Baby->mask());
        self::assertSame(0.5, self::float($metadata, 38));
        self::assertSame(0.45, self::float($metadata, 53));
        self::assertSame(0.65, self::float($metadata, 54));

        $sheep->setBaby(false);
        $sheep->setSheared(true);
        $metadata = $projector->metadata($sheep);
        self::assertNotSame(0, self::integer($metadata, 0) & ActorFlag::Sheared->mask());
        self::assertSame(0, self::integer($metadata, 0) & ActorFlag::Baby->mask());
        self::assertSame(1.0, self::float($metadata, 38));
        self::assertSame(0.9, self::float($metadata, 53));
        self::assertSame(1.3, self::float($metadata, 54));
    }

    public function testSkeletonUsesExactBaselineWithoutSpeciesOnlyFlags(): void
    {
        $skeleton = new SkeletonEntity(EntityUuid::random(), 2, 'world', new Position(0.0, 64.0, 0.0));
        $metadata = (new BedrockLivingActorProjector())->metadata($skeleton);
        $flags = self::integer($metadata, 0);

        self::assertSame(0, $flags & ActorFlag::Baby->mask());
        self::assertSame(0, $flags & ActorFlag::Sheared->mask());
        self::assertSame(0.6, self::float($metadata, 53));
        self::assertSame(1.99, self::float($metadata, 54));
    }

    public function testSpecialHostilesProjectTheirBoundedSpeciesState(): void
    {
        $projector = new BedrockLivingActorProjector();
        $spider = new SpiderEntity(EntityUuid::random(), 3, 'world', new Position(0.0, 64.0, 0.0));
        self::assertNotSame(0, self::integer($projector->metadata($spider), 0) & ActorFlag::CanClimb->mask());

        $slime = new SlimeEntity(EntityUuid::random(), 4, 'world', new Position(0.0, 64.0, 0.0), SlimeSize::MEDIUM);
        self::assertSame(2, self::integer($projector->metadata($slime), 2));

        $creeper = new CreeperEntity(EntityUuid::random(), 5, 'world', new Position(0.0, 64.0, 0.0));
        $creeper->setCharged(true);
        $creeper->setIgnited(true);
        $flags = self::integer($projector->metadata($creeper), 0);
        self::assertNotSame(0, $flags & ActorFlag::Powered->mask());
        self::assertSame(0, $flags & ActorFlag::Charged->mask());
        self::assertNotSame(0, $flags & ActorFlag::Ignited->mask());

        $magma = new MagmaCubeEntity(EntityUuid::random(), 6, 'world', new Position(0.0, 64.0, 0.0));
        self::assertNotSame(0, self::integer($projector->metadata($magma), 0) & ActorFlag::FireImmune->mask());
    }

    /** @param list<ActorMetadata> $metadata */
    private static function integer(array $metadata, int $id): int
    {
        foreach ($metadata as $entry) {
            if ($entry->id === $id && is_int($entry->value)) {
                return $entry->value;
            }
        }
        self::fail("Missing integer actor metadata {$id}.");
    }

    /** @param list<ActorMetadata> $metadata */
    private static function float(array $metadata, int $id): float
    {
        foreach ($metadata as $entry) {
            if ($entry->id === $id && is_float($entry->value)) {
                return $entry->value;
            }
        }
        self::fail("Missing float actor metadata {$id}.");
    }
}
