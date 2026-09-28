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

namespace Bedriox\Server\Worker;

final readonly class WorkerSubmission
{
    private function __construct(
        public ?WorkerReceipt $receipt,
        public ?WorkerRejectionReason $rejection,
    ) {}

    public static function accepted(WorkerReceipt $receipt): self
    {
        return new self($receipt, null);
    }

    public static function rejected(WorkerRejectionReason $reason): self
    {
        return new self(null, $reason);
    }

    public function isAccepted(): bool
    {
        return $this->receipt !== null;
    }
}
