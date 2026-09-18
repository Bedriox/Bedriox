<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin;

enum PluginLifecycleState
{
    case DISCOVERED;
    case VALIDATED;
    case LOADED;
    case ENABLED;
    case DISABLING;
    case DISABLED;
    case FAILED;
}
