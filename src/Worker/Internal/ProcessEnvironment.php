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

final class ProcessEnvironment
{
    /** @return array<string, string> */
    public static function allowlisted(): array
    {
        $environment = [];
        foreach ([
            'SystemRoot',
            'WINDIR',
            'COMSPEC',
            'PATHEXT',
            'TEMP',
            'TMP',
            'TMPDIR',
            'PATH',
            'LD_LIBRARY_PATH',
            'DYLD_LIBRARY_PATH',
            'BEDRIOX_RUNTIME_ROOT',
            'BEDRIOX_RUNTIME_CACHE',
            'OPENSSL_CONF',
            'SSL_CERT_FILE',
            'CURL_CA_BUNDLE',
        ] as $name) {
            $value = getenv($name);
            if (is_string($value)) {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }

    /** @return list<string> */
    public static function phpCommand(string $entryPoint, string ...$arguments): array
    {
        $command = [PHP_BINARY];
        $configuration = php_ini_loaded_file();
        if (is_string($configuration)) {
            $command[] = '-c';
            $command[] = $configuration;
        } else {
            $command[] = '-n';
        }
        $command[] = $entryPoint;

        return array_values([...$command, ...$arguments]);
    }
}
