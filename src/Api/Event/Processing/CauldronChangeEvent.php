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
use Bedriox\Api\Processing\CauldronChangeCause;
use Bedriox\Api\Processing\CauldronContentType;
use Bedriox\Api\World\BlockPosition;
use InvalidArgumentException;

final class CauldronChangeEvent extends CancellableEvent
{
    public readonly int $oldLevel;
    private CauldronContentType $newContent;
    private int $newLevel;
    public function __construct(public readonly ?Player $player, public readonly BlockPosition $position, public readonly CauldronContentType $oldContent, int $oldLevel, CauldronContentType $newContent, int $newLevel, public readonly CauldronChangeCause $cause, public readonly ?ItemStack $item = null)
    {
        $this->oldLevel = ProcessingEventValues::level($oldLevel, 6);
        $this->setOutcomeInternal($newContent, $newLevel);
    }public function newContent(): CauldronContentType
    {
        return $this->newContent;
    }public function newLevel(): int
    {
        return $this->newLevel;
    }public function setOutcome(CauldronContentType $content, int $level): void
    {
        $this->assertMutable();
        $this->setOutcomeInternal($content, $level);
    }protected function state(): mixed
    {
        return[parent::state(),$this->newContent,$this->newLevel];
    }protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 3 || !is_bool($state[0]) || !$state[1] instanceof CauldronContentType || !is_int($state[2])) {
            throw new InvalidArgumentException('Invalid cauldron event state.');
        }parent::replaceState($state[0]);
        $this->setOutcomeInternal($state[1], $state[2]);
    }private function setOutcomeInternal(CauldronContentType $content, int $level): void
    {
        $level = ProcessingEventValues::level($level, 6);
        if (($content === CauldronContentType::EMPTY) !== ($level === 0)) {
            throw new InvalidArgumentException('Empty cauldrons require level zero and filled cauldrons require a positive level.');
        }$this->newContent = $content;
        $this->newLevel = $level;
    }
}
