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

final readonly class StepSlider implements FormComponent
{
    /** @param list<string> $steps */
    public function __construct(private string $key, private string $text, private array $steps, private int $default = 0)
    {
        ComponentSupport::id($key);
        ComponentSupport::text($text);
        if ($steps === [] || count($steps) > 128 || !isset($steps[$default])) {
            throw new InvalidArgumentException('Step slider options are invalid.');
        } foreach ($steps as $step) {
            ComponentSupport::text($step);
        }
    }
    public function id(): string
    {
        return $this->key;
    }
    public function definition(): array
    {
        return ['type' => 'step_slider', 'text' => $this->text, 'steps' => $this->steps, 'default' => $this->default];
    }
    public function normalize(mixed $value): string
    {
        if (!is_int($value) || !isset($this->steps[$value])) {
            throw new InvalidArgumentException('Step slider response is invalid.');
        } return $this->steps[$value];
    }
}
