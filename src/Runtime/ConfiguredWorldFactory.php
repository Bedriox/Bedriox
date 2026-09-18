<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Data\BedrockDataSet;

interface ConfiguredWorldFactory
{
    public function open(ServerConfig $config, BedrockDataSet $data): OpenedWorld;
}
