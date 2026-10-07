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

namespace Bedriox\Server\Entity\Vanilla;

use Bedriox\Api\Entity\Controller\CreeperController;
use Bedriox\Api\Entity\Vanilla\Creeper;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\Ai\VanillaAiBehaviors;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\MonsterEntity;
use Bedriox\Server\Entity\Persistence\IntrinsicEntityPersistence;
use Bedriox\Server\Entity\VanillaEntityDefinitions;
use Bedriox\Server\Plugin\BufferedCreeperController;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use JsonException;
use LogicException;

final class CreeperEntity extends MonsterEntity implements Creeper, IntrinsicEntityPersistence
{
    private const int FUSE_DURATION_TICKS = 30;

    private bool $charged = false;
    private bool $ignited = false;
    private int $fuseTicks = 0;
    private bool $proximityIgnited = false;

    public function __construct(string $uniqueId, int $runtimeId, string $worldName, Position $position, ?AiBehaviorDefinition $behavior = null, EntityMotion $motion = new EntityMotion(), float $yaw = 0.0, float $pitch = 0.0, ?float $health = null)
    {
        parent::__construct($uniqueId, $runtimeId, VanillaEntityDefinitions::creeper(), $worldName, $position, $behavior ?? VanillaAiBehaviors::creeper(), $motion, $yaw, $pitch, $health);
    }

    public function isCharged(): bool
    {
        return $this->charged;
    }

    public function getController(): CreeperController
    {
        $controller = parent::getController();
        if (!$controller instanceof CreeperController) {
            throw new LogicException('A creeper must expose a creeper controller.');
        }
        return $controller;
    }

    protected function createController(?PluginActionBuffer $actions): CreeperController
    {
        return new BufferedCreeperController($actions, $this, $this->equipmentState());
    }

    public function isIgnited(): bool
    {
        return $this->ignited;
    }

    public function getFuseTicks(): int
    {
        return $this->fuseTicks;
    }

    /** @internal Advances the authoritative fuse without allowing unbounded tick input. */
    public function advanceFuse(int $ticks = 1): bool
    {
        if ($ticks < 1 || $ticks > self::FUSE_DURATION_TICKS) {
            throw new InvalidArgumentException('Creeper fuse advancement is outside its supported bounds.');
        }
        if (!$this->ignited || $this->fuseTicks === self::FUSE_DURATION_TICKS) {
            return $this->fuseTicks === self::FUSE_DURATION_TICKS;
        }
        $this->fuseTicks = min(self::FUSE_DURATION_TICKS, $this->fuseTicks + $ticks);
        $this->markPresentationChanged();

        return $this->fuseTicks === self::FUSE_DURATION_TICKS;
    }

    /** @internal */
    public function setCharged(bool $charged): void
    {
        if ($this->charged !== $charged) {
            $this->charged = $charged;
            $this->markPresentationChanged();
        }
    }

    /** @internal */
    public function setIgnited(bool $ignited): void
    {
        $this->proximityIgnited = false;
        $this->changeIgnited($ignited);
    }

    /** @internal */
    public function beginProximityFuse(): void
    {
        $this->proximityIgnited = true;
        $this->changeIgnited(true);
    }

    /** @internal */
    public function cancelProximityFuse(): bool
    {
        if ($this->proximityIgnited) {
            $this->proximityIgnited = false;
            $this->changeIgnited(false);
            return true;
        }

        return false;
    }

    /** @internal */
    private function changeIgnited(bool $ignited): void
    {
        if ($this->ignited !== $ignited) {
            $this->ignited = $ignited;
            if (!$ignited) {
                $this->fuseTicks = 0;
            }
            $this->markPresentationChanged();
        }
    }

    public function persistenceVariant(): null
    {
        return null;
    }

    public function persistenceSchemaVersion(): int
    {
        return 1;
    }

    public function persistenceData(): string
    {
        return json_encode([
            'charged' => $this->charged,
            'fuseTicks' => $this->fuseTicks,
            'ignited' => $this->ignited,
            'proximityIgnited' => $this->proximityIgnited,
        ], JSON_THROW_ON_ERROR);
    }

    public function restorePersistenceState(int|string|null $variant, int $schemaVersion, string $data): void
    {
        if ($variant !== null || $schemaVersion !== 1 || strlen($data) > 96) {
            throw new InvalidArgumentException('Persisted creeper state has an unsupported schema.');
        }
        try {
            $decoded = json_decode($data, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Persisted creeper state is malformed.', previous: $error);
        }
        if (!is_array($decoded) || array_keys($decoded) !== ['charged', 'fuseTicks', 'ignited', 'proximityIgnited']
            || !is_bool($decoded['charged']) || !is_int($decoded['fuseTicks']) || !is_bool($decoded['ignited'])
            || !is_bool($decoded['proximityIgnited'])
            || $decoded['fuseTicks'] < 0 || $decoded['fuseTicks'] > self::FUSE_DURATION_TICKS
            || (!$decoded['ignited'] && ($decoded['fuseTicks'] !== 0 || $decoded['proximityIgnited']))
            || ($decoded['proximityIgnited'] && !$decoded['ignited'])) {
            throw new InvalidArgumentException('Persisted creeper state is malformed.');
        }
        $this->charged = $decoded['charged'];
        $this->fuseTicks = $decoded['fuseTicks'];
        $this->ignited = $decoded['ignited'];
        $this->proximityIgnited = $decoded['proximityIgnited'];
    }
}
