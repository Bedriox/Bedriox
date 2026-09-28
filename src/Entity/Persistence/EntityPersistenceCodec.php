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

namespace Bedriox\Server\Entity\Persistence;

use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Entity\EntityDespawnPolicy;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\ChunkPosition;
use InvalidArgumentException;
use RuntimeException;

final readonly class EntityPersistenceCodec
{
    private const string MAGIC = 'BXER';
    private const int DOCUMENT_VERSION = 1;
    private const int RECORD_VERSION = 4;
    private const int EQUIPMENT_WITHOUT_DROP_CHANCE_RECORD_VERSION = 3;
    private const int PREVIOUS_RECORD_VERSION = 2;
    private const int LEGACY_RECORD_VERSION = 1;

    /** @var array<string, true> */
    private array $knownTypes;

    /** @param array<int, string> $knownTypes */
    public function __construct(array $knownTypes)
    {
        if (!array_is_list($knownTypes) || count($knownTypes) > 1_024) {
            throw new InvalidArgumentException('Known persistent entity types are oversized or unordered.');
        }
        $validated = [];
        foreach ($knownTypes as $type) {
            if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $type) !== 1
                || strlen($type) > EntityPersistenceLimits::MAX_IDENTIFIER_BYTES || isset($validated[$type])) {
                throw new InvalidArgumentException('Known persistent entity type is invalid or duplicated.');
            }
            $validated[$type] = true;
        }
        $this->knownTypes = $validated;
    }

    public static function vanilla(): self
    {
        return new self(array_values(array_map(
            static fn(\Bedriox\Data\EntityTypeDefinition $type): string => $type->identifier(),
            BedrockDataSet::bundled()->entityTypeRegistry()->definitions(),
        )));
    }

    /** Resolves a byte-preserved custom record after its owning definition becomes available. */
    public function materializeDormant(DormantEntityRecord $record): EntityPersistenceRecord
    {
        $decoded = $this->decodeRecord($record->encodedRecord());
        if (!$decoded instanceof EntityPersistenceRecord) {
            throw new RuntimeException('Dormant entity type is not admitted by the active definition registry.');
        }

        return $decoded;
    }

    public function encode(EntityChunkSnapshot $snapshot): string
    {
        $body = self::MAGIC
            . pack('n', self::DOCUMENT_VERSION)
            . self::string($snapshot->worldName)
            . self::signedInt($snapshot->chunk->x)
            . self::signedInt($snapshot->chunk->z)
            . self::nonNegativeLong($snapshot->chunkRevision)
            . pack('n', count($snapshot->records()));
        foreach ($snapshot->records() as $record) {
            $encoded = $record instanceof DormantEntityRecord
                ? $this->validatedDormantBytes($record)
                : $this->encodeRecord($record);
            if (strlen($encoded) > EntityPersistenceLimits::MAX_RECORD_BYTES) {
                throw new RuntimeException('Encoded entity persistence record is oversized.');
            }
            $body .= pack('N', strlen($encoded)) . $encoded;
        }
        $document = $body . hash('sha256', $body, true);
        if (strlen($document) > EntityPersistenceLimits::MAX_DOCUMENT_BYTES) {
            throw new RuntimeException('Encoded entity persistence document is oversized.');
        }

        return $document;
    }

    public function decode(string $document): EntityChunkSnapshot
    {
        if (strlen($document) < 32 || strlen($document) > EntityPersistenceLimits::MAX_DOCUMENT_BYTES) {
            throw new RuntimeException('Entity persistence document is truncated or oversized.');
        }
        $body = substr($document, 0, -32);
        $checksum = substr($document, -32);
        if (!hash_equals(hash('sha256', $body, true), $checksum)) {
            throw new RuntimeException('Entity persistence document checksum is invalid.');
        }
        $reader = new EntityPersistenceBinaryReader($body);
        if ($reader->read(4) !== self::MAGIC || $reader->unsignedShort() !== self::DOCUMENT_VERSION) {
            throw new RuntimeException('Entity persistence document version is unsupported.');
        }
        $worldName = $reader->string(EntityPersistenceLimits::MAX_WORLD_NAME_BYTES);
        $chunk = new ChunkPosition($reader->signedInt(), $reader->signedInt());
        $chunkRevision = $reader->nonNegativeLong();
        $count = $reader->unsignedShort();
        if ($count > EntityPersistenceLimits::MAX_RECORDS) {
            throw new RuntimeException('Entity persistence document contains too many records.');
        }
        $records = [];
        /** @var array<string, true> $uuids */
        $uuids = [];
        for ($index = 0; $index < $count; ++$index) {
            $recordBytes = $reader->sizedBytes(EntityPersistenceLimits::MAX_RECORD_BYTES);
            if ($recordBytes === '') {
                throw new RuntimeException('Entity persistence document contains an empty record.');
            }
            try {
                $record = $this->decodeRecord($recordBytes);
            } catch (InvalidArgumentException $error) {
                throw new RuntimeException('Entity persistence record contains an invalid bounded value.', previous: $error);
            }
            if (isset($uuids[$record->uuid()])) {
                throw new RuntimeException('Entity persistence document contains a duplicate UUID.');
            }
            $uuids[$record->uuid()] = true;
            if ($record->worldName() !== $worldName || $record->ownerChunk()->x !== $chunk->x
                || $record->ownerChunk()->z !== $chunk->z) {
                throw new RuntimeException('Entity persistence record does not belong to its document owner.');
            }
            $records[] = $record;
        }
        $reader->finish();

        try {
            return new EntityChunkSnapshot($worldName, $chunk, $chunkRevision, $records);
        } catch (InvalidArgumentException $error) {
            throw new RuntimeException('Entity persistence document contains invalid snapshot metadata.', previous: $error);
        }
    }

    private function encodeRecord(PersistentEntityRecord $record): string
    {
        if (!$record instanceof EntityPersistenceRecord) {
            throw new RuntimeException('Entity persistence record implementation is unsupported.');
        }
        $variant = match (true) {
            $record->variant === null => "\x00",
            is_int($record->variant) => "\x01" . self::signedInt($record->variant),
            default => "\x02" . self::string($record->variant),
        };
        $equipment = pack('C', count($record->equipment()));
        foreach ($record->equipment() as $entry) {
            $equipment .= self::string($entry->slot)
                . self::string($entry->itemIdentifier)
                . pack('C', $entry->count)
                . pack('N', $entry->damage)
                . pack('n', $entry->auxValue)
                . pack('N', strlen($entry->customData))
                . $entry->customData
                . pack('E', $entry->dropChance);
        }
        $uuidBytes = hex2bin(str_replace('-', '', $record->uuid()));
        if (!is_string($uuidBytes) || strlen($uuidBytes) !== 16) {
            throw new RuntimeException('Entity persistence UUID cannot be encoded.');
        }

        return pack('n', self::RECORD_VERSION)
            . self::string($record->typeIdentifier())
            . $uuidBytes
            . self::string($record->worldName())
            . self::signedInt($record->ownerChunk()->x)
            . self::signedInt($record->ownerChunk()->z)
            . self::nonNegativeLong($record->revision())
            . pack('E', $record->position->x)
            . pack('E', $record->position->y)
            . pack('E', $record->position->z)
            . pack('E', $record->yaw)
            . pack('E', $record->pitch)
            . pack('E', $record->motion->x)
            . pack('E', $record->motion->y)
            . pack('E', $record->motion->z)
            . ($record->health === null ? "\x00" : "\x01" . pack('E', $record->health))
            . pack('N', $record->ageTicks)
            . pack('n', $record->fireTicks)
            . ($record->persistent ? "\x01" : "\x00")
            . self::string($record->spawnOrigin->value)
            . self::string($record->despawnPolicy->value)
            . $variant
            . $equipment
            . pack('n', $record->customSchemaVersion)
            . pack('N', strlen($record->customData))
            . $record->customData;
    }

    private function decodeRecord(string $bytes): PersistentEntityRecord
    {
        $reader = new EntityPersistenceBinaryReader($bytes);
        $recordVersion = $reader->unsignedShort();
        if (!in_array($recordVersion, [
            self::RECORD_VERSION,
            self::EQUIPMENT_WITHOUT_DROP_CHANCE_RECORD_VERSION,
            self::PREVIOUS_RECORD_VERSION,
            self::LEGACY_RECORD_VERSION,
        ], true)) {
            throw new RuntimeException('Entity persistence record version is unsupported.');
        }
        $type = $reader->string(EntityPersistenceLimits::MAX_IDENTIFIER_BYTES);
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $type) !== 1) {
            throw new RuntimeException('Entity persistence record type is invalid.');
        }
        $uuid = self::uuid($reader->read(16));
        $worldName = $reader->string(EntityPersistenceLimits::MAX_WORLD_NAME_BYTES);
        $chunk = new ChunkPosition($reader->signedInt(), $reader->signedInt());
        $revision = $reader->nonNegativeLong();
        $position = new Position($reader->double(), $reader->double(), $reader->double());
        $yaw = $reader->double();
        $pitch = $reader->double();
        $motion = new EntityMotion($reader->double(), $reader->double(), $reader->double());
        $hasHealth = $reader->byte();
        if ($hasHealth !== 0 && $hasHealth !== 1) {
            throw new RuntimeException('Entity persistence health marker is invalid.');
        }
        $health = $hasHealth === 1 ? $reader->double() : null;
        $ageTicks = $reader->unsignedInt();
        $fireTicks = $recordVersion >= 3 ? $reader->unsignedShort() : 0;
        $persistent = self::boolean($reader->byte(), 'persistence flag');
        $spawnOrigin = SpawnCause::CHUNK_LOAD;
        $despawnPolicy = EntityDespawnPolicy::EXPLICIT_ONLY;
        if ($recordVersion >= 2) {
            $spawnOrigin = SpawnCause::tryFrom($reader->string(32))
                ?? throw new RuntimeException('Entity persistence spawn origin is invalid.');
            $despawnPolicy = EntityDespawnPolicy::tryFrom($reader->string(32))
                ?? throw new RuntimeException('Entity persistence despawn policy is invalid.');
        }
        $variant = match ($reader->byte()) {
            0 => null,
            1 => $reader->signedInt(),
            2 => $reader->string(EntityPersistenceLimits::MAX_VARIANT_BYTES),
            default => throw new RuntimeException('Entity persistence variant marker is invalid.'),
        };
        $equipmentCount = $reader->byte();
        if ($equipmentCount > EntityPersistenceLimits::MAX_EQUIPMENT_ENTRIES) {
            throw new RuntimeException('Entity persistence equipment is oversized.');
        }
        $equipment = [];
        for ($index = 0; $index < $equipmentCount; ++$index) {
            $equipment[] = new EntityEquipmentEntry(
                $reader->string(32),
                $reader->string(EntityPersistenceLimits::MAX_IDENTIFIER_BYTES),
                $reader->byte(),
                $reader->unsignedInt(),
                $reader->unsignedShort(),
                $reader->sizedBytes(EntityPersistenceLimits::MAX_ITEM_DATA_BYTES),
                $recordVersion >= 4 ? $reader->double() : 0.0,
            );
        }
        $customSchemaVersion = $reader->unsignedShort();
        $customData = $reader->sizedBytes(EntityPersistenceLimits::MAX_CUSTOM_DATA_BYTES);
        $reader->finish();

        try {
            $record = new EntityPersistenceRecord(
                $type,
                $uuid,
                $worldName,
                $chunk,
                $position,
                $yaw,
                $pitch,
                $motion,
                $health,
                $ageTicks,
                $persistent,
                $variant,
                $equipment,
                $customSchemaVersion,
                $customData,
                $revision,
                $spawnOrigin,
                $despawnPolicy,
                $fireTicks,
            );
        } catch (InvalidArgumentException $error) {
            throw new RuntimeException('Entity persistence record state is invalid.', previous: $error);
        }
        if (isset($this->knownTypes[$type])) {
            return $record;
        }
        if (str_starts_with($type, 'minecraft:')) {
            throw new RuntimeException('Entity persistence record contains an unknown built-in type.');
        }

        return new DormantEntityRecord($type, $uuid, $worldName, $chunk, $revision, $bytes);
    }

    private function validatedDormantBytes(DormantEntityRecord $dormant): string
    {
        $decoded = $this->decodeRecord($dormant->encodedRecord());
        if ($decoded->typeIdentifier() !== $dormant->typeIdentifier()
            || $decoded->uuid() !== $dormant->uuid()
            || $decoded->worldName() !== $dormant->worldName()
            || $decoded->ownerChunk()->x !== $dormant->ownerChunk()->x
            || $decoded->ownerChunk()->z !== $dormant->ownerChunk()->z
            || $decoded->revision() !== $dormant->revision()) {
            throw new RuntimeException('Dormant entity record metadata does not match its retained bytes.');
        }

        return $dormant->encodedRecord();
    }

    private static function boolean(int $value, string $field): bool
    {
        if ($value !== 0 && $value !== 1) {
            throw new RuntimeException("Entity persistence {$field} is invalid.");
        }

        return $value === 1;
    }

    private static function uuid(string $bytes): string
    {
        $hex = bin2hex($bytes);
        $uuid = substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);

        try {
            return EntityUuid::validate($uuid);
        } catch (InvalidArgumentException $error) {
            throw new RuntimeException('Entity persistence UUID is invalid.', previous: $error);
        }
    }

    private static function string(string $value): string
    {
        if (strlen($value) > 0xffff) {
            throw new RuntimeException('Entity persistence string cannot be encoded.');
        }

        return pack('n', strlen($value)) . $value;
    }

    private static function signedInt(int $value): string
    {
        if ($value < -0x80000000 || $value > 0x7fffffff) {
            throw new RuntimeException('Entity persistence signed integer cannot be encoded.');
        }

        return pack('N', $value & 0xffffffff);
    }

    private static function nonNegativeLong(int $value): string
    {
        if ($value < 0) {
            throw new RuntimeException('Entity persistence revision cannot be encoded.');
        }

        return pack('J', $value);
    }
}
