<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Plugin;

use Bedriox\Server\Plugin\PluginDependencyResolver;
use Bedriox\Server\Plugin\PluginException;
use Bedriox\Server\Plugin\PluginManifest;
use Bedriox\Server\Plugin\PluginManifestParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PluginManifestParserTest extends TestCase
{
    public function testParsesCompleteBoundedManifest(): void
    {
        $manifest = (new PluginManifestParser())->parse($this->json());

        self::assertSame('ExamplePlugin', $manifest->name);
        self::assertSame('1.2.3', $manifest->version);
        self::assertSame(['CorePlugin'], $manifest->dependencies);
        self::assertSame('WORLD_READY', $manifest->loadPhase);
    }

    #[DataProvider('invalidManifestProvider')]
    public function testRejectsMalformedOrAmbiguousManifests(string $json): void
    {
        $this->expectException(PluginException::class);
        (new PluginManifestParser())->parse($json);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidManifestProvider(): iterable
    {
        yield 'invalid json' => ['{'];
        yield 'duplicate key' => [str_replace('"name":"ExamplePlugin"', '"name":"ExamplePlugin","name":"Other"', self::validJson())];
        yield 'unknown field' => [str_replace('"load":"WORLD_READY"', '"load":"WORLD_READY","extra":true', self::validJson())];
        yield 'self dependency' => [str_replace('["CorePlugin"]', '["ExamplePlugin"]', self::validJson())];
        yield 'namespace escape' => [str_replace('Bedriox\\\\ExamplePlugin\\\\Main', 'Foreign\\\\Main', self::validJson())];
        yield 'oversized' => [str_repeat(' ', 32769)];
    }

    public function testOrdersRequiredAndPresentSoftDependenciesDeterministically(): void
    {
        $core = $this->manifest('CorePlugin');
        $soft = $this->manifest('SoftPlugin');
        $feature = $this->manifest('FeaturePlugin', ['CorePlugin'], ['SoftPlugin']);

        $ordered = (new PluginDependencyResolver())->order([$feature, $soft, $core]);

        self::assertSame(['CorePlugin', 'SoftPlugin', 'FeaturePlugin'], array_map(
            static fn(PluginManifest $manifest): string => $manifest->name,
            $ordered,
        ));
    }

    public function testRejectsMissingDependencyAndCycle(): void
    {
        $resolver = new PluginDependencyResolver();
        try {
            $resolver->order([$this->manifest('FeaturePlugin', ['MissingPlugin'])]);
            self::fail('Missing dependency was accepted.');
        } catch (PluginException $exception) {
            self::assertStringContainsString('Missing dependency', $exception->getMessage());
        }

        $this->expectException(PluginException::class);
        $this->expectExceptionMessage('cycle');
        $resolver->order([
            $this->manifest('FirstPlugin', ['SecondPlugin']),
            $this->manifest('SecondPlugin', ['FirstPlugin']),
        ]);
    }

    private function json(): string
    {
        return self::validJson();
    }

    private static function validJson(): string
    {
        return '{"schema":1,"name":"ExamplePlugin","version":"1.2.3","api":"^0.3","main":"Bedriox\\\\ExamplePlugin\\\\Main","namespace":"Bedriox\\\\ExamplePlugin","authors":["Bedriox Team"],"dependencies":["CorePlugin"],"softDependencies":[],"load":"WORLD_READY"}';
    }

    /**
     * @param list<string> $dependencies
     * @param list<string> $softDependencies
     */
    private function manifest(string $name, array $dependencies = [], array $softDependencies = []): PluginManifest
    {
        return new PluginManifest(1, $name, '1.0.0', '^0.3', "Tests\\{$name}", 'Tests', [], $dependencies, $softDependencies, 'WORLD_READY');
    }
}
