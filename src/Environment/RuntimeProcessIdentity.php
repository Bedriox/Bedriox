<?php

declare(strict_types=1);

namespace Bedriox\Server\Environment;

final readonly class RuntimeProcessIdentity
{
    /**
     * @param list<string> $extensions
     */
    public function __construct(
        public string $binary,
        public string $version,
        public bool $threadSafe,
        public string $osFamily,
        public string $machine,
        public string $sapi,
        public int $integerSize,
        public ?string $loadedIni,
        public string|false $scannedIni,
        public array $extensions,
    ) {}

    public static function current(): self
    {
        return new self(
            PHP_BINARY,
            PHP_VERSION,
            (bool) PHP_ZTS,
            PHP_OS_FAMILY,
            php_uname('m'),
            PHP_SAPI,
            PHP_INT_SIZE,
            ($loadedIni = php_ini_loaded_file()) === false ? null : $loadedIni,
            php_ini_scanned_files(),
            get_loaded_extensions(),
        );
    }
}
