<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Persistence;

use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Server\Entity\EntityDespawnPolicy;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\ChunkPosition;
use InvalidArgumentException;

final readonly class EntityPersistenceRecord implements PersistentEntityRecord
{
    /** @var list<EntityEquipmentEntry> */
    private array $equipment;

    /**
     * @param array<int, EntityEquipmentEntry> $equipment
     */
    public function __construct(
        private string $typeIdentifier,
        private string $uuid,
        private string $worldName,
        private ChunkPosition $ownerChunk,
        public Position $position,
        public float $yaw,
        public float $pitch,
        public EntityMotion $motion,
        public ?float $health,
        public int $ageTicks,
        public bool $persistent,
        public int|string|null $variant,
        array $equipment,
        public int $customSchemaVersion,
        public string $customData,
        private int $revision,
        public SpawnCause $spawnOrigin = SpawnCause::CHUNK_LOAD,
        public EntityDespawnPolicy $despawnPolicy = EntityDespawnPolicy::EXPLICIT_ONLY,
        public int $fireTicks = 0,
    ) {
        self::validateIdentifier($typeIdentifier);
        if (EntityUuid::validate($uuid) !== $uuid) {
            throw new InvalidArgumentException('Entity persistence UUID must be canonical.');
        }
        if ($worldName === '' || strlen($worldName) > EntityPersistenceLimits::MAX_WORLD_NAME_BYTES
            || preg_match('//u', $worldName) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $worldName) === 1) {
            throw new InvalidArgumentException('Entity persistence world name is invalid.');
        }
        if (!is_finite($position->x) || !is_finite($position->y) || !is_finite($position->z)
            || abs($position->x) > 30_000_000.0 || abs($position->z) > 30_000_000.0
            || abs($position->y) > 2_048.0) {
            throw new InvalidArgumentException('Entity persistence position is outside world bounds.');
        }
        if ((int) floor($position->x / 16.0) !== $ownerChunk->x
            || (int) floor($position->z / 16.0) !== $ownerChunk->z) {
            throw new InvalidArgumentException('Entity persistence position does not belong to its owning chunk.');
        }
        if (!is_finite($yaw) || $yaw < 0.0 || $yaw >= 360.0
            || !is_finite($pitch) || $pitch < -90.0 || $pitch > 90.0) {
            throw new InvalidArgumentException('Entity persistence rotation is invalid.');
        }
        if ($health !== null && (!is_finite($health) || $health < 0.0 || $health > 1_000_000.0)) {
            throw new InvalidArgumentException('Entity persistence health is invalid.');
        }
        if ($health === null && $fireTicks !== 0) {
            throw new InvalidArgumentException('A non-living entity persistence record cannot be on fire.');
        }
        if ($ageTicks < 0 || $ageTicks > 0x7fffffff || $fireTicks < 0 || $fireTicks > 0x7fff || $customSchemaVersion < 0
            || $customSchemaVersion > 0xffff || $revision < 0 || strlen($customData) > EntityPersistenceLimits::MAX_CUSTOM_DATA_BYTES) {
            throw new InvalidArgumentException('Entity persistence age, fire state, schema, revision, or custom data is invalid.');
        }
        if ($despawnPolicy === EntityDespawnPolicy::NATURAL_DISTANCE && $spawnOrigin !== SpawnCause::NATURAL) {
            throw new InvalidArgumentException('Natural-distance despawn ownership requires a natural spawn origin.');
        }
        if (is_string($variant) && ($variant === '' || strlen($variant) > EntityPersistenceLimits::MAX_VARIANT_BYTES
            || preg_match('//u', $variant) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $variant) === 1)) {
            throw new InvalidArgumentException('Entity persistence variant is invalid.');
        }
        if (is_int($variant) && ($variant < -0x80000000 || $variant > 0x7fffffff)) {
            throw new InvalidArgumentException('Entity persistence numeric variant is invalid.');
        }
        if (!array_is_list($equipment) || count($equipment) > EntityPersistenceLimits::MAX_EQUIPMENT_ENTRIES) {
            throw new InvalidArgumentException('Entity persistence equipment is oversized or unordered.');
        }
        $slots = [];
        foreach ($equipment as $entry) {
            if (isset($slots[$entry->slot])) {
                throw new InvalidArgumentException('Entity persistence equipment contains an invalid or duplicate slot.');
            }
            $slots[$entry->slot] = true;
        }
        $this->equipment = $equipment;
    }

    public function typeIdentifier(): string
    {
        return $this->typeIdentifier;
    }

    public function uuid(): string
    {
        return $this->uuid;
    }

    public function worldName(): string
    {
        return $this->worldName;
    }

    public function ownerChunk(): ChunkPosition
    {
        return $this->ownerChunk;
    }

    /** @return list<EntityEquipmentEntry> */
    public function equipment(): array
    {
        return $this->equipment;
    }

    public function revision(): int
    {
        return $this->revision;
    }

    private static function validateIdentifier(string $identifier): void
    {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1
            || strlen($identifier) > EntityPersistenceLimits::MAX_IDENTIFIER_BYTES) {
            throw new InvalidArgumentException('Entity persistence type identifier is invalid.');
        }
    }
}
