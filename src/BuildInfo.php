<?php

declare(strict_types=1);

namespace Bedriox\Server;

use Bedriox\Protocol\ProtocolVersion;
use Bedriox\Server\Plugin\PluginPackageLoader;

final readonly class BuildInfo
{
    public function __construct(
        public string $productName,
        public string $serverVersion,
        public string $gameVersion,
        public int $protocolVersion,
        public string $pluginApiVersion,
        public string $phpVersion,
    ) {}

    public static function current(): self
    {
        return new self(
            Bedriox::NAME,
            Bedriox::VERSION,
            ProtocolVersion::GAME_VERSION,
            ProtocolVersion::CURRENT,
            PluginPackageLoader::API_VERSION,
            PHP_VERSION,
        );
    }

    public function displayName(): string
    {
        return $this->productName . ' ' . $this->serverVersion;
    }

    /** @return list<string> */
    public function publicSummary(): array
    {
        return [
            $this->displayName(),
            'Minecraft: Bedrock ' . $this->gameVersion,
            'Protocol: ' . $this->protocolVersion,
            'PHP: ' . $this->phpVersion,
            'Plugin API: ' . $this->pluginApiVersion,
        ];
    }
}
