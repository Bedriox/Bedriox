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

final readonly class TextField implements FormComponent
{
    public function __construct(private string $key, private string $text, private string $placeholder = '', private string $default = '')
    {
        ComponentSupport::id($key);
        ComponentSupport::text($text);
        ComponentSupport::text($placeholder, 512, true);
        ComponentSupport::text($default, 8_192, true);
    }
    public function id(): string
    {
        return $this->key;
    }
    public function definition(): array
    {
        return ['type' => 'input', 'text' => $this->text, 'placeholder' => $this->placeholder, 'default' => $this->default];
    }
    public function normalize(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 8_192 || preg_match('//u', $value) !== 1 || str_contains($value, "\0")) {
            throw new InvalidArgumentException('Text field response is invalid.');
        } return $value;
    }
}
