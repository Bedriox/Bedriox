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

namespace Bedriox\Server\Persistence;

use InvalidArgumentException;

/** A caller may acknowledge authoritative state only when key and revision still match this completion. */
final readonly class PersistenceWriteCompletion
{
    public function __construct(
        public int $requestId,
        public string $key,
        public int $revision,
        public bool $successful,
        public ?string $failureCode = null,
    ) {
        if ($requestId < 1 || $revision < 0 || $key === '' || strlen($key) > 256 || str_contains($key, "\0")) {
            throw new InvalidArgumentException('Persistence completion identity is invalid.');
        }
        if ($successful !== ($failureCode === null)
            || ($failureCode !== null && (strlen($failureCode) > 128
                || preg_match('/^[a-z][a-z0-9_.-]*$/D', $failureCode) !== 1))) {
            throw new InvalidArgumentException('Persistence completion outcome is invalid.');
        }
    }
}
