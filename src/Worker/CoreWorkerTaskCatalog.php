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

namespace Bedriox\Server\Worker;

use Bedriox\Server\Plugin\Scheduler\Worker\PluginAsyncTaskHandler;
use Bedriox\Server\Update\UpdateTaskCodec;
use Bedriox\Server\Worker\Chunk\ChunkPreparationRequestCodec;
use Bedriox\Server\Worker\Navigation\NavigationPathCodec;
use Bedriox\Server\Worker\Navigation\NavigationSearchRequestCodec;
use Bedriox\Server\Worker\Network\BatchCompressionTask;
use Bedriox\Server\Worker\Task\CheckForUpdatesTask;
use Bedriox\Server\Worker\Task\FindNavigationPathTask;
use Bedriox\Server\Worker\Task\GenerateChunkTask;
use Bedriox\Server\Worker\Task\PrepareChunkTask;
use Bedriox\Server\Worker\Task\PrepareWorldTask;
use Bedriox\Server\Worker\Task\SelfTestTask;
use Bedriox\Server\Worker\Task\SpawnWorldStorageOwnerTask;
use Bedriox\Server\Worker\World\WorldPreparationCodec;

final class CoreWorkerTaskCatalog
{
    public const SELF_TEST = 1;
    public const GENERATE_CHUNK = 2;
    public const COMPRESS_BATCH = 3;
    public const PLUGIN_ASYNC_TASK = 4;
    public const PREPARE_CHUNK = 5;
    public const FIND_NAVIGATION_PATH = 6;
    public const SPAWN_WORLD_STORAGE_OWNER = 7;
    public const PREPARE_WORLD = 8;
    public const CHECK_FOR_UPDATES = 9;

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
        $registry->register(new WorkerTaskDefinition(
            self::FIND_NAVIGATION_PATH,
            1,
            'entity-navigation',
            WorkerLane::WORLD,
            FindNavigationPathTask::class,
            NavigationSearchRequestCodec::MAXIMUM_ENCODED_BYTES,
            NavigationPathCodec::MAXIMUM_ENCODED_BYTES,
            2_000,
            cancellable: true,
            retryWhenNotStarted: true,
        ));
        $registry->register(new WorkerTaskDefinition(
            self::SPAWN_WORLD_STORAGE_OWNER,
            1,
            'world-storage-launch',
            WorkerLane::CONTROL,
            SpawnWorldStorageOwnerTask::class,
            1_024,
            0,
            10_000,
            cancellable: false,
        ));
        $registry->register(new WorkerTaskDefinition(
            self::PREPARE_WORLD,
            1,
            'world-preparation',
            WorkerLane::WORLD,
            PrepareWorldTask::class,
            WorldPreparationCodec::MAXIMUM_REQUEST_BYTES,
            WorldPreparationCodec::MAXIMUM_RESULT_BYTES,
            300_000,
            cancellable: true,
            retryWhenNotStarted: true,
        ));
        $registry->register(new WorkerTaskDefinition(
            self::CHECK_FOR_UPDATES,
            1,
            'update-check',
            WorkerLane::CONTROL,
            CheckForUpdatesTask::class,
            UpdateTaskCodec::MAXIMUM_REQUEST_BYTES,
            UpdateTaskCodec::MAXIMUM_RESPONSE_BYTES,
            5_000,
            cancellable: false,
        ));

        return $registry;
    }
}
