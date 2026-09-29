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

namespace Bedriox\Api\Event\Processing;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\Player;
use Bedriox\Api\Processing\FurnaceType;
use Bedriox\Api\World\BlockPosition;
use InvalidArgumentException;

final class FurnaceExtractEvent extends CancellableEvent
{
    public function __construct(public readonly Player $player, public readonly BlockPosition $position, public readonly FurnaceType $furnaceType, public readonly ItemStack $result, private int $experience)
    {
        $this->experience = ProcessingEventValues::experience($experience);
    }
    public function experience(): int
    {
        return $this->experience;
    }
    public function setExperience(int $experience): void
    {
        $this->assertMutable();
        $this->experience = ProcessingEventValues::experience($experience);
    }
    protected function state(): mixed
    {
        return [parent::state(),$this->experience];
    }
    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !is_int($state[1])) {
            throw new InvalidArgumentException('Invalid furnace-extract event state.');
        } parent::replaceState($state[0]);
        $this->experience = ProcessingEventValues::experience($state[1]);
    }
}
