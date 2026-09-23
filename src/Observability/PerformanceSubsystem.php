<?php

declare(strict_types=1);

namespace Bedriox\Server\Observability;

/** Fixed low-cardinality subsystem names accepted by the performance monitor. */
final class PerformanceSubsystem
{
    public const TRANSPORT = 'transport';
    public const SESSIONS = 'sessions';
    public const PLUGINS = 'plugins';
    public const WORLD = 'world';
    public const CHUNKS = 'chunks';
    public const PERSISTENCE = 'persistence';
    public const WORKERS = 'workers';
    public const NETWORK_OUTBOUND = 'network_outbound';

    /** @var list<string> */
    public const ALL = [
        self::TRANSPORT,
        self::SESSIONS,
        self::PLUGINS,
        self::WORLD,
        self::CHUNKS,
        self::PERSISTENCE,
        self::WORKERS,
        self::NETWORK_OUTBOUND,
    ];

    private function __construct() {}
}
