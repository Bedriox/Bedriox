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

use Bedriox\Api\World\BlockFace;
use Bedriox\Protocol\Packet\ActorMetadata;
use Bedriox\Protocol\Packet\ShulkerActorMetadata;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vanilla\End\ShulkerEntity;
use Bedriox\Server\Runtime\BedrockLivingActorProjector;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShulkerActorMetadataProjectionTest extends TestCase
{
    /** @return iterable<string, array{BlockFace, int}> */
    public static function attachmentFaces(): iterable
    {
        yield 'down' => [BlockFace::DOWN, 0];
        yield 'up' => [BlockFace::UP, 1];
        yield 'north' => [BlockFace::NORTH, 2];
        yield 'south' => [BlockFace::SOUTH, 3];
        yield 'west' => [BlockFace::WEST, 4];
        yield 'east' => [BlockFace::EAST, 5];
    }

    #[DataProvider('attachmentFaces')]
    public function testProjectsCurrentAttachmentFaceAndPeekMetadata(BlockFace $face, int $wireFace): void
    {
        $shulker = new ShulkerEntity(
            EntityUuid::random(),
            1,
            'end',
            new Position(0.5, 70.0, 0.5),
        );
        $shulker->setAttachmentFace($face);
        $shulker->setPeekAmount(75);

        $metadata = (new BedrockLivingActorProjector())->metadata($shulker);

        self::assertSame(75, self::integer($metadata, ShulkerActorMetadata::PEEK_AMOUNT));
        self::assertSame($wireFace, self::integer($metadata, ShulkerActorMetadata::ATTACH_FACE));
        self::assertSame(1, self::integer($metadata, ShulkerActorMetadata::ATTACHED));
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
}
