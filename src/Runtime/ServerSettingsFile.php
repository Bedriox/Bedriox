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
# Number of workers reserved for chunk generation and other server-owned background work.
# Use auto for a conservative CPU-based value, 0 to disable core workers, or an integer from 1 through 32.
workers.core-count=auto
# Number of workers shared fairly by asynchronous plugin tasks. Auto allocates independent plugin
# capacity while reserving CPU for the main tick, networking, storage and Bedriox core workers.
# Use auto for most servers, 0 to disable plugin async execution, or an integer from 1 through 32.
workers.plugin-count=auto

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
# Login authentication: FULL verifies Xbox Live, SELF_SIGNED is local development only,
# and GATEWAY enables a backend for signed connections from Bedriox Gateway.
network.authentication=FULL
# These settings are used only with network.authentication=GATEWAY. Direct
# player joins are rejected in that mode; players must connect through Gateway.
network.proxy.issuer=bedriox-gateway
network.proxy.audience=backend
network.proxy.secret-file=gateway.secret
network.proxy.trusted-cidrs=127.0.0.1/32
network.proxy.token-max-age-seconds=15
# Internal control-protocol key label. Gateway currently selects "primary"
# internally; the same global secret authenticates player forwarding and control.
network.proxy.control-key-id=primary
# Keep false. Enabling this permits GATEWAY mode to bind every interface; use a
# host firewall or private network in addition to the application checks.
network.proxy.allow-public-bind=false

# Selects bounded NetherNet signaling and ICE negotiation limits.
# lenient permits more concurrent negotiations, balanced is recommended, strict
# lowers admission limits, and custom reads the network.nethernet.maximum-* values.
security.network.profile=balanced
# UDP 7551 is the standard LAN discovery port. Set 0 for a backend that is only
# reached through Gateway. HTTP signaling and ICE remain on server-port.
network.nethernet.lan-port=7551
# Custom-profile admission limits. These are ignored by the named profiles.
network.nethernet.maximum-signaling-connections=256
network.nethernet.maximum-negotiations=256
network.nethernet.maximum-negotiations-per-address=8
network.nethernet.maximum-remote-candidates=64
# ICE gathering and data-channel opening deadlines in milliseconds.
network.nethernet.gathering-timeout-milliseconds=15000
network.nethernet.channel-timeout-milliseconds=5000
# Optional comma-separated public IP addresses advertised as ICE candidates.
network.nethernet.advertised-addresses=

# Authenticated Bedrock packet handling. These limits do not apply to unknown supported-version
# notifications, stale input, or gameplay mistakes that Bedriox can safely ignore or correct.
# Repeated impossible semantic actions, such as another actor's runtime identity, close only the
# affected session after this many soft strikes. Unsupported, stale, and correctable input never counts.
# Accepted range: 1 through 100. The recommended default is 10.
network.protocol-soft-strike-limit=10
# One accumulated soft strike expires after this many violation-free seconds.
# Accepted range: 1 through 3600. The recommended default is 15.
network.protocol-strike-decay-seconds=15
# When true, bounded metadata for unsupported packet IDs is written to protocol tracing.
# This never logs packet bodies, identity tokens, encryption material, or credentials.
network.trace-unsupported-packets=true
# Maximum trace entries retained for each unsupported packet ID during one session.
# Accepted range: 0 through 1000. Zero disables these entries without changing packet handling.
network.unsupported-packet-log-limit=5

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
# Enables the explicit /timings paste command. Reports are never uploaded automatically.
# Uploads contain bounded performance counters and optional plugin names, but no player identities,
# chat, commands, world coordinates, inventories, packets, credentials, or complete configuration.
# Reports are sent over HTTPS to https://timings.bedriox.com, are publicly viewable by random report ID,
# and remain available until deleted. The service lists recent reports without server or player identity.
timings.upload-enabled=true
# Maximum recording duration accepted by /timings on (or /timings start), in seconds (1 through 1800).
timings.maximum-duration-seconds=1800
# Local compressed reports are retained in timings/ for this many days (1 through 90).
timings.local-retention-days=14
# Include plugin names and versions so plugin-owned workload can be attributed. Disable for anonymous labels.
timings.include-plugin-identities=true
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
