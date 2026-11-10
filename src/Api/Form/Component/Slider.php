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

final readonly class Slider implements FormComponent
{
    public function __construct(private string $key, private string $text, private float $minimum, private float $maximum, private float $step = 1.0, private ?float $default = null)
    {
        ComponentSupport::id($key);
        ComponentSupport::text($text);
        if (!is_finite($minimum) || !is_finite($maximum) || !is_finite($step) || $minimum >= $maximum || $step <= 0.0 || ($default !== null && (!is_finite($default) || $default < $minimum || $default > $maximum))) {
            throw new InvalidArgumentException('Slider bounds are invalid.');
        }
    }
    public function id(): string
    {
        return $this->key;
    }
    public function definition(): array
    {
        $v = ['type' => 'slider', 'text' => $this->text, 'min' => $this->minimum, 'max' => $this->maximum, 'step' => $this->step];
        if ($this->default !== null) {
            $v['default'] = $this->default;
        } return $v;
    }
    public function normalize(mixed $value): float
    {
        if (!is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException('Slider response is invalid.');
        } $v = (float) $value;
        if (!is_finite($v) || $v < $this->minimum || $v > $this->maximum) {
            throw new InvalidArgumentException('Slider response is out of bounds.');
        } return $v;
    }
}
