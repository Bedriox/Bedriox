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

namespace Bedriox\Server\Plugin\Command;

use Bedriox\Api\Command\CommandSoftEnum;

/** @internal Registry-owned implementation of the public soft-enum handle. */
final readonly class RegisteredCommandSoftEnum implements CommandSoftEnum
{
    public function __construct(
        private int $id,
        private string $enumName,
        private CommandRegistry $registry,
    ) {}

    public function name(): string
    {
        return $this->enumName;
    }

    public function values(): array
    {
        return $this->registry->softEnumValues($this->id);
    }

    public function replace(array $values): bool
    {
        return $this->registry->replaceSoftEnum($this->id, $values);
    }

    public function add(string $value): bool
    {
        $values = $this->values();
        foreach ($values as $existing) {
            if (strcasecmp($existing, $value) === 0) {
                return false;
            }
        }
        $values[] = $value;

        return $this->replace($values);
    }

    public function remove(string $value): bool
    {
        $values = $this->values();
        foreach ($values as $index => $existing) {
            if (strcasecmp($existing, $value) !== 0) {
                continue;
            }
            array_splice($values, $index, 1);

            return $this->replace($values);
        }

        return false;
    }

    public function isRegistered(): bool
    {
        return $this->registry->hasSoftEnum($this->id);
    }
}
