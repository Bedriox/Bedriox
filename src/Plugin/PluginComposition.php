<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin;

use Bedriox\Server\Runtime\BootstrappedServer;

/** @internal Mutable startup hand-off; never exposed to plugins. */
final class PluginComposition
{
    public ?BootstrappedServer $server = null;
    public ?PluginHost $host = null;
    public ?PluginItemBehaviorRegistrar $itemBehaviors = null;
    public ?PluginRecipeRegistrar $recipes = null;
}
