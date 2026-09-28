<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace Bedriox\Server\Tests\Authentication;

use Bedriox\Server\Authentication\SubjectIdentityUuid;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SubjectIdentityUuidTest extends TestCase
{
    public function testKnownRfc4122V5MappingIsStableAndDistinct(): void
    {
        self::assertSame(
            '026ee0e6-5a16-5f47-9275-231775016d13',
            SubjectIdentityUuid::fromSubject('retail-subject'),
        );
        self::assertNotSame(
            SubjectIdentityUuid::fromSubject('retail-subject'),
            SubjectIdentityUuid::fromSubject('another-subject'),
        );
    }

    public function testInvalidSubjectsAreRejected(): void
    {
        foreach (['', "\xff", str_repeat('x', 257)] as $subject) {
            try {
                SubjectIdentityUuid::fromSubject($subject);
                self::fail('Invalid subject was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
