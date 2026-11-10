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

namespace Bedriox\Api\Form;

use InvalidArgumentException;

final readonly class ModalFormResponse
{
    /** @param array<string, string|bool|float> $values */
    public function __construct(private array $values) {}
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->values);
    }
    public function getString(string $id): string
    {
        $v = $this->value($id);
        if (!is_string($v)) {
            throw new InvalidArgumentException("Form value {$id} is not text.");
        } return $v;
    }
    public function getBool(string $id): bool
    {
        $v = $this->value($id);
        if (!is_bool($v)) {
            throw new InvalidArgumentException("Form value {$id} is not boolean.");
        } return $v;
    }
    public function getFloat(string $id): float
    {
        $v = $this->value($id);
        if (!is_float($v)) {
            throw new InvalidArgumentException("Form value {$id} is not numeric.");
        } return $v;
    }
    /** @return array<string, string|bool|float> */ public function all(): array
    {
        return $this->values;
    }
    private function value(string $id): string|bool|float
    {
        return $this->values[$id] ?? throw new InvalidArgumentException("Form value {$id} does not exist.");
    }
}
