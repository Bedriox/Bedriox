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

/** Runs after lethal damage commits and before death messages are presented. */
final class PlayerDeathEvent extends Event
{
    public function __construct(
        public readonly Player $player,
        public readonly string $cause,
        public readonly float $damage,
        public readonly bool $keepInventory = true,
        public readonly ?Player $killer = null,
        private string|TranslatableMessage|null $deathMessage = null,
        private string|TranslatableMessage|null $deathScreenMessage = null,
    ) {
        if (!is_finite($damage) || $damage <= 0.0 || $damage > 1_000_000.0) {
            throw new InvalidArgumentException('Fatal damage must be finite, positive, and bounded.');
        }
        self::validateMessage($deathMessage);
        self::validateMessage($deathScreenMessage);
    }

    public function deathMessage(): string|TranslatableMessage|null
    {
        return $this->deathMessage;
    }

    public function setDeathMessage(string|TranslatableMessage|null $message): void
    {
        $this->assertMutable();
        self::validateMessage($message);
        $this->deathMessage = $message;
    }

    public function deathScreenMessage(): string|TranslatableMessage|null
    {
        return $this->deathScreenMessage;
    }

    public function setDeathScreenMessage(string|TranslatableMessage|null $message): void
    {
        $this->assertMutable();
        self::validateMessage($message);
        $this->deathScreenMessage = $message;
    }

    protected function state(): mixed
    {
        return [$this->deathMessage, $this->deathScreenMessage];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2) {
            throw new InvalidArgumentException('Invalid player death event state.');
        }
        [$deathMessage, $deathScreenMessage] = $state;
        if ((!is_string($deathMessage) && !$deathMessage instanceof TranslatableMessage && $deathMessage !== null)
            || (!is_string($deathScreenMessage) && !$deathScreenMessage instanceof TranslatableMessage && $deathScreenMessage !== null)) {
            throw new InvalidArgumentException('Invalid player death event message state.');
        }
        $this->deathMessage = $deathMessage;
        $this->deathScreenMessage = $deathScreenMessage;
    }

    private static function validateMessage(string|TranslatableMessage|null $message): void
    {
        if (!is_string($message)) {
            return;
        }
        $characters = preg_match_all('/./us', $message);
        if ($message === '' || strlen($message) > 4096 || !is_int($characters) || $characters > 1024) {
            throw new InvalidArgumentException('Death messages must be valid, non-empty, bounded UTF-8 strings.');
        }
    }
}
