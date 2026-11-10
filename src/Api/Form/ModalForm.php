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

use Bedriox\Api\Form\Component\FormComponent;
use Bedriox\Api\Player\Player;
use Closure;
use InvalidArgumentException;

final class ModalForm implements Form
{
    /** @var list<FormComponent> */ private array $components = [];
    private ?Closure $submit = null;
    private ?Closure $closed = null;
    public function __construct(private string $title)
    {
        if ($title === '' || strlen($title) > 512 || preg_match('//u', $title) !== 1) {
            throw new InvalidArgumentException('Form title is invalid.');
        }
    }
    public function add(FormComponent $component): self
    {
        if (count($this->components) >= 128) {
            throw new InvalidArgumentException('Modal forms support at most 128 components.');
        } $id = $component->id();
        if ($id !== null) {
            foreach ($this->components as $existing) {
                if ($existing->id() === $id) {
                    throw new InvalidArgumentException('Component IDs must be unique.');
                }
            }
        } $this->components[] = $component;
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
        return json_encode(['type' => 'custom_form', 'title' => $this->title, 'content' => array_map(static fn(FormComponent $c): array => $c->definition(), $this->components)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    public function submit(Player $player, mixed $response): void
    {
        if (!is_array($response) || !array_is_list($response) || count($response) !== count($this->components)) {
            throw new InvalidArgumentException('Modal form response shape is invalid.');
        } $values = [];
        foreach ($this->components as $index => $component) {
            $id = $component->id();
            if ($id !== null) {
                $value = $component->normalize($response[$index]);
                if (is_string($value) || is_bool($value) || is_float($value)) {
                    $values[$id] = $value;
                }
            }
        } if ($this->submit !== null) {
            ($this->submit)($player, new ModalFormResponse($values));
        }
    }
    public function cancel(Player $player, FormCancelReason $reason): void
    {
        if ($this->closed !== null) {
            ($this->closed)($player, $reason);
        }
    }
}
