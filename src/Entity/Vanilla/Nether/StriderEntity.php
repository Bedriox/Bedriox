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

namespace Bedriox\Server\Entity\Vanilla\Nether;

use Bedriox\Api\Entity\Value\MountSeat;
use Bedriox\Api\Entity\Vanilla\Strider;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\Ai\VanillaAiBehaviors;
use Bedriox\Server\Entity\BreedableAnimalEntity;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Persistence\IntrinsicEntityPersistence;
use Bedriox\Server\Entity\VanillaEntityDefinitions;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use JsonException;

final class StriderEntity extends BreedableAnimalEntity implements Strider, IntrinsicEntityPersistence
{
    private bool $warm = true;

    public function __construct(string $uniqueId, int $runtimeId, string $worldName, Position $position, ?AiBehaviorDefinition $behavior = null, EntityMotion $motion = new EntityMotion(), float $yaw = 0.0, float $pitch = 0.0, ?float $health = null, bool $baby = false, private bool $saddled = false)
    {
        parent::__construct($uniqueId, $runtimeId, VanillaEntityDefinitions::strider(), $worldName, $position, $behavior ?? VanillaAiBehaviors::strider(), $motion, $yaw, $pitch, $health);
        $this->initializeBreedableState($baby);
    }

    public function isSaddled(): bool
    {
        return $this->saddled;
    }

    public function setSaddled(bool $saddled): void
    {
        if ($this->saddled !== $saddled) {
            $this->saddled = $saddled;
            $this->markPresentationChanged();
        }
    }

    public function isWarm(): bool
    {
        return $this->warm;
    }

    /** @internal Updated from the authoritative lava-support query. */
    public function setWarm(bool $warm): void
    {
        if ($this->warm !== $warm) {
            $this->warm = $warm;
            $this->markPresentationChanged();
        }
    }

    public function getSeatCapacity(): int
    {
        return $this->saddled ? 1 : 0;
    }

    public function mountedPassengerOffsetY(MountSeat $seat, float $passengerHeight, bool $playerPassenger): float
    {
        return parent::mountedPassengerOffsetY($seat, $passengerHeight, $playerPassenger) + 0.65;
    }

    public function persistenceVariant(): null
    {
        return null;
    }

    public function persistenceSchemaVersion(): int
    {
        return 2;
    }

    public function persistenceData(): string
    {
        return json_encode([...$this->breedablePersistenceData(), 'saddled' => $this->saddled, 'warm' => $this->warm], JSON_THROW_ON_ERROR);
    }

    public function restorePersistenceState(int|string|null $variant, int $schemaVersion, string $data): void
    {
        if ($variant !== null || $schemaVersion !== 2 || strlen($data) > 256) {
            throw new InvalidArgumentException('Persisted strider state has an unsupported schema.');
        }
        try {
            $decoded = json_decode($data, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Persisted strider state is malformed.', previous: $error);
        }
        if (!is_array($decoded)
            || array_keys($decoded) !== ['baby', 'babyGrowthTicks', 'breedingCooldownTicks', 'loveTicks', 'saddled', 'warm']
            || !is_bool($decoded['saddled']) || !is_bool($decoded['warm'])) {
            throw new InvalidArgumentException('Persisted strider state is malformed.');
        }
        $this->restoreBreedablePersistenceData($decoded);
        $this->saddled = $decoded['saddled'];
        $this->warm = $decoded['warm'];
    }
}
