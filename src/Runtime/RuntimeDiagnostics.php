<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Closure;
use Throwable;

/** Emits bounded, structured operational events without accepting packet or credential payloads. */
final readonly class RuntimeDiagnostics
{
    private const int MAXIMUM_EVENT_BYTES = 64;
    private const int MAXIMUM_FIELD_BYTES = 128;
    private const int MAXIMUM_FIELDS = 12;

    /** @param Closure(string): void $writer */
    public function __construct(
        private Closure $writer,
        private bool $protocolTrace = true,
    ) {}

    public static function disabled(): self
    {
        return new self(static function (string $line): void {});
    }

    /** @param array<string, bool|int|string|null> $fields */
    public function record(string $event, array $fields = []): void
    {
        if (!$this->protocolTrace && str_ends_with($event, '.protocol_trace')) {
            return;
        }
        if (strlen($event) > self::MAXIMUM_EVENT_BYTES || preg_match('/\A[a-z0-9]+(?:[._-][a-z0-9]+)*\z/D', $event) !== 1) {
            return;
        }
        $safe = ['event' => $event];
        $count = 0;
        foreach ($fields as $name => $value) {
            if (++$count > self::MAXIMUM_FIELDS
                || preg_match('/\A[a-z][a-z0-9_]{0,31}\z/D', $name) !== 1) {
                return;
            }
            $safe[$name] = is_string($value) && strlen($value) > self::MAXIMUM_FIELD_BYTES
                ? substr($value, 0, self::MAXIMUM_FIELD_BYTES)
                : $value;
        }
        try {
            $encoded = json_encode($safe, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            ($this->writer)('[bedriox] ' . $encoded . PHP_EOL);
        } catch (Throwable) {
            // Diagnostics must never change runtime behavior.
        }
    }
}
