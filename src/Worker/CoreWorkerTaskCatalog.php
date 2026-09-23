<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker;

use Bedriox\Server\Plugin\Scheduler\Worker\PluginAsyncTaskHandler;
use Bedriox\Server\Worker\Chunk\ChunkPreparationRequestCodec;
use Bedriox\Server\Worker\Network\BatchCompressionTask;
use Bedriox\Server\Worker\Task\GenerateChunkTask;
use Bedriox\Server\Worker\Task\PrepareChunkTask;
use Bedriox\Server\Worker\Task\SelfTestTask;

final class CoreWorkerTaskCatalog
{
    public const SELF_TEST = 1;
    public const GENERATE_CHUNK = 2;
    public const COMPRESS_BATCH = 3;
    public const PLUGIN_ASYNC_TASK = 4;
    public const PREPARE_CHUNK = 5;

    public static function create(): WorkerTaskRegistry
    {
        $registry = new WorkerTaskRegistry();
        $registry->register(new WorkerTaskDefinition(
            self::SELF_TEST,
            1,
            'worker-runtime',
            WorkerLane::CONTROL,
            SelfTestTask::class,
            4_096,
            32,
            2_000,
        ));
        $registry->register(new WorkerTaskDefinition(
            self::GENERATE_CHUNK,
            1,
            'world-generation',
            WorkerLane::WORLD,
            GenerateChunkTask::class,
            4_096,
            16_777_216,
            300_000,
            cancellable: true,
            retryWhenNotStarted: true,
        ));
        $registry->register(new WorkerTaskDefinition(
            self::COMPRESS_BATCH,
            1,
            'network-compression',
            WorkerLane::NETWORK,
            BatchCompressionTask::class,
            4_194_400,
            1_048_577,
            30_000,
        ));
        $registry->register(new WorkerTaskDefinition(
            self::PLUGIN_ASYNC_TASK,
            1,
            'plugin-async',
            WorkerLane::CONTROL,
            PluginAsyncTaskHandler::class,
            1_052_000,
            1_052_000,
            300_000,
        ));
        $registry->register(new WorkerTaskDefinition(
            self::PREPARE_CHUNK,
            1,
            'chunk-preparation',
            WorkerLane::NETWORK,
            PrepareChunkTask::class,
            ChunkPreparationRequestCodec::MAXIMUM_ENCODED_BYTES,
            1_048_577,
            30_000,
            cancellable: true,
            retryWhenNotStarted: true,
        ));

        return $registry;
    }
}
