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

namespace Bedriox\Server\Login;

use Bedriox\NetherNet\Protocol\Reliability;
use InvalidArgumentException;

final readonly class OutgoingLoginPayload
{
    public Reliability $reliability;

    public function __construct(public string $payload, public ?int $receiptId = null)
    {
        if ($payload === '') {
            throw new InvalidArgumentException('Outgoing login payload cannot be empty.');
        }
        if ($receiptId !== null && ($receiptId < 1 || $receiptId > 0x7fff_ffff)) {
            throw new InvalidArgumentException('Outgoing login receipt identifier is outside its range.');
        }
        $this->reliability = Reliability::RELIABLE;
    }
}
