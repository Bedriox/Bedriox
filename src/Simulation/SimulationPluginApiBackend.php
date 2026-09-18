<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

use Bedriox\Api\Inventory\ItemStack as ApiItemStack;
use Bedriox\Api\Server;
use Bedriox\Api\World\Block;
use Bedriox\Api\World\BlockPosition as ApiBlockPosition;
use Bedriox\Api\World\Position as ApiPosition;
use Bedriox\Api\World\World as ApiWorld;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\World;
use InvalidArgumentException;
use OverflowException;

/** @internal Owns the only public-API projection into the authoritative simulation. */
final readonly class SimulationPluginApiBackend
{
    public function __construct(
        private WorldSimulation $simulation,
        private World $world,
        private FixedFlatBlockPalette $palette,
    ) {}

    public function serverFor(
        string $plugin,
        PluginRuntimeControl $plugins,
        PluginActionBuffer $actions,
    ): Server {
        return new SimulationPluginServer(
            $plugin,
            $plugins,
            $actions,
            $this->worldView(...),
            $this->simulation->pluginPlayers(...),
            $this->simulation->pluginPlayer(...),
            $this->blockView(...),
            function (string $identity, string $message): void {
                $this->requireQueued($this->simulation->enqueuePluginMessage($identity, $message));
            },
            function (string $identity, ApiPosition $position): void {
                $this->requireQueued($this->simulation->enqueuePluginTeleport(
                    $identity,
                    new Position($position->x, $position->y, $position->z),
                ));
            },
            function (ApiBlockPosition $position, string $identifier) use ($plugin): void {
                $this->requireQueued($this->simulation->enqueuePluginBlock(
                    $plugin,
                    new BlockPosition($position->x, $position->y, $position->z),
                    $identifier,
                ));
            },
            function (string $identity, int $slot, ?ApiItemStack $stack): void {
                $internal = $stack === null ? null : $this->inventoryStack($stack);
                $this->requireQueued($this->simulation->enqueuePluginInventorySlot($identity, $slot, $internal));
            },
        );
    }

    private function worldView(): ApiWorld
    {
        $spawn = $this->world->spawn();

        return new ApiWorld(
            $this->world->metadata->name,
            new ApiPosition($spawn->x, $spawn->y, $spawn->z),
        );
    }

    private function blockView(ApiBlockPosition $position): Block
    {
        $state = $this->world->blockStateAt($position->x, $position->y, $position->z);
        $identifier = match ($state->value) {
            $this->palette->air->value => 'minecraft:air',
            $this->palette->bedrock->value => 'minecraft:bedrock',
            $this->palette->dirt->value => 'minecraft:dirt',
            $this->palette->grassBlock->value => 'minecraft:grass_block',
            default => throw new InvalidArgumentException('Block state is not exposed by the current plugin API.'),
        };

        return new Block($position, $identifier);
    }

    private function inventoryStack(ApiItemStack $stack): InventoryStack
    {
        if ($stack->identifier !== 'minecraft:grass_block') {
            throw new InvalidArgumentException('The current plugin API exposes only minecraft:grass_block inventory stacks.');
        }

        return new InventoryStack($stack->identifier, $stack->count, 1, $this->palette->grassBlock);
    }

    private function requireQueued(bool $queued): void
    {
        if (!$queued) {
            throw new OverflowException('The authoritative plugin action queue rejected the request.');
        }
    }
}
