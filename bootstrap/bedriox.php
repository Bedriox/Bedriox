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

use Bedriox\Server\Bedriox;
use Bedriox\Server\Environment\RuntimeEnvironmentValidator;

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/src/Environment/RuntimeEnvironmentException.php';
require_once $projectRoot . '/src/Environment/RuntimeProcessIdentity.php';
require_once $projectRoot . '/src/Environment/RuntimeManifest.php';
require_once $projectRoot . '/src/Environment/RuntimeArtifactExpectation.php';
require_once $projectRoot . '/src/Environment/RuntimeEnvironmentValidator.php';

try {
    RuntimeEnvironmentValidator::validate($projectRoot, getenv('BEDRIOX_RUNTIME_ROOT'));
    require $projectRoot . '/vendor/autoload.php';

    $application = new Bedriox();
    exit($application->run(
        array_values(array_slice($argv, 1)),
        static function (string $message): void {
            fwrite(STDOUT, $message);
        },
        static function (string $message): void {
            fwrite(STDERR, $message);
        },
    ));
} catch (\Throwable) {
    fwrite(STDERR, 'Bedriox runtime validation failed. Reinstall the qualified packaged runtime.' . PHP_EOL);
    exit(1);
}
