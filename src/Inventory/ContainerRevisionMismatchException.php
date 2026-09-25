<?php

declare(strict_types=1);

namespace Bedriox\Server\Inventory;

use RuntimeException;

final class ContainerRevisionMismatchException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Container inventory revision changed before the mutation could commit.');
    }
}
