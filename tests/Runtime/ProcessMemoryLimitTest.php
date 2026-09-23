<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Server\Runtime\ProcessMemoryLimit;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProcessMemoryLimitTest extends TestCase
{
    public function testParsesSupportedDecimalBinaryAndUnlimitedValues(): void
    {
        self::assertSame(500_000_000, ProcessMemoryLimit::parse('500MB'));
        self::assertSame(2_000_000_000, ProcessMemoryLimit::parse('2GB'));
        self::assertSame(536_870_912, ProcessMemoryLimit::parse('512MiB'));
        self::assertSame(2_147_483_648, ProcessMemoryLimit::parse('2GiB'));
        self::assertSame(500_000_000, ProcessMemoryLimit::parse('500mb'));
        self::assertSame(536_870_912, ProcessMemoryLimit::parse('512mIb'));
        self::assertSame(128_000_000, ProcessMemoryLimit::parse('128MB'));
        self::assertSame(0, ProcessMemoryLimit::parse('0'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidValues(): iterable
    {
        yield 'empty' => [''];
        yield 'negative' => ['-1'];
        yield 'bare bytes' => ['500'];
        yield 'php shorthand' => ['500M'];
        yield 'fractional' => ['0.5GB'];
        yield 'leading zero' => ['0500MB'];
        yield 'below minimum' => ['127MB'];
        yield 'above maximum' => ['65GiB'];
    }

    #[DataProvider('invalidValues')]
    public function testRejectsMalformedOrOutOfRangeValues(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProcessMemoryLimit::parse($value);
    }

    public function testAppliesExactBytesAndVerifiesTheEffectiveLimit(): void
    {
        $effective = '-1';
        $limit = new ProcessMemoryLimit(
            static function (string $name, string $value) use (&$effective): string {
                self::assertSame('memory_limit', $name);
                $previous = $effective;
                $effective = $value;

                return $previous;
            },
            static function (string $name) use (&$effective): string {
                self::assertSame('memory_limit', $name);

                return $effective;
            },
        );

        $limit->apply(500_000_000);
        self::assertSame('500000000', $effective);
        $limit->apply(0);
        self::assertSame('-1', $effective);
    }

    public function testFailsWhenTheRuntimeRejectsOrDoesNotApplyTheLimit(): void
    {
        $rejected = new ProcessMemoryLimit(
            static fn(string $name, string $value): false => false,
            static fn(string $name): string => '-1',
        );
        try {
            $rejected->apply(500_000_000);
            self::fail('Rejected memory limit was accepted.');
        } catch (RuntimeException) {
        }

        $unchanged = new ProcessMemoryLimit(
            static fn(string $name, string $value): string => '-1',
            static fn(string $name): string => '-1',
        );
        $this->expectException(RuntimeException::class);
        $unchanged->apply(500_000_000);
    }
}
