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

use Bedriox\Api\Entity\Value\FoxVariant;
use Bedriox\Api\Entity\Vanilla\Fox;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

final class FoxEntity extends LandBreedableAnimalEntity implements Fox
{
    public function __construct(
        string $uniqueId,
        int $runtimeId,
        string $worldName,
        Position $position,
        ?AiBehaviorDefinition $behavior = null,
        EntityMotion $motion = new EntityMotion(),
        float $yaw = 0.0,
        float $pitch = 0.0,
        ?float $health = null,
        bool $baby = false,
        private FoxVariant $variant = FoxVariant::RED,
        private ?string $primaryTrustedPlayerUniqueId = null,
        private ?string $secondaryTrustedPlayerUniqueId = null,
        private bool $sleeping = false,
    ) {
        parent::__construct($uniqueId, $runtimeId, LandAnimalEntityDefinitions::fox(), $worldName, $position, $behavior ?? LandAnimalAiBehaviors::passive('fox', ['minecraft:sweet_berries', 'minecraft:glow_berries'], 0.10), $motion, $yaw, $pitch, $health);
        $this->initializeBreedableState($baby);
        $this->primaryTrustedPlayerUniqueId = $primaryTrustedPlayerUniqueId === null
            ? null
            : EntityUuid::validate($primaryTrustedPlayerUniqueId);
        $this->secondaryTrustedPlayerUniqueId = $secondaryTrustedPlayerUniqueId === null
            ? null
            : EntityUuid::validate($secondaryTrustedPlayerUniqueId);
    }

    public function getVariant(): FoxVariant
    {
        return $this->variant;
    }

    public function isTrusting(): bool
    {
        return $this->primaryTrustedPlayerUniqueId !== null || $this->secondaryTrustedPlayerUniqueId !== null;
    }

    public function trustsPlayer(string $playerUniqueId): bool
    {
        $playerUniqueId = strtolower(EntityUuid::validate($playerUniqueId));

        return ($this->primaryTrustedPlayerUniqueId !== null && strtolower($this->primaryTrustedPlayerUniqueId) === $playerUniqueId)
            || ($this->secondaryTrustedPlayerUniqueId !== null && strtolower($this->secondaryTrustedPlayerUniqueId) === $playerUniqueId);
    }

    public function isSleeping(): bool
    {
        return $this->sleeping;
    }

    public function getTrustedPlayerUniqueIds(): array
    {
        return array_values(array_filter([
            $this->primaryTrustedPlayerUniqueId,
            $this->secondaryTrustedPlayerUniqueId,
        ], static fn(?string $value): bool => $value !== null));
    }

    /** @internal Authoritative offspring trust mutation. */
    public function addTrustedPlayerUniqueId(string $playerUniqueId): void
    {
        $playerUniqueId = EntityUuid::validate($playerUniqueId);
        if ($this->trustsPlayer($playerUniqueId)) {
            return;
        }
        if ($this->primaryTrustedPlayerUniqueId === null) {
            $this->primaryTrustedPlayerUniqueId = $playerUniqueId;
        } else {
            $this->secondaryTrustedPlayerUniqueId = $playerUniqueId;
        }
        $this->markPresentationChanged();
    }

    /** @internal Authoritative rest-state mutation. */
    public function setSleeping(bool $sleeping): void
    {
        if ($this->sleeping !== $sleeping) {
            $this->sleeping = $sleeping;
            $this->markPresentationChanged();
        }
    }

    /** @internal Authoritative species-state mutation. */
    public function setVariant(FoxVariant $variant): void
    {
        if ($this->variant !== $variant) {
            $this->variant = $variant;
            $this->markPresentationChanged();
        }
    }

    protected function speciesPersistenceVariant(): int
    {
        return $this->variant->value;
    }

    /** @return array{primaryTrustedPlayerUniqueId: ?string, secondaryTrustedPlayerUniqueId: ?string, sleeping: bool} */
    protected function speciesPersistenceData(): array
    {
        return [
            'primaryTrustedPlayerUniqueId' => $this->primaryTrustedPlayerUniqueId,
            'secondaryTrustedPlayerUniqueId' => $this->secondaryTrustedPlayerUniqueId,
            'sleeping' => $this->sleeping,
        ];
    }

    protected function restoreSpeciesPersistenceState(int|string|null $variant, array $data): void
    {
        if (!is_int($variant) || ($foxVariant = FoxVariant::tryFrom($variant)) === null
            || ($data['primaryTrustedPlayerUniqueId'] !== null && !is_string($data['primaryTrustedPlayerUniqueId']))
            || ($data['secondaryTrustedPlayerUniqueId'] !== null && !is_string($data['secondaryTrustedPlayerUniqueId']))
            || !is_bool($data['sleeping'])) {
            throw new InvalidArgumentException('Persisted fox variant is unsupported.');
        }
        $this->variant = $foxVariant;
        $this->primaryTrustedPlayerUniqueId = $data['primaryTrustedPlayerUniqueId'] === null
            ? null
            : EntityUuid::validate($data['primaryTrustedPlayerUniqueId']);
        $this->secondaryTrustedPlayerUniqueId = $data['secondaryTrustedPlayerUniqueId'] === null
            ? null
            : EntityUuid::validate($data['secondaryTrustedPlayerUniqueId']);
        $this->sleeping = $data['sleeping'];
    }
}
