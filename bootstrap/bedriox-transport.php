#!/usr/bin/env php
<?php

declare(strict_types=1);

use Bedriox\Server\Transport\Process\TransportProcessProgram;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(TransportProcessProgram::run($argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? ''));
