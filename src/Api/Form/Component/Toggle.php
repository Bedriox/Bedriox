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

namespace Bedriox\Api\Form\Component;

use InvalidArgumentException;

final readonly class Toggle implements FormComponent
{
    public function __construct(private string $key, private string $text, private bool $default = false)
    {
        ComponentSupport::id($key);
        ComponentSupport::text($text);
    }
    public function id(): string
    {
        return $this->key;
    }
    public function definition(): array
    {
        return ['type' => 'toggle', 'text' => $this->text, 'default' => $this->default];
    }
    public function normalize(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new InvalidArgumentException('Toggle response is invalid.');
        } return $value;
    }
}
