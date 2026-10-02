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

namespace Bedriox\Server\Access;

use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\Server\BanListChangedEvent;
use Bedriox\Api\Event\Server\BanListChangeEvent;
use Bedriox\Api\Event\Server\BanListChangeType;
use Closure;
use JsonException;
use RuntimeException;

/** Atomic bounded persistent player and IPv4 ban owner. */
final class BanManager
{
    private const int MAXIMUM_BYTES = 2_097_152;
    private const int MAXIMUM_ENTRIES = 20_000;

    /** @var array<string, BanEntry> */
    private array $players = [];
    /** @var array<string, BanEntry> */
    private array $addresses = [];

    /** @param null|Closure(Event): Event $dispatch */
    public function __construct(private readonly string $path, private readonly ?Closure $dispatch = null)
    {
        $this->load();
    }

    public function banPlayer(string $name, string $reason = 'Banned by an operator.', ?string $uuid = null): bool
    {
        $name = self::name($name);
        self::reason($reason);
        if ($uuid !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $uuid) !== 1) {
            throw new \InvalidArgumentException('Ban UUID is invalid.');
        }
        $key = strtolower($name);
        if (isset($this->players[$key])) {
            return false;
        }
        return $this->mutate(BanListChangeType::PLAYER_ADDED, $name, $reason, function () use ($key, $name, $reason, $uuid): void {
            $this->assertCapacity();
            $this->players[$key] = new BanEntry($name, $reason, time(), $uuid);
        });
    }

    public function pardonPlayer(string $name): bool
    {
        $name = self::name($name);
        $key = strtolower($name);
        $entry = $this->players[$key] ?? null;
        if ($entry === null) {
            return false;
        }
        return $this->mutate(BanListChangeType::PLAYER_REMOVED, $entry->target, $entry->reason, function () use ($key): void {
            unset($this->players[$key]);
        });
    }

    public function banAddress(string $address, string $reason = 'Banned by an operator.'): bool
    {
        $address = self::address($address);
        self::reason($reason);
        if (isset($this->addresses[$address])) {
            return false;
        }
        return $this->mutate(BanListChangeType::IP_ADDED, $address, $reason, function () use ($address, $reason): void {
            $this->assertCapacity();
            $this->addresses[$address] = new BanEntry($address, $reason, time());
        });
    }

    public function pardonAddress(string $address): bool
    {
        $address = self::address($address);
        $entry = $this->addresses[$address] ?? null;
        if ($entry === null) {
            return false;
        }
        return $this->mutate(BanListChangeType::IP_REMOVED, $address, $entry->reason, function () use ($address): void {
            unset($this->addresses[$address]);
        });
    }

    public function playerBan(string $name, ?string $uuid = null): ?BanEntry
    {
        $entry = $this->players[strtolower(trim($name))] ?? null;
        if ($entry !== null) {
            return $entry;
        }
        if ($uuid !== null) {
            foreach ($this->players as $candidate) {
                if ($candidate->uuid !== null && strcasecmp($candidate->uuid, $uuid) === 0) {
                    return $candidate;
                }
            }
        }
        return null;
    }

    public function addressBan(string $address): ?BanEntry
    {
        return $this->addresses[$address] ?? null;
    }

    /** @return list<BanEntry> */
    public function playerBans(): array
    {
        return $this->sorted($this->players);
    }

    /** @return list<BanEntry> */
    public function addressBans(): array
    {
        return $this->sorted($this->addresses);
    }

    /**
     * @param array<string, BanEntry> $entries
     * @return list<BanEntry>
     */
    private function sorted(array $entries): array
    {
        $entries = array_values($entries);
        usort($entries, static fn(BanEntry $left, BanEntry $right): int => strcasecmp($left->target, $right->target));
        return $entries;
    }

    private function mutate(BanListChangeType $type, string $target, string $reason, Closure $mutation): bool
    {
        $event = new BanListChangeEvent($type, $target, $reason);
        if ($this->dispatch !== null) {
            ($this->dispatch)($event);
        }
        if ($event->isCancelled()) {
            return false;
        }
        $players = $this->players;
        $addresses = $this->addresses;
        $mutation();
        try {
            $this->save();
        } catch (\Throwable $failure) {
            $this->players = $players;
            $this->addresses = $addresses;
            throw $failure;
        }
        if ($this->dispatch !== null) {
            ($this->dispatch)(new BanListChangedEvent($type, $target, $reason));
        }
        return true;
    }

    private function assertCapacity(): void
    {
        if (count($this->players) + count($this->addresses) >= self::MAXIMUM_ENTRIES) {
            throw new RuntimeException('Ban list entry limit reached.');
        }
    }

    private function load(): void
    {
        if (!file_exists($this->path)) {
            return;
        }
        $size = filesize($this->path);
        if (is_link($this->path) || !is_file($this->path) || !is_int($size) || $size > self::MAXIMUM_BYTES) {
            throw new RuntimeException('Ban data is not a bounded regular file.');
        }
        $contents = file_get_contents($this->path);
        if (!is_string($contents)) {
            throw new RuntimeException('Ban data could not be read.');
        }
        try {
            $data = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Ban data is invalid JSON.', previous: $error);
        }
        if (!is_array($data) || ($data['schema'] ?? null) !== 1) {
            throw new RuntimeException('Ban data has an unsupported schema.');
        }
        foreach (['players', 'addresses'] as $list) {
            if (!is_array($data[$list] ?? null) || !array_is_list($data[$list])) {
                throw new RuntimeException('Ban data contains an invalid list.');
            }
            foreach ($data[$list] as $raw) {
                if (!is_array($raw) || !is_string($raw['target'] ?? null) || !is_string($raw['reason'] ?? null)
                    || !is_int($raw['created_at'] ?? null)) {
                    throw new RuntimeException('Ban data contains an invalid entry.');
                }
                $uuid = is_string($raw['uuid'] ?? null) ? $raw['uuid'] : null;
                $entry = new BanEntry($raw['target'], $raw['reason'], $raw['created_at'], $uuid);
                self::reason($entry->reason);
                if ($entry->createdAt < 0 || ($entry->uuid !== null
                    && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $entry->uuid) !== 1)) {
                    throw new RuntimeException('Ban data contains invalid metadata.');
                }
                if ($list === 'players') {
                    $key = strtolower(self::name($entry->target));
                    if (isset($this->players[$key])) {
                        throw new RuntimeException('Ban data contains duplicate players.');
                    }
                    $this->players[$key] = $entry;
                } else {
                    $key = self::address($entry->target);
                    if (isset($this->addresses[$key])) {
                        throw new RuntimeException('Ban data contains duplicate addresses.');
                    }
                    $this->addresses[$key] = $entry;
                }
            }
        }
        if (count($this->players) + count($this->addresses) > self::MAXIMUM_ENTRIES) {
            throw new RuntimeException('Ban data exceeds its entry limit.');
        }
    }

    private function save(): void
    {
        $encode = static fn(BanEntry $entry): array => [
            'target' => $entry->target,
            'uuid' => $entry->uuid,
            'reason' => $entry->reason,
            'created_at' => $entry->createdAt,
        ];
        $json = json_encode([
            'schema' => 1,
            'players' => array_map($encode, $this->playerBans()),
            'addresses' => array_map($encode, $this->addressBans()),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        if (strlen($json) > self::MAXIMUM_BYTES) {
            throw new RuntimeException('Ban data exceeds its encoded size limit.');
        }
        $temporary = tempnam(dirname($this->path), '.bans-');
        if (!is_string($temporary)) {
            throw new RuntimeException('Unable to create a ban-data temporary file.');
        }
        try {
            if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || !rename($temporary, $this->path)) {
                throw new RuntimeException('Unable to publish ban data.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private static function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 64 || preg_match('//u', $name) !== 1 || str_contains($name, "\0")) {
            throw new \InvalidArgumentException('Ban names must be valid text between 1 and 64 bytes.');
        }
        return $name;
    }

    private static function reason(string $reason): void
    {
        if ($reason === '' || strlen($reason) > 512 || preg_match('//u', $reason) !== 1 || str_contains($reason, "\0")) {
            throw new \InvalidArgumentException('Ban reasons must be valid text between 1 and 512 bytes.');
        }
    }

    private static function address(string $address): string
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new \InvalidArgumentException('A valid IPv4 address is required.');
        }
        return $address;
    }
}
