<?php

declare(strict_types=1);

namespace Bedriox\Api\Command;

/** A cooperative job whose poll method must return promptly and perform bounded work. */
interface CommandJob
{
    /** Returns true once the job has completed. */
    public function poll(): bool;

    /** Releases external resources when the job or its owning plugin is stopped. */
    public function cancel(): void;
}
