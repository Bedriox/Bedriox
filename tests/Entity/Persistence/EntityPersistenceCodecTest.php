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

namespace Bedriox\Server\Tests\Entity\Persistence;

use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Server\Entity\EntityDespawnPolicy;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Persistence\DormantEntityRecord;
use Bedriox\Server\Entity\Persistence\EntityChunkOwnershipBookkeeper;
use Bedriox\Server\Entity\Persistence\EntityChunkSnapshot;
use Bedriox\Server\Entity\Persistence\EntityEquipmentEntry;
use Bedriox\Server\Entity\Persistence\EntityPersistenceCodec;
use Bedriox\Server\Entity\Persistence\EntityPersistenceLimits;
use Bedriox\Server\Entity\Persistence\EntityPersistenceRecord;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\ChunkPosition;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EntityPersistenceCodecTest extends TestCase
{
    private const string UUID = '123e4567-e89b-42d3-a456-426614174000';

    public function testKnownRecordRoundTripsByteIdenticallyWithCanonicalDurableState(): void
    {
        $codec = new EntityPersistenceCodec(['minecraft:cow']);
        $record = self::record();
        $snapshot = new EntityChunkSnapshot('world', new ChunkPosition(2, -3), 91, [$record]);

        $encoded = $codec->encode($snapshot);
        $decoded = $codec->decode($encoded);

        self::assertSame($encoded, $codec->encode($decoded));
        self::assertSame('world', $decoded->worldName);
        self::assertSame(91, $decoded->chunkRevision);
        self::assertSame([self::UUID => 17], $decoded->revisions());
        self::assertTrue($decoded->containsExactRevision(self::UUID, 17));
        self::assertFalse($decoded->containsExactRevision(self::UUID, 18));
        $restored = $decoded->records()[0];
        self::assertInstanceOf(EntityPersistenceRecord::class, $restored);
        self::assertSame('minecraft:cow', $restored->typeIdentifier());
        self::assertEquals(new Position(47.25, 72.0, -40.5), $restored->position);
        self::assertEquals(new EntityMotion(0.25, -0.5, 0.75), $restored->motion);
        self::assertSame(19.5, $restored->health);
        self::assertSame('brown', $restored->variant);
        self::assertSame("\x00custom\xff", $restored->customData);
        self::assertCount(2, $restored->equipment());
        self::assertSame('minecraft:iron_sword', $restored->equipment()[0]->itemIdentifier);
        self::assertSame(ItemNbt::empty()->toBinary(), $restored->equipment()[0]->customData);
        self::assertSame(0.085, $restored->equipment()[0]->dropChance);
        self::assertSame(SpawnCause::NATURAL, $restored->spawnOrigin);
        self::assertSame(EntityDespawnPolicy::NATURAL_DISTANCE, $restored->despawnPolicy);
        self::assertSame(157, $restored->fireTicks);
    }

    public function testUnknownCustomRecordRemainsDormantAndByteIdentical(): void
    {
        $writer = new EntityPersistenceCodec(['example:clockwork_cow']);
        $original = $writer->encode(new EntityChunkSnapshot(
            'world',
            new ChunkPosition(2, -3),
            91,
            [self::record(type: 'example:clockwork_cow')],
        ));

        $reader = new EntityPersistenceCodec(['minecraft:cow']);
        $decoded = $reader->decode($original);

        self::assertInstanceOf(DormantEntityRecord::class, $decoded->records()[0]);
        self::assertSame('example:clockwork_cow', $decoded->records()[0]->typeIdentifier());
        self::assertSame(self::UUID, $decoded->records()[0]->uuid());
        self::assertSame($original, $reader->encode($decoded));
    }

    public function testLegacyRecordsRemainExplicitOnlyWhenOwnershipMetadataIsAbsent(): void
    {
        $codec = new EntityPersistenceCodec(['minecraft:cow']);
        $current = $codec->encode(new EntityChunkSnapshot(
            'world',
            new ChunkPosition(2, -3),
            91,
            [self::record(withEquipment: false)],
        ));

        $decoded = $codec->decode(self::withoutOwnershipMetadata($current));
        $record = $decoded->records()[0];

        self::assertInstanceOf(EntityPersistenceRecord::class, $record);
        self::assertSame(SpawnCause::CHUNK_LOAD, $record->spawnOrigin);
        self::assertSame(EntityDespawnPolicy::EXPLICIT_ONLY, $record->despawnPolicy);
    }

    public function testPreviousRecordsRetainOwnershipAndDefaultToNoFire(): void
    {
        $codec = new EntityPersistenceCodec(['minecraft:cow']);
        $current = $codec->encode(new EntityChunkSnapshot(
            'world',
            new ChunkPosition(2, -3),
            91,
            [self::record(withEquipment: false)],
        ));

        $decoded = $codec->decode(self::withoutFireState($current));
        $record = $decoded->records()[0];

        self::assertInstanceOf(EntityPersistenceRecord::class, $record);
        self::assertSame(SpawnCause::NATURAL, $record->spawnOrigin);
        self::assertSame(EntityDespawnPolicy::NATURAL_DISTANCE, $record->despawnPolicy);
        self::assertSame(0, $record->fireTicks);
    }

    public function testChecksumVersionTruncationAndOversizedRecordFailClosed(): void
    {
        $codec = new EntityPersistenceCodec(['minecraft:cow']);
        $encoded = $codec->encode(new EntityChunkSnapshot(
            'world',
            new ChunkPosition(2, -3),
            91,
            [self::record()],
        ));
        $corrupt = $encoded;
        $corrupt[10] = chr(ord($corrupt[10]) ^ 0x01);
        $unsupported = self::replaceBodyBytes($encoded, 4, pack('n', 2));
        $recordLengthOffset = self::recordLengthOffset($encoded);
        $oversized = self::replaceBodyBytes(
            $encoded,
            $recordLengthOffset,
            pack('N', EntityPersistenceLimits::MAX_RECORD_BYTES + 1),
        );

        foreach ([$corrupt, substr($encoded, 0, -1), $unsupported, $oversized] as $invalid) {
            try {
                $codec->decode($invalid);
                self::fail('Malformed entity persistence bytes were accepted.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testDuplicateUuidAndUnknownBuiltInTypeFailClosed(): void
    {
        $codec = new EntityPersistenceCodec(['minecraft:cow']);
        $single = $codec->encode(new EntityChunkSnapshot(
            'world',
            new ChunkPosition(2, -3),
            91,
            [self::record()],
        ));
        $body = substr($single, 0, -32);
        $lengthOffset = self::recordLengthOffset($single);
        $recordLength = unpack('Nvalue', substr($body, $lengthOffset, 4))['value'] ?? null;
        self::assertIsInt($recordLength);
        $recordBytes = substr($body, $lengthOffset + 4, $recordLength);
        $duplicateBody = substr($body, 0, $lengthOffset - 2)
            . pack('n', 2)
            . pack('N', $recordLength) . $recordBytes
            . pack('N', $recordLength) . $recordBytes;
        $duplicate = $duplicateBody . hash('sha256', $duplicateBody, true);

        $this->expectException(RuntimeException::class);
        try {
            $codec->decode($duplicate);
        } finally {
            $unknownWriter = new EntityPersistenceCodec(['minecraft:future_entity']);
            $unknown = $unknownWriter->encode(new EntityChunkSnapshot(
                'world',
                new ChunkPosition(2, -3),
                91,
                [self::record(type: 'minecraft:future_entity')],
            ));
            try {
                $codec->decode($unknown);
                self::fail('Unknown built-in entity record was accepted as dormant.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testValueAndSnapshotBoundsRejectInvalidState(): void
    {
        $cases = [
            static fn() => self::record(position: new Position(NAN, 64.0, 0.0)),
            static fn() => self::record(customData: str_repeat('x', EntityPersistenceLimits::MAX_CUSTOM_DATA_BYTES + 1)),
            static fn() => self::record(fireTicks: 0x8000),
            static fn() => new EntityEquipmentEntry('body', 'minecraft:stone'),
            static fn() => new EntityEquipmentEntry('main_hand', 'minecraft:stone', dropChance: 1.01),
            static fn() => new EntityEquipmentEntry('main_hand', 'minecraft:stone', customData: "\x01invalid"),
            static fn() => new EntityPersistenceRecord(
                'minecraft:cow',
                self::UUID,
                'world',
                new ChunkPosition(2, -3),
                new Position(47.25, 72.0, -40.5),
                45.0,
                -10.0,
                new EntityMotion(),
                20.0,
                100,
                true,
                null,
                [
                    new EntityEquipmentEntry('head', 'minecraft:stone'),
                    new EntityEquipmentEntry('head', 'minecraft:dirt'),
                ],
                1,
                '',
                17,
            ),
            static fn() => new EntityChunkSnapshot(
                'world',
                new ChunkPosition(2, -3),
                1,
                [self::record(), self::record()],
            ),
            static fn() => new EntityChunkSnapshot(
                'world',
                new ChunkPosition(3, -3),
                1,
                [self::record()],
            ),
        ];

        foreach ($cases as $case) {
            try {
                $case();
                self::fail('Invalid entity persistence state was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testChunkOwnershipTransferIsAtomicAndRevisionChecked(): void
    {
        $bookkeeper = new EntityChunkOwnershipBookkeeper();
        $record = self::record();
        $bookkeeper->register($record);
        self::assertSame([self::UUID], $bookkeeper->ownedBy('world', new ChunkPosition(2, -3)));

        try {
            $bookkeeper->transfer(
                self::UUID,
                'world',
                new ChunkPosition(2, -3),
                16,
                'world',
                new ChunkPosition(3, -3),
                18,
            );
            self::fail('Stale ownership transfer was accepted.');
        } catch (RuntimeException) {
            self::assertSame([self::UUID], $bookkeeper->ownedBy('world', new ChunkPosition(2, -3)));
            self::assertSame([], $bookkeeper->ownedBy('world', new ChunkPosition(3, -3)));
        }

        $owner = $bookkeeper->transfer(
            self::UUID,
            'world',
            new ChunkPosition(2, -3),
            17,
            'world',
            new ChunkPosition(3, -3),
            18,
        );
        self::assertSame(3, $owner->chunk->x);
        self::assertSame(18, $owner->revision);
        self::assertSame([], $bookkeeper->ownedBy('world', new ChunkPosition(2, -3)));
        self::assertSame([self::UUID], $bookkeeper->ownedBy('world', new ChunkPosition(3, -3)));
        self::assertSame($owner, $bookkeeper->owner(self::UUID));

        $this->expectException(RuntimeException::class);
        $bookkeeper->register($record);
    }

    private static function record(
        string $type = 'minecraft:cow',
        ?Position $position = null,
        string $customData = "\x00custom\xff",
        int $fireTicks = 157,
        bool $withEquipment = true,
    ): EntityPersistenceRecord {
        return new EntityPersistenceRecord(
            $type,
            self::UUID,
            'world',
            new ChunkPosition(2, -3),
            $position ?? new Position(47.25, 72.0, -40.5),
            45.0,
            -10.0,
            new EntityMotion(0.25, -0.5, 0.75),
            19.5,
            12_345,
            true,
            'brown',
            $withEquipment ? [
                new EntityEquipmentEntry(
                    'main_hand',
                    'minecraft:iron_sword',
                    1,
                    7,
                    0,
                    ItemNbt::empty()->toBinary(),
                    0.085,
                ),
                new EntityEquipmentEntry('head', 'minecraft:leather_helmet', 1, 3, 2, dropChance: 0.25),
            ] : [],
            4,
            $customData,
            17,
            SpawnCause::NATURAL,
            EntityDespawnPolicy::NATURAL_DISTANCE,
            $fireTicks,
        );
    }

    private static function recordLengthOffset(string $document): int
    {
        $worldLength = unpack('nvalue', substr($document, 6, 2))['value'] ?? null;
        self::assertIsInt($worldLength);

        return 4 + 2 + 2 + $worldLength + 4 + 4 + 8 + 2;
    }

    private static function replaceBodyBytes(string $document, int $offset, string $replacement): string
    {
        $body = substr($document, 0, -32);
        $body = substr_replace($body, $replacement, $offset, strlen($replacement));

        return $body . hash('sha256', $body, true);
    }

    private static function withoutOwnershipMetadata(string $document): string
    {
        $body = substr($document, 0, -32);
        $lengthOffset = self::recordLengthOffset($document);
        $recordLength = unpack('Nvalue', substr($body, $lengthOffset, 4))['value'] ?? null;
        self::assertIsInt($recordLength);
        $record = substr($body, $lengthOffset + 4, $recordLength);
        $record = substr_replace($record, '', 133, 2);
        $metadataOffset = 134;
        $originLength = unpack('nvalue', substr($record, $metadataOffset, 2))['value'] ?? null;
        self::assertIsInt($originLength);
        $policyOffset = $metadataOffset + 2 + $originLength;
        $policyLength = unpack('nvalue', substr($record, $policyOffset, 2))['value'] ?? null;
        self::assertIsInt($policyLength);
        $metadataLength = 2 + $originLength + 2 + $policyLength;
        $record = pack('n', 1)
            . substr($record, 2, $metadataOffset - 2)
            . substr($record, $metadataOffset + $metadataLength);
        $body = substr($body, 0, $lengthOffset)
            . pack('N', strlen($record))
            . $record;

        return $body . hash('sha256', $body, true);
    }

    private static function withoutFireState(string $document): string
    {
        $body = substr($document, 0, -32);
        $lengthOffset = self::recordLengthOffset($document);
        $recordLength = unpack('Nvalue', substr($body, $lengthOffset, 4))['value'] ?? null;
        self::assertIsInt($recordLength);
        $record = substr($body, $lengthOffset + 4, $recordLength);
        $record = pack('n', 2) . substr_replace(substr($record, 2), '', 131, 2);
        $body = substr($body, 0, $lengthOffset)
            . pack('N', strlen($record))
            . $record;

        return $body . hash('sha256', $body, true);
    }
}
