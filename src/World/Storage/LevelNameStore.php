<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Storage;

use Bedriox\Server\World\Storage\Exception\CorruptWorldDataException;
use Bedriox\Server\World\Storage\Exception\WorldDataWriteException;

/** Maintains Bedrock's levelname.txt as a recoverable UTF-8 mirror of level.dat. */
final readonly class LevelNameStore
{
    public const int MAX_BYTES = 64;

    public function __construct(private AtomicFileWriter $writer = new LocalAtomicFileWriter()) {}

    public function synchronize(string $path, string $authoritativeName): void
    {
        self::validate($authoritativeName);
        if (is_file($path)) {
            $current = @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
            if (is_string($current) && $current === $authoritativeName) {
                return;
            }
        }
        $this->writer->write($path, $authoritativeName);
    }

    public function load(string $path): string
    {
        $contents = @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if (!is_string($contents)) {
            throw new CorruptWorldDataException('Unable to read levelname.txt.');
        }
        if (!self::valid($contents)) {
            throw new CorruptWorldDataException('levelname.txt is not valid bounded UTF-8.');
        }

        return $contents;
    }

    private static function validate(string $name): void
    {
        if (!self::valid($name)) {
            throw new WorldDataWriteException('World display name is not valid bounded UTF-8.');
        }
    }

    private static function valid(string $name): bool
    {
        return $name !== '' && strlen($name) <= self::MAX_BYTES && preg_match('//u', $name) === 1
            && preg_match('/[\x00-\x1f\x7f]/', $name) !== 1;
    }
}
