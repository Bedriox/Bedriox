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
use JsonException;

final class ActionForm implements BoundedFormResponse, Form
{
    private const int MAXIMUM_RESPONSE_BYTES = 16;
    /** @var list<FormButton> */
    private array $buttons = [];
    private string $body = '';
    private ?Closure $submit = null;
    private ?Closure $closed = null;

    public function __construct(private string $title)
    {
        self::text($title, 'Form title', 512, false);
    }

    public function setBody(string $body): self
    {
        self::text($body, 'Form body', 8_192, true);
        $this->body = $body;
        return $this;
    }
    public function addButton(FormButton $button): self
    {
        if (count($this->buttons) >= 64) {
            throw new InvalidArgumentException('Action forms support at most 64 buttons.');
        }
        if ($button->id() === '' || $this->button($button->id()) !== null) {
            throw new InvalidArgumentException('Button IDs must be unique.');
        }
        $this->buttons[] = $button;
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
        return json_encode(['type' => 'form', 'title' => $this->title, 'content' => $this->body,
            'buttons' => array_map(static fn(FormButton $button): array => $button->definition(), $this->buttons)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function submit(Player $player, mixed $response): void
    {
        if (!is_int($response) || !isset($this->buttons[$response])) {
            throw new InvalidArgumentException('Action form response is not a valid button index.');
        }
        if ($this->submit !== null) {
            ($this->submit)($player, $this->buttons[$response]);
        }
    }

    public function maximumResponseBytes(): int
    {
        return self::MAXIMUM_RESPONSE_BYTES;
    }
    public function cancel(Player $player, FormCancelReason $reason): void
    {
        if ($this->closed !== null) {
            ($this->closed)($player, $reason);
        }
    }
    private function button(string $id): ?FormButton
    {
        foreach ($this->buttons as $button) {
            if ($button->id() === $id) {
                return $button;
            }
        } return null;
    }
    private static function text(string $value, string $field, int $max, bool $empty): void
    {
        if ((!$empty && $value === '') || strlen($value) > $max || preg_match('//u', $value) !== 1 || str_contains($value, "\0")) {
            throw new InvalidArgumentException($field . ' is invalid.');
        }
    }
}
