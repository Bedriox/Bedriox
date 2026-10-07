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

namespace Bedriox\Server\Runtime;

use InvalidArgumentException;
use RuntimeException;

class ServerSettingsFile
{
    public const int MAX_BYTES = 65_536;
    public const int MAX_LINES = 256;

    /** @return array<string, string> */
    public function loadOrCreate(string $path): array
    {
        if (!is_file($path)) {
            $this->create($path);
        }

        return $this->parse($this->read($path));
    }

    /** @return array<string, string> */
    public function parse(string $contents): array
    {
        if (strlen($contents) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Settings file exceeds the 65536-byte limit.');
        }
        if (str_contains($contents, "\0")) {
            throw new InvalidArgumentException('Settings file contains a NUL byte.');
        }

        $lines = preg_split('/\R/u', $contents);
        if (!is_array($lines)) {
            throw new InvalidArgumentException('Settings file is not valid UTF-8 text.');
        }
        if (count($lines) > self::MAX_LINES) {
            throw new InvalidArgumentException('Settings file exceeds the 256-line limit.');
        }

        $values = [];
        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                throw new InvalidArgumentException(sprintf('Malformed setting on line %d.', $index + 1));
            }
            [$key, $value] = array_map(trim(...), explode('=', $line, 2));
            if ($key === '' || preg_match('/\A[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*\z/D', $key) !== 1) {
                throw new InvalidArgumentException(sprintf('Invalid setting name on line %d.', $index + 1));
            }
            if (array_key_exists($key, $values)) {
                throw new InvalidArgumentException(sprintf('Duplicate setting "%s".', $key));
            }
            $values[$key] = $value;
        }

        return $values;
    }

    public static function defaults(): string
    {
        return <<<'SETTINGS'
# Bedriox advanced settings. Common server settings belong in server.properties.

# Runtime and workers
runtime.ticks-per-second=20
workers.core-count=auto

# Chunk orchestration
chunk-sending.spawn-radius=4
chunk-sending.per-tick=8
chunk-generation.per-tick=4
chunk-generation.queue-size=1024
chunk-loading.prefetch-radius=1
chunk-cache.limit=auto
chunk-saving.per-tick=8
chunk-unloading.grace-ticks=600
chunk-unloading.per-tick=96

# Memory pressure and garbage collection
memory-management.enabled=true
memory-management.soft-threshold=70
memory-management.high-threshold=85
memory-management.critical-threshold=92

# Persistence and movement
level.autosave-interval-ticks=6000
players.autosave-interval-ticks=6000
players.save-per-tick=8
movement.rewind-history-size=40

# Network protection. Changes take effect after restart.
# enabled: true or false. Keep true for every public server.
security.network.enabled=true
# automatic-blocking: true temporarily blocks abusive source addresses; false only drops over-limit traffic.
security.network.automatic-blocking=true
# profile: lenient, balanced, strict, or custom.
# balanced is recommended. Lenient permits larger bursts; strict is for smaller controlled deployments.
# custom reads the security.network.custom.* values below.
security.network.profile=balanced
# Initial block duration in seconds. Accepted range: 1 through 300.
security.network.block-base-seconds=10
# Maximum block duration in seconds. Accepted range: the base duration through 86400.
security.network.block-maximum-seconds=1800
# Repeated offenses inside this window increase the block duration. Accepted range: 30 through 3600 seconds.
security.network.block-escalation-window-seconds=300
# Custom-profile limits. These are ignored by lenient, balanced, and strict profiles.
# Unauthenticated datagram rate: 100 through 100000; burst: 20 through 20000.
security.network.custom.unauthenticated-datagrams-per-second=2000
security.network.custom.unauthenticated-datagram-burst=400
# Connected endpoint datagram rate: 500 through 100000; burst: 40 through 20000.
security.network.custom.connected-datagrams-per-second=12000
security.network.custom.connected-datagram-burst=240
# Handshake rate: 10 through 10000; burst: 5 through 2000.
security.network.custom.handshakes-per-second=250
security.network.custom.handshake-burst=128
# Malformed datagrams from one address before a temporary block. Accepted range: 1 through 20.
security.network.custom.malformed-threshold=3

# Movement authority. These switches never make client positions authoritative.
# enabled records high-confidence violations such as unauthorized survival flight.
security.movement.enabled=true
# correct-invalid-movement returns the player to authoritative state. Keep true for normal gameplay.
security.movement.correct-invalid-movement=true
# kick-repeated-violations disconnects only after repeated violations reach the internal confidence threshold.
security.movement.kick-repeated-violations=true

# Entity simulation
entities.ai.enabled=true

# Update notifications
# Bedriox checks https://update.bedriox.com for release notifications only.
# The request selects the stable or beta channel but does not send the current version or server,
# player, plugin, configuration, or installation identifiers. As with normal HTTPS traffic, the
# service and its CDN can observe the public IP, request time, channel, and standard HTTP/TLS metadata.
# Allow outbound HTTPS access to that host. Updates are never downloaded or installed automatically.
updates.enabled=true
updates.notify-operators=true

# Plugins and diagnostics
plugins.maximum=64
logging.level=INFO
logging.console=true
logging.console-colors=auto
logging.file=true
logging.file-max-size=16777216
logging.file-history=10
logging.protocol-trace=false
crash-report.include-player-identifiers=true
SETTINGS
            . PHP_EOL;
    }

    private function create(string $path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            throw new RuntimeException('Settings directory does not exist.');
        }
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            if (is_file($path)) {
                return;
            }
            throw new RuntimeException('Unable to create the settings file.');
        }
        try {
            $contents = $this->defaultContents();
            if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
                throw new RuntimeException('Unable to write the settings file.');
            }
        } finally {
            fclose($handle);
        }
    }

    protected function defaultContents(): string
    {
        return self::defaults();
    }

    private function read(string $path): string
    {
        $size = @filesize($path);
        if ($size === false || $size > self::MAX_BYTES) {
            throw new RuntimeException('Settings file is unreadable or exceeds the size limit.');
        }
        $contents = @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read the settings file.');
        }

        return $contents;
    }
}
