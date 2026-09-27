<?php

declare(strict_types=1);

namespace Bedriox\Server\Transport\Process;

use Bedriox\RakNet\Protocol\Reliability;

final readonly class TransportProcessFrame
{
    /** @param array<string, int|string|bool|null|array<array-key, mixed>> $metadata */
    public function __construct(
        public TransportProcessFrameKind $kind,
        public int $sessionId = 0,
        public string $payload = '',
        public ?Reliability $reliability = null,
        public ?int $orderingChannel = null,
        public array $metadata = [],
    ) {}
}
