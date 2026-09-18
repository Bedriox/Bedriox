<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Tools;

use Bedriox\Tools\ComponentPinExporter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tools/ComponentPinExporter.php';

final class ComponentPinExporterTest extends TestCase
{
    private const string COMMIT = '0123456789abcdef0123456789abcdef01234567';

    public function testExportsOnlyNamedImmutableComponentCommits(): void
    {
        self::assertSame([
            'protocol_commit' => self::COMMIT,
            'raknet_commit' => self::COMMIT,
            'data_commit' => self::COMMIT,
        ], ComponentPinExporter::export(self::manifest()));
    }

    /** @param array<string, mixed> $manifest */
    #[DataProvider('invalidManifestProvider')]
    public function testRejectsManifestThatCannotSafelyDriveCi(array $manifest): void
    {
        $this->expectException(InvalidArgumentException::class);
        ComponentPinExporter::export($manifest);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidManifestProvider(): iterable
    {
        $manifest = self::manifest();
        $manifest['schema'] = 2;
        yield 'wrong schema' => [$manifest];

        $manifest = self::manifest();
        unset($manifest['components']['data']);
        yield 'missing component' => [$manifest];

        $manifest = self::manifest();
        $manifest['components']['extra'] = [];
        yield 'extra component' => [$manifest];

        $manifest = self::manifest();
        $manifest['components']['protocol']['package'] = 'bedriox/raknet';
        yield 'wrong package' => [$manifest];

        $manifest = self::manifest();
        $manifest['components']['raknet']['commit'] = 'main';
        yield 'mutable ref' => [$manifest];

        $manifest = self::manifest();
        $manifest['components']['data']['commit'] = strtoupper(self::COMMIT);
        yield 'uppercase commit' => [$manifest];
    }

    /**
     * @return array{
     *   schema: int,
     *   components: array{
     *     protocol: array{package: string, commit: string},
     *     raknet: array{package: string, commit: string},
     *     data: array{package: string, commit: string}
     *   }
     * }
     */
    private static function manifest(): array
    {
        return [
            'schema' => 1,
            'components' => [
                'protocol' => ['package' => 'bedriox/protocol', 'commit' => self::COMMIT],
                'raknet' => ['package' => 'bedriox/raknet', 'commit' => self::COMMIT],
                'data' => ['package' => 'bedriox/data', 'commit' => self::COMMIT],
            ],
        ];
    }
}
