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

namespace Bedriox\Server\Tests\Plugin;

use Bedriox\Api\Event\Gateway\GatewayPluginMessageEvent;
use Bedriox\Api\Event\Gateway\GatewayPluginMessageRejectedEvent;
use Bedriox\Api\Gateway\GatewayPluginChannel;
use Bedriox\Api\Gateway\GatewayPluginMessageRejectionReason;
use Bedriox\Server\Plugin\Event\EventDispatcher;
use Bedriox\Server\Plugin\Gateway\GatewayPluginConnectionRegistry;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginException;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Throwable;

final class GatewayPluginConnectionRegistryTest extends TestCase
{
    public function testOwnerReceivesMessagesAndCleanupUnregistersChannel(): void
    {
        [$registry, $events, $ownership, $transport] = $this->registry();
        $connection = $registry->forOwner('Example');
        $messages = [];
        $events->register('Example', GatewayPluginMessageEvent::class, static function (
            GatewayPluginMessageEvent $event,
        ) use (&$messages): void {
            $messages[] = [$event->getChannel()->getName(), $event->getPayload()];
        });

        $channel = $connection->registerChannel('example:status');
        self::assertTrue($channel->isRegistered());
        self::assertSame([$channel], $connection->getChannels());
        ($transport->messages['example:status'])('pong');

        self::assertSame([['example:status', 'pong']], $messages);
        self::assertSame([], $ownership->releaseAll('Example'));
        self::assertFalse($channel->isRegistered());
        self::assertSame(['example:status'], $transport->unregistered);
    }

    public function testMessageEventsAreDispatchedOnlyToChannelOwner(): void
    {
        [$registry, $events, , $transport] = $this->registry(['example' => true, 'observer' => true]);
        $ownerCalls = 0;
        $observerCalls = 0;
        $events->register('Example', GatewayPluginMessageEvent::class, static function () use (&$ownerCalls): void {
            ++$ownerCalls;
        });
        $events->register('Observer', GatewayPluginMessageEvent::class, static function () use (&$observerCalls): void {
            ++$observerCalls;
        });
        $registry->forOwner('Example')->registerChannel('example:private');

        ($transport->messages['example:private'])('secret');

        self::assertSame(1, $ownerCalls);
        self::assertSame(0, $observerCalls);
    }

    public function testUnavailableAndOversizedSendsFailWithOwnerTargetedReasons(): void
    {
        [$registry, $events, , $transport] = $this->registry(enabled: false, connected: false);
        $reasons = [];
        $events->register('Example', GatewayPluginMessageRejectedEvent::class, static function (
            GatewayPluginMessageRejectedEvent $event,
        ) use (&$reasons): void {
            $reasons[] = $event->getReason();
        });
        $channel = $registry->forOwner('Example')->registerChannel('example:status');

        self::assertFalse($channel->send('hello'));
        self::assertFalse($channel->send(str_repeat('x', GatewayPluginChannel::MAXIMUM_PAYLOAD_BYTES + 1)));
        self::assertSame([
            GatewayPluginMessageRejectionReason::GATEWAY_DISABLED,
            GatewayPluginMessageRejectionReason::PAYLOAD_TOO_LARGE,
        ], $reasons);
        self::assertSame([], $transport->sent);
    }

    public function testRejectsNonCanonicalReservedAndDuplicateChannels(): void
    {
        [$registry] = $this->registry();
        $connection = $registry->forOwner('Example');
        $connection->registerChannel('example:status');
        $rejections = 0;

        try {
            $connection->registerChannel('example:status');
            self::fail('Expected duplicate channel rejection.');
        } catch (PluginException) {
            ++$rejections;
        }
        foreach (['Example:status', 'other:status', 'gateway:status', 'bedriox:status', ':status', 'example:'] as $name) {
            try {
                $connection->registerChannel($name);
                self::fail("Expected {$name} to be rejected.");
            } catch (InvalidArgumentException) {
                ++$rejections;
            }
        }
        self::assertSame(7, $rejections);
    }

    /**
     * @param array<string, bool> $plugins
     * @return array{GatewayPluginConnectionRegistry, EventDispatcher, PluginOwnershipRegistry, GatewayTestTransport}
     */
    private function registry(
        array $plugins = ['example' => true],
        bool $enabled = true,
        bool $connected = true,
    ): array {
        $control = new GatewayTestRuntimeControl($plugins);
        $ownership = new PluginOwnershipRegistry();
        $events = new EventDispatcher(
            $control,
            new PluginExecutionContext(),
            new PluginActionBuffer(),
            $ownership,
        );
        $control->ownership = $ownership;
        $transport = new GatewayTestTransport();
        $registry = new GatewayPluginConnectionRegistry(
            $ownership,
            $events,
            static fn(): bool => $enabled,
            static fn(): bool => $connected,
            $transport->register(...),
            $transport->unregister(...),
            $transport->send(...),
        );

        return [$registry, $events, $ownership, $transport];
    }
}

final class GatewayTestTransport
{
    /** @var array<string, Closure(string): void> */
    public array $messages = [];
    /** @var array<string, Closure(string): void> */
    public array $rejections = [];
    /** @var list<string> */
    public array $unregistered = [];
    /** @var list<array{string, string}> */
    public array $sent = [];

    public function register(string $channel, Closure $message, Closure $rejection): void
    {
        $this->messages[$channel] = $message;
        $this->rejections[$channel] = $rejection;
    }

    public function unregister(string $channel): void
    {
        unset($this->messages[$channel], $this->rejections[$channel]);
        $this->unregistered[] = $channel;
    }

    public function send(string $channel, string $payload): int
    {
        $this->sent[] = [$channel, $payload];
        return 1;
    }
}

final class GatewayTestRuntimeControl implements PluginRuntimeControl
{
    public ?PluginOwnershipRegistry $ownership = null;

    /** @param array<string, bool> $enabled */
    public function __construct(private array $enabled) {}

    public function isEnabled(string $plugin): bool
    {
        return $this->enabled[strtolower($plugin)] ?? false;
    }

    public function version(string $plugin): string
    {
        return '1.0.0';
    }

    public function disableAfterFailure(string $plugin, Throwable $failure, ?PluginExecutionFrame $frame): void
    {
        $this->enabled[strtolower($plugin)] = false;
        $this->ownership?->releaseAll($plugin);
    }
}
