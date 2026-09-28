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
use InvalidArgumentException;

final class PlayerChatEvent extends CancellableEvent
{
    public function __construct(
        public readonly Player $player,
        private string $message,
    ) {
        self::validateMessage($message);
    }

    public function message(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): void
    {
        $this->assertMutable();
        self::validateMessage($message);
        $this->message = $message;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->message];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !is_string($state[1])) {
            throw new InvalidArgumentException('Invalid chat event state.');
        }
        parent::replaceState($state[0]);
        $this->message = $state[1];
    }

    private static function validateMessage(string $message): void
    {
        $characters = preg_match_all('/./us', $message);
        if ($message === '' || strlen($message) > 1024 || $characters === false || $characters > 256) {
            throw new InvalidArgumentException('Chat message must be valid UTF-8 with at most 256 characters and 1024 bytes.');
        }
    }
}
