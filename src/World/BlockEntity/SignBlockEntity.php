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

namespace Bedriox\Server\World\BlockEntity;

use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;
use OverflowException;

/** Authoritative two-sided sign state; editor ownership is session-scoped and deliberately not persisted. */
final readonly class SignBlockEntity extends BlockEntity
{
    public const int MAXIMUM_EDITOR_ID_BYTES = 128;

    public function __construct(
        BlockPosition $position,
        public SignText $front,
        public SignText $back,
        public bool $waxed = false,
        public ?string $editorSessionId = null,
        public ?bool $editorFront = null,
        public ?int $editorRuntimeActorId = null,
        int $revision = 0,
        public bool $hanging = false,
    ) {
        parent::__construct($hanging ? BlockEntityType::HangingSign : BlockEntityType::Sign, $position, $revision);
        if ($editorSessionId !== null && ($editorSessionId === ''
            || strlen($editorSessionId) > self::MAXIMUM_EDITOR_ID_BYTES
            || preg_match('/[\x00-\x1f\x7f]/D', $editorSessionId) === 1)) {
            throw new InvalidArgumentException('Sign editor session identity is invalid or oversized.');
        }
        if (($editorSessionId === null) !== ($editorFront === null)
            || ($editorSessionId === null) !== ($editorRuntimeActorId === null)
            || ($editorRuntimeActorId !== null && $editorRuntimeActorId < 1)) {
            throw new InvalidArgumentException('Sign editor identity, side, and runtime actor must be valid and present together.');
        }
        if ($revision < 0) {
            throw new InvalidArgumentException('Sign block-actor revision cannot be negative.');
        }
    }

    public static function empty(
        BlockPosition $position,
        ?string $editorSessionId = null,
        ?bool $editorFront = null,
        ?int $editorRuntimeActorId = null,
        bool $hanging = false,
    ): self {
        return new self(
            $position,
            new SignText(),
            new SignText(),
            editorSessionId: $editorSessionId,
            editorFront: $editorSessionId === null ? null : ($editorFront ?? true),
            editorRuntimeActorId: $editorRuntimeActorId,
            hanging: $hanging,
        );
    }

    public function withText(bool $front, SignText $text, string $editorSessionId): self
    {
        if ($this->waxed || $this->editorSessionId === null || $this->editorFront !== $front
            || !hash_equals($this->editorSessionId, $editorSessionId)) {
            throw new InvalidArgumentException('This session is not authorized to edit the sign.');
        }
        if (($front ? $this->front : $this->back) == $text) {
            return $this;
        }

        return $front
            ? $this->replace(front: $text)
            : $this->replace(back: $text);
    }

    public function withoutEditor(): self
    {
        return $this->editorSessionId === null
            ? $this
            : $this->replace(
                editorSessionId: null,
                editorFront: null,
                editorRuntimeActorId: null,
                replaceEditor: true,
            );
    }

    public function withEditor(string $editorSessionId, bool $front, int $editorRuntimeActorId): self
    {
        if ($this->waxed) {
            throw new InvalidArgumentException('A waxed sign cannot be edited.');
        }
        if ($this->editorSessionId === $editorSessionId && $this->editorFront === $front
            && $this->editorRuntimeActorId === $editorRuntimeActorId) {
            return $this;
        }

        return $this->replace(
            editorSessionId: $editorSessionId,
            editorFront: $front,
            editorRuntimeActorId: $editorRuntimeActorId,
            replaceEditor: true,
        );
    }

    public function withWaxed(bool $waxed): self
    {
        return $waxed === $this->waxed ? $this : $this->replace(waxed: $waxed);
    }

    private function replace(
        ?SignText $front = null,
        ?SignText $back = null,
        ?bool $waxed = null,
        ?string $editorSessionId = null,
        ?bool $editorFront = null,
        ?int $editorRuntimeActorId = null,
        bool $replaceEditor = false,
    ): self {
        if ($this->revision === PHP_INT_MAX) {
            throw new OverflowException('Sign block-actor revision space is exhausted.');
        }

        return new self(
            $this->position,
            $front ?? $this->front,
            $back ?? $this->back,
            $waxed ?? $this->waxed,
            $replaceEditor ? $editorSessionId : $this->editorSessionId,
            $replaceEditor ? $editorFront : $this->editorFront,
            $replaceEditor ? $editorRuntimeActorId : $this->editorRuntimeActorId,
            $this->revision + 1,
            $this->hanging,
        );
    }
}
