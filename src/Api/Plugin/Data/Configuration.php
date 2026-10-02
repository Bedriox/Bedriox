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

namespace Bedriox\Api\Plugin\Data;

interface Configuration
{
    public function has(string $key): bool;

    public function get(string $key, mixed $default = null): mixed;

    public function getString(string $key, string $default = ''): string;

    public function getInt(string $key, int $default = 0): int;

    public function getFloat(string $key, float $default = 0.0): float;

    public function getBool(string $key, bool $default = false): bool;

    /** @param list<mixed> $default
     * @return list<mixed>
     */
    public function getList(string $key, array $default = []): array;

    /** @param array<string, mixed> $default
     * @return array<string, mixed>
     */
    public function getMap(string $key, array $default = []): array;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /** @return array<string, mixed> */
    public function all(): array;

    public function save(): void;

    public function reload(): void;
}
