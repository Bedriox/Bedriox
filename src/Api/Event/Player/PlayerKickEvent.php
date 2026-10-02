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

/** Cancellable server-initiated kick; a normal transport timeout does not fire this event. */
final class PlayerKickEvent extends CancellableEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly PlayerKickCause $cause,
        private string $reason,
        private ?string $quitMessage,
        private ?string $disconnectScreenMessage,
        public readonly ?string $actor = null,
    ) {}

    public function reason(): string
    {
        return $this->reason;
    }
    public function quitMessage(): ?string
    {
        return $this->quitMessage;
    }
    public function disconnectScreenMessage(): ?string
    {
        return $this->disconnectScreenMessage;
    }

    public function setReason(string $reason): void
    {
        $this->assertMutable();
        $this->reason = $reason;
    }
    public function setQuitMessage(?string $message): void
    {
        $this->assertMutable();
        $this->quitMessage = $message;
    }
    public function setDisconnectScreenMessage(?string $message): void
    {
        $this->assertMutable();
        $this->disconnectScreenMessage = $message;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->reason, $this->quitMessage, $this->disconnectScreenMessage];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 4 || !is_bool($state[0]) || !is_string($state[1])
            || ($state[2] !== null && !is_string($state[2])) || ($state[3] !== null && !is_string($state[3]))) {
            throw new InvalidArgumentException('Invalid player kick event state.');
        }
        parent::replaceState($state[0]);
        $this->reason = $state[1];
        $this->quitMessage = $state[2];
        $this->disconnectScreenMessage = $state[3];
    }
}
