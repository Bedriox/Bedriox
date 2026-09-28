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

namespace Bedriox\Server\Transport\Process;

use Bedriox\RakNet\Protocol\Reliability;

final readonly class TransportProcessFrame
{
    /** @param array<string, int|string|bool|null|array<array-key, mixed>> $metadata */
    public function __construct(
        public TransportProcessFrameKind $kind,
        public int $sessionId = 0,
        public string $payload = '',
        public ?Reliability $reliability = null,
        public ?int $orderingChannel = null,
        public array $metadata = [],
    ) {}
}
