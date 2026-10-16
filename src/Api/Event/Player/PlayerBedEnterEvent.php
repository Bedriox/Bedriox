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

namespace Bedriox\Api\Event\Player;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Player\Player;
use Bedriox\Api\TranslatableMessage;
use Bedriox\Api\World\BlockPosition;
use InvalidArgumentException;

final class PlayerBedEnterEvent extends CancellableEvent
{
    public readonly PlayerBedEnterResult $originalResult;
    private PlayerBedEnterResult $result;

    public function __construct(
        public readonly Player $player,
        public readonly BlockPosition $bed,
        PlayerBedEnterResult $result,
        private string|TranslatableMessage|null $message,
    ) {
        $this->originalResult = $result;
        $this->result = $result;
        $this->validateMessage($message);
    }

    public function result(): PlayerBedEnterResult
    {
        return $this->result;
    }

    public function setResult(PlayerBedEnterResult $result): void
    {
        $this->assertMutable();
        if ($result === PlayerBedEnterResult::ALLOWED && !$this->originalResult->mayBeOverridden()) {
            throw new InvalidArgumentException('This bed-enter failure cannot be overridden safely.');
        }
        $this->result = $result;
    }

    public function message(): string|TranslatableMessage|null
    {
        return $this->message;
    }

    public function setMessage(string|TranslatableMessage|null $message): void
    {
        $this->assertMutable();
        $this->validateMessage($message);
        $this->message = $message;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->result, $this->message];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 3
            || !is_bool($state[0])
            || !$state[1] instanceof PlayerBedEnterResult
            || (!is_string($state[2]) && !$state[2] instanceof TranslatableMessage && $state[2] !== null)) {
            throw new InvalidArgumentException('Invalid player bed-enter event state.');
        }
        parent::replaceState($state[0]);
        $this->result = $state[1];
        $this->message = $state[2];
    }

    private function validateMessage(string|TranslatableMessage|null $message): void
    {
        if (is_string($message) && (strlen($message) > 2_048 || preg_match('//u', $message) !== 1)) {
            throw new InvalidArgumentException('Bed-enter feedback must be bounded UTF-8 text.');
        }
    }
}
