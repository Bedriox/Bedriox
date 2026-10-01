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

namespace Bedriox\Api\Event\Entity;

use Bedriox\Api\Entity\Capability\Breedable;
use Bedriox\Api\Entity\EntityType;
use Bedriox\Api\Event\CancellableEvent;
use InvalidArgumentException;

final class EntityBreedEvent extends CancellableEvent
{
    public function __construct(
        public readonly Breedable $firstParent,
        public readonly Breedable $secondParent,
        public readonly EntityType $childType,
        private int $experience,
    ) {
        self::validateExperience($experience);
    }
    public function getExperience(): int
    {
        return $this->experience;
    }
    public function setExperience(int $experience): void
    {
        $this->assertMutable();
        self::validateExperience($experience);
        $this->experience = $experience;
    }
    protected function state(): mixed
    {
        return [parent::state(), $this->experience];
    }
    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !is_int($state[1])) {
            throw new InvalidArgumentException('Invalid entity breed event state.');
        } parent::replaceState($state[0]);
        self::validateExperience($state[1]);
        $this->experience = $state[1];
    }
    private static function validateExperience(int $experience): void
    {
        if ($experience < 0 || $experience > 100) {
            throw new InvalidArgumentException('Breeding experience must be between 0 and 100.');
        }
    }
}
