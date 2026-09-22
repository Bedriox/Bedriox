<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

use Bedriox\Api\Inventory\ItemStack as ApiItemStack;
use Bedriox\Api\Server;
use Bedriox\Api\World\Block;
use Bedriox\Api\World\BlockPosition as ApiBlockPosition;
use Bedriox\Api\World\Position as ApiPosition;
use Bedriox\Api\World\World as ApiWorld;
use Bedriox\Server\Gameplay\Block\BlockCatalog;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Bedriox\Server\World\Block\BlockStateRegistry;
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
        private ?ItemCatalog $itemCatalog = null,
        private ?BlockCatalog $blockCatalog = null,
        private ?BlockStateRegistry $blockStateRegistry = null,
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
            function (string $identity, float $amount): void {
                $this->requireQueued($this->simulation->enqueuePluginDamage($identity, $amount));
            },
            function (string $identity, \Bedriox\Api\Player\GameMode $gameMode): void {
                $this->requireQueued($this->simulation->enqueueGameMode($identity, $gameMode));
            },
            function (string $identity, ApiItemStack $stack): void {
                $this->requireQueued($this->simulation->enqueueGiveItem(
                    $identity,
                    $stack->identifier,
                    $stack->count,
                    $stack->damage,
                    $stack->nbt,
                ));
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
        $identifier = $this->blockCatalog !== null && $this->blockStateRegistry !== null
            ? $this->blockCatalog->typeForInternalId($state, $this->blockStateRegistry)->identifier()
            : match ($state->value) {
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
        if ($this->itemCatalog !== null) {
            $type = $this->itemCatalog->type($stack->identifier);
            $placed = $type->placedBlockState === null || $this->blockStateRegistry === null
                ? null
                : $this->blockStateRegistry->internalId($type->placedBlockState);

            return new InventoryStack($stack->identifier, $stack->count, 1, $placed, $stack->damage, $stack->nbt);
        }
        if ($stack->identifier !== 'minecraft:grass_block') {
            throw new InvalidArgumentException('The inventory stack is outside the current gameplay catalog.');
        }

        return new InventoryStack($stack->identifier, $stack->count, 1, $this->palette->grassBlock, $stack->damage, $stack->nbt);
    }

    private function requireQueued(bool $queued): void
    {
        if (!$queued) {
            throw new OverflowException('The authoritative plugin action queue rejected the request.');
        }
    }
}
