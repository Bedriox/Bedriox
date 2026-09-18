<?php

declare(strict_types=1);

namespace Bedriox\Server\Player\Persistence;

use Bedriox\Server\Player\Persistence\Exception\CorruptPlayerDataException;
use Bedriox\Server\Player\Persistence\Exception\PlayerDataWriteException;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\World\Storage\AtomicFileWriter;
use Bedriox\Server\World\Storage\LocalAtomicFileWriter;
use Throwable;

/** One bounded atomic profile file per authenticated UUID. */
final readonly class FilePlayerDataStore implements PlayerDataStore
{
    public function __construct(
        private string $directory,
        private PlayerDataCodec $codec = new PlayerDataCodec(),
        private AtomicFileWriter $writer = new LocalAtomicFileWriter(),
    ) {
        if (is_link($this->directory)) {
            throw new PlayerDataWriteException('Player data directory must not be a symbolic link.');
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0777, false) && !is_dir($this->directory)) {
            throw new PlayerDataWriteException('Unable to create the player data directory.');
        }
    }

    public function exists(string $uuid): bool
    {
        $path = $this->path($uuid);

        return is_file($path) && !is_link($path);
    }

    public function load(string $uuid): ?PlayerBootstrap
    {
        $path = $this->path($uuid);
        if (!file_exists($path)) {
            return null;
        }
        if (is_link($path) || !is_file($path)) {
            throw new CorruptPlayerDataException('Player profile path is not a regular file.');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new CorruptPlayerDataException('Unable to read the player profile.');
        }
        try {
            $stat = @fstat($handle);
            if ($stat === false || $stat['size'] < 1 || $stat['size'] > PlayerDataCodec::MAX_BYTES) {
                throw new CorruptPlayerDataException('Player profile is empty, unreadable, or exceeds the size limit.');
            }
            $contents = @stream_get_contents($handle, PlayerDataCodec::MAX_BYTES + 1);
            if (!is_string($contents) || strlen($contents) > PlayerDataCodec::MAX_BYTES || !feof($handle)) {
                throw new CorruptPlayerDataException('Player profile is unreadable or exceeds the size limit.');
            }
        } finally {
            fclose($handle);
        }
        $player = $this->codec->decode($contents);
        if ($player->identity->uuid !== $uuid) {
            throw new CorruptPlayerDataException('Player profile identity does not match its filename.');
        }

        return $player;
    }

    public function save(PlayerBootstrap $player): void
    {
        $path = $this->path($player->identity->uuid);
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new PlayerDataWriteException('Player profile path is not a regular file.');
        }
        $contents = $this->codec->encode($player);
        try {
            $this->writer->write($path, $contents);
        } catch (Throwable $error) {
            throw new PlayerDataWriteException('Unable to save the player profile atomically.', previous: $error);
        }
    }

    private function path(string $uuid): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $uuid) !== 1) {
            throw new CorruptPlayerDataException('Player profile key must be a canonical lowercase UUID.');
        }

        return $this->directory . DIRECTORY_SEPARATOR . $uuid . '.dat';
    }
}
