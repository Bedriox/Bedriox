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
use Bedriox\Server\Entity\Vehicle\Minecart\ChestMinecartEntity;
use Bedriox\Server\Entity\Vehicle\Minecart\RideableMinecartEntity;
use Bedriox\Server\Runtime\BedrockLivingActorProjector;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class MinecartActorMetadataProjectionTest extends TestCase
{
    public function testOrdinaryMinecartProjectsCollisionWithoutLivingBreathingFlag(): void
    {
        $entity = new RideableMinecartEntity(
            '00000000-0000-4000-8000-000000000021',
            1,
            'world',
            new Position(0.5, 64.0, 0.5),
        );
        $metadata = (new BedrockLivingActorProjector())->metadata($entity);

        self::assertSame(ActorFlag::combine(ActorFlag::HasCollision), self::value($metadata, 0));
        self::assertSame(0, self::value($metadata, 18));
        self::assertSame(0.98, self::value($metadata, 53));
        self::assertSame(0.7, self::value($metadata, 54));
    }

    public function testChestMinecartProjectsCurrentSignedChestBlockRuntimeId(): void
    {
        $entity = new ChestMinecartEntity(
            '00000000-0000-4000-8000-000000000022',
            2,
            'world',
            new Position(0.5, 64.0, 0.5),
        );
        $metadata = (new BedrockLivingActorProjector())->metadata($entity, minecartDisplayBlockRuntimeId: -1_132_117_234);

        self::assertSame(-1_132_117_234, self::value($metadata, 16));
        self::assertSame(6, self::value($metadata, 17));
        self::assertSame(1, self::value($metadata, 18));
    }

    /** @param list<ActorMetadata> $metadata */
    private static function value(array $metadata, int $id): int|float|string|\Bedriox\Protocol\Packet\BlockPosition|\Bedriox\Protocol\Packet\ActorMetadataVector3
    {
        foreach ($metadata as $entry) {
            if ($entry->id === $id) {
                return $entry->value;
            }
        }

        self::fail('Missing actor metadata ID ' . $id . '.');
    }
}
