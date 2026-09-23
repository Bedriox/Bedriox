<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin\Scheduler;

use Bedriox\Api\Plugin\PluginContext;
use Bedriox\Api\Scheduler\AsyncTask;
use Bedriox\Api\Scheduler\AsyncTaskFailure;
use Bedriox\Api\Scheduler\AsyncTaskValue;
use Bedriox\Api\Scheduler\TaskHandle;
use Bedriox\Api\Scheduler\TaskRejectedException;
use Bedriox\Api\Scheduler\TaskState;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginArchiveIdentity;
use Bedriox\Server\Plugin\PluginException;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Closure;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use Throwable;

final class MainThreadPluginScheduler
{
    /** @var array<int, ScheduledPluginTask> */
    private array $scheduled = [];
    /** @var array<int, PendingAsyncTask> */
    private array $async = [];
    /** @var array<string, int> */
    private array $generations = [];
    private int $currentTick = 0;
    private int $nextTaskId = 1;
    private int $nextSequence = 1;
    private bool $dispatching = false;
    private bool $accepting = true;
    private int $deferredLastTick = 0;

    public function __construct(
        private readonly PluginRuntimeControl $plugins,
        private readonly PluginExecutionContext $execution,
        private readonly PluginActionBuffer $actions,
        private readonly PluginOwnershipRegistry $ownership,
        private readonly ?PluginAsyncTaskExecutor $asyncExecutor = null,
        private readonly int $maximumTasks = 8192,
        private readonly int $maximumTasksPerPlugin = 256,
        private readonly int $maximumCallbacksPerTick = 256,
        private readonly int $maximumAsyncCompletionsPerTick = 64,
        private readonly int $asyncDeadlineMilliseconds = 5000,
    ) {
        if ($maximumTasks < 1 || $maximumTasks > 65536
            || $maximumTasksPerPlugin < 1 || $maximumTasksPerPlugin > $maximumTasks
            || $maximumCallbacksPerTick < 1 || $maximumCallbacksPerTick > 4096
            || $maximumAsyncCompletionsPerTick < 1 || $maximumAsyncCompletionsPerTick > 1024
            || $asyncDeadlineMilliseconds < 1 || $asyncDeadlineMilliseconds > 300000) {
            throw new InvalidArgumentException('Invalid plugin scheduler limits.');
        }
    }

    public function forPlugin(string $owner, string $ownerVersion, ?PluginArchiveIdentity $packageIdentity): OwnedPluginScheduler
    {
        $key = strtolower($owner);
        $generation = ($this->generations[$key] ?? 0) + 1;
        $this->generations[$key] = $generation;
        foreach ($this->async as $pending) {
            if (strcasecmp($pending->request->owner, $owner) === 0
                && $pending->request->ownerGeneration !== $generation) {
                $this->cancel($pending->request->taskId, TaskState::OWNER_DISABLED);
            }
        }

        return new OwnedPluginScheduler($this, $owner, $ownerVersion, $packageIdentity, $generation);
    }

    /** @param callable(): void $callback */
    public function schedule(string $owner, int $delayTicks, int $periodTicks, callable $callback): TaskHandle
    {
        if (!$this->accepting) {
            throw new TaskRejectedException('The plugin scheduler is shutting down.');
        }
        if (!$this->plugins->isEnabled($owner)) {
            throw new PluginException("Disabled plugin {$owner} cannot schedule tasks.");
        }
        if ($delayTicks < 1 || $periodTicks < 0) {
            throw new InvalidArgumentException('Task delays must be positive and periods cannot be negative.');
        }
        $this->assertCapacity($owner);
        $id = $this->nextTaskId++;
        $handle = new OwnedTaskHandle($id, function (int $taskId): void {
            $this->cancel($taskId);
        });
        $this->scheduled[$id] = new ScheduledPluginTask(
            $id,
            $this->nextSequence++,
            $owner,
            Closure::fromCallable($callback),
            $periodTicks,
            $handle,
            $this->currentTick + $delayTicks,
        );
        $this->ownership->own(
            $owner,
            "scheduled-task:{$id}",
            fn() => $this->cancel($id, TaskState::OWNER_DISABLED, false),
        );

        return $handle;
    }

    public function submitAsync(
        string $owner,
        string $ownerVersion,
        ?PluginArchiveIdentity $packageIdentity,
        int $ownerGeneration,
        AsyncTask $task,
        AsyncTaskValue $input,
        PluginContext $context,
    ): TaskHandle {
        if (!$this->accepting) {
            throw new TaskRejectedException('The plugin scheduler is shutting down.');
        }
        if (!$this->plugins->isEnabled($owner)) {
            throw new PluginException("Disabled plugin {$owner} cannot submit async tasks.");
        }
        $taskClass = $task::class;
        $reflection = new ReflectionClass($taskClass);
        if (!$reflection->isInstantiable() || ($reflection->getConstructor()?->getNumberOfParameters() ?? 0) !== 0) {
            throw new InvalidArgumentException('Async task entry points cannot declare constructor arguments.');
        }
        if (($this->generations[strtolower($owner)] ?? null) !== $ownerGeneration) {
            throw new TaskRejectedException('The plugin scheduler owner generation is stale.');
        }
        if ($this->asyncExecutor === null) {
            throw new TaskRejectedException('The plugin worker pool is not available.');
        }
        if (!$packageIdentity instanceof PluginArchiveIdentity
            || strcasecmp($packageIdentity->owner, $owner) !== 0
            || $packageIdentity->version !== $ownerVersion) {
            throw new TaskRejectedException('The plugin package has no matching admitted archive identity.');
        }
        $this->assertCapacity($owner);
        $id = $this->nextTaskId++;
        $handle = new OwnedTaskHandle($id, function (int $taskId): void {
            $this->cancel($taskId);
        });
        $request = new AsyncTaskRequest(
            $id,
            $owner,
            $ownerVersion,
            $ownerGeneration,
            $packageIdentity,
            $taskClass,
            $input,
            hrtime(true) + ($this->asyncDeadlineMilliseconds * 1000000),
        );
        $pending = new PendingAsyncTask(
            $request,
            $task,
            $context,
            $handle,
        );
        $this->async[$id] = $pending;
        $this->ownership->own(
            $owner,
            "async-task:{$id}",
            fn() => $this->cancel($id, TaskState::OWNER_DISABLED, false),
        );
        try {
            $this->asyncExecutor->submit($request);
        } catch (Throwable $failure) {
            unset($this->async[$id]);
            $this->ownership->forget($owner, "async-task:{$id}");
            $handle->transition(TaskState::REJECTED);
            throw new TaskRejectedException('The plugin worker pool rejected the task.', $handle);
        }

        return $handle;
    }

    public function tick(int $currentTick): void
    {
        if (!$this->accepting) {
            return;
        }
        if ($currentTick <= $this->currentTick) {
            throw new LogicException('Plugin scheduler ticks must increase monotonically.');
        }
        if ($this->dispatching) {
            throw new LogicException('Plugin scheduler dispatch cannot be nested.');
        }
        $this->currentTick = $currentTick;
        $this->dispatching = true;
        try {
            $this->pollAsyncCompletions();
            $due = array_values(array_filter(
                $this->scheduled,
                fn(ScheduledPluginTask $task): bool => $task->targetTick <= $this->currentTick
                    && $task->handle->state() === TaskState::QUEUED,
            ));
            usort($due, static fn(ScheduledPluginTask $left, ScheduledPluginTask $right): int =>
                [$left->targetTick, $left->sequence] <=> [$right->targetTick, $right->sequence]);
            $execute = array_slice($due, 0, $this->maximumCallbacksPerTick);
            $this->deferredLastTick = count($due) - count($execute);
            foreach ($execute as $task) {
                $this->runScheduled($task);
            }
        } finally {
            $this->dispatching = false;
        }
    }

    public function cancel(int $taskId, TaskState $terminalState = TaskState::CANCELLED, bool $forget = true): void
    {
        if (!$terminalState->isTerminal()) {
            throw new InvalidArgumentException('Cancelled tasks require a terminal state.');
        }
        $task = $this->scheduled[$taskId] ?? null;
        if ($task !== null) {
            unset($this->scheduled[$taskId]);
            $task->handle->transition($terminalState);
            if ($forget) {
                $this->ownership->forget($task->owner, "scheduled-task:{$taskId}");
            }

            return;
        }
        $pending = $this->async[$taskId] ?? null;
        if ($pending === null) {
            return;
        }
        unset($this->async[$taskId]);
        $pending->handle->transition($terminalState);
        if ($forget) {
            $this->ownership->forget($pending->request->owner, "async-task:{$taskId}");
        }
        try {
            $this->asyncExecutor?->cancel($taskId);
        } catch (Throwable) {
            // Cancellation remains authoritative even if a worker is already unavailable.
        }
    }

    public function shutdown(): void
    {
        if (!$this->accepting) {
            return;
        }
        $this->accepting = false;
        foreach (array_keys($this->scheduled + $this->async) as $taskId) {
            $this->cancel($taskId);
        }
        try {
            $this->asyncExecutor?->shutdown();
        } catch (Throwable) {
            // Worker shutdown cannot prevent authoritative plugin cleanup.
        }
    }

    public function registeredCount(): int
    {
        return count($this->scheduled) + count($this->async);
    }

    public function scheduledCount(): int
    {
        return count($this->scheduled);
    }

    public function pendingAsyncCount(): int
    {
        return count($this->async);
    }

    public function maximumAsyncCompletionsPerTick(): int
    {
        return $this->maximumAsyncCompletionsPerTick;
    }

    public function deferredLastTick(): int
    {
        return $this->deferredLastTick;
    }

    private function runScheduled(ScheduledPluginTask $task): void
    {
        if (!isset($this->scheduled[$task->id]) || !$this->plugins->isEnabled($task->owner)) {
            $this->cancel($task->id, TaskState::OWNER_DISABLED);

            return;
        }
        $frame = new PluginExecutionFrame(
            $task->owner,
            $this->plugins->version($task->owner),
            'scheduled-task',
            listener: (string) $task->id,
            startedAtNanoseconds: hrtime(true),
        );
        $task->handle->transition(TaskState::RUNNING);
        $this->execution->enter($frame);
        $this->actions->begin();
        try {
            ($task->callback)();
            if (!$this->plugins->isEnabled($task->owner)) {
                $this->actions->discard();
                $this->cancel($task->id, TaskState::OWNER_DISABLED);

                return;
            }
            $this->actions->commit();
            if ($task->handle->state() === TaskState::CANCELLED) {
                return;
            }
            if ($task->periodTicks > 0) {
                $task->targetTick = $this->currentTick + $task->periodTicks;
                $task->handle->transition(TaskState::QUEUED);
            } else {
                $this->finishScheduled($task, TaskState::COMPLETED);
            }
        } catch (Throwable $failure) {
            if ($this->actions->isCapturing()) {
                $this->actions->discard();
            }
            $this->finishScheduled($task, TaskState::FAILED);
            $this->plugins->disableAfterFailure($task->owner, $failure, $frame);
        } finally {
            $this->execution->leave();
        }
    }

    private function finishScheduled(ScheduledPluginTask $task, TaskState $state): void
    {
        unset($this->scheduled[$task->id]);
        $this->ownership->forget($task->owner, "scheduled-task:{$task->id}");
        $task->handle->transition($state);
    }

    private function pollAsyncCompletions(): void
    {
        if ($this->asyncExecutor === null) {
            return;
        }
        try {
            $outcomes = $this->asyncExecutor->poll($this->maximumAsyncCompletionsPerTick);
        } catch (Throwable $failure) {
            $outcomes = [];
            foreach (array_slice($this->async, 0, $this->maximumAsyncCompletionsPerTick, true) as $pending) {
                $outcomes[] = AsyncTaskOutcome::failed(
                    $pending->request->taskId,
                    $pending->request->owner,
                    $pending->request->ownerGeneration,
                    new AsyncTaskFailure('worker_pool_failure', $failure::class),
                );
            }
        }
        foreach ($outcomes as $outcome) {
            $pending = $this->async[$outcome->taskId] ?? null;
            if ($pending === null
                || strcasecmp($pending->request->owner, $outcome->owner) !== 0
                || $pending->request->ownerGeneration !== $outcome->ownerGeneration
                || ($this->generations[strtolower($outcome->owner)] ?? null) !== $outcome->ownerGeneration) {
                continue;
            }
            if (!$this->plugins->isEnabled($pending->request->owner)) {
                $this->cancel($outcome->taskId, TaskState::OWNER_DISABLED);
                continue;
            }
            $this->runAsyncCompletion($pending, $outcome);
            unset($this->async[$outcome->taskId]);
            $this->ownership->forget($pending->request->owner, "async-task:{$outcome->taskId}");
        }
    }

    private function runAsyncCompletion(PendingAsyncTask $pending, AsyncTaskOutcome $outcome): void
    {
        $frame = new PluginExecutionFrame(
            $pending->request->owner,
            $pending->request->ownerVersion,
            'async-task-completion',
            listener: $pending->request->taskClass,
            startedAtNanoseconds: hrtime(true),
        );
        $pending->handle->transition(TaskState::RUNNING);
        $this->execution->enter($frame);
        $this->actions->begin();
        try {
            if ($outcome->failure !== null) {
                $pending->task->onFailure($outcome->failure, $pending->context);
                if (!$this->plugins->isEnabled($pending->request->owner)) {
                    $this->actions->discard();
                    $pending->handle->transition(TaskState::OWNER_DISABLED);

                    return;
                }
                $this->actions->commit();
                if ($pending->handle->state() !== TaskState::CANCELLED) {
                    $pending->handle->transition(TaskState::FAILED);
                }

                return;
            }
            if (!$outcome->result instanceof AsyncTaskValue) {
                throw new RemoteAsyncTaskException('Worker returned an invalid async task result.');
            }
            $pending->task->onCompletion($outcome->result, $pending->context);
            if (!$this->plugins->isEnabled($pending->request->owner)) {
                $this->actions->discard();
                $pending->handle->transition(TaskState::OWNER_DISABLED);

                return;
            }
            $this->actions->commit();
            if ($pending->handle->state() !== TaskState::CANCELLED) {
                $pending->handle->transition(TaskState::COMPLETED);
            }
        } catch (Throwable $failure) {
            if ($this->actions->isCapturing()) {
                $this->actions->discard();
            }
            $pending->handle->transition(TaskState::FAILED);
            $this->plugins->disableAfterFailure($pending->request->owner, $failure, $frame);
        } finally {
            $this->execution->leave();
        }
    }

    private function assertCapacity(string $owner): void
    {
        if ($this->registeredCount() >= $this->maximumTasks) {
            throw new TaskRejectedException('The global plugin task limit has been reached.');
        }
        $owned = 0;
        foreach ($this->scheduled as $task) {
            $owned += strcasecmp($task->owner, $owner) === 0 ? 1 : 0;
        }
        foreach ($this->async as $task) {
            $owned += strcasecmp($task->request->owner, $owner) === 0 ? 1 : 0;
        }
        if ($owned >= $this->maximumTasksPerPlugin) {
            throw new TaskRejectedException("Plugin {$owner} reached its task limit.");
        }
    }
}
