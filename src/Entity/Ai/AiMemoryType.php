<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Ai;

use InvalidArgumentException;

final readonly class AiMemoryType
{
    public function __construct(
        public int $slot,
        public string $identifier,
        public bool $persistent = false,
    ) {
        if ($slot < 0 || $slot >= AiMemoryStore::MAXIMUM_MEMORIES) {
            throw new InvalidArgumentException('AI memory slot is outside its supported range.');
        }
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1
            || strlen($identifier) > 128) {
            throw new InvalidArgumentException('AI memory identifier must be canonical, namespaced, and bounded.');
        }
    }
}
