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

use InvalidArgumentException;
use Throwable;

/** Owns ordered file writes; intended to run inside the dedicated log I/O service. */
final readonly class OrderedLogWriterService
{
    public function __construct(
        private BoundedLogQueue $queue,
        private RotatingFileLog $file,
    ) {}

    public function enqueue(int $sequence, LogLevel $level, string $formattedRedactedLine): LogQueueSubmission
    {
        return $this->queue->enqueue($sequence, $level, $formattedRedactedLine);
    }

    /** Drains at most the supplied number and stops at the first failed ordered write. */
    public function poll(int $maximumWrites): int
    {
        if ($maximumWrites < 1) {
            throw new InvalidArgumentException('Log write budget must be positive.');
        }

        $written = 0;
        while ($written < $maximumWrites) {
            $line = $this->queue->head();
            if (!$line instanceof QueuedLogLine) {
                break;
            }
            try {
                $this->file->write($line->line);
            } catch (Throwable) {
                $this->queue->recordWriteFailure();
                break;
            }
            $this->queue->acknowledge($line->sequence);
            ++$written;
        }

        return $written;
    }

    public function snapshot(): LogQueueSnapshot
    {
        return $this->queue->snapshot();
    }
}
