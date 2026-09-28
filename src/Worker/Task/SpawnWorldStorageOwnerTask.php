<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Task;

use Bedriox\Server\Worker\Internal\ProcessEnvironment;
use Bedriox\Server\Worker\WorkerTaskHandler;
use RuntimeException;

/** @internal Launches storage owners away from the authoritative tick; sockets remain parent-owned. */
final class SpawnWorldStorageOwnerTask implements WorkerTaskHandler
{
    /** @var array<int, resource> */
    private array $children = [];

    public function execute(string $payload): string
    {
        $this->reapExitedChildren();
        $request = SpawnWorldStorageOwnerRequest::decode($payload);
        $entryPoint = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'bedriox-io.php';
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $pipes = [];
        $process = @proc_open(
            ProcessEnvironment::phpCommand(
                $entryPoint,
                'world',
                $request->epochHex,
                $request->applicationVersion,
                $request->endpoint,
                $request->tokenHex,
            ),
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'a'], 2 => ['file', $null, 'a']],
            $pipes,
            dirname($entryPoint, 2),
            ProcessEnvironment::allowlisted(),
            ['bypass_shell' => true, 'blocking_pipes' => false],
        );
        if (!is_resource($process)) {
            throw new RuntimeException('World storage process could not be launched.');
        }
        $this->children[] = $process;

        return '';
    }

    private function reapExitedChildren(): void
    {
        foreach ($this->children as $index => $process) {
            $status = @proc_get_status($process);
            if ($status['running']) {
                continue;
            }
            @proc_close($process);
            unset($this->children[$index]);
        }
        $this->children = array_values($this->children);
    }
}
