<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Protocol\Packet\Packet;

/** @internal Immutable initialization packet whose clear Bedrock batch is reusable for this server boot. */
final readonly class ReusablePlayPacket
{
    public function __construct(
        public string $key,
        public Packet $packet,
    ) {
        if ($key === '' || strlen($key) > 128) {
            throw new \InvalidArgumentException('Reusable play packet key is invalid.');
        }
    }
}
