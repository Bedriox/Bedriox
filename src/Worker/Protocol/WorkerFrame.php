<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Protocol;

use InvalidArgumentException;

final readonly class WorkerFrame
{
    /** @param array<string, bool|int|string|null> $metadata */
    public function __construct(
        public WorkerFrameKind $kind,
        public string $epoch,
        public int $taskId = 0,
        public int $taskTypeId = 0,
        public int $schemaVersion = 0,
        public int $flags = 0,
        public int $deadlineNanoseconds = 0,
        public array $metadata = [],
        public string $payload = '',
    ) {
        if (strlen($epoch) !== 16 || $taskId < 0 || $taskId > 0xffffffff
            || $taskTypeId < 0 || $taskTypeId > 65_535
            || $schemaVersion < 0 || $schemaVersion > 65_535
            || $flags < 0 || $flags > 255 || $deadlineNanoseconds < 0) {
            throw new InvalidArgumentException('Invalid worker frame field.');
        }
        foreach ($metadata as $key => $value) {
            if (!preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $key)
                || (is_string($value) && strlen($value) > 4_096)) {
                throw new InvalidArgumentException('Worker frame metadata must contain bounded scalar fields.');
            }
        }
    }
}
