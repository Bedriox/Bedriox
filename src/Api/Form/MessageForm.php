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

use Bedriox\Api\Player\Player;
use Closure;
use InvalidArgumentException;

final class MessageForm implements Form
{
    private ?FormButton $first = null;
    private ?FormButton $second = null;
    private ?Closure $submit = null;
    private ?Closure $closed = null;

    public function __construct(private string $title, private string $body = '')
    {
        self::text($title, false, 512);
        self::text($body, true, 8_192);
    }
    public function setFirstButton(FormButton $button): self
    {
        $button->id();
        $this->first = $button;
        return $this;
    }
    public function setSecondButton(FormButton $button): self
    {
        $button->id();
        $this->second = $button;
        return $this;
    }
    public function onSubmit(callable $callback): self
    {
        $this->submit = Closure::fromCallable($callback);
        return $this;
    }
    public function onClose(callable $callback): self
    {
        $this->closed = Closure::fromCallable($callback);
        return $this;
    }
    public function toJson(): string
    {
        if ($this->first === null || $this->second === null) {
            throw new \LogicException('Message forms require two buttons.');
        }
        return json_encode(['type' => 'modal', 'title' => $this->title, 'content' => $this->body,
            'button1' => $this->first->text(), 'button2' => $this->second->text()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
    public function submit(Player $player, mixed $response): void
    {
        if (!is_bool($response) || $this->first === null || $this->second === null) {
            throw new InvalidArgumentException('Message form response must be boolean.');
        }
        if ($this->submit !== null) {
            ($this->submit)($player, $response ? $this->first : $this->second);
        }
    }
    public function cancel(Player $player, FormCancelReason $reason): void
    {
        if ($this->closed !== null) {
            ($this->closed)($player, $reason);
        }
    }
    private static function text(string $value, bool $empty, int $max): void
    {
        if ((!$empty && $value === '') || strlen($value) > $max || preg_match('//u', $value) !== 1 || str_contains($value, "\0")) {
            throw new InvalidArgumentException('Message form text is invalid.');
        }
    }
}
