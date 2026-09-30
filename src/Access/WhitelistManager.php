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

use Bedriox\Api\Event\Server\WhitelistChangedEvent;
use Bedriox\Api\Event\Server\WhitelistChangeType;
use Bedriox\Api\Whitelist\Whitelist;
use Bedriox\Api\Whitelist\WhitelistEntry;
use Closure;
use JsonException;
use RuntimeException;

/** Atomic, bounded whitelist persistence and identity upgrade service. */
final class WhitelistManager implements Whitelist
{
    private const int MAX_BYTES = 1_048_576;
    private const int MAX_ENTRIES = 10_000;

    /** @var array<string, WhitelistEntry> normalized name => entry */
    private array $entries = [];

    /** @param Closure(bool): void $persistEnabled @param null|Closure(WhitelistChangedEvent): void $dispatch */
    public function __construct(
        private readonly string $path,
        private bool $enabled,
        private readonly Closure $persistEnabled,
        private readonly ?Closure $dispatch = null,
    ) {
        $this->reload(false);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): bool
    {
        if ($this->enabled === $enabled) {
            return false;
        }
        ($this->persistEnabled)($enabled);
        $this->enabled = $enabled;
        $this->emit($enabled ? WhitelistChangeType::ENABLED : WhitelistChangeType::DISABLED);
        return true;
    }

    public function contains(string $name, ?string $uuid = null): bool
    {
        $key = self::name($name);
        $entry = $this->entries[$key] ?? null;
        if ($entry === null) {
            return false;
        }
        if ($uuid === null || $entry->uuid === null) {
            return true;
        }
        return strtolower($entry->uuid) === strtolower($uuid);
    }

    public function add(string $name, ?string $uuid = null): bool
    {
        $key = self::name($name);
        self::uuid($uuid);
        if (isset($this->entries[$key])) {
            if ($uuid !== null && $this->entries[$key]->uuid === null) {
                $this->replace($key, new WhitelistEntry($uuid, $name));
            }
            return false;
        }
        if (count($this->entries) >= self::MAX_ENTRIES) {
            throw new RuntimeException('Whitelist entry limit reached.');
        }
        $entry = new WhitelistEntry($uuid, $name);
        $this->replace($key, $entry);
        $this->emit(WhitelistChangeType::ENTRY_ADDED, $entry);
        return true;
    }

    public function remove(string $name): bool
    {
        $key = self::name($name);
        $entry = $this->entries[$key] ?? null;
        if ($entry === null) {
            return false;
        }
        $previous = $this->entries;
        unset($this->entries[$key]);
        try {
            $this->save();
        } catch (\Throwable $failure) {
            $this->entries = $previous;
            throw $failure;
        }
        $this->emit(WhitelistChangeType::ENTRY_REMOVED, $entry);
        return true;
    }

    public function entries(): array
    {
        $entries = array_values($this->entries);
        usort($entries, static fn(WhitelistEntry $a, WhitelistEntry $b): int => strcasecmp($a->lastKnownName, $b->lastKnownName));
        return $entries;
    }

    public function reload(bool $emit = true): void
    {
        $loaded = $this->read();
        $this->entries = $loaded;
        if ($emit) {
            $this->emit(WhitelistChangeType::RELOADED);
        }
    }

    /** Upgrades a name-only entry after successful authenticated login. */
    public function admit(string $name, string $uuid): bool
    {
        if (!$this->contains($name, $uuid)) {
            return false;
        }
        $key = self::name($name);
        $entry = $this->entries[$key];
        if ($entry->uuid === null) {
            $this->replace($key, new WhitelistEntry($uuid, $name));
        }
        return true;
    }

    /** @return array<string, WhitelistEntry> */
    private function read(): array
    {
        if (!file_exists($this->path)) {
            return [];
        }
        if (is_link($this->path) || !is_file($this->path)) {
            throw new RuntimeException('Whitelist data must be a regular file.');
        }
        $size = filesize($this->path);
        if (!is_int($size) || $size > self::MAX_BYTES) {
            throw new RuntimeException('Whitelist data exceeds its size limit.');
        }
        $json = file_get_contents($this->path);
        if (!is_string($json)) {
            throw new RuntimeException('Whitelist data could not be read.');
        }
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Whitelist data is invalid JSON.', 0, $e);
        }
        if (!is_array($data) || ($data['schema'] ?? null) !== 1 || !is_array($data['entries'] ?? null)
            || !array_is_list($data['entries']) || count($data['entries']) > self::MAX_ENTRIES) {
            throw new RuntimeException('Whitelist data has an unsupported structure.');
        }
        $entries = [];
        foreach ($data['entries'] as $raw) {
            if (!is_array($raw) || !is_string($raw['name'] ?? null)) {
                throw new RuntimeException('Whitelist data contains an invalid entry.');
            }
            $name = $raw['name'];
            if (!array_key_exists('uuid', $raw) || $raw['uuid'] === null) {
                $uuid = null;
            } elseif (is_string($raw['uuid'])) {
                $uuid = $raw['uuid'];
            } else {
                throw new RuntimeException('Whitelist data contains an invalid UUID.');
            }
            $key = self::name($name);
            self::uuid($uuid);
            if (isset($entries[$key])) {
                throw new RuntimeException('Whitelist contains duplicate names.');
            }
            $entries[$key] = new WhitelistEntry($uuid, $name);
        }
        return $entries;
    }

    private function replace(string $key, WhitelistEntry $entry): void
    {
        $previous = $this->entries;
        $this->entries[$key] = $entry;
        try {
            $this->save();
        } catch (\Throwable $failure) {
            $this->entries = $previous;
            throw $failure;
        }
    }

    private function save(): void
    {
        $data = ['schema' => 1, 'entries' => array_map(static fn(WhitelistEntry $entry): array => [
            'uuid' => $entry->uuid, 'name' => $entry->lastKnownName, 'normalized_name' => strtolower($entry->lastKnownName),
        ], $this->entries())];
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        $temporary = tempnam(dirname($this->path), '.whitelist-');
        if (!is_string($temporary)) {
            throw new RuntimeException('Unable to create whitelist temporary file.');
        }
        try {
            if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || !rename($temporary, $this->path)) {
                throw new RuntimeException('Unable to publish whitelist data.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function emit(WhitelistChangeType $type, ?WhitelistEntry $entry = null): void
    {
        if ($this->dispatch !== null) {
            ($this->dispatch)(new WhitelistChangedEvent($type, $entry));
        }
    }

    private static function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 64 || preg_match('//u', $name) !== 1 || str_contains($name, "\0")) {
            throw new \InvalidArgumentException('Whitelist names must be valid text between 1 and 64 bytes.');
        }
        return strtolower($name);
    }

    private static function uuid(?string $uuid): void
    {
        if ($uuid !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $uuid) !== 1) {
            throw new \InvalidArgumentException('Whitelist UUID is invalid.');
        }
    }
}
