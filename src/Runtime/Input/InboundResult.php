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

namespace Bedriox\Server\Runtime\Input;

final readonly class InboundResult
{
    public function __construct(
        public InboundDisposition $disposition,
        public InboundOrigin $origin,
        public string $reason,
        public ?int $packetId = null,
        public ?string $exceptionClass = null,
    ) {
        if ($this->reason === '') {
            throw new \InvalidArgumentException('Inbound result reason must not be empty.');
        }
        if ($this->packetId !== null && ($this->packetId < 0 || $this->packetId > 1_023)) {
            throw new \InvalidArgumentException('Inbound packet ID is outside the Bedrock header range.');
        }
    }

    public static function handled(?int $packetId = null): self
    {
        return new self(InboundDisposition::HANDLED, InboundOrigin::PACKET_HANDLE, 'handled', $packetId);
    }

    public function keepsSessionOpen(): bool
    {
        return !$this->disposition->requiresDisconnect();
    }

    public function requiresDisconnect(): bool
    {
        return $this->disposition->requiresDisconnect();
    }

    /** Keeps the strongest result observed while processing one ordered packet batch. */
    public function merge(self $other): self
    {
        return self::weight($other->disposition) > self::weight($this->disposition) ? $other : $this;
    }

    private static function weight(InboundDisposition $disposition): int
    {
        return match ($disposition) {
            InboundDisposition::HANDLED => 0,
            InboundDisposition::IGNORED => 1,
            InboundDisposition::CORRECTED => 2,
            InboundDisposition::REJECTED => 3,
            InboundDisposition::SEMANTIC_VIOLATION => 4,
            InboundDisposition::PROTOCOL_VIOLATION => 5,
            InboundDisposition::SESSION_FATAL => 6,
        };
    }
}
