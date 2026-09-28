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

namespace Bedriox\Server\Observability;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final readonly class RotatingFileLog
{
    public function __construct(
        private string $path,
        private int $maximumBytes,
        private int $history,
    ) {
        if ($maximumBytes < 1 || $history < 0) {
            throw new RuntimeException('Log rotation limits are invalid.');
        }
    }

    public function write(string $line): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the log directory.');
        }
        clearstatcache(true, $this->path);
        $size = is_file($this->path) ? @filesize($this->path) : 0;
        if (is_int($size) && $size > 0 && $size + strlen($line) + strlen(PHP_EOL) > $this->maximumBytes) {
            $this->rotate();
        }
        $written = @file_put_contents($this->path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            throw new RuntimeException('Unable to write the server log.');
        }
    }

    private function rotate(): void
    {
        $archive = dirname($this->path) . DIRECTORY_SEPARATOR . 'archive';
        if (!is_dir($archive) && !@mkdir($archive, 0o775, true) && !is_dir($archive)) {
            throw new RuntimeException('Unable to create the log archive directory.');
        }
        $stamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d_H-i-s_u_UTC');
        $target = $archive . DIRECTORY_SEPARATOR . 'server-' . $stamp . '.log';
        if (!@rename($this->path, $target)) {
            throw new RuntimeException('Unable to rotate the server log.');
        }
        $archives = glob($archive . DIRECTORY_SEPARATOR . 'server-*.log') ?: [];
        rsort($archives, SORT_STRING);
        foreach (array_slice($archives, $this->history) as $expired) {
            @unlink($expired);
        }
    }
}
