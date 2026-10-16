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

final class InboundMetrics
{
    /** @var array<string, int> */
    private array $dispositions = [];
    /** @var array<string, int> */
    private array $origins = [];

    public function record(InboundResult $result): void
    {
        $disposition = $result->disposition->name;
        $origin = $result->origin->value;
        $this->dispositions[$disposition] = self::increment($this->dispositions[$disposition] ?? 0);
        $this->origins[$origin] = self::increment($this->origins[$origin] ?? 0);
    }

    public function snapshot(): InboundMetricsSnapshot
    {
        return new InboundMetricsSnapshot(
            handled: $this->disposition(InboundDisposition::HANDLED),
            ignored: $this->disposition(InboundDisposition::IGNORED),
            corrected: $this->disposition(InboundDisposition::CORRECTED),
            rejected: $this->disposition(InboundDisposition::REJECTED),
            semanticViolations: $this->disposition(InboundDisposition::SEMANTIC_VIOLATION),
            protocolViolations: $this->disposition(InboundDisposition::PROTOCOL_VIOLATION),
            sessionFatals: $this->disposition(InboundDisposition::SESSION_FATAL),
            transportOrigins: $this->origin(InboundOrigin::TRANSPORT),
            envelopeOrigins: $this->origin(InboundOrigin::ENVELOPE),
            packetDecodeOrigins: $this->origin(InboundOrigin::PACKET_DECODE),
            packetHandleOrigins: $this->origin(InboundOrigin::PACKET_HANDLE),
            admissionOrigins: $this->origin(InboundOrigin::ADMISSION),
        );
    }

    private function disposition(InboundDisposition $disposition): int
    {
        return $this->dispositions[$disposition->name] ?? 0;
    }

    private function origin(InboundOrigin $origin): int
    {
        return $this->origins[$origin->value] ?? 0;
    }

    private static function increment(int $value): int
    {
        return $value === PHP_INT_MAX ? PHP_INT_MAX : $value + 1;
    }
}
