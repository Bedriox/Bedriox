<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

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
        public string $outgoing = '',
    ) {}
}
