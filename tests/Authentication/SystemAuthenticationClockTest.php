<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Authentication;

use Bedriox\Server\Authentication\SystemAuthenticationClock;
use PHPUnit\Framework\TestCase;

final class SystemAuthenticationClockTest extends TestCase
{
    public function testReturnsCurrentUnixTime(): void
    {
        $before = time();
        $actual = (new SystemAuthenticationClock())->nowEpochSeconds();
        $after = time();

        self::assertGreaterThanOrEqual($before, $actual);
        self::assertLessThanOrEqual($after, $actual);
    }
}
