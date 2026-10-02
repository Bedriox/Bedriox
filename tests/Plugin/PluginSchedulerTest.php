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

use Bedriox\Api\Command\CommandRegistrar;
use Bedriox\Api\Event\EventRegistrar;
use Bedriox\Api\Inventory\ItemRegistrar;
use Bedriox\Api\Plugin\PluginContext;
use Bedriox\Api\Plugin\PluginLogger;
use Bedriox\Api\Plugin\SourcePluginRegistrar;
use Bedriox\Api\Scheduler\AsyncTask;
use Bedriox\Api\Scheduler\AsyncTaskFailure;
use Bedriox\Api\Scheduler\AsyncTaskValue;
use Bedriox\Api\Scheduler\TaskRejectedException;
use Bedriox\Api\Scheduler\TaskState;
use Bedriox\Api\Server;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginArchiveIdentity;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Bedriox\Server\Plugin\Scheduler\AsyncTaskOutcome;
use Bedriox\Server\Plugin\Scheduler\AsyncTaskRequest;
use Bedriox\Server\Plugin\Scheduler\MainThreadPluginScheduler;
use Bedriox\Server\Plugin\Scheduler\PluginAsyncTaskExecutor;
use PHPUnit\Framework\TestCase;
use Throwable;

final class PluginSchedulerTest extends TestCase
{
    public function testOrdersTasksAndNeverRunsNewRegistrationInSameDispatch(): void
    {
        [$scheduler, $plugins] = $this->scheduler();
        $owned = $scheduler->forPlugin('Example', '1.0.0', self::identity());
        $runs = [];
        $owned->nextTick(static function () use (&$runs): void {
            $runs[] = 'first';
        });
        $owned->nextTick(function () use (&$runs, $owned): void {
            $runs[] = 'second';
            $owned->nextTick(static function () use (&$runs): void {
                $runs[] = 'nested';
            });
        });

        $scheduler->tick(1);
        self::assertSame(['first', 'second'], $runs);
        $scheduler->tick(2);
        self::assertSame(['first', 'second', 'nested'], $runs);
        self::assertTrue($plugins->enabled['example']);
    }

    public function testRepeatingTasksUseFixedDelayWithoutCatchUpBursts(): void
    {
        [$scheduler] = $this->scheduler();
        $owned = $scheduler->forPlugin('Example', '1.0.0', self::identity());
        $runs = [];
        $handle = $owned->delayedRepeating(2, 3, static function () use (&$runs): void {
            $runs[] = true;
        });

        $scheduler->tick(1);
        $scheduler->tick(2);
        $scheduler->tick(5);
        $scheduler->tick(10);
        self::assertCount(3, $runs);
        self::assertSame(TaskState::QUEUED, $handle->state());
        $handle->cancel();
        $scheduler->tick(13);
        self::assertCount(3, $runs);
        self::assertSame(TaskState::CANCELLED, $handle->state());
    }

    public function testDueBudgetDefersTasksInStableOrder(): void
    {
        [$scheduler] = $this->scheduler(maximumCallbacksPerTick: 2);
        $owned = $scheduler->forPlugin('Example', '1.0.0', self::identity());
        $runs = [];
        foreach (range(1, 4) as $number) {
            $owned->nextTick(static function () use (&$runs, $number): void {
                $runs[] = $number;
            });
        }

        $scheduler->tick(1);
        self::assertSame([1, 2], $runs);
        self::assertSame(2, $scheduler->deferredLastTick());
        $scheduler->tick(2);
        self::assertSame([1, 2, 3, 4], $runs);
    }

    public function testOwnerCleanupCancelsEveryOwnedTask(): void
    {
        [$scheduler, , $ownership] = $this->scheduler();
        $owned = $scheduler->forPlugin('Example', '1.0.0', self::identity());
        $first = $owned->delayed(10, static function (): void {});
        $second = $owned->repeating(10, static function (): void {});

        $ownership->releaseAll('Example');

        self::assertSame(TaskState::OWNER_DISABLED, $first->state());
        self::assertSame(TaskState::OWNER_DISABLED, $second->state());
        self::assertSame(0, $scheduler->registeredCount());
    }

    public function testCallbackFailureDiscardsActionsAndDisablesOnlyOwner(): void
    {
        $actions = new PluginActionBuffer();
        [$scheduler, $plugins] = $this->scheduler(actions: $actions);
        $owned = $scheduler->forPlugin('Example', '1.0.0', self::identity());
        $committed = false;
        $handle = $owned->nextTick(static function () use ($actions, &$committed): void {
            $actions->stage(static function () use (&$committed): void {
                $committed = true;
            });
            throw new \RuntimeException('failure');
        });

        $scheduler->tick(1);

        self::assertFalse($committed);
        self::assertSame(TaskState::FAILED, $handle->state());
        self::assertFalse($plugins->enabled['example']);
        self::assertCount(1, $plugins->failures);
    }

    public function testAsyncCompletionReturnsThroughMainThreadBoundary(): void
    {
        $executor = new TestPluginAsyncExecutor();
        [$scheduler] = $this->scheduler(executor: $executor);
        [$owned, $context] = $this->ownedScheduler($scheduler);
        $task = new TestAsyncTask();
        $handle = $owned->async($task, new AsyncTaskValue(['value' => 21]));
        self::assertSame(0, $scheduler->scheduledCount());
        self::assertSame(1, $scheduler->pendingAsyncCount());
        self::assertSame(64, $scheduler->maximumAsyncCompletionsPerTick());
        $request = $executor->requests[0];
        $executor->outcomes[] = AsyncTaskOutcome::completed(
            $request->taskId,
            $request->owner,
            $request->ownerGeneration,
            new AsyncTaskValue(['value' => 42]),
        );

        $scheduler->tick(1);

        self::assertSame(['value' => 42], $task->completed?->value());
        self::assertSame($context, $task->context);
        self::assertSame(TaskState::COMPLETED, $handle->state());
        self::assertSame(0, $scheduler->pendingAsyncCount());
    }

    public function testCancelledAsyncTaskIgnoresLateCompletion(): void
    {
        $executor = new TestPluginAsyncExecutor();
        [$scheduler] = $this->scheduler(executor: $executor);
        [$owned] = $this->ownedScheduler($scheduler);
        $task = new TestAsyncTask();
        $handle = $owned->async($task, new AsyncTaskValue(null));
        $request = $executor->requests[0];
        $handle->cancel();
        $executor->outcomes[] = AsyncTaskOutcome::completed(
            $request->taskId,
            $request->owner,
            $request->ownerGeneration,
            new AsyncTaskValue(null),
        );

        $scheduler->tick(1);

        self::assertNull($task->completed);
        self::assertSame([$request->taskId], $executor->cancelled);
        self::assertSame(TaskState::CANCELLED, $handle->state());
    }

    public function testAsyncWorkerFailureRunsMainThreadFailureLifecycleWithoutDisablingOwner(): void
    {
        $executor = new TestPluginAsyncExecutor();
        [$scheduler, $plugins] = $this->scheduler(executor: $executor);
        [$owned, $context] = $this->ownedScheduler($scheduler);
        $task = new TestAsyncTask();
        $handle = $owned->async($task, new AsyncTaskValue(null));
        $request = $executor->requests[0];
        $executor->outcomes[] = AsyncTaskOutcome::failed(
            $request->taskId,
            $request->owner,
            $request->ownerGeneration,
            new AsyncTaskFailure('task_exception', 'calculation failed'),
        );

        $scheduler->tick(1);

        self::assertSame('task_exception', $task->failure?->type);
        self::assertSame($context, $task->context);
        self::assertSame(TaskState::FAILED, $handle->state());
        self::assertTrue($plugins->enabled['example']);
        self::assertCount(0, $plugins->failures);
    }

    public function testMainThreadAsyncCallbackExceptionIsAttributedAndIsolatesOwner(): void
    {
        $executor = new TestPluginAsyncExecutor();
        [$scheduler, $plugins] = $this->scheduler(executor: $executor);
        [$owned] = $this->ownedScheduler($scheduler);
        $handle = $owned->async(new ThrowingCompletionAsyncTask(), new AsyncTaskValue(null));
        $request = $executor->requests[0];
        $executor->outcomes[] = AsyncTaskOutcome::completed(
            $request->taskId,
            $request->owner,
            $request->ownerGeneration,
            new AsyncTaskValue(null),
        );

        $scheduler->tick(1);

        self::assertSame(TaskState::FAILED, $handle->state());
        self::assertFalse($plugins->enabled['example']);
        self::assertCount(1, $plugins->failures);
    }

    public function testMainThreadAsyncFailureCallbackExceptionIsAttributedAndIsolatesOwner(): void
    {
        $executor = new TestPluginAsyncExecutor();
        [$scheduler, $plugins] = $this->scheduler(executor: $executor);
        [$owned] = $this->ownedScheduler($scheduler);
        $handle = $owned->async(new ThrowingFailureAsyncTask(), new AsyncTaskValue(null));
        $request = $executor->requests[0];
        $executor->outcomes[] = AsyncTaskOutcome::failed(
            $request->taskId,
            $request->owner,
            $request->ownerGeneration,
            new AsyncTaskFailure('task_exception', 'worker failed'),
        );

        $scheduler->tick(1);

        self::assertSame(TaskState::FAILED, $handle->state());
        self::assertFalse($plugins->enabled['example']);
        self::assertCount(1, $plugins->failures);
    }

    public function testDisabledOwnerCancelsAsyncTaskAndIgnoresItsLateResult(): void
    {
        $executor = new TestPluginAsyncExecutor();
        [$scheduler, , $ownership] = $this->scheduler(executor: $executor);
        [$owned] = $this->ownedScheduler($scheduler);
        $task = new TestAsyncTask();
        $handle = $owned->async($task, new AsyncTaskValue(null));
        $request = $executor->requests[0];

        $ownership->releaseAll('Example');
        $executor->outcomes[] = AsyncTaskOutcome::completed(
            $request->taskId,
            $request->owner,
            $request->ownerGeneration,
            new AsyncTaskValue('late'),
        );
        $scheduler->tick(1);

        self::assertSame(TaskState::OWNER_DISABLED, $handle->state());
        self::assertNull($task->completed);
        self::assertSame([$request->taskId], $executor->cancelled);
        self::assertSame(0, $scheduler->registeredCount());
    }

    public function testNewOwnerGenerationCancelsPendingWorkAndRejectsItsLateResult(): void
    {
        $executor = new TestPluginAsyncExecutor();
        [$scheduler] = $this->scheduler(executor: $executor);
        [$owned] = $this->ownedScheduler($scheduler);
        $task = new TestAsyncTask();
        $handle = $owned->async($task, new AsyncTaskValue(null));
        $request = $executor->requests[0];

        $replacement = $scheduler->forPlugin('Example', '1.0.0', self::identity());
        $replacementContext = $this->pluginContext($replacement);
        $replacement->attachContext($replacementContext);
        $executor->outcomes[] = AsyncTaskOutcome::completed(
            $request->taskId,
            $request->owner,
            $request->ownerGeneration,
            new AsyncTaskValue('stale'),
        );
        $scheduler->tick(1);

        self::assertSame(TaskState::OWNER_DISABLED, $handle->state());
        self::assertNull($task->completed);
        self::assertSame([$request->taskId], $executor->cancelled);
        self::assertSame(0, $scheduler->registeredCount());
    }

    public function testAsyncValueRejectsObjectsReferencesAndNonFiniteNumbers(): void
    {
        foreach ([new \stdClass(), INF] as $invalid) {
            try {
                new AsyncTaskValue($invalid);
                self::fail('Invalid async value was accepted.');
            } catch (\InvalidArgumentException) {
            }
        }
        $referenced = 'value';
        $invalid = ['reference' => &$referenced];
        $this->expectException(\InvalidArgumentException::class);
        new AsyncTaskValue($invalid);
    }

    public function testAsyncTaskConstructorArgumentsAreRejectedInsteadOfSilentlyUsingWorkerDefaults(): void
    {
        $executor = new TestPluginAsyncExecutor();
        [$scheduler] = $this->scheduler(executor: $executor);
        [$owned] = $this->ownedScheduler($scheduler);

        $this->expectException(\InvalidArgumentException::class);
        $owned->async(new ConstructorArgumentAsyncTask(), new AsyncTaskValue(null));
    }

    public function testSourcePluginWithoutAdmittedArchiveIdentityCannotSubmitAsyncTask(): void
    {
        [$scheduler] = $this->scheduler(new TestPluginAsyncExecutor());
        $owned = $scheduler->forPlugin('Example', '1.0.0', null);
        $context = $this->pluginContext($owned);
        $owned->attachContext($context);

        $this->expectException(TaskRejectedException::class);
        $owned->async(new TestAsyncTask(), new AsyncTaskValue(null));
    }

    /**
     * @return array{MainThreadPluginScheduler, TestPluginRuntimeControl, PluginOwnershipRegistry}
     */
    private function scheduler(
        ?PluginAsyncTaskExecutor $executor = null,
        ?PluginActionBuffer $actions = null,
        int $maximumCallbacksPerTick = 256,
    ): array {
        $ownership = new PluginOwnershipRegistry();
        $plugins = new TestPluginRuntimeControl($ownership);
        $scheduler = new MainThreadPluginScheduler(
            $plugins,
            new PluginExecutionContext(),
            $actions ?? new PluginActionBuffer(),
            $ownership,
            $executor,
            maximumCallbacksPerTick: $maximumCallbacksPerTick,
        );

        return [$scheduler, $plugins, $ownership];
    }

    private static function identity(): PluginArchiveIdentity
    {
        return new PluginArchiveIdentity(
            __FILE__ . '.phar',
            'Example',
            '1.0.0',
            str_repeat('a', 64),
            'SHA-256',
            str_repeat('b', 64),
        );
    }

    /** @return array{\Bedriox\Server\Plugin\Scheduler\OwnedPluginScheduler, PluginContext} */
    private function ownedScheduler(MainThreadPluginScheduler $scheduler): array
    {
        $owned = $scheduler->forPlugin('Example', '1.0.0', self::identity());
        $context = $this->pluginContext($owned);
        $owned->attachContext($context);

        return [$owned, $context];
    }

    private function pluginContext(\Bedriox\Api\Scheduler\PluginScheduler $scheduler): PluginContext
    {
        return new PluginContext(
            'Example',
            $this->createStub(PluginLogger::class),
            $this->createStub(EventRegistrar::class),
            $this->createStub(CommandRegistrar::class),
            $this->createStub(SourcePluginRegistrar::class),
            $this->createStub(Server::class),
            new NullPluginData(sys_get_temp_dir()),
            $this->createStub(ItemRegistrar::class),
            $scheduler,
        );
    }
}

final class TestPluginRuntimeControl implements PluginRuntimeControl
{
    /** @var array<string, bool> */
    public array $enabled = ['example' => true];
    /** @var list<Throwable> */
    public array $failures = [];

    public function __construct(private readonly PluginOwnershipRegistry $ownership) {}

    public function isEnabled(string $plugin): bool
    {
        return $this->enabled[strtolower($plugin)] ?? false;
    }

    public function version(string $plugin): string
    {
        return '1.0.0';
    }

    public function disableAfterFailure(string $plugin, Throwable $failure, ?PluginExecutionFrame $frame): void
    {
        $this->enabled[strtolower($plugin)] = false;
        $this->failures[] = $failure;
        $this->ownership->releaseAll($plugin);
    }
}

final class TestPluginAsyncExecutor implements PluginAsyncTaskExecutor
{
    /** @var list<AsyncTaskRequest> */
    public array $requests = [];
    /** @var list<AsyncTaskOutcome> */
    public array $outcomes = [];
    /** @var list<int> */
    public array $cancelled = [];

    public function submit(AsyncTaskRequest $request): void
    {
        $this->requests[] = $request;
    }

    public function cancel(int $taskId): void
    {
        $this->cancelled[] = $taskId;
    }

    public function poll(int $maximum): array
    {
        return array_splice($this->outcomes, 0, $maximum);
    }

    public function shutdown(): void {}
}

class TestAsyncTask extends AsyncTask
{
    public ?AsyncTaskValue $completed = null;
    public ?AsyncTaskFailure $failure = null;
    public ?PluginContext $context = null;

    public function onRun(AsyncTaskValue $input): AsyncTaskValue
    {
        return $input;
    }

    public function onCompletion(AsyncTaskValue $result, PluginContext $context): void
    {
        $this->completed = $result;
        $this->context = $context;
    }

    public function onFailure(AsyncTaskFailure $failure, PluginContext $context): void
    {
        $this->failure = $failure;
        $this->context = $context;
    }
}

final class ThrowingCompletionAsyncTask extends TestAsyncTask
{
    public function onCompletion(AsyncTaskValue $result, PluginContext $context): void
    {
        throw new \RuntimeException('main-thread callback failed');
    }
}

final class ThrowingFailureAsyncTask extends TestAsyncTask
{
    public function onFailure(AsyncTaskFailure $failure, PluginContext $context): void
    {
        throw new \RuntimeException('main-thread failure callback failed');
    }
}

final class ConstructorArgumentAsyncTask extends TestAsyncTask
{
    public function __construct(public int $configuration = 1) {}
}
