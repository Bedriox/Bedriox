<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Persistence;

use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Provider\Exception\WorldProviderException;
use Throwable;

/** A malformed entity snapshot isolated from terrain and other chunk records. */
final class CorruptEntityPersistenceException extends WorldProviderException
{
    public function __construct(
        public readonly ChunkPosition $chunk,
        string $message = 'The chunk entity snapshot is corrupt.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
