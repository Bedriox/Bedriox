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

namespace Bedriox\Server\Worker\Network;

final readonly class OutboundCompressionSubmission
{
    private function __construct(
        public OutboundCompressionAdmission $admission,
        public ?int $sequence,
        public bool $synchronousFallback,
    ) {}

    public static function accepted(int $sequence, bool $synchronousFallback): self
    {
        return new self(OutboundCompressionAdmission::ACCEPTED, $sequence, $synchronousFallback);
    }

    public static function saturated(): self
    {
        return new self(OutboundCompressionAdmission::SATURATED, null, false);
    }

    public static function closed(): self
    {
        return new self(OutboundCompressionAdmission::CLOSED, null, false);
    }

    public function isAccepted(): bool
    {
        return $this->admission === OutboundCompressionAdmission::ACCEPTED;
    }
}
