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

namespace Bedriox\Api\Event\Entity\Villager;

use Bedriox\Api\Entity\Value\VillagerProfession;
use Bedriox\Api\Entity\Vanilla\Villager;
use Bedriox\Api\Event\CancellableEvent;
use InvalidArgumentException;

/** Cancellable profession transition before the villager and its offers are mutated. */
final class VillagerProfessionChangeEvent extends CancellableEvent
{
    public function __construct(
        public readonly Villager $villager,
        public readonly VillagerProfession $previousProfession,
        private VillagerProfession $profession,
    ) {}

    public function profession(): VillagerProfession
    {
        return $this->profession;
    }

    public function setProfession(VillagerProfession $profession): void
    {
        $this->assertMutable();
        $this->profession = $profession;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->profession];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0])
            || !$state[1] instanceof VillagerProfession) {
            throw new InvalidArgumentException('Invalid villager profession-change event state.');
        }
        parent::replaceState($state[0]);
        $this->profession = $state[1];
    }
}
