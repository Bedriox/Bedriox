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

final readonly class Label implements FormComponent
{
    public function __construct(private string $text)
    {
        ComponentSupport::text($text, 8_192, true);
    }
    public function id(): ?string
    {
        return null;
    }
    public function definition(): array
    {
        return ['type' => 'label', 'text' => $this->text];
    }
    public function normalize(mixed $value): null
    {
        return null;
    }
}
