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

namespace Bedriox\Server\Plugin\Gateway;

use Bedriox\Api\Event\Gateway\GatewayPluginMessageEvent;
use Bedriox\Api\Event\Gateway\GatewayPluginMessageRejectedEvent;
use Bedriox\Api\Gateway\GatewayConnection;
use Bedriox\Api\Gateway\GatewayPluginChannel;
use Bedriox\Api\Gateway\GatewayPluginMessageRejectionReason;
use Bedriox\Server\Plugin\Event\EventDispatcher;
use Bedriox\Server\Plugin\PluginException;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Closure;
use InvalidArgumentException;
use Throwable;

/** Owns the bounded mapping between plugin lifecycle resources and Gateway control channels. */
final class GatewayPluginConnectionRegistry
{
    private const int MAXIMUM_CHANNELS = 256;
    private const int MAXIMUM_CHANNEL_NAME_BYTES = 128;

    /** @var array<string, array{owner: string, channel: GatewayPluginChannel}> */
    private array $channels = [];

    /**
     * @param Closure(): bool $enabled
     * @param Closure(): bool $connected
     * @param Closure(string, Closure(string): void, Closure(string): void): void $register
     * @param Closure(string): void $unregister
     * @param Closure(string, string): int $send
     */
    public function __construct(
        private readonly PluginOwnershipRegistry $ownership,
        private readonly EventDispatcher $events,
        private readonly Closure $enabled,
        private readonly Closure $connected,
        private readonly Closure $register,
        private readonly Closure $unregister,
        private readonly Closure $send,
    ) {}

    public function forOwner(string $owner): GatewayConnection
    {
        return new GatewayConnection(
            fn(): bool => ($this->enabled)(),
            fn(): bool => ($this->connected)(),
            fn(string $name): GatewayPluginChannel => $this->register($owner, $name),
            fn(): array => $this->channelsFor($owner),
        );
    }

    private function register(string $owner, string $name): GatewayPluginChannel
    {
        self::validateChannelName($owner, $name);
        if (isset($this->channels[$name])) {
            throw new PluginException("Gateway plugin channel {$name} is already registered.");
        }
        if (count($this->channels) >= self::MAXIMUM_CHANNELS) {
            throw new PluginException('Gateway plugin channel capacity is exhausted.');
        }

        $channel = new GatewayPluginChannel(
            $name,
            fn(): bool => isset($this->channels[$name])
                && strcasecmp($this->channels[$name]['owner'], $owner) === 0,
            fn(string $payload): bool => $this->send($owner, $name, $payload),
        );
        $this->channels[$name] = ['owner' => $owner, 'channel' => $channel];

        try {
            if (($this->enabled)()) {
                ($this->register)(
                    $name,
                    function (string $payload) use ($owner, $name): void {
                        $this->receive($owner, $name, $payload);
                    },
                    function (string $reason) use ($owner, $name): void {
                        $this->rejected(
                            $owner,
                            $name,
                            GatewayPluginMessageRejectionReason::REMOTE_REJECTED,
                            $reason,
                        );
                    },
                );
            }
            $this->ownership->own($owner, "gateway-channel:{$name}", fn() => $this->remove($owner, $name));
        } catch (Throwable $failure) {
            unset($this->channels[$name]);
            if (($this->enabled)()) {
                ($this->unregister)($name);
            }
            throw $failure;
        }

        return $channel;
    }

    private function send(string $owner, string $name, string $payload): bool
    {
        if (!isset($this->channels[$name]) || strcasecmp($this->channels[$name]['owner'], $owner) !== 0) {
            $this->rejected($owner, $name, GatewayPluginMessageRejectionReason::CHANNEL_UNREGISTERED);
            return false;
        }
        if (strlen($payload) > GatewayPluginChannel::MAXIMUM_PAYLOAD_BYTES) {
            $this->rejected($owner, $name, GatewayPluginMessageRejectionReason::PAYLOAD_TOO_LARGE);
            return false;
        }
        if (!(($this->enabled)())) {
            $this->rejected($owner, $name, GatewayPluginMessageRejectionReason::GATEWAY_DISABLED);
            return false;
        }
        if (!(($this->connected)())) {
            $this->rejected($owner, $name, GatewayPluginMessageRejectionReason::GATEWAY_DISCONNECTED);
            return false;
        }
        try {
            if (($this->send)($name, $payload) > 0) {
                return true;
            }
        } catch (Throwable) {
            // The rejection event below is the stable plugin-facing failure surface.
        }
        $this->rejected($owner, $name, GatewayPluginMessageRejectionReason::DELIVERY_FAILED);
        return false;
    }

    private function receive(string $owner, string $name, string $payload): void
    {
        $entry = $this->channels[$name] ?? null;
        if ($entry === null || strcasecmp($entry['owner'], $owner) !== 0) {
            return;
        }
        $this->events->dispatchTo($owner, new GatewayPluginMessageEvent($entry['channel'], $payload));
    }

    private function rejected(
        string $owner,
        string $name,
        GatewayPluginMessageRejectionReason $reason,
        string $detail = '',
    ): void {
        $entry = $this->channels[$name] ?? null;
        if ($entry === null || strcasecmp($entry['owner'], $owner) !== 0) {
            return;
        }
        $this->events->dispatchTo(
            $owner,
            new GatewayPluginMessageRejectedEvent($entry['channel'], $reason, substr($detail, 0, 256)),
        );
    }

    private function remove(string $owner, string $name): void
    {
        $entry = $this->channels[$name] ?? null;
        if ($entry === null || strcasecmp($entry['owner'], $owner) !== 0) {
            return;
        }
        unset($this->channels[$name]);
        if (($this->enabled)()) {
            ($this->unregister)($name);
        }
    }

    /** @return list<GatewayPluginChannel> */
    private function channelsFor(string $owner): array
    {
        $channels = [];
        foreach ($this->channels as $entry) {
            if (strcasecmp($entry['owner'], $owner) === 0) {
                $channels[] = $entry['channel'];
            }
        }
        return $channels;
    }

    private static function validateChannelName(string $owner, string $name): void
    {
        if ($name === '' || strlen($name) > self::MAXIMUM_CHANNEL_NAME_BYTES
            || preg_match('/\A[a-z0-9][a-z0-9_.-]*:[a-z0-9][a-z0-9_.-]*\z/D', $name) !== 1) {
            throw new InvalidArgumentException('Gateway channel must be a bounded canonical lowercase name.');
        }
        [$namespace] = explode(':', $name, 2);
        if ($namespace === 'bedriox' || $namespace === 'gateway') {
            throw new InvalidArgumentException('Gateway channel uses a reserved namespace.');
        }
        if ($namespace !== strtolower($owner)) {
            throw new InvalidArgumentException('Gateway channel namespace must match its owning plugin.');
        }
    }
}
