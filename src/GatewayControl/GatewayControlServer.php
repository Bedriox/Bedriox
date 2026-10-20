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

namespace Bedriox\Server\GatewayControl;

use Bedriox\Protocol\GatewayControl\ControlFrameCodec;
use Closure;
use InvalidArgumentException;
use Throwable;

/** Bounded owner for control sessions and plugin control channels. */
final class GatewayControlServer
{
    /** @var array<string, GatewayControlSession> */
    private array $sessions = [];
    /** @var array<string, array{message: Closure(string): void, rejection: ?Closure(string): void}> */
    private array $pluginHandlers = [];
    private readonly GatewayControlNonceGuard $nonces;
    private bool $draining = false;

    public function __construct(private readonly GatewayControlConfig $config)
    {
        $this->nonces = new GatewayControlNonceGuard();
    }

    public static function identifies(string $payload): bool
    {
        return strlen($payload) >= 4 && hash_equals(ControlFrameCodec::MAGIC, substr($payload, 0, 4));
    }

    public function draining(): bool
    {
        return $this->draining;
    }

    /** @return list<string> */
    public function openAndAccept(string $endpoint, string $payload, int $now, int $onlinePlayers, int $maximumPlayers): array
    {
        if (isset($this->sessions[$endpoint]) || count($this->sessions) >= $this->config->maximumSessions) {
            throw new InvalidArgumentException('Gateway control session capacity is exhausted.');
        }
        $session = new GatewayControlSession(
            $this->config,
            $this->nonces,
            function (string $channel, string $message): bool {
                $handlers = $this->pluginHandlers[$channel] ?? null;
                if ($handlers === null) {
                    return false;
                }
                try {
                    ($handlers['message'])($message);
                    return true;
                } catch (Throwable) {
                    return false;
                }
            },
            function (string $channel, string $reason): void {
                $handler = $this->pluginHandlers[$channel]['rejection'] ?? null;
                if ($handler !== null) {
                    $handler($reason);
                }
            },
        );
        $this->sessions[$endpoint] = $session;
        try {
            return $session->accept($payload, $now, $onlinePlayers, $maximumPlayers, $this->draining);
        } catch (Throwable $exception) {
            unset($this->sessions[$endpoint]);
            throw $exception;
        }
    }

    /** @return list<string> */
    public function accept(string $endpoint, string $payload, int $now, int $onlinePlayers, int $maximumPlayers): array
    {
        $session = $this->sessions[$endpoint] ?? null;
        if ($session === null) {
            throw new InvalidArgumentException('Gateway control session is not open.');
        }
        try {
            return $session->accept($payload, $now, $onlinePlayers, $maximumPlayers, $this->draining);
        } catch (Throwable $exception) {
            unset($this->sessions[$endpoint]);
            throw $exception;
        }
    }

    public function has(string $endpoint): bool
    {
        return isset($this->sessions[$endpoint]);
    }

    public function close(string $endpoint): void
    {
        unset($this->sessions[$endpoint]);
    }

    public function readySessionCount(): int
    {
        $count = 0;
        foreach ($this->sessions as $session) {
            if ($session->ready()) {
                ++$count;
            }
        }
        return $count;
    }

    /** Public plugin API seam. One owner per namespaced channel. */
    public function registerPluginChannel(string $channel, Closure $handler, ?Closure $rejectionHandler = null): void
    {
        if (strlen($channel) > 128
            || preg_match('/\A[a-z0-9][a-z0-9_.-]*:[a-z0-9][a-z0-9_.-]*\z/D', $channel) !== 1) {
            throw new InvalidArgumentException('Gateway control plugin channel is invalid.');
        }
        if (isset($this->pluginHandlers[$channel])) {
            throw new InvalidArgumentException('Gateway control plugin channel is already registered.');
        }
        if (count($this->pluginHandlers) >= 256) {
            throw new InvalidArgumentException('Gateway control plugin channel limit is exhausted.');
        }
        $this->pluginHandlers[$channel] = ['message' => $handler, 'rejection' => $rejectionHandler];
    }

    public function unregisterPluginChannel(string $channel): void
    {
        unset($this->pluginHandlers[$channel]);
    }

    /** @return array<string, string> endpoint => encoded frame */
    public function broadcastPluginMessage(string $channel, string $payload): array
    {
        if (strlen($channel) > 128 || preg_match('/\A[a-z0-9][a-z0-9_.-]*:[a-z0-9][a-z0-9_.-]*\z/D', $channel) !== 1
            || strlen($payload) > 24_000) {
            throw new InvalidArgumentException('Gateway control plugin message is invalid or oversized.');
        }
        $frames = [];
        foreach ($this->sessions as $endpoint => $session) {
            $frame = $session->pluginMessage($channel, $payload);
            if ($frame !== null) {
                $frames[$endpoint] = $frame;
            }
        }
        return $frames;
    }

    /** @return array<string, string> endpoint => encoded frame */
    public function stoppingFrames(): array
    {
        $frames = [];
        foreach ($this->sessions as $endpoint => $session) {
            $frame = $session->serverStopping();
            if ($frame !== null) {
                $frames[$endpoint] = $frame;
            }
        }
        return $frames;
    }

    /** @return array<string, string> endpoint => encoded frame */
    public function statusFrames(string $status, string $reason): array
    {
        $frames = [];
        foreach ($this->sessions as $endpoint => $session) {
            $frame = $session->serverStatus($status, $reason);
            if ($frame !== null) {
                $frames[$endpoint] = $frame;
            }
        }
        return $frames;
    }
}
