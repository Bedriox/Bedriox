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

namespace Bedriox\Server\Plugin\Command;

use Bedriox\Server\Observability\ServerLogger;
use RuntimeException;

final class WindowsConsoleInput implements ConsoleInput
{
    private const WORKER = <<<'PHP'
        $socket = stream_socket_client($argv[1], $errorCode, $errorMessage, 5, STREAM_CLIENT_CONNECT);
        if (!is_resource($socket)) {
            exit(2);
        }
        $token = hex2bin($argv[2]);
        if (!is_string($token)) {
            exit(3);
        }
        $write = static function (string $bytes) use ($socket): void {
            while ($bytes !== '') {
                $written = fwrite($socket, $bytes);
                if ($written === false || $written === 0) {
                    exit(4);
                }
                $bytes = substr($bytes, $written);
            }
            fflush($socket);
        };
        $write($token);
        while (($chunk = fgets(STDIN, 8192)) !== false) {
            $write($chunk);
        }
        PHP;

    /** @var resource|null */
    private $process;
    /** @var resource|null */
    private $listener;
    /** @var resource|null */
    private $connection = null;
    private ?StreamConsoleInput $lines = null;
    private string $receivedToken = '';
    private bool $failureReported = false;

    /**
     * @param resource $process
     * @param resource $listener
     */
    private function __construct(
        $process,
        $listener,
        private readonly string $expectedToken,
        private readonly ServerLogger $logger,
        private readonly int $maximumLineBytes,
        private readonly int $maximumLinesPerPoll,
    ) {
        $this->process = $process;
        $this->listener = $listener;
    }

    /** @param resource $input */
    public static function start(
        $input,
        ServerLogger $logger,
        int $maximumLineBytes = 1024,
        int $maximumLinesPerPoll = 64,
    ): self {
        if (!is_resource($input) || $maximumLineBytes < 1 || $maximumLineBytes > 65536
            || $maximumLinesPerPoll < 1 || $maximumLinesPerPoll > 1024) {
            throw new \InvalidArgumentException('Invalid Windows console input configuration.');
        }

        $listener = @stream_socket_server(
            'tcp://127.0.0.1:0',
            $errorCode,
            $errorMessage,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        if (!is_resource($listener)) {
            throw new RuntimeException('The Windows console input channel could not be created.');
        }
        @stream_set_blocking($listener, false);
        $address = @stream_socket_get_name($listener, false);
        if (!is_string($address) || $address === '') {
            @fclose($listener);
            throw new RuntimeException('The Windows console input channel address could not be resolved.');
        }

        $token = random_bytes(32);
        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = @proc_open(
            [PHP_BINARY, '-n', '-d', 'display_errors=0', '-r', self::WORKER, '--', 'tcp://' . $address, bin2hex($token)],
            [0 => $input, 1 => ['file', $nullDevice, 'a'], 2 => ['file', $nullDevice, 'a']],
            $unusedPipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            @fclose($listener);
            throw new RuntimeException('The Windows console input helper could not be started.');
        }

        return new self($process, $listener, $token, $logger, $maximumLineBytes, $maximumLinesPerPoll);
    }

    public function readAvailable(): array
    {
        if (!is_resource($this->process)) {
            return [];
        }

        if (!is_resource($this->connection) && is_resource($this->listener)) {
            $connection = @stream_socket_accept($this->listener, 0);
            if (is_resource($connection)) {
                @stream_set_blocking($connection, false);
                $this->connection = $connection;
                @fclose($this->listener);
                $this->listener = null;
            }
        }
        if (is_resource($this->connection) && $this->lines === null) {
            $remaining = strlen($this->expectedToken) - strlen($this->receivedToken);
            if ($remaining > 0) {
                $chunk = @fread($this->connection, $remaining);
                if (is_string($chunk)) {
                    $this->receivedToken .= $chunk;
                }
            }
            if (strlen($this->receivedToken) === strlen($this->expectedToken)) {
                if (!hash_equals($this->expectedToken, $this->receivedToken)) {
                    $this->reportFailure();
                    $this->close();

                    return [];
                }
                $this->receivedToken = '';
                $this->lines = new StreamConsoleInput(
                    $this->connection,
                    $this->logger,
                    $this->maximumLineBytes,
                    $this->maximumLinesPerPoll,
                );
            }
        }

        $lines = $this->lines?->readAvailable() ?? [];
        $status = @proc_get_status($this->process);
        if (!$status['running']) {
            $this->reportFailure();
            $this->close();
        }

        return $lines;
    }

    public function close(): void
    {
        $this->lines?->close();
        $this->lines = null;
        if (is_resource($this->process)) {
            $status = @proc_get_status($this->process);
            if ($status['running']) {
                @proc_terminate($this->process);
            }
        }
        foreach (['connection', 'listener'] as $property) {
            if (is_resource($this->{$property})) {
                @fclose($this->{$property});
            }
            $this->{$property} = null;
        }
        if (is_resource($this->process)) {
            @proc_close($this->process);
        }
        $this->process = null;
        $this->receivedToken = '';
    }

    private function reportFailure(): void
    {
        if ($this->failureReported) {
            return;
        }
        $this->failureReported = true;
        $this->logger->warning('Windows console input stopped; console commands are disabled', 'Command');
    }
}
