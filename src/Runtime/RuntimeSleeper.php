<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

interface RuntimeSleeper
{
    public function idle(): void;
}
