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

namespace Bedriox\Server\Plugin\Form;

use Bedriox\Api\Form\Form;
use Bedriox\Api\Form\FormCancelReason;
use Bedriox\Api\Form\FormHandle;
use Bedriox\Api\Form\FormSendMode;
use Bedriox\Api\Form\FormState;
use Bedriox\Protocol\Packet\ClientboundCloseFormPacket;
use Bedriox\Protocol\Packet\ModalFormRequestPacket;
use Bedriox\Protocol\Packet\Packet;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Closure;

final class PlayerFormSession
{
    private const int MAXIMUM_QUEUED_FORMS = 16;
    private const int MAXIMUM_TOMBSTONES = 64;
    private const int UNKNOWN_WINDOW_NANOSECONDS = 10_000_000_000;
    private const float RESPONSE_RATE_PER_SECOND = 8.0;
    private const float RESPONSE_BURST = 16.0;
    private const int FORM_LIFETIME_NANOSECONDS = 300_000_000_000;

    private PendingFormQueue $queue;
    private ?PendingForm $active = null;
    /** @var array<int, true> */ private array $tombstones = [];
    private int $unknownResponses = 0;
    private int $unknownWindowStarted = 0;
    private int $nextId;
    private float $responseTokens = self::RESPONSE_BURST;
    private int $responseTokenUpdatedAt;

    /** @param Closure(Packet, bool): bool $send */
    public function __construct(public readonly string $identity, public readonly string $sessionId, private readonly Closure $send)
    {
        $this->queue = self::pendingQueue();
        $this->nextId = random_int(1, 0x7fffffff);
        $this->responseTokenUpdatedAt = hrtime(true);
    }

    public function enqueue(Form $form, FormSendMode $mode, ?PluginExecutionFrame $owner, int $now, Closure $owned, Closure $forgotten): ?FormHandle
    {
        if ($this->active !== null && $mode === FormSendMode::FAIL_IF_BUSY) {
            return null;
        }
        if ($this->active !== null && $mode === FormSendMode::REPLACE) {
            $this->finish($this->active, FormState::CANCELLED, $forgotten);
            ($this->send)(new ClientboundCloseFormPacket(), true);
            $this->active = null;
        }
        if ($this->active !== null && $this->queue->count() >= self::MAXIMUM_QUEUED_FORMS) {
            return null;
        }
        $id = $this->allocateId();
        $resourceId = 'form:' . $this->sessionId . ':' . $id;
        $pending = new PendingForm($id, $form, $owner, $resourceId, $now);
        $owned($pending);
        if ($this->active === null) {
            if (!$this->send($pending)) {
                $this->finish($pending, FormState::INVALID, $forgotten);
                return null;
            }
        } else {
            $this->queue->enqueue($pending);
        }
        return new FormHandle($id, $this->sessionId, static fn(): FormState => $pending->state, fn(): bool => $this->close($id, $forgotten));
    }

    public function active(): ?PendingForm
    {
        return $this->active;
    }
    public function isQueued(int $id): bool
    {
        foreach ($this->queue as $pending) {
            if ($pending->id === $id) {
                return true;
            }
        } return false;
    }
    public function isTombstone(int $id): bool
    {
        return isset($this->tombstones[$id]);
    }

    public function close(int $id, Closure $forgotten): bool
    {
        if ($this->active?->id === $id) {
            $pending = $this->active;
            $this->active = null;
            $this->finish($pending, FormState::CANCELLED, $forgotten);
            ($this->send)(new ClientboundCloseFormPacket(), true);
            $this->advance($forgotten);
            return true;
        }
        $replacement = self::pendingQueue();
        $found = null;
        while (!$this->queue->isEmpty()) {
            $pending = $this->queue->dequeue();
            if ($pending->id === $id) {
                $found = $pending;
            } else {
                $replacement->enqueue($pending);
            }
        }
        $this->queue = $replacement;
        if ($found === null) {
            return false;
        }
        $this->finish($found, FormState::CANCELLED, $forgotten);
        return true;
    }

    public function takeActive(int $id, Closure $forgotten): ?PendingForm
    {
        if ($this->active?->id !== $id) {
            return null;
        }
        $pending = $this->active;
        $this->active = null;
        $pending->state = FormState::PROCESSING;
        $forgotten($pending);
        return $pending;
    }

    public function complete(PendingForm $pending, FormState $state, Closure $forgotten): void
    {
        $this->finish($pending, $state, $forgotten);
        $this->advance($forgotten);
    }

    public function closeAll(FormCancelReason $reason, Closure $forgotten): void
    {
        if ($this->active !== null) {
            $this->finish($this->active, FormState::CANCELLED, $forgotten);
            $this->active = null;
        }
        while (!$this->queue->isEmpty()) {
            $this->finish($this->queue->dequeue(), FormState::CANCELLED, $forgotten);
        }
        if ($reason !== FormCancelReason::DISCONNECTED) {
            ($this->send)(new ClientboundCloseFormPacket(), true);
        }
    }

    public function noteUnknown(int $now): FormResponseDisposition
    {
        if ($this->unknownWindowStarted === 0 || $now - $this->unknownWindowStarted > self::UNKNOWN_WINDOW_NANOSECONDS) {
            $this->unknownWindowStarted = $now;
            $this->unknownResponses = 0;
        }
        ++$this->unknownResponses;
        return $this->unknownResponses === 1 ? FormResponseDisposition::IGNORED_STALE : FormResponseDisposition::SEMANTIC_VIOLATION;
    }

    public function admitResponse(int $now): bool
    {
        $elapsed = max(0, $now - $this->responseTokenUpdatedAt);
        $this->responseTokens = min(
            self::RESPONSE_BURST,
            $this->responseTokens + ($elapsed / 1_000_000_000) * self::RESPONSE_RATE_PER_SECOND,
        );
        $this->responseTokenUpdatedAt = $now;
        if ($this->responseTokens < 1.0) {
            return false;
        }
        $this->responseTokens -= 1.0;
        return true;
    }

    public function expire(int $now, Closure $forgotten): void
    {
        if ($this->active !== null && $now - $this->active->createdAtNanoseconds >= self::FORM_LIFETIME_NANOSECONDS) {
            $pending = $this->active;
            $this->active = null;
            $this->finish($pending, FormState::CANCELLED, $forgotten);
            ($this->send)(new ClientboundCloseFormPacket(), true);
        }
        $replacement = self::pendingQueue();
        while (!$this->queue->isEmpty()) {
            $pending = $this->queue->dequeue();
            if ($now - $pending->createdAtNanoseconds >= self::FORM_LIFETIME_NANOSECONDS) {
                $this->finish($pending, FormState::CANCELLED, $forgotten);
                continue;
            }
            $replacement->enqueue($pending);
        }
        $this->queue = $replacement;
        $this->advance($forgotten);
    }

    private function send(PendingForm $pending): bool
    {
        if (!(($this->send)(new ModalFormRequestPacket($pending->id, $pending->form->toJson()), false))) {
            return false;
        }
        $pending->state = FormState::SENT;
        $this->active = $pending;
        return true;
    }
    private function advance(Closure $forgotten): void
    {
        while ($this->active === null && !$this->queue->isEmpty()) {
            $pending = $this->queue->dequeue();
            if ($this->send($pending)) {
                return;
            } $this->finish($pending, FormState::INVALID, $forgotten);
        }
    }
    private function finish(PendingForm $pending, FormState $state, Closure $forgotten): void
    {
        $pending->state = $state;
        $this->tombstones[$pending->id] = true;
        while (count($this->tombstones) > self::MAXIMUM_TOMBSTONES) {
            array_shift($this->tombstones);
        } $forgotten($pending);
    }
    private function allocateId(): int
    {
        do {
            $id = $this->nextId;
            $this->nextId = ($this->nextId + 1) & 0xffffffff;
            if ($this->nextId === 0) {
                $this->nextId = 1;
            }
        } while ($this->active?->id === $id || $this->isQueued($id) || isset($this->tombstones[$id]));
        return $id;
    }

    private static function pendingQueue(): PendingFormQueue
    {
        return new PendingFormQueue();
    }
}
