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

use Bedriox\Api\Entity\Entity;
use Bedriox\Api\Entity\Value\EntityBlockChangeReason;
use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\World\Block;
use InvalidArgumentException;

/** Cancellable, adjustable block replacement requested by an entity behavior. */
final class EntityBlockChangeEvent extends CancellableEvent
{
    public function __construct(
        public readonly Entity $entity,
        public readonly Block $from,
        private Block $to,
        public readonly EntityBlockChangeReason $reason,
    ) {
        self::validateReplacement($from, $to);
    }

    public function to(): Block
    {
        return $this->to;
    }

    public function setTo(Block $to): void
    {
        $this->assertMutable();
        self::validateReplacement($this->from, $to);
        $this->to = $to;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->to];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !$state[1] instanceof Block) {
            throw new InvalidArgumentException('Invalid entity block-change event state.');
        }
        self::validateReplacement($this->from, $state[1]);
        parent::replaceState($state[0]);
        $this->to = $state[1];
    }

    private static function validateReplacement(Block $from, Block $to): void
    {
        if ($from->position != $to->position) {
            throw new InvalidArgumentException('Entity block changes must retain the authoritative block position.');
        }
    }
}
