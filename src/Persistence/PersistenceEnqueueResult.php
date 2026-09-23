<?php

declare(strict_types=1);

namespace Bedriox\Server\Persistence;

final readonly class PersistenceEnqueueResult
{
    public function __construct(
        public PersistenceSubmission $status,
        public ?PersistenceWriteRequest $request,
    ) {}
}
