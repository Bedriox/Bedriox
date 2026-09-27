<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Server\Observability\PerformanceMonitor;
use Closure;
use Throwable;

final readonly class RuntimeRunner
{
    public function __construct(
        private RuntimeDriver $runtime,
        private RuntimeSleeper $sleeper = new SystemRuntimeSleeper(),
        private ?RuntimeDiagnostics $diagnostics = null,
        private ?Closure $failureHandler = null,
        private ?PerformanceMonitor $performance = null,
        private ?Closure $backgroundPoll = null,
    ) {}

    /** @param Closure(): bool $stopRequested */
    public function run(Closure $stopRequested): int
    {
        $result = 0;
        $reportedFailure = null;
        try {
            while (!$stopRequested()) {
                $pollStarted = hrtime(true);
                ($this->backgroundPoll)?->__invoke();
                $alive = $this->runtime->poll();
                $this->performance?->recordPoll(hrtime(true) - $pollStarted);
                if (!$alive) {
                    $result = 1;
                    if ($this->runtime instanceof RuntimeFailureSource) {
                        $failure = $this->runtime->failure();
                        if ($failure !== null) {
                            $this->reportFailure($failure);
                            $reportedFailure = $failure;
                        }
                    }
                    break;
                }
                if (!$this->runtime instanceof RuntimeIdleAdvisor || $this->runtime->shouldIdleAfterPoll()) {
                    $this->sleeper->idle();
                }
            }
        } catch (Throwable $exception) {
            ($this->diagnostics ?? RuntimeDiagnostics::disabled())->record('runtime.runner_failed', [
                'exception' => $exception::class,
            ]);
            $this->reportFailure($exception);
            $reportedFailure = $exception;
            $result = 1;
        }

        try {
            $this->runtime->close();
        } catch (Throwable $exception) {
            ($this->diagnostics ?? RuntimeDiagnostics::disabled())->record('runtime.runner_close_failed', [
                'exception' => $exception::class,
            ]);
            $this->reportFailure($exception);
            $reportedFailure = $exception;
            $result = 1;
        }

        if ($this->runtime instanceof RuntimeFailureSource) {
            $failure = $this->runtime->failure();
            if ($failure !== null) {
                $result = 1;
                if ($failure !== $reportedFailure) {
                    $this->reportFailure($failure);
                }
            }
        }

        return $result;
    }

    private function reportFailure(Throwable $failure): void
    {
        if ($this->failureHandler === null) {
            return;
        }
        try {
            ($this->failureHandler)($failure);
        } catch (Throwable) {
            // Failure reporting must not prevent runtime cleanup.
        }
    }
}
