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

namespace Bedriox\Server\Tests\Observability;

use Bedriox\Server\Command\Default\TimingsCommand;
use Bedriox\Server\Observability\PerformanceMonitor;
use Bedriox\Server\Observability\Profiling\PerformanceProfiler;
use Bedriox\Server\Observability\Profiling\TimingsUploadTaskCodec;
use Bedriox\Server\Worker\WorkerDispatcher;
use Bedriox\Server\Worker\WorkerPoolSnapshot;
use Bedriox\Server\Worker\WorkerReceipt;
use Bedriox\Server\Worker\WorkerResult;
use Bedriox\Server\Worker\WorkerResultStatus;
use Bedriox\Server\Worker\WorkerSubmission;
use Closure;
use PHPUnit\Framework\TestCase;

final class PerformanceProfilerTest extends TestCase
{
    public function testTimingsCommandProvidesOnAndPasteWorkflow(): void
    {
        $profiler = new PerformanceProfiler(
            sys_get_temp_dir(),
            new RejectingTimingsWorkerDispatcher(),
            false,
            [],
        );
        $usage = (new TimingsCommand($profiler))->defineArguments()->usage('timings');

        self::assertContains('/timings on [seconds]', $usage);
        self::assertContains('/timings paste', $usage);
    }

    public function testCreatesBoundedCompressedReportFromExistingMonitor(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-timings-' . bin2hex(random_bytes(8));
        $profiler = new PerformanceProfiler(
            $directory,
            new RejectingTimingsWorkerDispatcher(),
            false,
            [
                'view_distance' => 4,
                'simulation_distance' => 4,
                'memory_limit' => '500MB',
                'memory_limit_bytes' => 500_000_000,
                'network_profile' => 'balanced',
                'core_workers' => 1,
                'plugin_workers' => 1,
            ],
        );
        try {
            $profiler->start(30);
            $monitor = new PerformanceMonitor(profiler: $profiler);
            $monitor->recordTick(60_000_000);
            $snapshot = $monitor->snapshot(
                onlinePlayers: 2,
                maximumPlayers: 20,
                loadedChunks: 16,
                configuredMemoryLimitBytes: 500_000_000,
                entityCount: 12,
            );
            $monitor->sampleProfile($snapshot, ['entity_ai_us' => 1_250]);
            $path = $profiler->stop();

            self::assertFileExists($path);
            $compressed = file_get_contents($path);
            self::assertIsString($compressed);
            $decoded = gzdecode($compressed);
            self::assertIsString($decoded);
            $report = json_decode($decoded, true, 32, JSON_THROW_ON_ERROR);
            self::assertIsArray($report);
            self::assertSame('bedriox.timings/3', $report['schema']);
            $samples = $report['samples'];
            self::assertIsArray($samples);
            self::assertNotEmpty($samples);
            $sample = $samples[0];
            self::assertIsArray($sample);
            self::assertSame(2, $sample['players']);
            $subsystems = $sample['subsystems_ms'];
            self::assertIsArray($subsystems);
            self::assertSame(1.25, $subsystems['world.entity_ai_us']);
            $records = $report['records'];
            self::assertIsArray($records);
            self::assertNotEmpty($records);
            $record = $records[0];
            self::assertIsArray($record);
            self::assertSame('tick', $record['category']);
            $diagnostics = $report['diagnostics'];
            self::assertIsArray($diagnostics);
            $memory = $diagnostics['memory'];
            self::assertIsArray($memory);
            self::assertSame(500_000_000, $memory['limit_bytes']);
        } finally {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    }

    public function testUploadCodecAcceptsOnlyOfficialOrLoopbackEndpoints(): void
    {
        $codec = new TimingsUploadTaskCodec();
        $request = $codec->decodeRequest($codec->encodeRequest(
            'http://127.0.0.1:8080/api/v1/reports',
            gzencode('{}') ?: '',
        ));
        self::assertSame('http://127.0.0.1:8080/api/v1/reports', $request['endpoint']);

        $this->expectException(\InvalidArgumentException::class);
        $codec->encodeRequest('https://example.com/upload', 'report');
    }

    public function testUploadCodecRejectsAmbiguousOfficialUrls(): void
    {
        $codec = new TimingsUploadTaskCodec();
        $id = 'AbCdEfGhIjKlMnOpQrStUv';
        self::assertSame(
            ['id' => $id, 'view_url' => 'https://timings.bedriox.com/r/' . $id],
            $codec->decodeResponse($codec->encodeResponse($id, 'https://timings.bedriox.com/r/' . $id)),
        );

        foreach ([
            'https://timings.bedriox.com:444/api/v1/reports',
            'https://timings.bedriox.com/api/v1/reports?target=other',
            'https://user@timings.bedriox.com/api/v1/reports',
        ] as $endpoint) {
            try {
                $codec->encodeRequest($endpoint, 'report');
                self::fail('Ambiguous timing endpoint was accepted: ' . $endpoint);
            } catch (\InvalidArgumentException) {
            }
        }

        foreach ([
            'https://timings.bedriox.com:444/r/' . $id,
            'https://timings.bedriox.com/r/' . $id . '?target=other',
            'https://user@timings.bedriox.com/r/' . $id,
        ] as $url) {
            try {
                $codec->encodeResponse($id, $url);
                self::fail('Ambiguous timing report URL was accepted: ' . $url);
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testPasteOrganizesPublishedReportAndWritesLinkFile(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-timings-' . bin2hex(random_bytes(8));
        $dispatcher = new CompletingTimingsWorkerDispatcher();
        $profiler = new PerformanceProfiler($directory, $dispatcher, true, []);
        $id = 'AbCdEfGhIjKlMnOpQrStUv';
        $url = 'http://127.0.0.1:8080/r/' . $id;
        try {
            $profiler->start(30);
            $monitor = new PerformanceMonitor(profiler: $profiler);
            $monitor->recordTick(50_000_000);
            $monitor->sampleProfile($monitor->snapshot(1, 20, 4, 500_000_000, 2), []);

            $completed = null;
            self::assertTrue($profiler->paste(
                static function (?string $publishedUrl, ?string $linkPath, ?string $error) use (&$completed): void {
                    $completed = [$publishedUrl, $linkPath, $error];
                },
            ));
            $dispatcher->complete($id, $url);

            $reportPath = $directory . DIRECTORY_SEPARATOR . $id
                . DIRECTORY_SEPARATOR . 'report.json.gz';
            $linkPath = dirname($reportPath) . DIRECTORY_SEPARATOR . 'link.txt';
            self::assertSame([$url, $linkPath, null], $completed);
            self::assertFileExists($reportPath);
            self::assertSame($reportPath, $profiler->lastReport());
            self::assertSame($url . PHP_EOL, file_get_contents($linkPath));
        } finally {
            $publishedDirectory = $directory . DIRECTORY_SEPARATOR . $id;
            @unlink($publishedDirectory . DIRECTORY_SEPARATOR . 'link.txt');
            @unlink($publishedDirectory . DIRECTORY_SEPARATOR . 'report.json.gz');
            @rmdir($publishedDirectory);
            @rmdir($directory);
        }
    }
}

final class CompletingTimingsWorkerDispatcher implements WorkerDispatcher
{
    private ?Closure $completion = null;
    private ?WorkerReceipt $receipt = null;

    public function submit(int $taskTypeId, string $payload, Closure $completion, ?int $deadlineNanoseconds = null): WorkerSubmission
    {
        $this->completion = $completion;
        $this->receipt = new WorkerReceipt('timings-test', 1, $taskTypeId, 'timings', $deadlineNanoseconds ?? PHP_INT_MAX);

        return WorkerSubmission::accepted($this->receipt);
    }

    public function complete(string $id, string $url): void
    {
        if ($this->completion === null || $this->receipt === null) {
            throw new \LogicException('No timing upload is pending.');
        }
        $payload = (new TimingsUploadTaskCodec())->encodeResponse($id, $url);
        ($this->completion)(new WorkerResult($this->receipt, WorkerResultStatus::SUCCESS, $payload));
    }

    public function cancel(WorkerReceipt $receipt): bool
    {
        return false;
    }

    public function poll(int $maximumCompletions = 256): void {}

    public function snapshot(): WorkerPoolSnapshot
    {
        return new WorkerPoolSnapshot('test', 1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, true);
    }

    public function shutdown(): void {}
}

final class RejectingTimingsWorkerDispatcher implements WorkerDispatcher
{
    public function submit(int $taskTypeId, string $payload, Closure $completion, ?int $deadlineNanoseconds = null): WorkerSubmission
    {
        return WorkerSubmission::rejected(\Bedriox\Server\Worker\WorkerRejectionReason::DISABLED);
    }

    public function cancel(WorkerReceipt $receipt): bool
    {
        return false;
    }

    public function poll(int $maximumCompletions = 256): void {}

    public function snapshot(): WorkerPoolSnapshot
    {
        return new WorkerPoolSnapshot('test', 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, false);
    }

    public function shutdown(): void {}
}
