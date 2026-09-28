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

namespace Bedriox\Server\Permission;

use JsonException;
use RuntimeException;

/** UUID-keyed persistent operator and explicit permission assignments. */
final class PermissionStore
{
    private const int SCHEMA = 1;
    private const int MAX_BYTES = 1_048_576;
    private const int MAX_IDENTITIES = 10_000;
    private const int MAX_PERMISSIONS_PER_IDENTITY = 256;

    /** @var array<string, string> */
    private array $operators = [];
    /** @var array<string, array{name: string, grants: array<string, true>}> */
    private array $permissions = [];

    public function __construct(private readonly string $path)
    {
        $this->load();
    }

    public function isOperator(string $uuid): bool
    {
        return isset($this->operators[self::uuid($uuid)]);
    }

    public function hasPermission(string $uuid, string $permission): bool
    {
        $uuid = self::uuid($uuid);
        self::permission($permission);
        if (isset($this->operators[$uuid])) {
            return true;
        }
        $grants = $this->permissions[$uuid]['grants'] ?? [];
        if (isset($grants[$permission]) || isset($grants['*'])) {
            return true;
        }
        $parts = explode('.', $permission);
        while (count($parts) > 1) {
            array_pop($parts);
            if (isset($grants[implode('.', $parts) . '.*'])) {
                return true;
            }
        }
        return false;
    }

    public function setOperator(string $uuid, string $lastKnownName, bool $operator): bool
    {
        $uuid = self::uuid($uuid);
        self::name($lastKnownName);
        $changed = $operator ? !isset($this->operators[$uuid]) : isset($this->operators[$uuid]);
        $previous = $this->operators;
        if ($operator) {
            $this->operators[$uuid] = $lastKnownName;
        } else {
            unset($this->operators[$uuid]);
        }
        if ($changed) {
            try {
                $this->save();
            } catch (\Throwable $failure) {
                $this->operators = $previous;
                throw $failure;
            }
        }
        return $changed;
    }

    public function grant(string $uuid, string $lastKnownName, string $permission): bool
    {
        $uuid = self::uuid($uuid);
        self::name($lastKnownName);
        self::permission($permission);
        $previous = $this->permissions;
        $entry = $this->permissions[$uuid] ?? ['name' => $lastKnownName, 'grants' => []];
        if (!isset($entry['grants'][$permission]) && count($entry['grants']) >= self::MAX_PERMISSIONS_PER_IDENTITY) {
            throw new RuntimeException('The permission assignment limit has been reached.');
        }
        $changed = !isset($entry['grants'][$permission]);
        $entry['name'] = $lastKnownName;
        $entry['grants'][$permission] = true;
        $this->permissions[$uuid] = $entry;
        if ($changed) {
            try {
                $this->save();
            } catch (\Throwable $failure) {
                $this->permissions = $previous;
                throw $failure;
            }
        }
        return $changed;
    }

    public function revoke(string $uuid, string $permission): bool
    {
        $uuid = self::uuid($uuid);
        self::permission($permission);
        if (!isset($this->permissions[$uuid]['grants'][$permission])) {
            return false;
        }
        $previous = $this->permissions;
        unset($this->permissions[$uuid]['grants'][$permission]);
        if ($this->permissions[$uuid]['grants'] === []) {
            unset($this->permissions[$uuid]);
        }
        try {
            $this->save();
        } catch (\Throwable $failure) {
            $this->permissions = $previous;
            throw $failure;
        }
        return true;
    }

    /** @return list<string> */
    public function grants(string $uuid): array
    {
        $grants = array_keys($this->permissions[self::uuid($uuid)]['grants'] ?? []);
        sort($grants, SORT_STRING);
        return $grants;
    }

    private function load(): void
    {
        if (!file_exists($this->path)) {
            return;
        }
        if (is_link($this->path) || !is_file($this->path)) {
            throw new RuntimeException('Permission data must be a regular file.');
        }
        $bytes = filesize($this->path);
        if (!is_int($bytes) || $bytes > self::MAX_BYTES) {
            throw new RuntimeException('Permission data exceeds its size limit.');
        }
        $contents = file_get_contents($this->path);
        if (!is_string($contents)) {
            throw new RuntimeException('Permission data could not be read.');
        }
        try {
            $decoded = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new RuntimeException('Permission data is invalid JSON.', previous: $failure);
        }
        if (!is_array($decoded) || ($decoded['schema'] ?? null) !== self::SCHEMA
            || !is_array($decoded['operators'] ?? null) || !is_array($decoded['permissions'] ?? null)
            || count($decoded['operators']) > self::MAX_IDENTITIES || count($decoded['permissions']) > self::MAX_IDENTITIES) {
            throw new RuntimeException('Permission data has an unsupported structure.');
        }
        foreach ($decoded['operators'] as $uuid => $name) {
            if (!is_string($uuid) || !is_string($name)) {
                throw new RuntimeException('Permission operator entry is invalid.');
            }
            $this->operators[self::uuid($uuid)] = self::name($name);
        }
        foreach ($decoded['permissions'] as $uuid => $entry) {
            if (!is_string($uuid) || !is_array($entry) || !is_string($entry['name'] ?? null)
                || !is_array($entry['grants'] ?? null) || !array_is_list($entry['grants'])
                || count($entry['grants']) > self::MAX_PERMISSIONS_PER_IDENTITY) {
                throw new RuntimeException('Permission assignment entry is invalid.');
            }
            $grants = [];
            foreach ($entry['grants'] as $permission) {
                if (!is_string($permission)) {
                    throw new RuntimeException('Permission grant is invalid.');
                }
                $permission = self::permission($permission);
                if (isset($grants[$permission])) {
                    throw new RuntimeException('Permission grants must be unique.');
                }
                $grants[$permission] = true;
            }
            $this->permissions[self::uuid($uuid)] = ['name' => self::name($entry['name']), 'grants' => $grants];
        }
    }

    private function save(): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Permission data directory could not be created.');
        }
        if (is_link($directory)) {
            throw new RuntimeException('Permission data directory must not be a symbolic link.');
        }
        $permissions = [];
        foreach ($this->permissions as $uuid => $entry) {
            $grants = array_keys($entry['grants']);
            sort($grants, SORT_STRING);
            $permissions[$uuid] = ['name' => $entry['name'], 'grants' => $grants];
        }
        ksort($this->operators, SORT_STRING);
        ksort($permissions, SORT_STRING);
        try {
            $json = json_encode([
                'schema' => self::SCHEMA,
                'operators' => $this->operators,
                'permissions' => $permissions,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        } catch (JsonException $failure) {
            throw new RuntimeException('Permission data could not be encoded.', previous: $failure);
        }
        $temporary = $this->path . '.tmp.' . bin2hex(random_bytes(8));
        if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || !@rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new RuntimeException('Permission data could not be saved atomically.');
        }
    }

    private static function uuid(string $uuid): string
    {
        $uuid = strtolower($uuid);
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $uuid) !== 1) {
            throw new RuntimeException('Permission identity must be a canonical UUID.');
        }
        return $uuid;
    }

    private static function name(string $name): string
    {
        if ($name === '' || strlen($name) > 64 || preg_match('//u', $name) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
            throw new RuntimeException('Permission identity name is invalid.');
        }
        return $name;
    }

    private static function permission(string $permission): string
    {
        $permission = strtolower($permission);
        if ($permission !== '*' && preg_match('/^[a-z][a-z0-9_-]*(?:\.[a-z0-9_*_-]+)*$/D', $permission) !== 1) {
            throw new RuntimeException('Permission node is invalid.');
        }
        if (strlen($permission) > 128) {
            throw new RuntimeException('Permission node exceeds its length limit.');
        }
        return $permission;
    }
}
