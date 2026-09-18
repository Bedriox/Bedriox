<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Server\World\Provider\WorldData;
use Bedriox\Server\World\World;

/** The single authoritative runtime world together with its effective initialization metadata. */
final readonly class OpenedWorld
{
    public function __construct(
        public World $world,
        public WorldData $data,
    ) {}
}
