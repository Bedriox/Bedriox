<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Tools;

use Bedriox\Tools\IntegrationManifestValidator;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tools/IntegrationManifestValidator.php';

final class IntegrationManifestValidatorTest extends TestCase
{
    private const string PROTOCOL_COMMIT = 'fb0a0da40952a4f3b5e321f0b4e3df86bb38e920';
    private const string RAKNET_COMMIT = 'aa84f71169f6401029b070af35ecb3f85b7fcac4';
    private const string DATA_COMMIT = '718037f5dabd25d0ce7d2e6a2517119725959c95';

    public function testExactPrivateWorkspaceFixtureIsAccepted(): void
    {
        self::assertSame([], IntegrationManifestValidator::validate(
            self::manifest(),
            self::composer(),
            self::lock(),
            '0.1.0-alpha.1',
            ['protocol' => self::PROTOCOL_COMMIT, 'raknet' => self::RAKNET_COMMIT, 'data' => self::DATA_COMMIT],
        ));
    }

    public function testRepositoryTypePathSymlinkAndVersionMappingAreExact(): void
    {
        $composer = self::composer();
        $composer['repositories'][0]['type'] = 'vcs';
        self::assertInvalid($composer, self::lock(), 'exact approved sibling path entry');

        $composer = self::composer();
        $composer['repositories'][0]['url'] = '../../outside/Protocol';
        self::assertInvalid($composer, self::lock(), 'exact approved sibling path entry');

        $composer = self::composer();
        $composer['repositories'][0]['options']['symlink'] = true;
        self::assertInvalid($composer, self::lock(), 'exact approved sibling path entry');

        $composer = self::composer();
        $composer['repositories'][0]['options']['versions']['bedriox/protocol'] = 'dev-main';
        self::assertInvalid($composer, self::lock(), 'exact approved sibling path entry');

        $composer = self::composer();
        $composer['repositories'][] = $composer['repositories'][0];
        self::assertInvalid($composer, self::lock(), 'exactly the approved component repositories');
    }

    public function testLockedPathTypeUrlVersionReferenceAndSourceAreExact(): void
    {
        $lock = self::lock();
        $lock['packages'][0]['dist']['type'] = 'zip';
        self::assertInvalid(self::composer(), $lock, 'exact approved path and reference');

        $lock = self::lock();
        $lock['packages'][0]['dist']['url'] = '../../outside/Protocol';
        self::assertInvalid(self::composer(), $lock, 'exact approved path and reference');

        $lock = self::lock();
        $lock['packages'][0]['version'] = 'dev-main';
        self::assertInvalid(self::composer(), $lock, 'lock version');

        $lock = self::lock();
        $lock['packages'][0]['dist']['reference'] = str_repeat('0', 40);
        self::assertInvalid(self::composer(), $lock, 'exact approved path and reference');

        $lock = self::lock();
        $lock['packages'][0]['source'] = ['type' => 'git', 'url' => 'https://example.invalid/repository'];
        self::assertInvalid(self::composer(), $lock, 'must not declare a source override');
    }

    public function testComponentIdentityAndUnqualifiedSupportClaimsAreRejected(): void
    {
        $manifest = self::manifest();
        $manifest['components']['protocol']['package'] = 'bedriox/raknet';
        self::assertInvalid(self::composer(), self::lock(), 'must map to bedriox/protocol', $manifest);

        $manifest = self::manifest();
        $manifest['bedrock']['clientVersions'] = ['1.26.52'];
        self::assertInvalid(self::composer(), self::lock(), 'must match the qualified client and protocol set', $manifest);

        $manifest = self::manifest();
        $manifest['bedrock']['networkProtocols'] = [2194];
        self::assertInvalid(self::composer(), self::lock(), 'must match the qualified client and protocol set', $manifest);

        $manifest = self::manifest();
        $manifest['components']['unexpected'] = null;
        self::assertInvalid(self::composer(), self::lock(), 'component set differs', $manifest);
    }

    /**
     * @param array<string, mixed> $composer
     * @param array<string, mixed> $lock
     * @param array<string, mixed>|null $manifest
     */
    private static function assertInvalid(array $composer, array $lock, string $message, ?array $manifest = null): void
    {
        $errors = IntegrationManifestValidator::validate(
            $manifest ?? self::manifest(),
            $composer,
            $lock,
            '0.1.0-alpha.1',
            ['protocol' => self::PROTOCOL_COMMIT, 'raknet' => self::RAKNET_COMMIT, 'data' => self::DATA_COMMIT],
        );
        self::assertNotSame([], $errors);
        self::assertStringContainsString($message, implode("\n", $errors));
    }

    /**
     * @return array{
     *   schema: int,
     *   status: string,
     *   server: string,
     *   php: string,
     *   bedrock: array{clientVersions: list<string>, networkProtocols: list<int>},
     *   components: array<string, array{package: string, version: string, commit: string}|null>
     * }
     */
    private static function manifest(): array
    {
        return [
            'schema' => 1,
            'status' => 'alpha',
            'server' => '0.1.0-alpha.1',
            'php' => '^8.4',
            'bedrock' => ['clientVersions' => ['1.26.50', '1.26.51'], 'networkProtocols' => [2193]],
            'components' => [
                'protocol' => ['package' => 'bedriox/protocol', 'version' => '0.1.0-alpha.1', 'commit' => self::PROTOCOL_COMMIT],
                'raknet' => ['package' => 'bedriox/raknet', 'version' => '0.1.0-alpha.1', 'commit' => self::RAKNET_COMMIT],
                'data' => ['package' => 'bedriox/data', 'version' => '0.1.0-alpha.1', 'commit' => self::DATA_COMMIT],
            ],
        ];
    }

    /**
     * @return array{
     *   repositories: list<array{type: string, url: string, options: array{symlink: bool, versions: array<string, string>}}>,
     *   require: array<string, string>
     * }
     */
    private static function composer(): array
    {
        return [
            'repositories' => [
                ['type' => 'path', 'url' => '../Protocol', 'options' => ['symlink' => false, 'versions' => ['bedriox/protocol' => '0.1.0-alpha.1']]],
                ['type' => 'path', 'url' => '../RakNet', 'options' => ['symlink' => false, 'versions' => ['bedriox/raknet' => '0.1.0-alpha.1']]],
                ['type' => 'path', 'url' => '../Data', 'options' => ['symlink' => false, 'versions' => ['bedriox/data' => '0.1.0-alpha.1']]],
            ],
            'require' => [
                'php' => '^8.4',
                'bedriox/protocol' => '0.1.0-alpha.1',
                'bedriox/raknet' => '0.1.0-alpha.1',
                'bedriox/data' => '0.1.0-alpha.1',
            ],
        ];
    }

    /**
     * @return array{
     *   packages: list<array{
     *     name: string,
     *     version: string,
     *     dist: array{type: string, url: string, reference: string},
     *     source?: array{type: string, url: string}
     *   }>
     * }
     */
    private static function lock(): array
    {
        return ['packages' => [
            ['name' => 'bedriox/protocol', 'version' => '0.1.0-alpha.1', 'dist' => ['type' => 'path', 'url' => '../Protocol', 'reference' => self::PROTOCOL_COMMIT]],
            ['name' => 'bedriox/raknet', 'version' => '0.1.0-alpha.1', 'dist' => ['type' => 'path', 'url' => '../RakNet', 'reference' => self::RAKNET_COMMIT]],
            ['name' => 'bedriox/data', 'version' => '0.1.0-alpha.1', 'dist' => ['type' => 'path', 'url' => '../Data', 'reference' => self::DATA_COMMIT]],
        ]];
    }
}
