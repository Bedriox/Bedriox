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

namespace Bedriox\Server\Plugin;

use Bedriox\Api\Entity\CustomEntityType;
use Bedriox\Api\Entity\CustomMobDefinition;
use Bedriox\Api\Entity\EntityRegistrar;
use Bedriox\Api\Entity\Human;
use Bedriox\Api\Entity\Skin;
use Bedriox\Api\Visibility\VisibilityPolicy;
use Bedriox\Api\World\Position;
use Bedriox\Server\Plugin\Entity\HumanOwnershipRegistry;
use Closure;
use InvalidArgumentException;
use Throwable;

final readonly class OwnedEntityRegistrar implements EntityRegistrar
{
    /** @var null|Closure(CustomEntityType, Position, float, float): bool */
    private ?Closure $spawner;
    /** @var null|Closure(Position, string, Skin, float, float, VisibilityPolicy): Human */
    private ?Closure $humanSpawner;

    /**
     * @param null|callable(CustomEntityType, Position, float, float): bool $spawner
     * @param null|callable(Position, string, Skin, float, float, VisibilityPolicy): Human $humanSpawner
     */
    public function __construct(
        private string $plugin,
        private PluginEntityRegistrar $registrar,
        private ?PluginActionBuffer $actions = null,
        ?callable $spawner = null,
        ?callable $humanSpawner = null,
        private ?HumanOwnershipRegistry $humanOwnership = null,
    ) {
        $this->spawner = $spawner === null ? null : Closure::fromCallable($spawner);
        $this->humanSpawner = $humanSpawner === null ? null : Closure::fromCallable($humanSpawner);
    }

    public function register(CustomMobDefinition $definition, bool $replace = false): void
    {
        $this->registrar->register($this->plugin, $definition, $replace);
    }

    public function spawn(CustomEntityType $type, Position $position, float $yaw = 0.0, float $pitch = 0.0): void
    {
        $registration = $this->registrar->definition($type);
        if ($registration === null || strcasecmp($registration->owner, $this->plugin) !== 0) {
            throw new PluginException('Plugin may spawn only custom entity types it currently owns.');
        }
        if (!is_finite($position->x) || !is_finite($position->y) || !is_finite($position->z)
            || abs($position->x) > 30_000_000.0 || abs($position->z) > 30_000_000.0
            || abs($position->y) > 2_048.0 || !is_finite($yaw) || !is_finite($pitch)
            || $pitch < -90.0 || $pitch > 90.0) {
            throw new InvalidArgumentException('Custom entity spawn transform is outside world bounds.');
        }
        if ($this->spawner === null) {
            throw new PluginException('Custom entity spawning is unavailable.');
        }
        $spawn = function () use ($type, $position, $yaw, $pitch): void {
            if (!(($this->spawner)($type, $position, $yaw, $pitch))) {
                throw new PluginException('The authoritative simulation rejected the custom entity spawn.');
            }
        };
        if ($this->actions?->isCapturing() === true) {
            $this->actions->stage($spawn);
        } else {
            $spawn();
        }
    }

    public function spawnHuman(
        Position $position,
        string $name,
        Skin $skin,
        float $yaw = 0.0,
        float $pitch = 0.0,
        VisibilityPolicy $visibility = new VisibilityPolicy(),
    ): Human {
        $position->validate();
        if ($this->humanSpawner === null) {
            throw new PluginException('Human spawning is unavailable.');
        }
        $human = ($this->humanSpawner)($position, $name, $skin, $yaw, $pitch, $visibility);
        try {
            $this->humanOwnership?->track($this->plugin, $human);
        } catch (Throwable $failure) {
            if ($human->getController()->isAvailable()) {
                $human->getController()->despawn();
            }
            throw $failure;
        }

        return $human;
    }
}
