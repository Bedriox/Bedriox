<?php

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

        $bundled = dirname($phpBinary) . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf';

        return is_file($bundled) && is_readable($bundled) ? $bundled : null;
    }

    private function __construct() {}
}
