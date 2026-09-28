<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Worker\World;

use Bedriox\Server\Tests\Fixtures\WorkerPluginGenerator;
use Bedriox\Server\Worker\Task\PrepareWorldTask;
use Bedriox\Server\Worker\WorkerDispatcher;
use Bedriox\Server\Worker\WorkerPoolSnapshot;
use Bedriox\Server\Worker\WorkerReceipt;
use Bedriox\Server\Worker\WorkerResult;
use Bedriox\Server\Worker\WorkerResultStatus;
use Bedriox\Server\Worker\WorkerSubmission;
use Bedriox\Server\Worker\World\PendingWorldPreparation;
use Bedriox\Server\Worker\World\WorldPreparationCodec;
use Bedriox\Server\Worker\World\WorldPreparationRequest;
use Bedriox\Server\Worker\World\WorldPreparationResult;
use Bedriox\Server\World\Generator\BuiltInGeneratorDefinitions;
use Bedriox\Server\World\Generator\GeneratorOptions;
use Bedriox\Server\World\Generator\WorkerGeneratorSource;
use Bedriox\Server\World\SpawnPosition;
use Closure;
use PHPUnit\Framework\TestCase;

final class WorldPreparationTest extends TestCase
{
    public function testCodecRoundTripsBoundedRequestAndResult(): void
    {
        $codec = new WorldPreparationCodec();
        $request = new WorldPreparationRequest(
            'flat',
            BuiltInGeneratorDefinitions::FLAT,
            1,
            73,
            'minecraft:overworld',
            new GeneratorOptions(['preset' => 'classic']),
        );
        $result = new WorldPreparationResult(
            BuiltInGeneratorDefinitions::FLAT,
            1,
            new SpawnPosition(0, 64, 0),
        );

        $decodedRequest = $codec->decodeRequest($codec->encodeRequest($request));
        $decodedResult = $codec->decodeResult($codec->encodeResult($result));

        self::assertSame($request->generatorIdentifier, $decodedRequest->generatorIdentifier);
        self::assertSame($request->options->canonicalJson(), $decodedRequest->options->canonicalJson());
        self::assertEquals($result, $decodedResult);
    }

    public function testTaskCalculatesBuiltInAndPluginDefaultSpawn(): void
    {
        $codec = new WorldPreparationCodec();
        $task = new PrepareWorldTask();
        $flat = $codec->decodeResult($task->execute($codec->encodeRequest(new WorldPreparationRequest(
            'flat',
            BuiltInGeneratorDefinitions::FLAT,
            1,
            73,
            'minecraft:overworld',
        ))));
        $plugin = $codec->decodeResult($task->execute($codec->encodeRequest(new WorldPreparationRequest(
            'test:worker',
            'test:worker',
            1,
            73,
            'minecraft:overworld',
            workerSource: WorkerGeneratorSource::capture(WorkerPluginGenerator::class),
        ))));

        self::assertSame([0, 64, 0], [$flat->defaultSpawn->x, $flat->defaultSpawn->y, $flat->defaultSpawn->z]);
        self::assertSame([0, 65, 0], [$plugin->defaultSpawn->x, $plugin->defaultSpawn->y, $plugin->defaultSpawn->z]);
    }

    public function testPendingPreparationRejectsMismatchedWorkerIdentityWithoutFallback(): void
    {
        $workers = new FakeWorldPreparationDispatcher();
        $pending = new PendingWorldPreparation($workers, 8, new WorldPreparationRequest(
            'flat',
            BuiltInGeneratorDefinitions::FLAT,
            1,
            73,
            'minecraft:overworld',
        ));
        self::assertNull($pending->poll());

        $workers->complete((new WorldPreparationCodec())->encodeResult(new WorldPreparationResult(
            BuiltInGeneratorDefinitions::DEFAULT,
            1,
            new SpawnPosition(0, 64, 0),
        )));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid-result');
        $pending->poll();
    }

    public function testCancellationCancelsAdmittedWorkerTask(): void
    {
        $workers = new FakeWorldPreparationDispatcher();
        $pending = new PendingWorldPreparation($workers, 8, new WorldPreparationRequest(
            'flat',
            BuiltInGeneratorDefinitions::FLAT,
            1,
            73,
            'minecraft:overworld',
        ));

        $pending->cancel();

        self::assertSame(1, $workers->cancellations);
    }
}

final class FakeWorldPreparationDispatcher implements WorkerDispatcher
{
    public int $cancellations = 0;
    private Closure $completion;
    private WorkerReceipt $receipt;

    public function submit(int $taskTypeId, string $payload, Closure $completion, ?int $deadlineNanoseconds = null): WorkerSubmission
    {
        (new WorldPreparationCodec())->decodeRequest($payload);
        $this->completion = $completion;
        $this->receipt = new WorkerReceipt(str_repeat('w', 16), 1, $taskTypeId, 'world-preparation', hrtime(true) + 1_000_000_000);

        return WorkerSubmission::accepted($this->receipt);
    }

    public function complete(string $payload): void
    {
        ($this->completion)(new WorkerResult($this->receipt, WorkerResultStatus::SUCCESS, $payload));
    }

    public function cancel(WorkerReceipt $receipt): bool
    {
        ++$this->cancellations;

        return $receipt->taskId === $this->receipt->taskId;
    }

    public function poll(int $maximumCompletions = 256): void {}

    public function snapshot(): WorkerPoolSnapshot
    {
        return new WorkerPoolSnapshot('', 1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, true);
    }

    public function shutdown(): void {}
}
