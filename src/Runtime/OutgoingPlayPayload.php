<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\RakNet\Protocol\Reliability;
use InvalidArgumentException;

final readonly class OutgoingPlayPayload
{
    public Reliability $reliability;
    public int $orderingChannel;

    public function __construct(public string $payload)
    {
        if ($payload === '') {
            throw new InvalidArgumentException('Outgoing play payload cannot be empty.');
        }
        $this->reliability = Reliability::ReliableOrdered;
        $this->orderingChannel = 0;
    }
}
