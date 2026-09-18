<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Storage\LevelDb;

use Throwable;

/** Binary-safe adapter for the Bedriox Runtime's php-leveldb extension. */
final class NativeLevelDbDatabase implements LevelDbDatabase
{
    private ?\LevelDB $database;

    private function __construct(\LevelDB $database)
    {
        $this->database = $database;
    }

    public static function open(string $path, bool $create): self
    {
        if (!extension_loaded('leveldb') || !class_exists('LevelDB') || !class_exists('LevelDBWriteBatch')) {
            throw new LevelDbIoException('The qualified Bedriox leveldb extension is unavailable.');
        }
        if (!defined('LEVELDB_ZLIB_RAW_COMPRESSION')) {
            throw new LevelDbIoException('The leveldb extension does not provide Bedrock raw-DEFLATE compression.');
        }

        try {
            $database = new \LevelDB($path, [
                'create_if_missing' => $create,
                'compression' => constant('LEVELDB_ZLIB_RAW_COMPRESSION'),
                'block_size' => 64 * 1024,
            ], [
                'verify_check_sum' => true,
                'fill_cache' => true,
            ]);
        } catch (Throwable $error) {
            throw new LevelDbIoException('Unable to open the world LevelDB database.', previous: $error);
        }
        return new self($database);
    }

    public function get(string $key): ?string
    {
        $database = $this->requireOpen();
        try {
            $result = $database->get($key, ['verify_check_sum' => true, 'fill_cache' => true]);
        } catch (Throwable $error) {
            throw new LevelDbIoException('Unable to read a world LevelDB record.', previous: $error);
        }
        if ($result === false) {
            return null;
        }

        return $result;
    }

    public function writeBatch(array $puts, array $deletes): void
    {
        $database = $this->requireOpen();
        try {
            $batch = new \LevelDBWriteBatch();
            foreach ($puts as $key => $value) {
                $batch->put($key, $value);
            }
            foreach ($deletes as $key) {
                $batch->delete($key);
            }
            $database->write($batch, ['sync' => true]);
        } catch (Throwable $error) {
            throw new LevelDbIoException('Unable to atomically write world LevelDB records.', previous: $error);
        }
    }

    public function close(): void
    {
        $database = $this->database;
        if ($database === null) {
            return;
        }
        $this->database = null;
        try {
            // The qualified extension exposes close() but currently marks it deprecated in favour of destruction.
            @$database->close();
        } catch (Throwable $error) {
            throw new LevelDbIoException('Unable to close the world LevelDB database.', previous: $error);
        } finally {
            gc_collect_cycles();
        }
    }

    private function requireOpen(): \LevelDB
    {
        return $this->database ?? throw new LevelDbIoException('The LevelDB database is closed.');
    }
}
