<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Internal;

use Bedriox\Server\Worker\Protocol\WorkerFrame;
use Bedriox\Server\Worker\Protocol\WorkerFrameDecoder;

final class BrokerWorker
{
    /**
     * @param resource $process
     * @param resource $input
     * @param resource $output
     */
    public function __construct(
        public $process,
        public $input,
        public $output,
        public WorkerFrameDecoder $decoder,
        public ?WorkerFrame $task = null,
        public int $startedAtNanoseconds = 0,
        public bool $discardResult = false,
        public int $memoryBytes = 0,
    ) {}
}
