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

namespace Bedriox\Server\Tests\Tools;

use Bedriox\Tools\BoundedSplitValidator;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tools/BoundedSplitValidator.php';

final class BoundedSplitValidatorTest extends TestCase
{
    public function testUnboundedGlobalSplitIsRejected(): void
    {
        self::assertSame(
            ['fixture.php:2 explode() must specify an explicit split limit.'],
            BoundedSplitValidator::validateSource("<?php\nexplode('.', \$input);", 'fixture.php'),
        );
    }

    public function testBoundedAndStaticCallsAreAccepted(): void
    {
        self::assertSame([], BoundedSplitValidator::validateSource(
            "<?php\nexplode('.', helper(\$input, [1, 2]), 4);\nSound::explode();",
            'fixture.php',
        ));
    }
}
