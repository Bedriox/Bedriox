<?php

declare(strict_types=1);

namespace Bedriox\Server\Observability;

use Closure;
use Throwable;

final class CrashHandler
{
    private ?string $reserve;
    private bool $reported = false;

    /** @param Closure(): CrashContext $contextProvider */
    public function __construct(
        private readonly CrashReporter $reporter,
        private readonly ServerLogger $logger,
        private readonly Closure $contextProvider,
        int $reserveBytes = 65_536,
    ) {
        $this->reserve = str_repeat('R', $reserveBytes);
    }

    public function install(): void
    {
        set_exception_handler(function (Throwable $failure): void {
            $this->capture($failure);
        });
        register_shutdown_function(function (): void {
            $fatal = error_get_last();
            if ($fatal === null || !in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                return;
            }
            if ($this->reported) {
                return;
            }
            $this->reported = true;
            $this->releaseReserve();
            try {
                $path = $this->reporter->reportFatal($fatal, ($this->contextProvider)());
                $this->logger->critical('Crash report written to ' . $path);
            } catch (Throwable) {
                $this->logger->critical('Bedriox encountered a fatal error and could not write a crash report.');
            }
        });
    }

    public function capture(Throwable $failure): ?string
    {
        if ($this->reported) {
            return null;
        }
        $this->reported = true;
        $this->releaseReserve();
        try {
            $path = $this->reporter->report($failure, ($this->contextProvider)());
            $this->logger->critical('Crash report written to ' . $path);
            return $path;
        } catch (Throwable) {
            $this->logger->critical('Bedriox encountered an unexpected failure and could not write a crash report.');
            return null;
        }
    }

    private function releaseReserve(): void
    {
        if ($this->reserve !== null) {
            $this->reserve = null;
        }
    }
}
