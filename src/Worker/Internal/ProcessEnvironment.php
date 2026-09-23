<?php

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
