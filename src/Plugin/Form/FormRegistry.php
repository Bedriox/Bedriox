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

use Bedriox\Api\Form\BoundedFormResponse;
use Bedriox\Api\Form\Form;
use Bedriox\Api\Form\FormCancelReason;
use Bedriox\Api\Form\FormHandle;
use Bedriox\Api\Form\FormSendMode;
use Bedriox\Api\Form\FormState;
use Bedriox\Api\Player\Player;
use Bedriox\Protocol\Packet\ModalFormCancelReason;
use Bedriox\Protocol\Packet\ModalFormResponsePacket;
use Bedriox\Protocol\Packet\Packet;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginManager;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Closure;
use JsonException;
use Throwable;

final class FormRegistry
{
    /** @var array<string, PlayerFormSession> */ private array $sessions = [];

    public function __construct(private readonly PluginManager $plugins, private readonly PluginExecutionContext $execution, private readonly PluginOwnershipRegistry $ownership) {}

    /** @param Closure(Packet, bool): bool $send */
    public function connect(string $identity, string $sessionId, Closure $send): void
    {
        $key = self::key($identity);
        ($this->sessions[$key] ?? null)?->closeAll(FormCancelReason::DISCONNECTED, $this->forget(...));
        $this->sessions[$key] = new PlayerFormSession($identity, $sessionId, $send);
    }

    public function disconnect(string $identity, string $sessionId): void
    {
        $key = self::key($identity);
        $session = $this->sessions[$key] ?? null;
        if ($session === null || $session->sessionId !== $sessionId) {
            return;
        }
        $session->closeAll(FormCancelReason::DISCONNECTED, $this->forget(...));
        unset($this->sessions[$key]);
    }

    public function send(string $identity, string $sessionId, Form $form, FormSendMode $mode = FormSendMode::QUEUE): ?FormHandle
    {
        $session = $this->session($identity, $sessionId);
        if ($session === null) {
            return null;
        }
        $frame = $this->execution->current();
        return $session->enqueue(
            $form,
            $mode,
            $frame,
            hrtime(true),
            function (PendingForm $pending) use ($session): void {
                $owner = $pending->owner;
                if ($owner !== null) {
                    $this->ownership->own(
                        $owner->plugin,
                        $pending->resourceId,
                        fn() => $session->close($pending->id, $this->forget(...)),
                    );
                }
            },
            $this->forget(...),
        );
    }

    public function closeAll(string $identity, string $sessionId): bool
    {
        $session = $this->session($identity, $sessionId);
        if ($session === null) {
            return false;
        }
        $session->closeAll(FormCancelReason::REPLACED, $this->forget(...));
        return true;
    }

    public function respond(string $identity, string $sessionId, ModalFormResponsePacket $packet, Player $player): FormResponseDisposition
    {
        $session = $this->session($identity, $sessionId);
        if ($session === null) {
            return FormResponseDisposition::IGNORED_STALE;
        }
        if (!$session->admitResponse(hrtime(true))) {
            return FormResponseDisposition::SEMANTIC_VIOLATION;
        }
        if ($session->isTombstone($packet->formId) || $session->isQueued($packet->formId)) {
            return FormResponseDisposition::SEMANTIC_VIOLATION;
        }
        $pending = $session->takeActive($packet->formId, $this->forget(...));
        if ($pending === null) {
            return $session->noteUnknown(hrtime(true));
        }
        if (($packet->responseJson === null) === ($packet->cancelReason === null)) {
            $session->complete($pending, FormState::INVALID, $this->forget(...));
            return FormResponseDisposition::SEMANTIC_VIOLATION;
        }
        try {
            if ($packet->cancelReason !== null) {
                $reason = match (ModalFormCancelReason::tryFrom($packet->cancelReason)) {
                    ModalFormCancelReason::UserClosed => FormCancelReason::USER_CLOSED,
                    ModalFormCancelReason::UserBusy => FormCancelReason::USER_BUSY,
                    null => FormCancelReason::UNKNOWN,
                };
                $this->invoke($pending, fn() => $pending->form->cancel($player, $reason));
                $session->complete($pending, FormState::CANCELLED, $this->forget(...));
                return FormResponseDisposition::HANDLED;
            }
            if ($pending->form instanceof BoundedFormResponse
                && strlen($packet->responseJson ?? '') > $pending->form->maximumResponseBytes()) {
                throw new \InvalidArgumentException('Form response exceeds the active form limit.');
            }
            $value = json_decode($packet->responseJson ?? '', true, 2, JSON_THROW_ON_ERROR);
            $this->invoke($pending, fn() => $pending->form->submit($player, $value));
            $session->complete($pending, FormState::COMPLETED, $this->forget(...));
            return FormResponseDisposition::HANDLED;
        } catch (JsonException|\InvalidArgumentException) {
            $session->complete($pending, FormState::INVALID, $this->forget(...));
            return FormResponseDisposition::SEMANTIC_VIOLATION;
        } catch (Throwable $failure) {
            $session->complete($pending, FormState::INVALID, $this->forget(...));
            $owner = $pending->owner;
            if ($owner !== null) {
                $this->plugins->disableAfterFailure($owner->plugin, $failure, $owner);
            }
            return FormResponseDisposition::HANDLED;
        }
    }

    public function expire(int $now): void
    {
        foreach ($this->sessions as $session) {
            $session->expire($now, $this->forget(...));
        }
    }

    private function invoke(PendingForm $pending, Closure $callback): void
    {
        $owner = $pending->owner;
        if ($owner === null) {
            $callback();
            return;
        }
        if (!$this->plugins->isEnabled($owner->plugin)) {
            return;
        }
        $frame = new PluginExecutionFrame($owner->plugin, $owner->version, 'form_callback', startedAtNanoseconds: hrtime(true));
        $this->execution->enter($frame);
        try {
            $callback();
        } finally {
            $this->execution->leave();
        }
    }
    private function forget(PendingForm $pending): void
    {
        $owner = $pending->owner;
        if ($owner !== null) {
            $this->ownership->forget($owner->plugin, $pending->resourceId);
        }
    }
    private function session(string $identity, string $sessionId): ?PlayerFormSession
    {
        $session = $this->sessions[self::key($identity)] ?? null;
        return $session !== null && $session->sessionId === $sessionId ? $session : null;
    }
    private static function key(string $identity): string
    {
        return strtolower($identity);
    }
}
