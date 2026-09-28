<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests;

use Bedriox\Protocol\ProtocolVersion;
use Bedriox\Server\Bedriox;
use Bedriox\Server\BuildInfo;
use Bedriox\Server\Plugin\PluginPackageLoader;
use PHPUnit\Framework\TestCase;

final class BuildInfoTest extends TestCase
{
    public function testCurrentBuildUsesOwningVersionAuthorities(): void
    {
        $build = BuildInfo::current();

        self::assertSame(Bedriox::NAME, $build->productName);
        self::assertSame(Bedriox::VERSION, $build->serverVersion);
        self::assertSame(ProtocolVersion::GAME_VERSION, $build->gameVersion);
        self::assertSame(ProtocolVersion::CURRENT, $build->protocolVersion);
        self::assertSame(PluginPackageLoader::API_VERSION, $build->pluginApiVersion);
        self::assertSame(PHP_VERSION, $build->phpVersion);
        self::assertSame('Bedriox 0.3.0-alpha.1', $build->displayName());
    }

    public function testPublicSummaryContainsNoDuplicatedVersionSources(): void
    {
        self::assertSame([
            'Bedriox 0.3.0-alpha.1',
            'Minecraft: Bedrock 1.26.50',
            'Protocol: 2193',
            'PHP: ' . PHP_VERSION,
            'Plugin API: 0.3.0',
        ], BuildInfo::current()->publicSummary());
    }
}
