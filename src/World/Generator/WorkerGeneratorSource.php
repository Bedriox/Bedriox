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

namespace Bedriox\Server\World\Generator;

use Bedriox\Api\World\Generator\Generator as ApiGenerator;
use InvalidArgumentException;
use ReflectionClass;
use RuntimeException;

/** Immutable code identity used to load a stateless plugin generator inside a worker. */
final readonly class WorkerGeneratorSource
{
    public function __construct(
        public string $class,
        public string $file,
        public string $sha256,
    ) {
        if ($class === '' || strlen($class) > 512
            || preg_match('/^[A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)*$/D', $class) !== 1
            || $file === '' || strlen($file) > 4096 || str_contains($file, "\0")
            || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
            throw new InvalidArgumentException('Worker generator source identity is invalid.');
        }
    }

    public static function capture(string $class): self
    {
        if (!class_exists($class) || !is_a($class, ApiGenerator::class, true)) {
            throw new InvalidArgumentException('Plugin generator class is unavailable.');
        }
        $reflection = new ReflectionClass($class);
        $file = $reflection->getFileName();
        if (!is_string($file) || !is_file($file)) {
            throw new InvalidArgumentException('Plugin generator source file is unavailable.');
        }
        $digest = hash_file('sha256', $file);
        if (!is_string($digest)) {
            throw new InvalidArgumentException('Plugin generator source identity could not be calculated.');
        }

        return new self($class, $file, $digest);
    }

    public function load(): void
    {
        if (!is_file($this->file)) {
            throw new RuntimeException('Plugin generator source file is unavailable in the worker.');
        }
        $digest = hash_file('sha256', $this->file);
        if (!is_string($digest) || !hash_equals($this->sha256, $digest)) {
            throw new RuntimeException('Plugin generator source no longer matches its admitted identity.');
        }
        if (!class_exists($this->class, false)) {
            require_once $this->file;
        }
        if (!class_exists($this->class, false) || !is_a($this->class, ApiGenerator::class, true)) {
            throw new RuntimeException('Plugin generator class is unavailable in the worker.');
        }
        $reflection = new ReflectionClass($this->class);
        $loadedFile = $reflection->getFileName();
        if (!is_string($loadedFile) || !$this->sameFile($loadedFile, $this->file)) {
            throw new RuntimeException('Plugin generator class resolved from an unexpected source.');
        }
    }

    private function sameFile(string $left, string $right): bool
    {
        $leftPath = str_replace('\\', '/', $left);
        $rightPath = str_replace('\\', '/', $right);

        return PHP_OS_FAMILY === 'Windows'
            ? strcasecmp($leftPath, $rightPath) === 0
            : hash_equals($leftPath, $rightPath);
    }
}
