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

namespace Bedriox\Server\Gameplay\Potion;

use Bedriox\Api\Potion\PotionType;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use OverflowException;

/** Bounded authoritative owner for active lingering-potion clouds. */
final class AreaEffectCloudRegistry
{
    public const int DEFAULT_CAPACITY = 512;
    public const int MAXIMUM_CAPACITY = 4_096;

    /** @var array<int, AreaEffectCloud> */
    private array $clouds = [];
    private int $nextEntityId;

    public function __construct(
        private readonly int $capacity = self::DEFAULT_CAPACITY,
        int $firstEntityId = 1,
    ) {
        if ($capacity < 1 || $capacity > self::MAXIMUM_CAPACITY
            || $firstEntityId < 1 || $firstEntityId >= PHP_INT_MAX) {
            throw new InvalidArgumentException('Area-effect cloud registry bounds are invalid.');
        }
        $this->nextEntityId = $firstEntityId;
    }

    public function spawn(string $ownerUuid, PotionType $type, Position $position): AreaEffectCloud
    {
        if (count($this->clouds) >= $this->capacity || $this->nextEntityId >= PHP_INT_MAX) {
            throw new OverflowException('Area-effect cloud registry is exhausted.');
        }
        $id = $this->nextEntityId++;
        $cloud = new AreaEffectCloud($id, $id, $ownerUuid, $type, $position);
        $this->clouds[$id] = $cloud;

        return $cloud;
    }

    public function replace(AreaEffectCloud $cloud): void
    {
        if (!isset($this->clouds[$cloud->runtimeEntityId])) {
            throw new InvalidArgumentException('Area-effect cloud is not registered.');
        }
        $this->clouds[$cloud->runtimeEntityId] = $cloud;
    }

    public function remove(int $runtimeEntityId): ?AreaEffectCloud
    {
        $cloud = $this->clouds[$runtimeEntityId] ?? null;
        unset($this->clouds[$runtimeEntityId]);

        return $cloud;
    }

    public function restore(AreaEffectCloud $cloud): void
    {
        if (count($this->clouds) >= $this->capacity || isset($this->clouds[$cloud->runtimeEntityId])) {
            throw new OverflowException('Area-effect cloud restore exceeds registry bounds or duplicates identity.');
        }
        $this->clouds[$cloud->runtimeEntityId] = $cloud;
        $this->nextEntityId = max($this->nextEntityId, $cloud->runtimeEntityId + 1);
    }

    public function get(int $runtimeEntityId): ?AreaEffectCloud
    {
        return $this->clouds[$runtimeEntityId] ?? null;
    }

    public function affected(int $runtimeEntityId, string $victimUuid): ?AreaEffectCloud
    {
        $cloud = $this->clouds[$runtimeEntityId] ?? null;
        if ($cloud === null || !$cloud->canAffect($victimUuid)) {
            return $cloud;
        }
        $cloud = $cloud->afterAffecting($victimUuid);
        if ($cloud->expired()) {
            unset($this->clouds[$runtimeEntityId]);

            return null;
        }
        $this->clouds[$runtimeEntityId] = $cloud;

        return $cloud;
    }

    /** @return list<AreaEffectCloud> */
    public function tick(): array
    {
        $updated = [];
        foreach ($this->clouds as $runtimeId => $cloud) {
            $cloud = $cloud->tick();
            if ($cloud->expired()) {
                unset($this->clouds[$runtimeId]);
                continue;
            }
            $this->clouds[$runtimeId] = $cloud;
            $updated[] = $cloud;
        }

        return $updated;
    }

    /** @return list<AreaEffectCloud> */
    public function all(): array
    {
        return array_values($this->clouds);
    }
}
