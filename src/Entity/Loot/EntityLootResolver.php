<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace Bedriox\Server\Entity\Loot;

use Bedriox\Api\Entity\VanillaEntityType;
use InvalidArgumentException;

final class EntityLootResolver
{
    public const int MAX_TABLES = 512;

    /** @var array<string, LootTable> */
    private array $tables = [];

    /**
     * @param array<string, LootTable> $tables
     */
    public function __construct(
        private readonly LootRandomSource $random,
        private readonly LootOutputNormalizer $normalizer,
        array $tables = [],
    ) {
        foreach ($tables as $identifier => $table) {
            $this->register($identifier, $table);
        }
    }

    public static function vanilla(LootItemRegistry $items, ?LootRandomSource $random = null): self
    {
        return new self(
            $random ?? new SystemLootRandomSource(),
            new LootOutputNormalizer($items),
            [
                VanillaEntityType::ZOMBIE->value => new ZombieLootTable(),
                VanillaEntityType::COW->value => new CowLootTable(),
                VanillaEntityType::CHICKEN->value => new ChickenLootTable(),
                VanillaEntityType::PIG->value => new PigLootTable(),
                VanillaEntityType::RABBIT->value => new RabbitLootTable(),
                VanillaEntityType::SHEEP->value => new SheepLootTable(),
                VanillaEntityType::SKELETON->value => new SkeletonLootTable(),
                VanillaEntityType::HUSK->value => new HuskLootTable(),
                VanillaEntityType::ZOMBIE_VILLAGER->value => new ZombieVillagerLootTable(),
                VanillaEntityType::STRAY->value => new StrayLootTable(),
                VanillaEntityType::BOGGED->value => new BoggedLootTable(),
                VanillaEntityType::PARCHED->value => new ParchedLootTable(),
                VanillaEntityType::WITHER_SKELETON->value => new WitherSkeletonLootTable(),
                VanillaEntityType::SPIDER->value => new SpiderLootTable(),
                VanillaEntityType::CAVE_SPIDER->value => new SpiderLootTable(),
                VanillaEntityType::CREEPER->value => new CreeperLootTable(),
                VanillaEntityType::SLIME->value => new SlimeLootTable(),
                VanillaEntityType::MAGMA_CUBE->value => new MagmaCubeLootTable(),
                VanillaEntityType::BLAZE->value => new NetherMobLootTable(VanillaEntityType::BLAZE),
                VanillaEntityType::GHAST->value => new NetherMobLootTable(VanillaEntityType::GHAST),
                VanillaEntityType::HAPPY_GHAST->value => new EmptyLootTable(),
                VanillaEntityType::HOGLIN->value => new NetherMobLootTable(VanillaEntityType::HOGLIN),
                VanillaEntityType::PIGLIN->value => new EmptyLootTable(),
                VanillaEntityType::PIGLIN_BRUTE->value => new EmptyLootTable(),
                VanillaEntityType::STRIDER->value => new NetherMobLootTable(VanillaEntityType::STRIDER),
                VanillaEntityType::ZOGLIN->value => new NetherMobLootTable(VanillaEntityType::ZOGLIN),
                VanillaEntityType::ZOMBIFIED_PIGLIN->value => new NetherMobLootTable(VanillaEntityType::ZOMBIFIED_PIGLIN),
                VanillaEntityType::ENDERMAN->value => new EndermanLootTable(),
                VanillaEntityType::WITCH->value => new WitchLootTable(),
                VanillaEntityType::COD->value => new AquaticLootTable('minecraft:cod', 1, 1, 'minecraft:cooked_cod'),
                VanillaEntityType::SALMON->value => new AquaticLootTable('minecraft:salmon', 1, 1, 'minecraft:cooked_salmon'),
                VanillaEntityType::TROPICAL_FISH->value => new AquaticLootTable('minecraft:tropical_fish', 1, 1),
                VanillaEntityType::PUFFERFISH->value => new AquaticLootTable('minecraft:pufferfish', 1, 1),
                VanillaEntityType::SQUID->value => new AquaticLootTable('minecraft:ink_sac', 1, 3),
                VanillaEntityType::GLOW_SQUID->value => new AquaticLootTable('minecraft:glow_ink_sac', 1, 3),
                VanillaEntityType::DOLPHIN->value => new AquaticLootTable('minecraft:cod', 0, 1),
                VanillaEntityType::TURTLE->value => new AquaticLootTable('minecraft:seagrass', 0, 2),
                VanillaEntityType::AXOLOTL->value => new AquaticLootTable(null, 0, 0),
                VanillaEntityType::DROWNED->value => new AquaticLootTable('minecraft:rotten_flesh', 0, 2),
                VanillaEntityType::GUARDIAN->value => new AquaticLootTable('minecraft:prismarine_shard', 0, 2),
                VanillaEntityType::HORSE->value => new AquaticLootTable('minecraft:leather', 0, 2),
                VanillaEntityType::DONKEY->value => new AquaticLootTable('minecraft:leather', 0, 2),
                VanillaEntityType::MULE->value => new AquaticLootTable('minecraft:leather', 0, 2),
                VanillaEntityType::CAMEL->value => new AquaticLootTable('minecraft:leather', 0, 2),
                VanillaEntityType::LLAMA->value => new AquaticLootTable('minecraft:leather', 0, 2),
                VanillaEntityType::TRADER_LLAMA->value => new AquaticLootTable('minecraft:leather', 0, 2),
                VanillaEntityType::SKELETON_HORSE->value => new AquaticLootTable('minecraft:bone', 0, 2),
                VanillaEntityType::ZOMBIE_HORSE->value => new AquaticLootTable('minecraft:rotten_flesh', 0, 2),
                VanillaEntityType::PANDA->value => new AquaticLootTable('minecraft:bamboo', 0, 2),
                VanillaEntityType::POLAR_BEAR->value => new AquaticLootTable('minecraft:cod', 0, 2, 'minecraft:cooked_cod'),
                VanillaEntityType::MOOSHROOM->value => new CowLootTable(),
            ],
        );
    }

    public function register(string $entityIdentifier, LootTable $table, bool $replace = false): void
    {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $entityIdentifier) !== 1
            || strlen($entityIdentifier) > 128) {
            throw new InvalidArgumentException('Loot table entity identifier must be canonical and bounded.');
        }
        if (isset($this->tables[$entityIdentifier]) && !$replace) {
            throw new InvalidArgumentException('A loot table is already registered for this entity type.');
        }
        if (!isset($this->tables[$entityIdentifier]) && count($this->tables) >= self::MAX_TABLES) {
            throw new InvalidArgumentException('Loot table registry capacity is exhausted.');
        }
        $this->tables[$entityIdentifier] = $table;
    }

    public function prepare(LootContext $context): PreparedEntityLoot
    {
        return new PreparedEntityLoot(
            $this->tables[$context->entityType->identifier()] ?? new EmptyLootTable(),
            $context,
            $this->random,
            $this->normalizer,
        );
    }
}
