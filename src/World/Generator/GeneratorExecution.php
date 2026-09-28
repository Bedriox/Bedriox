<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Generator;

enum GeneratorExecution: string
{
    case WORKER = 'worker';
    case MAIN_THREAD = 'main-thread';
}
