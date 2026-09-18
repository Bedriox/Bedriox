<?php

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
      - uses: actions/checkout@example
        with:
          repository: Bedriox/RakNet
          ref: ${{ steps.component-pins.outputs.raknet_commit }}
      - uses: actions/checkout@example
        with:
          repository: Bedriox/Data
          ref: ${{ steps.component-pins.outputs.data_commit }}
YAML;
    }
}
