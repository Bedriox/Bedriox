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

namespace Bedriox\Api\Command;

use InvalidArgumentException;

final readonly class CommandResult
{
    private function __construct(
        private bool $successful,
        private ?string $message,
    ) {
        if ($message !== null && ($message === '' || strlen($message) > 1_024 || preg_match('//u', $message) !== 1 || str_contains($message, "\0"))) {
            throw new InvalidArgumentException('A command result message must be valid, bounded text.');
        }
    }

    public static function success(?string $message = null): self
    {
        return new self(true, $message);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }

    public function isSuccess(): bool
    {
        return $this->successful;
    }

    public function message(): ?string
    {
        return $this->message;
    }
}
