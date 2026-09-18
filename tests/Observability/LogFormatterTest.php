<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Observability;

use Bedriox\Server\Observability\LogFormatter;
use Bedriox\Server\Observability\LogLevel;
use Bedriox\Server\Observability\LogRecord;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class LogFormatterTest extends TestCase
{
    public function testRendersTheOperatorFormatExactly(): void
    {
        $record = new LogRecord(
            new DateTimeImmutable('2026-09-17 21:42:10'),
            1,
            LogLevel::INFO,
            'Starting Bedriox',
        );

        self::assertSame(
            '[17-Sep-2026 21:42:10] Bedriox INFO > Starting Bedriox',
            (new LogFormatter())->format($record),
        );
    }

    public function testColorsNeverEnterPlainOutput(): void
    {
        $record = new LogRecord(new DateTimeImmutable(), 1, LogLevel::CRITICAL, 'Failure', 'ExamplePlugin');
        $formatter = new LogFormatter();

        self::assertStringNotContainsString("\033", $formatter->format($record));
        self::assertStringContainsString("\033", $formatter->format($record, true));
        self::assertStringContainsString('[ExamplePlugin] Failure', $formatter->format($record, true));
    }
}
