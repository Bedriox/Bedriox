<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Closure;
use Throwable;

final readonly class RuntimeRunner
{
    public function __construct(
        private RuntimeDriver $runtime,
        private RuntimeSleeper $sleeper = new SystemRuntimeSleeper(),
        private ?RuntimeDiagnostics $diagnostics = null,
        private ?Closure $failureHandler = null,
    ) {}

    /** @param Closure(): bool $stopRequested */
    public function run(Closure $stopRequested): int
    {
        try {
            while (!$stopRequested()) {
                if (!$this->runtime->poll()) {
                    if ($this->runtime instanceof RuntimeFailureSource) {
                        $failure = $this->runtime->failure();
                        if ($failure !== null && $this->failureHandler !== null) {
                            try {
                                ($this->failureHandler)($failure);
                            } catch (Throwable) {
                                // Failure reporting must not prevent runtime cleanup.
                            }
                        }
                    }
                    return 1;
                }
                $this->sleeper->idle();
            }

            return 0;
        } catch (Throwable $exception) {
            ($this->diagnostics ?? RuntimeDiagnostics::disabled())->record('runtime.runner_failed', [
                'exception' => $exception::class,
            ]);
            if ($this->failureHandler !== null) {
                try {
                    ($this->failureHandler)($exception);
                } catch (Throwable) {
                    // Failure reporting must not prevent runtime cleanup.
                }
            }
            return 1;
        } finally {
            $this->runtime->close();
        }
    }
}
