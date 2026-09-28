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
use LogicException;

/** Bounded FIFO with entry and byte capacity reserved for ERROR and CRITICAL records. */
final class BoundedLogQueue
{
    /** @var list<QueuedLogLine> */
    private array $lines = [];
    private int $bytes = 0;
    private int $routineEntries = 0;
    private int $routineBytes = 0;
    private int $lastSequence = 0;
    private int $droppedRoutine = 0;
    private int $droppedHighSeverity = 0;
    private int $writeFailures = 0;

    public function __construct(
        private readonly int $maximumEntries,
        private readonly int $maximumBytes,
        private readonly int $maximumLineBytes,
        private readonly int $reservedHighSeverityEntries,
        private readonly int $reservedHighSeverityBytes,
    ) {
        if ($maximumEntries < 1 || $maximumBytes < 1 || $maximumLineBytes < 1) {
            throw new InvalidArgumentException('Log queue limits must be positive.');
        }
        if ($reservedHighSeverityEntries < 0 || $reservedHighSeverityEntries >= $maximumEntries
            || $reservedHighSeverityBytes < 0 || $reservedHighSeverityBytes >= $maximumBytes) {
            throw new InvalidArgumentException('Log queue reserve must leave capacity for routine records.');
        }
        if ($maximumLineBytes > $maximumBytes) {
            throw new InvalidArgumentException('A log line cannot exceed the queue byte limit.');
        }
    }

    public function enqueue(int $sequence, LogLevel $level, string $line): LogQueueSubmission
    {
        if ($sequence <= $this->lastSequence) {
            throw new LogicException('Log records must be submitted in strictly increasing sequence order.');
        }
        $this->lastSequence = $sequence;
        $entry = new QueuedLogLine($sequence, $level, $line);
        $highSeverity = $level->value >= LogLevel::ERROR->value;
        $fitsTotal = count($this->lines) < $this->maximumEntries
            && $this->bytes + $entry->bytes() <= $this->maximumBytes
            && $entry->bytes() <= $this->maximumLineBytes;
        $fitsRoutine = $this->routineEntries < $this->maximumEntries - $this->reservedHighSeverityEntries
            && $this->routineBytes + $entry->bytes() <= $this->maximumBytes - $this->reservedHighSeverityBytes;
        if (!$fitsTotal || (!$highSeverity && !$fitsRoutine)) {
            if ($highSeverity) {
                ++$this->droppedHighSeverity;
            } else {
                ++$this->droppedRoutine;
            }

            return LogQueueSubmission::DROPPED;
        }

        $this->lines[] = $entry;
        $this->bytes += $entry->bytes();
        if (!$highSeverity) {
            ++$this->routineEntries;
            $this->routineBytes += $entry->bytes();
        }

        return LogQueueSubmission::ACCEPTED;
    }

    public function head(): ?QueuedLogLine
    {
        return $this->lines[0] ?? null;
    }

    public function at(int $offset): ?QueuedLogLine
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('Log queue offset cannot be negative.');
        }

        return $this->lines[$offset] ?? null;
    }

    public function acknowledge(int $sequence): void
    {
        $head = $this->head();
        if (!$head instanceof QueuedLogLine || $head->sequence !== $sequence) {
            throw new LogicException('Only the exact oldest log record may be acknowledged.');
        }
        array_shift($this->lines);
        $this->bytes -= $head->bytes();
        if ($head->level->value < LogLevel::ERROR->value) {
            --$this->routineEntries;
            $this->routineBytes -= $head->bytes();
        }
    }

    public function recordWriteFailure(): void
    {
        ++$this->writeFailures;
    }

    public function snapshot(): LogQueueSnapshot
    {
        return new LogQueueSnapshot(
            count($this->lines),
            $this->bytes,
            $this->droppedRoutine,
            $this->droppedHighSeverity,
            $this->writeFailures,
            $this->head()?->sequence,
        );
    }
}
