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

namespace Bedriox\Api\Event\Display;

use Bedriox\Api\Display\TextDisplay;
use Bedriox\Api\Event\CancellableEvent;
use InvalidArgumentException;

/** Cancellable text replacement before the authoritative display state changes. */
final class TextDisplayChangeEvent extends CancellableEvent
{
    public function __construct(
        public readonly TextDisplay $display,
        public readonly string $previousText,
        private string $text,
    ) {
        TextDisplay::validateText($text);
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function setText(string $text): void
    {
        $this->assertMutable();
        TextDisplay::validateText($text);
        $this->text = $text;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->text];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !is_string($state[1])) {
            throw new InvalidArgumentException('Invalid text-display change event state.');
        }
        TextDisplay::validateText($state[1]);
        parent::replaceState($state[0]);
        $this->text = $state[1];
    }
}
