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

use InvalidArgumentException;

final class FormButton
{
    private ?string $id = null;
    /** @var null|array{type: string, data: string} */
    private ?array $image = null;

    public function __construct(private string $text)
    {
        self::validateText($text, 'Button text', 512);
    }

    public function setId(string $id): self
    {
        if (preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Button ID must contain 1-64 safe identifier characters.');
        }
        $this->id = $id;
        return $this;
    }

    public function id(): string
    {
        return $this->id ?? throw new \LogicException('Button ID has not been assigned.');
    }
    public function text(): string
    {
        return $this->text;
    }

    public function setText(string $text): self
    {
        self::validateText($text, 'Button text', 512);
        $this->text = $text;
        return $this;
    }

    public function setTexture(string $path): self
    {
        return $this->setImage('path', $path);
    }
    public function setImageUrl(string $url): self
    {
        return $this->setImage('url', $url);
    }

    /** @return array{text: string, image?: array{type: string, data: string}} */
    public function definition(): array
    {
        $definition = ['text' => $this->text];
        if ($this->image !== null) {
            $definition['image'] = $this->image;
        }
        return $definition;
    }

    private function setImage(string $type, string $data): self
    {
        self::validateText($data, 'Button image', 2_048);
        if ($type === 'url' && filter_var($data, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Button image URL is invalid.');
        }
        $this->image = ['type' => $type, 'data' => $data];
        return $this;
    }

    private static function validateText(string $value, string $field, int $maximum): void
    {
        if ($value === '' || strlen($value) > $maximum || preg_match('//u', $value) !== 1 || str_contains($value, "\0")) {
            throw new InvalidArgumentException($field . ' must be valid, non-empty, bounded UTF-8.');
        }
    }
}
