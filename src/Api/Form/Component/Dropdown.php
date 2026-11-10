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

final readonly class Dropdown implements FormComponent
{
    /** @param list<string> $options */
    public function __construct(private string $key, private string $text, private array $options, private int $default = 0)
    {
        ComponentSupport::id($key);
        ComponentSupport::text($text);
        if ($options === [] || count($options) > 128 || !isset($options[$default])) {
            throw new InvalidArgumentException('Dropdown options are invalid.');
        } foreach ($options as $option) {
            ComponentSupport::text($option);
        }
    }
    public function id(): string
    {
        return $this->key;
    }
    public function definition(): array
    {
        return ['type' => 'dropdown', 'text' => $this->text, 'options' => $this->options, 'default' => $this->default];
    }
    public function normalize(mixed $value): string
    {
        if (!is_int($value) || !isset($this->options[$value])) {
            throw new InvalidArgumentException('Dropdown response is invalid.');
        } return $this->options[$value];
    }
}
