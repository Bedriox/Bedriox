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

use Bedriox\Tools\CiWorkflowValidator;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tools/CiWorkflowValidator.php';

final class CiWorkflowValidatorTest extends TestCase
{
    public function testRepositoryWorkflowDerivesEveryComponentRefFromManifest(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/ci.yml');
        self::assertIsString($workflow);
        self::assertSame([], CiWorkflowValidator::validate($workflow));
    }

    public function testLiteralComponentRefAndMissingDerivedRefAreRejected(): void
    {
        $workflow = self::workflow();
        $workflow = str_replace(
            'ref: ${{ steps.component-pins.outputs.raknet_commit }}',
            'ref: 0123456789abcdef0123456789abcdef01234567',
            $workflow,
        );

        $errors = CiWorkflowValidator::validate($workflow);
        self::assertStringContainsString('raknet', implode("\n", $errors));
        self::assertStringContainsString('literal commit hashes', implode("\n", $errors));
    }

    public function testPinExportMustPrecedePrivateCheckouts(): void
    {
        $workflow = self::workflow();
        $workflow = str_replace(
            "      - run: php tools/export-component-pins.php\n        id: component-pins\n",
            '',
            $workflow,
        );
        $workflow .= "\n      - run: php tools/export-component-pins.php\n        id: component-pins\n";

        self::assertStringContainsString(
            'before checking out private components',
            implode("\n", CiWorkflowValidator::validate($workflow)),
        );
    }

    public function testEveryPrivateCheckoutRequiresTheComponentToken(): void
    {
        $workflow = str_replace(
            'token: ${{ secrets.BEDRIOX_COMPONENTS_TOKEN }}',
            'token: ${{ github.token }}',
            self::workflow(),
        );

        self::assertStringContainsString(
            'private component token',
            implode("\n", CiWorkflowValidator::validate($workflow)),
        );
    }

    private static function workflow(): string
    {
        return <<<'YAML'
steps:
      - run: php tools/export-component-pins.php
        id: component-pins
      - uses: actions/checkout@example
        with:
          repository: Bedriox/Protocol
          ref: ${{ steps.component-pins.outputs.protocol_commit }}
          token: ${{ secrets.BEDRIOX_COMPONENTS_TOKEN }}
      - uses: actions/checkout@example
        with:
          repository: Bedriox/RakNet
          ref: ${{ steps.component-pins.outputs.raknet_commit }}
          token: ${{ secrets.BEDRIOX_COMPONENTS_TOKEN }}
      - uses: actions/checkout@example
        with:
          repository: Bedriox/Data
          ref: ${{ steps.component-pins.outputs.data_commit }}
          token: ${{ secrets.BEDRIOX_COMPONENTS_TOKEN }}
YAML;
    }
}
