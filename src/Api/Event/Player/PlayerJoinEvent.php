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

use Bedriox\Api\Event\Event;
use Bedriox\Api\Player\Player;
use Bedriox\Api\TranslatableMessage;
use InvalidArgumentException;

/** Runs after admission commits and allows plugins to control the join announcement. */
final class PlayerJoinEvent extends Event
{
    public function __construct(
        public readonly Player $player,
        private string|TranslatableMessage|null $joinMessage,
    ) {
        self::validateMessage($this->joinMessage);
    }

    public function getJoinMessage(): string|TranslatableMessage|null
    {
        return $this->joinMessage;
    }

    public function setJoinMessage(string|TranslatableMessage|null $message): void
    {
        $this->assertMutable();
        self::validateMessage($message);
        $this->joinMessage = $message;
    }

    protected function state(): mixed
    {
        return $this->joinMessage;
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_string($state) && !$state instanceof TranslatableMessage && $state !== null) {
            throw new InvalidArgumentException('Invalid player join event state.');
        }
        $this->joinMessage = $state;
    }

    private static function validateMessage(string|TranslatableMessage|null $message): void
    {
        if (!is_string($message)) {
            return;
        }
        $characters = preg_match_all('/./us', $message);
        if ($message === '' || strlen($message) > 4096 || !is_int($characters) || $characters > 1024) {
            throw new InvalidArgumentException('Join messages must be valid, non-empty, bounded UTF-8 strings.');
        }
    }
}
