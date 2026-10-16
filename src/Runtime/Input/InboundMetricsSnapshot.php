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

final readonly class InboundMetricsSnapshot
{
    public function __construct(
        public int $handled,
        public int $ignored,
        public int $corrected,
        public int $rejected,
        public int $semanticViolations,
        public int $protocolViolations,
        public int $sessionFatals,
        public int $transportOrigins,
        public int $envelopeOrigins,
        public int $packetDecodeOrigins,
        public int $packetHandleOrigins,
        public int $admissionOrigins,
    ) {
        foreach ((array) $this as $count) {
            if ($count < 0) {
                throw new \InvalidArgumentException('Inbound metric counts cannot be negative.');
            }
        }
    }

    public function total(): int
    {
        return $this->handled
            + $this->ignored
            + $this->corrected
            + $this->rejected
            + $this->semanticViolations
            + $this->protocolViolations
            + $this->sessionFatals;
    }
}
