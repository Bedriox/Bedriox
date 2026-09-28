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

namespace Bedriox\Server\Tests\Plugin;

use Bedriox\Api\Scheduler\AsyncTaskValue;
use Bedriox\Server\Plugin\PluginPackageLoader;
use Bedriox\Server\Plugin\Scheduler\AsyncTaskOutcome;
use Bedriox\Server\Plugin\Scheduler\AsyncTaskRequest;
use Bedriox\Server\Plugin\Scheduler\Worker\ManagedPluginAsyncTaskExecutor;
use Bedriox\Server\Worker\CoreWorkerTaskCatalog;
use Bedriox\Server\Worker\ManagedWorkerPool;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ManagedPluginAsyncTaskExecutorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-plugin-worker-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0o775, true));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->directory);
    }

    public function testExecutesAdmittedPharAndRejectsValidReplacementWithoutRunningIt(): void
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . 'worker.phar';
        $marker = $this->directory . DIRECTORY_SEPARATOR . 'replacement-executed.txt';
        $this->buildPhar($path, false, $marker);
        $package = (new PluginPackageLoader())->discover($this->directory)[0];
        self::assertNotNull($package->archiveIdentity);

        $pool = ManagedWorkerPool::start('plugin-worker-integration', 1);
        $executor = new ManagedPluginAsyncTaskExecutor($pool, CoreWorkerTaskCatalog::PLUGIN_ASYNC_TASK);
        try {
            $executor->submit($this->request(1, $package->archiveIdentity, ['value' => 42]));
            $success = $this->await($executor);
            self::assertNull($success->failure);
            self::assertSame(['value' => 42], $success->result?->value());

            self::assertTrue(unlink($path));
            $this->buildPhar($path, true, $marker);
            clearstatcache(true, $path);

            $executor->submit($this->request(2, $package->archiveIdentity, 'must-not-run'));
            $rejected = $this->await($executor);
            self::assertNull($rejected->result);
            self::assertNotNull($rejected->failure);
            self::assertSame('task_exception', $rejected->failure->type);
            self::assertSame(\RuntimeException::class, $rejected->failure->message);
            self::assertFileDoesNotExist($marker);
        } finally {
            $executor->shutdown();
        }
    }

    private function request(int $taskId, \Bedriox\Server\Plugin\PluginArchiveIdentity $identity, mixed $input): AsyncTaskRequest
    {
        return new AsyncTaskRequest(
            $taskId,
            'AsyncFixture',
            '1.0.0',
            1,
            $identity,
            'Fixture\\AsyncWorker\\EchoTask',
            new AsyncTaskValue($input),
            hrtime(true) + 10_000_000_000,
        );
    }

    private function await(ManagedPluginAsyncTaskExecutor $executor): AsyncTaskOutcome
    {
        $deadline = hrtime(true) + 10_000_000_000;
        do {
            $outcomes = $executor->poll(1);
            if ($outcomes !== []) {
                return $outcomes[0];
            }
            usleep(1_000);
        } while (hrtime(true) < $deadline);

        self::fail('Plugin worker did not produce a result before the deadline.');
    }

    private function buildPhar(string $path, bool $replacement, string $marker): void
    {
        $manifest = json_encode([
            'schema' => 1,
            'name' => 'AsyncFixture',
            'version' => '1.0.0',
            'api' => '^0.3',
            'main' => 'Fixture\\AsyncWorker\\Main',
            'namespace' => 'Fixture\\AsyncWorker',
            'authors' => ['Bedriox Team'],
            'dependencies' => [],
            'softDependencies' => [],
            'load' => 'WORLD_READY',
        ], JSON_THROW_ON_ERROR);
        $task = $replacement
            ? '<?php declare(strict_types=1); namespace Fixture\\AsyncWorker; use Bedriox\\Api\\Scheduler\\AsyncTask; use Bedriox\\Api\\Scheduler\\AsyncTaskValue; final class EchoTask extends AsyncTask { public function onRun(AsyncTaskValue $input): AsyncTaskValue { file_put_contents(' . var_export($marker, true) . ', \'executed\'); return $input; } }'
            : '<?php declare(strict_types=1); namespace Fixture\\AsyncWorker; use Bedriox\\Api\\Scheduler\\AsyncTask; use Bedriox\\Api\\Scheduler\\AsyncTaskValue; final class EchoTask extends AsyncTask { public function onRun(AsyncTaskValue $input): AsyncTaskValue { return $input; } }';
        $code = <<<'PHP'
$phar = new Phar($argv[1]);
$phar->startBuffering();
$phar['plugin.json'] = $argv[2];
$phar['src/Main.php'] = '<?php declare(strict_types=1); namespace Fixture\AsyncWorker; final class Main {}';
$phar['src/EchoTask.php'] = $argv[3];
$phar->setSignatureAlgorithm(Phar::SHA256);
$phar->setStub('<?php __HALT_COMPILER();');
$phar->stopBuffering();
PHP;
        $process = proc_open([
            PHP_BINARY,
            '-d',
            'phar.readonly=0',
            '-r',
            $code,
            $path,
            $manifest,
            $task,
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stdout . $stderr);
    }
}
