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

namespace Bedriox\Server\Gameplay\Projectile;

use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Api\Potion\PotionType;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Gameplay\Potion\AreaEffectCloud;
use Bedriox\Server\Gameplay\Potion\AreaEffectCloudRegistry;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use JsonException;

/** Bounded versioned persistence for transient projectile and potion-cloud actors owned by one world. */
final class ProjectilePersistenceCodec
{
    public const int MAXIMUM_BYTES = 1_048_576;
    private const int VERSION = 1;

    /**
     * @param list<Projectile> $projectiles
     * @param list<AreaEffectCloud> $clouds
     */
    public function encode(string $worldId, array $projectiles, array $clouds): string
    {
        if ($worldId === '' || strlen($worldId) > 128
            || count($projectiles) > ProjectileRegistry::MAXIMUM_CAPACITY
            || count($clouds) > AreaEffectCloudRegistry::MAXIMUM_CAPACITY) {
            throw new InvalidArgumentException('Potion entity snapshot exceeds supported bounds.');
        }
        $persistedProjectiles = array_values(array_filter(
            $projectiles,
            static fn(Projectile $projectile): bool => $projectile->type !== ProjectileType::FISHING_HOOK,
        ));
        $document = [
            'version' => self::VERSION,
            'world' => $worldId,
            'projectiles' => array_map(static fn(Projectile $entity): array => [
                'unique' => $entity->uniqueEntityId,
                'runtime' => $entity->runtimeEntityId,
                'owner' => $entity->ownerUuid,
                'potion' => $entity->potionType->value,
                'lingering' => $entity->lingering,
                'position' => [$entity->position->x, $entity->position->y, $entity->position->z],
                'motion' => [$entity->motion->x, $entity->motion->y, $entity->motion->z],
                'age' => $entity->ageTicks,
                'arrow' => $entity->tippedArrow,
                'pickup' => $entity->pickupAllowed,
                'damageBonus' => $entity->damageBonus,
                'knockbackStrength' => $entity->knockbackStrength,
                'fireTicks' => $entity->fireTicks,
                'type' => $entity->type->value,
                'piercing' => $entity->piercingRemaining,
                'hitActors' => $entity->hitActorKeys,
                'loyalty' => $entity->loyaltyLevel,
                'channeling' => $entity->channeling,
                'carriedItem' => self::encodedStack($entity->carriedItem),
                'ownerRuntime' => $entity->ownerRuntimeEntityId,
                'fishingBobbing' => $entity->fishingBobbing,
                'fishingWait' => $entity->fishingWaitTicks,
                'fishingBite' => $entity->fishingBiteTicks,
                'fishingLuck' => $entity->fishingLuckLevel,
                'fishingLure' => $entity->fishingLureLevel,
            ], $persistedProjectiles),
            'clouds' => array_map(static fn(AreaEffectCloud $entity): array => [
                'unique' => $entity->uniqueEntityId,
                'runtime' => $entity->runtimeEntityId,
                'owner' => $entity->ownerUuid,
                'potion' => $entity->potionType->value,
                'position' => [$entity->position->x, $entity->position->y, $entity->position->z],
                'age' => $entity->ageTicks,
                'duration' => $entity->durationTicks,
                'radius' => $entity->radius,
                'cooldowns' => $entity->victimCooldowns,
            ], $clouds),
        ];
        try {
            $encoded = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Potion entity snapshot cannot be encoded.', previous: $error);
        }
        if (strlen($encoded) > self::MAXIMUM_BYTES) {
            throw new InvalidArgumentException('Potion entity snapshot exceeds its byte limit.');
        }

        return $encoded;
    }

    /** @return array{list<Projectile>, list<AreaEffectCloud>} */
    public function decode(string $worldId, string $payload): array
    {
        if ($payload === '' || strlen($payload) > self::MAXIMUM_BYTES) {
            throw new InvalidArgumentException('Potion entity snapshot has an invalid size.');
        }
        try {
            $document = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Potion entity snapshot is malformed.', previous: $error);
        }
        if (!is_array($document) || ($document['version'] ?? null) !== self::VERSION
            || ($document['world'] ?? null) !== $worldId
            || !isset($document['projectiles'], $document['clouds'])
            || !is_array($document['projectiles']) || !array_is_list($document['projectiles'])
            || !is_array($document['clouds']) || !array_is_list($document['clouds'])
            || count($document['projectiles']) > ProjectileRegistry::MAXIMUM_CAPACITY
            || count($document['clouds']) > AreaEffectCloudRegistry::MAXIMUM_CAPACITY) {
            throw new InvalidArgumentException('Potion entity snapshot contract is invalid.');
        }
        $projectiles = [];
        foreach ($document['projectiles'] as $record) {
            if (!is_array($record)) {
                throw new InvalidArgumentException('Projectile record is invalid.');
            }
            $projectiles[] = new Projectile(
                self::integer($record, 'unique'),
                self::integer($record, 'runtime'),
                self::string($record, 'owner'),
                self::potion($record),
                self::boolean($record, 'lingering'),
                self::position($record),
                self::motion($record),
                self::integer($record, 'age'),
                self::boolean($record, 'arrow'),
                self::boolean($record, 'pickup'),
                self::optionalNumber($record, 'damageBonus', 0.0),
                self::optionalNumber($record, 'knockbackStrength', 0.4),
                self::optionalInteger($record, 'fireTicks', 0),
                self::optionalProjectileType(
                    $record,
                    self::boolean($record, 'arrow') ? ProjectileType::ARROW : ProjectileType::SPLASH_POTION,
                ),
                self::optionalInteger($record, 'piercing', 0),
                self::optionalStringList($record, 'hitActors'),
                self::optionalInteger($record, 'loyalty', 0),
                self::optionalBoolean($record, 'channeling', false),
                self::optionalStack($record, 'carriedItem'),
                self::optionalInteger($record, 'ownerRuntime', 0),
                self::optionalBoolean($record, 'fishingBobbing', false),
                self::optionalInteger($record, 'fishingWait', 0),
                self::optionalInteger($record, 'fishingBite', 0),
                self::optionalInteger($record, 'fishingLuck', 0),
                self::optionalInteger($record, 'fishingLure', 0),
            );
        }
        $clouds = [];
        foreach ($document['clouds'] as $record) {
            if (!is_array($record)) {
                throw new InvalidArgumentException('Area-effect cloud record is invalid.');
            }
            $cooldowns = $record['cooldowns'] ?? null;
            if (!is_array($cooldowns)) {
                throw new InvalidArgumentException('Area-effect cloud cooldowns are invalid.');
            }
            $validatedCooldowns = [];
            foreach ($cooldowns as $identity => $eligibleAt) {
                if (!is_string($identity) || !is_int($eligibleAt)) {
                    throw new InvalidArgumentException('Area-effect cloud cooldown entry is invalid.');
                }
                $validatedCooldowns[$identity] = $eligibleAt;
            }
            $clouds[] = new AreaEffectCloud(
                self::integer($record, 'unique'),
                self::integer($record, 'runtime'),
                self::string($record, 'owner'),
                self::potion($record),
                self::position($record),
                self::integer($record, 'age'),
                self::integer($record, 'duration'),
                self::number($record, 'radius'),
                $validatedCooldowns,
            );
        }

        return [$projectiles, $clouds];
    }

    /** @param array<mixed> $record */
    private static function potion(array $record): PotionType
    {
        return PotionType::tryFrom(self::integer($record, 'potion'))
            ?? throw new InvalidArgumentException('Potion entity references an unknown potion type.');
    }

    /** @param array<mixed> $record */
    private static function position(array $record): Position
    {
        $values = self::vector($record, 'position');
        return new Position($values[0], $values[1], $values[2]);
    }

    /** @param array<mixed> $record */
    private static function motion(array $record): EntityMotion
    {
        $values = self::vector($record, 'motion');
        return new EntityMotion($values[0], $values[1], $values[2]);
    }

    /**
     * @param array<mixed> $record
     * @return array{float, float, float}
     */
    private static function vector(array $record, string $key): array
    {
        $value = $record[$key] ?? null;
        if (!is_array($value) || !array_is_list($value) || count($value) !== 3) {
            throw new InvalidArgumentException('Potion entity vector is invalid.');
        }
        return [self::numeric($value[0]), self::numeric($value[1]), self::numeric($value[2])];
    }

    /** @param array<mixed> $record */
    private static function integer(array $record, string $key): int
    {
        $value = $record[$key] ?? null;
        if (!is_int($value)) {
            throw new InvalidArgumentException('Potion entity integer field is invalid.');
        }
        return $value;
    }

    /** @param array<mixed> $record */
    private static function string(array $record, string $key): string
    {
        $value = $record[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException('Potion entity string field is invalid.');
        }
        return $value;
    }

    /** @param array<mixed> $record */
    private static function boolean(array $record, string $key): bool
    {
        $value = $record[$key] ?? null;
        if (!is_bool($value)) {
            throw new InvalidArgumentException('Potion entity boolean field is invalid.');
        }
        return $value;
    }

    /** @param array<mixed> $record */
    private static function number(array $record, string $key): float
    {
        return self::numeric($record[$key] ?? null);
    }

    /** @param array<mixed> $record */
    private static function optionalNumber(array $record, string $key, float $default): float
    {
        return array_key_exists($key, $record) ? self::number($record, $key) : $default;
    }

    /** @param array<mixed> $record */
    private static function optionalInteger(array $record, string $key, int $default): int
    {
        return array_key_exists($key, $record) ? self::integer($record, $key) : $default;
    }

    /** @param array<mixed> $record */
    private static function optionalBoolean(array $record, string $key, bool $default): bool
    {
        return array_key_exists($key, $record) ? self::boolean($record, $key) : $default;
    }

    /** @param array<mixed> $record */
    private static function optionalProjectileType(array $record, ProjectileType $default): ProjectileType
    {
        if (!array_key_exists('type', $record)) {
            return $default;
        }

        return ProjectileType::tryFrom(self::string($record, 'type'))
            ?? throw new InvalidArgumentException('Projectile entity type is invalid.');
    }

    /**
     * @param array<mixed> $record
     * @return list<string>
     */
    private static function optionalStringList(array $record, string $key): array
    {
        if (!array_key_exists($key, $record)) {
            return [];
        }
        $value = $record[$key];
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('Projectile string list is invalid.');
        }
        $validated = [];
        foreach ($value as $entry) {
            if (!is_string($entry)) {
                throw new InvalidArgumentException('Projectile string list entry is invalid.');
            }
            $validated[] = $entry;
        }

        return $validated;
    }

    /** @return array{identifier: string, count: int, networkId: int, damage: int, nbt: string, aux: int}|null */
    private static function encodedStack(?InventoryStack $stack): ?array
    {
        if ($stack === null) {
            return null;
        }

        return [
            'identifier' => $stack->identifier,
            'count' => $stack->count,
            'networkId' => $stack->stackNetworkId,
            'damage' => $stack->damage,
            'nbt' => base64_encode($stack->nbt?->toBinary() ?? ''),
            'aux' => $stack->auxValue,
        ];
    }

    /** @param array<mixed> $record */
    private static function optionalStack(array $record, string $key): ?InventoryStack
    {
        $value = $record[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('Projectile carried item is invalid.');
        }
        $nbt = self::string($value, 'nbt');
        $decodedNbt = base64_decode($nbt, true);
        if ($decodedNbt === false) {
            throw new InvalidArgumentException('Projectile carried item NBT is invalid.');
        }

        return new InventoryStack(
            self::string($value, 'identifier'),
            self::integer($value, 'count'),
            self::integer($value, 'networkId'),
            damage: self::integer($value, 'damage'),
            nbt: $decodedNbt === '' ? null : ItemNbt::fromBinary($decodedNbt),
            auxValue: self::integer($value, 'aux'),
        );
    }

    private static function numeric(mixed $value): float
    {
        if (!is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException('Potion entity numeric field is invalid.');
        }
        return (float) $value;
    }
}
