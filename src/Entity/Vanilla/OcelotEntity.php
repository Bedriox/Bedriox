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

use Bedriox\Api\Entity\Vanilla\Ocelot;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

final class OcelotEntity extends LandBreedableAnimalEntity implements Ocelot
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
        private ?string $trustedPlayerUniqueId = null,
    ) {
        parent::__construct($uniqueId, $runtimeId, LandAnimalEntityDefinitions::ocelot(), $worldName, $position, $behavior ?? LandAnimalAiBehaviors::passive('ocelot', ['minecraft:cod', 'minecraft:salmon'], 0.10), $motion, $yaw, $pitch, $health);
        $this->initializeBreedableState($baby);
        $this->trustedPlayerUniqueId = $trustedPlayerUniqueId === null
            ? null
            : EntityUuid::validate($trustedPlayerUniqueId);
    }

    public function isTrusting(): bool
    {
        return $this->trustedPlayerUniqueId !== null;
    }

    public function getTrustedPlayerUniqueId(): ?string
    {
        return $this->trustedPlayerUniqueId;
    }

    /** @internal Authoritative trust mutation. */
    public function setTrustedPlayerUniqueId(?string $playerUniqueId): void
    {
        $playerUniqueId = $playerUniqueId === null ? null : EntityUuid::validate($playerUniqueId);
        if ($this->trustedPlayerUniqueId !== $playerUniqueId) {
            $this->trustedPlayerUniqueId = $playerUniqueId;
            $this->markPresentationChanged();
        }
    }

    /** @return array{trustedPlayerUniqueId: ?string} */
    protected function speciesPersistenceData(): array
    {
        return ['trustedPlayerUniqueId' => $this->trustedPlayerUniqueId];
    }

    protected function restoreSpeciesPersistenceState(int|string|null $variant, array $data): void
    {
        if ($variant !== null || ($data['trustedPlayerUniqueId'] !== null && !is_string($data['trustedPlayerUniqueId']))) {
            throw new InvalidArgumentException('Persisted ocelot trust state is malformed.');
        }
        $this->trustedPlayerUniqueId = $data['trustedPlayerUniqueId'] === null
            ? null
            : EntityUuid::validate($data['trustedPlayerUniqueId']);
    }
}
