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

namespace Bedriox\Server\Runtime;

use InvalidArgumentException;

/** Resolves the optional OpenSSL configuration required by Windows PHP builds. */
final class OpenSslConfiguration
{
    public static function discover(string $phpBinary, ?string $configuredPath = null): ?string
    {
        if ($configuredPath !== null && $configuredPath !== '') {
            if (!is_file($configuredPath) || !is_readable($configuredPath)) {
                throw new InvalidArgumentException('OPENSSL_CONF does not identify a readable file.');
            }

            return $configuredPath;
        }

        $runtimeRoot = dirname($phpBinary);
        $candidates = [
            $runtimeRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'openssl.cnf',
            $runtimeRoot . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
        ];
        foreach ($candidates as $bundled) {
            if (is_file($bundled) && is_readable($bundled)) {
                return $bundled;
            }
        }

        return null;
    }

    private function __construct() {}
}
