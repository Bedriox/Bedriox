<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Persistence;

use Bedriox\Server\World\Provider\Exception\WorldProviderException;

/** A stale snapshot or ownership transfer that must be rebuilt from current durable state. */
final class EntityPersistenceConflictException extends WorldProviderException {}
