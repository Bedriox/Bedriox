<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

/** Reports whether the runner may yield after completing the current poll. */
interface RuntimeIdleAdvisor
{
    public function shouldIdleAfterPoll(): bool;
}
