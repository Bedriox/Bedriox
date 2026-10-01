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

namespace Bedriox\Server\Entity;

use Bedriox\Api\Entity\Capability\Sittable;
use Bedriox\Api\Entity\Capability\Tameable;
use InvalidArgumentException;

/** Shared bounded ownership, posture, and anger state for tameable animals. */
abstract class TameableAnimalEntity extends BreedableAnimalEntity implements Tameable, Sittable
{
    private ?string $ownerUniqueId = null;
    private bool $sitting = false;

    final protected function initializeTameableState(
        ?string $ownerUniqueId = null,
        bool $sitting = false,
    ): void {
        self::validateState($ownerUniqueId, $sitting);
        $this->ownerUniqueId = $ownerUniqueId;
        $this->sitting = $sitting;
        if ($sitting) {
            $currentMotion = $this->getMotion();
            $this->setMotion(new EntityMotion(0.0, $currentMotion->y, 0.0));
        }
    }

    final public function isTamed(): bool
    {
        return $this->ownerUniqueId !== null;
    }

    final public function getOwnerUniqueId(): ?string
    {
        return $this->ownerUniqueId;
    }

    final public function isSitting(): bool
    {
        return $this->sitting;
    }

    final public function setOwnerUniqueId(?string $ownerUniqueId): void
    {
        if ($ownerUniqueId !== null) {
            $ownerUniqueId = EntityUuid::validate($ownerUniqueId);
        }
        if ($this->ownerUniqueId === $ownerUniqueId) {
            return;
        }
        $this->ownerUniqueId = $ownerUniqueId;
        if ($ownerUniqueId === null) {
            $this->sitting = false;
        }
        $this->markPresentationChanged();
    }

    final public function setSitting(bool $sitting): void
    {
        if ($sitting && !$this->isTamed()) {
            throw new InvalidArgumentException('An untamed animal cannot be ordered to sit.');
        }
        if ($this->sitting !== $sitting) {
            $this->sitting = $sitting;
            if ($sitting) {
                $motion = $this->getMotion();
                $this->setMotion(new EntityMotion(0.0, $motion->y, 0.0));
            }
            $this->markPresentationChanged();
        }
    }

    /** @return array{ownerUniqueId: ?string, sitting: bool} */
    final protected function tameablePersistenceData(): array
    {
        return [
            'ownerUniqueId' => $this->ownerUniqueId,
            'sitting' => $this->sitting,
        ];
    }

    /** @param array<mixed> $data */
    final protected function restoreTameablePersistenceData(array $data): void
    {
        foreach (['ownerUniqueId', 'sitting'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new InvalidArgumentException('Persisted tameable state is incomplete.');
            }
        }
        if (($data['ownerUniqueId'] !== null && !is_string($data['ownerUniqueId']))
            || !is_bool($data['sitting'])) {
            throw new InvalidArgumentException('Persisted tameable state is malformed.');
        }
        self::validateState(
            $data['ownerUniqueId'],
            $data['sitting'],
        );
        $this->ownerUniqueId = $data['ownerUniqueId'];
        $this->sitting = $data['sitting'];
        if ($this->sitting) {
            $motion = $this->getMotion();
            $this->setMotion(new EntityMotion(0.0, $motion->y, 0.0));
        }
    }

    private static function validateState(
        ?string $ownerUniqueId,
        bool $sitting,
    ): void {
        if ($ownerUniqueId !== null) {
            EntityUuid::validate($ownerUniqueId);
        }
        if ($sitting && $ownerUniqueId === null) {
            throw new InvalidArgumentException('Persisted sitting state requires an owner.');
        }
    }
}
