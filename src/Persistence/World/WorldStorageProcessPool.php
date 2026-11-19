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

namespace Bedriox\Server\Persistence\World;

use Bedriox\Server\Worker\Internal\ProcessEnvironment;
use Bedriox\Server\World\Provider\Exception\WorldStorageException;

/** Boot-scoped owner of one catalog-sharing world-storage service. */
final class WorldStorageProcessPool
{
    /** @var resource|null */
    private $process = null;
    /** @var resource|null */
    private $control = null;
    /** @var resource|null */
    private $stderr = null;
    private string $endpoint = '';
    private string $clientToken = '';
    private bool $closed = false;

    public function __construct(
        private readonly string $applicationVersion,
        private readonly string $entryPoint,
        private readonly int $startupTimeoutMilliseconds = 30_000,
    ) {
        if ($applicationVersion === '' || strlen($applicationVersion) > 128
            || !is_file($entryPoint)
            || $startupTimeoutMilliseconds < 1_000 || $startupTimeoutMilliseconds > 300_000) {
            throw new \InvalidArgumentException('World storage pool configuration is invalid.');
        }
        $this->start();
    }

    /** @return resource */
    public function connect()
    {
        if ($this->closed || !$this->isRunning()) {
            throw new WorldStorageException('World storage pool is unavailable.');
        }
        $connection = @stream_socket_client(
            $this->endpoint,
            $errorCode,
            $errorMessage,
            max(1.0, $this->startupTimeoutMilliseconds / 1_000),
            STREAM_CLIENT_CONNECT,
        );
        if (!is_resource($connection)) {
            throw new WorldStorageException('World storage pool connection failed.');
        }
        stream_set_blocking($connection, true);
        self::write($connection, $this->clientToken);
        stream_set_blocking($connection, false);

        return $connection;
    }

    public function isRunning(): bool
    {
        if (!is_resource($this->process) || !is_resource($this->control)) {
            return false;
        }
        $status = @proc_get_status($this->process);

        return $status['running'] && !feof($this->control);
    }

    public function failureDetail(): string
    {
        if (!is_resource($this->stderr)) {
            return '';
        }
        $detail = @stream_get_contents($this->stderr);

        return is_string($detail) ? substr(trim($detail), 0, 1_024) : '';
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if (is_resource($this->control)) {
            @fclose($this->control);
        }
        $this->control = null;
        if (is_resource($this->stderr)) {
            @fclose($this->stderr);
        }
        $this->stderr = null;
        if (is_resource($this->process)) {
            $deadline = hrtime(true) + 2_000_000_000;
            do {
                $status = @proc_get_status($this->process);
                if (!$status['running']) {
                    break;
                }
                usleep(10_000);
            } while (hrtime(true) < $deadline);
            $status = @proc_get_status($this->process);
            if ($status['running']) {
                @proc_terminate($this->process);
            }
            @proc_close($this->process);
        }
        $this->process = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function start(): void
    {
        $listener = @stream_socket_server(
            'tcp://127.0.0.1:0',
            $errorCode,
            $errorMessage,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        if (!is_resource($listener)) {
            throw new WorldStorageException('World storage pool control listener could not be created.');
        }
        $name = stream_socket_get_name($listener, false);
        if (!is_string($name) || $name === '') {
            @fclose($listener);
            throw new WorldStorageException('World storage pool control endpoint is unavailable.');
        }
        $controlToken = random_bytes(32);
        $pipes = [];
        $process = @proc_open(
            ProcessEnvironment::phpCommand(
                $this->entryPoint,
                'world-pool',
                $this->applicationVersion,
                'tcp://' . $name,
                bin2hex($controlToken),
            ),
            [
                0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
                1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'a'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname($this->entryPoint, 2),
            ProcessEnvironment::allowlisted(),
            ['bypass_shell' => true, 'blocking_pipes' => false],
        );
        if (!is_resource($process)) {
            @fclose($listener);
            throw new WorldStorageException('World storage pool process could not be started.');
        }
        stream_set_blocking($listener, false);
        $deadline = hrtime(true) + $this->startupTimeoutMilliseconds * 1_000_000;
        $control = null;
        while (hrtime(true) < $deadline) {
            $control = @stream_socket_accept($listener, 0);
            if (is_resource($control)) {
                break;
            }
            usleep(1_000);
        }
        @fclose($listener);
        if (!is_resource($control)) {
            @proc_terminate($process);
            @proc_close($process);
            throw new WorldStorageException('World storage pool did not connect.');
        }
        stream_set_blocking($control, true);
        if (!hash_equals($controlToken, self::readExact($control, 32))) {
            @fclose($control);
            @proc_terminate($process);
            @proc_close($process);
            throw new WorldStorageException('World storage pool authentication failed.');
        }
        $line = @stream_get_line($control, 1_024, "\n");
        $ready = is_string($line) ? json_decode($line, true, 4) : null;
        if (!is_array($ready)
            || !is_string($ready['endpoint'] ?? null)
            || preg_match('/^tcp:\/\/127\.0\.0\.1:\d{1,5}$/D', $ready['endpoint']) !== 1
            || !is_string($ready['token'] ?? null)
            || preg_match('/^[0-9a-f]{64}$/D', $ready['token']) !== 1) {
            @fclose($control);
            @proc_terminate($process);
            @proc_close($process);
            throw new WorldStorageException('World storage pool returned an invalid endpoint.');
        }
        stream_set_blocking($control, false);
        $this->process = $process;
        $this->control = $control;
        $stderr = $pipes[2] ?? null;
        if (is_resource($stderr)) {
            stream_set_blocking($stderr, false);
            $this->stderr = $stderr;
        }
        $this->endpoint = $ready['endpoint'];
        $token = hex2bin($ready['token']);
        if (!is_string($token)) {
            throw new WorldStorageException('World storage pool token is invalid.');
        }
        $this->clientToken = $token;
    }

    /** @param resource $stream */
    private static function write($stream, string $bytes): void
    {
        while ($bytes !== '') {
            $written = @fwrite($stream, $bytes);
            if (!is_int($written) || $written < 1) {
                throw new WorldStorageException('World storage pool control write failed.');
            }
            $bytes = substr($bytes, $written);
        }
    }

    /** @param resource $stream */
    private static function readExact($stream, int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length && !feof($stream)) {
            $remaining = $length - strlen($bytes);
            if ($remaining < 1) {
                break;
            }
            $chunk = @fread($stream, $remaining);
            if (!is_string($chunk) || $chunk === '') {
                usleep(1_000);
                continue;
            }
            $bytes .= $chunk;
        }

        return $bytes;
    }
}
