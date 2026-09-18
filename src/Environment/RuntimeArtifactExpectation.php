<?php

declare(strict_types=1);

namespace Bedriox\Server\Environment;

use JsonException;
use RuntimeException;

final readonly class RuntimeArtifactExpectation
{
    /**
     * @param list<string> $extensions
     */
    private function __construct(
        public string $commit,
        public int $manifestSchema,
        public string $target,
        public string $file,
        public string $format,
        public int $bytes,
        public string $sha256,
        public string $manifestSha256,
        public string $phpVersion,
        public bool $threadSafe,
        public array $extensions,
    ) {}

    public static function fromLockFile(string $lockFile, string $target): self
    {
        if (!is_file($lockFile) || is_link($lockFile)) {
            throw new RuntimeException('The Bedriox lock file is not a regular local file.');
        }
        $contents = file_get_contents($lockFile);
        if ($contents === false || strlen($contents) > 1_048_576) {
            throw new RuntimeException('The Bedriox lock file could not be read within its size limit.');
        }
        try {
            $lock = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The Bedriox lock file is not valid JSON.', previous: $exception);
        }
        if (!is_array($lock) || array_is_list($lock)) {
            throw new RuntimeException('The Bedriox lock root must be an object.');
        }
        if (($lock['schema'] ?? null) !== 2) {
            throw new RuntimeException('The Bedriox lock schema is unsupported.');
        }
        $runtime = self::object($lock['runtime'] ?? null, 'runtime');
        self::exactKeys(
            $runtime,
            ['commit', 'manifestSchema', 'phpVersion', 'extensions', 'artifacts'],
            'runtime',
        );
        $commit = self::hex($runtime['commit'], 40, 'runtime.commit');
        $manifestSchema = self::integer($runtime['manifestSchema'], 1, 100, 'runtime.manifestSchema');
        $phpVersion = self::version($runtime['phpVersion']);
        $extensionSets = self::object($runtime['extensions'] ?? null, 'runtime.extensions');
        self::exactKeys($extensionSets, ['all', 'unix'], 'runtime.extensions');
        $extensions = self::extensionList($extensionSets['all'], 'runtime.extensions.all');
        if (!str_starts_with($target, 'windows-')) {
            $extensions = array_merge(
                $extensions,
                self::extensionList($extensionSets['unix'], 'runtime.extensions.unix'),
            );
        }
        $normalizedExtensions = [];
        foreach ($extensions as $extension) {
            $normalized = strtolower($extension);
            if (isset($normalizedExtensions[$normalized])) {
                throw new RuntimeException('The expected runtime extension sets contain a duplicate name.');
            }
            $normalizedExtensions[$normalized] = $extension;
        }

        $artifacts = self::object($runtime['artifacts'] ?? null, 'runtime.artifacts');
        $artifact = self::object($artifacts[$target] ?? null, 'runtime.artifacts.' . $target);
        self::exactKeys(
            $artifact,
            ['filename', 'size', 'sha256', 'manifestSha256', 'threadSafe'],
            'runtime.artifacts.' . $target,
        );
        $file = self::string($artifact['filename'], 'artifact filename');
        if ($file !== basename($file) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,159}$/D', $file) !== 1) {
            throw new RuntimeException('The expected runtime artifact file name is unsafe.');
        }
        $format = str_ends_with($file, '.tar.gz') ? 'tar.gz' : (str_ends_with($file, '.zip') ? 'zip' : null);
        if ($format === null) {
            throw new RuntimeException('The expected runtime artifact filename has an unsupported archive suffix.');
        }

        return new self(
            $commit,
            $manifestSchema,
            $target,
            $file,
            $format,
            self::integer($artifact['size'], 1, 536_870_912, 'artifact size'),
            self::hex($artifact['sha256'], 64, 'artifact SHA-256'),
            self::hex($artifact['manifestSha256'], 64, 'runtime manifest SHA-256'),
            $phpVersion,
            self::boolean($artifact['threadSafe'], 'artifact threadSafe'),
            array_values($normalizedExtensions),
        );
    }

    /** @return list<string> */
    private static function extensionList(mixed $value, string $label): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException($label . ' must be a list.');
        }
        $extensions = [];
        foreach ($value as $extension) {
            $extension = self::string($extension, 'runtime extension');
            if (preg_match('/^[a-z][a-z0-9_ -]{0,63}$/iD', $extension) !== 1) {
                throw new RuntimeException($label . ' contains an invalid extension name.');
            }
            $extensions[] = $extension;
        }

        return $extensions;
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value, string $label): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException($label . ' must be an object.');
        }

        $object = [];
        foreach ($value as $key => $entry) {
            if (!is_string($key)) {
                throw new RuntimeException($label . ' must have string field names.');
            }
            $object[$key] = $entry;
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $object
     * @param list<string>         $keys
     */
    private static function exactKeys(array $object, array $keys, string $label): void
    {
        $actual = array_keys($object);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($actual !== $keys) {
            throw new RuntimeException($label . ' has an unexpected field set.');
        }
    }

    private static function string(mixed $value, string $label): string
    {
        if (!is_string($value) || $value === '') {
            throw new RuntimeException($label . ' must be a non-empty string.');
        }

        return $value;
    }

    private static function boolean(mixed $value, string $label): bool
    {
        if (!is_bool($value)) {
            throw new RuntimeException($label . ' must be a boolean.');
        }

        return $value;
    }

    private static function integer(mixed $value, int $minimum, int $maximum, string $label): int
    {
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException($label . ' is outside its supported range.');
        }

        return $value;
    }

    private static function hex(mixed $value, int $length, string $label): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{' . $length . '}$/D', $value) !== 1) {
            throw new RuntimeException($label . ' must be lowercase hexadecimal.');
        }

        return $value;
    }

    private static function version(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^8\.4\.\d{1,3}$/D', $value) !== 1) {
            throw new RuntimeException('The expected runtime PHP version is invalid.');
        }

        return $value;
    }
}
