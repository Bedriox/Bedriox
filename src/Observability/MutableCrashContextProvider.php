<?php

declare(strict_types=1);

namespace Bedriox\Server\Observability;

final class MutableCrashContextProvider implements CrashContextProvider, CrashContextPublisher
{
    private CrashContext $context;

    public function __construct()
    {
        $this->context = new CrashContext();
    }

    public function update(CrashContext $context): void
    {
        $this->context = $context;
    }

    public function publishRuntime(int $tick, array $players, ?CrashPlayer $involvedPlayer = null): void
    {
        $this->context = new CrashContext(
            $tick,
            $players,
            $involvedPlayer,
            $this->context->pluginAttribution,
        );
    }

    public function publishPlugin(?string $attribution): void
    {
        $this->context = new CrashContext(
            $this->context->tick,
            $this->context->players,
            $this->context->involvedPlayer,
            $attribution,
        );
    }

    public function current(): CrashContext
    {
        return $this->context;
    }
}
