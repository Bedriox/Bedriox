<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Ai;

final readonly class AiTickContext
{
    public function __construct(
        public int $tick,
        public AiWorldView $world,
    ) {}
}
