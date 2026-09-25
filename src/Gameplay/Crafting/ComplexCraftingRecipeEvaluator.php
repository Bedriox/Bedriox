<?php

declare(strict_types=1);

namespace Bedriox\Server\Gameplay\Crafting;

use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Api\Nbt\Tag;
use Bedriox\Api\Nbt\TagType;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\World\Block\BlockStateRegistry;

/** Resolves crafting-grid recipes whose result depends on authoritative input state. */
final readonly class ComplexCraftingRecipeEvaluator
{
    public const string BANNER_ADD_PATTERN = 'd81aaeaf-e172-4440-9225-868df030d27b';
    public const string BANNER_DUPLICATE = 'b5c5d105-75a2-4076-af2b-923ea2bf4bf0';
    public const string FIREWORK_ROCKET = '00000000-0000-0000-0000-000000000002';
    public const string WRITTEN_BOOK_CLONE = 'd1ca6b84-338e-4f2f-9c6b-76cc8b4bd98d';
    public const string MAP_CLONE = '85939755-ba10-4d9d-a4cc-efb7a8e943c4';
    public const string DECORATED_POT = '685a742a-c42e-4a4e-88ea-5eb83fc98e5b';
    public const string MAP_EXTEND = 'd392b075-4ba1-40ae-8789-af868d56f6ce';
    public const string REPAIR_ITEM = '00000000-0000-0000-0000-000000000001';
    public const string BANNER_TO_SHIELD = '00000000-0000-0000-0000-0000000000c8';
    public const string MAP_UPGRADE = 'aecd2294-4b94-434b-8667-4499bb2c9327';
    public const string CARTOGRAPHY_MAP_CLONE = '442d85ed-8272-4543-a6f1-418f90ded05d';
    public const string CARTOGRAPHY_MAP_EXTEND = '8b36268c-1829-483c-a0f1-993b7156a8f2';
    public const string CARTOGRAPHY_MAP_LOCK = '602234e4-cac1-4353-8bb7-b1ebff70024b';
    public const string CARTOGRAPHY_MAP_UPGRADE = '98c84b38-1085-46bd-b1ce-dd38c159e6cc';

    /** @var array<string, true> */
    private array $potterySherds;

    /** @var array<string, int> */
    private const array DYE_COLORS = [
        'minecraft:black_dye' => 0,
        'minecraft:red_dye' => 1,
        'minecraft:green_dye' => 2,
        'minecraft:brown_dye' => 3,
        'minecraft:blue_dye' => 4,
        'minecraft:purple_dye' => 5,
        'minecraft:cyan_dye' => 6,
        'minecraft:light_gray_dye' => 7,
        'minecraft:gray_dye' => 8,
        'minecraft:pink_dye' => 9,
        'minecraft:lime_dye' => 10,
        'minecraft:yellow_dye' => 11,
        'minecraft:light_blue_dye' => 12,
        'minecraft:magenta_dye' => 13,
        'minecraft:orange_dye' => 14,
        'minecraft:white_dye' => 15,
    ];

    /** @var array<string, string> */
    private const array BANNER_PATTERN_ITEMS = [
        'minecraft:field_masoned_banner_pattern' => 'bri',
        'minecraft:bordure_indented_banner_pattern' => 'cbo',
        'minecraft:creeper_banner_pattern' => 'cre',
        'minecraft:flower_banner_pattern' => 'flo',
        'minecraft:skull_banner_pattern' => 'sku',
        'minecraft:mojang_banner_pattern' => 'moj',
        'minecraft:globe_banner_pattern' => 'glb',
        'minecraft:piglin_banner_pattern' => 'pig',
        'minecraft:flow_banner_pattern' => 'flw',
        'minecraft:guster_banner_pattern' => 'gus',
    ];

    /** @var array<string, list<string>> */
    private const array BANNER_GRID_PATTERNS = [
        'bo' => ['###', '# #', '###'],
        'mc' => ['   ', ' # ', '   '],
        'cr' => ['# #', ' # ', '# #'],
        'sc' => [' # ', '###', ' # '],
        'ld' => ['## ', '#  ', '   '],
        'rud' => [' ##', '  #', '   '],
        'lud' => ['   ', '#  ', '## '],
        'rd' => ['   ', '  #', ' ##'],
        'gra' => ['# #', ' # ', ' # '],
        'gru' => [' # ', ' # ', '# #'],
        'hh' => ['###', '###', '   '],
        'hhb' => ['   ', '###', '###'],
        'vh' => ['## ', '## ', '## '],
        'vhr' => [' ##', ' ##', ' ##'],
        'mr' => [' # ', '# #', ' # '],
        'ss' => ['# #', '# #', '# #'],
        'bl' => ['   ', '   ', '#  '],
        'br' => ['   ', '   ', '  #'],
        'tl' => ['#  ', '   ', '   '],
        'tr' => ['  #', '   ', '   '],
        'bs' => ['   ', '   ', '###'],
        'cs' => [' # ', ' # ', ' # '],
        'dls' => ['  #', ' # ', '#  '],
        'drs' => ['#  ', ' # ', '  #'],
        'ls' => ['#  ', '#  ', '#  '],
        'ms' => ['   ', '###', '   '],
        'rs' => ['  #', '  #', '  #'],
        'ts' => ['###', '   ', '   '],
        'bt' => ['   ', '   ', '# #'],
        'tt' => ['# #', '   ', '   '],
        'bts' => ['   ', '# #', ' # '],
        'tts' => [' # ', '# #', '   '],
    ];

    public function __construct(
        private ItemCatalog $items,
        private BlockStateRegistry $blocks,
    ) {
        $potterySherds = [];
        foreach ($items->all() as $item) {
            if (str_starts_with($item->identifier, 'minecraft:')
                && str_ends_with($item->identifier, '_pottery_sherd')) {
                $potterySherds[$item->identifier] = true;
            }
        }
        $this->potterySherds = $potterySherds;
    }

    public function match(string $uuid, CraftingGrid $grid, int $repetitions = 1): ?CraftingRecipeMatch
    {
        if ($repetitions < 1 || $repetitions > 255) {
            return null;
        }
        $uuid = strtolower($uuid);

        return match ($uuid) {
            self::BANNER_ADD_PATTERN => $this->bannerAddPattern($grid, $repetitions),
            self::BANNER_DUPLICATE => $this->bannerDuplicate($grid, $repetitions),
            self::FIREWORK_ROCKET => $this->fireworkRocket($grid, $repetitions),
            self::WRITTEN_BOOK_CLONE => $this->writtenBookClone($grid, $repetitions),
            self::MAP_CLONE => $this->mapClone($grid, $repetitions),
            self::DECORATED_POT => $this->decoratedPot($grid, $repetitions),
            self::MAP_EXTEND => $this->mapExtend($grid, $repetitions),
            self::REPAIR_ITEM => $this->repairItem($grid, $repetitions),
            self::BANNER_TO_SHIELD => $this->bannerToShield($grid, $repetitions),
            self::MAP_UPGRADE => $this->mapUpgrade($grid, $repetitions),
            self::CARTOGRAPHY_MAP_CLONE,
            self::CARTOGRAPHY_MAP_EXTEND,
            self::CARTOGRAPHY_MAP_LOCK,
            self::CARTOGRAPHY_MAP_UPGRADE => null,
            default => null,
        };
    }

    private function bannerAddPattern(CraftingGrid $grid, int $repetitions): ?CraftingRecipeMatch
    {
        $occupied = $grid->occupiedSlots();
        $bannerSlot = null;
        $banner = null;
        $dyeSlot = null;
        $dyeColor = null;
        $pattern = null;
        foreach ($occupied as $slot => $stack) {
            if ($stack->identifier === 'minecraft:banner') {
                if ($banner !== null) {
                    return null;
                }
                $bannerSlot = $slot;
                $banner = $stack;
                continue;
            }
            if (isset(self::DYE_COLORS[$stack->identifier])) {
                if ($dyeColor !== null) {
                    return null;
                }
                $dyeSlot = $slot;
                $dyeColor = self::DYE_COLORS[$stack->identifier];
                continue;
            }
            if (isset(self::BANNER_PATTERN_ITEMS[$stack->identifier])) {
                if ($pattern !== null) {
                    return null;
                }
                $pattern = self::BANNER_PATTERN_ITEMS[$stack->identifier];
                continue;
            }
            return null;
        }
        if ($bannerSlot === null || $banner === null || $banner->auxValue > 15
            || $dyeSlot === null || $dyeColor === null) {
            return null;
        }
        $patterns = self::bannerPatterns($banner);
        if ($patterns === null || count($patterns) >= 6) {
            return null;
        }
        if ($pattern === null) {
            $pattern = self::gridBannerPattern($grid, $bannerSlot, $dyeColor);
            if ($pattern === null) {
                return null;
            }
        } elseif (count($occupied) !== 3) {
            return null;
        }
        $patterns[] = Tag::compound([
            'Pattern' => Tag::string($pattern),
            'Color' => Tag::int($dyeColor),
        ]);
        $nbt = ($banner->nbt ?? ItemNbt::empty())->withTag('Patterns', Tag::list(TagType::COMPOUND, $patterns));
        $consumption = self::consumeAll($occupied, $repetitions);
        if ($consumption === null) {
            return null;
        }

        return $this->result(self::BANNER_ADD_PATTERN, $repetitions, $consumption, [
            $this->outputFromStack($banner, nbt: $nbt),
        ]);
    }

    private function bannerDuplicate(CraftingGrid $grid, int $repetitions): ?CraftingRecipeMatch
    {
        $occupied = $grid->occupiedSlots();
        if (count($occupied) !== 2) {
            return null;
        }
        $banners = array_values($occupied);
        if ($banners[0]->identifier !== 'minecraft:banner'
            || $banners[1]->identifier !== 'minecraft:banner'
            || $banners[0]->auxValue > 15
            || $banners[0]->auxValue !== $banners[1]->auxValue) {
            return null;
        }
        $first = self::bannerPatterns($banners[0]);
        $second = self::bannerPatterns($banners[1]);
        if ($first === null || $second === null || ($first === []) === ($second === [])) {
            return null;
        }
        $source = $first === [] ? $banners[1] : $banners[0];
        $consumption = self::consumeAll($occupied, $repetitions);
        if ($consumption === null) {
            return null;
        }

        return $this->result(self::BANNER_DUPLICATE, $repetitions, $consumption, [
            $this->outputFromStack($source, count: 2),
        ]);
    }

    private function fireworkRocket(CraftingGrid $grid, int $repetitions): ?CraftingRecipeMatch
    {
        $occupied = $grid->occupiedSlots();
        $paper = 0;
        $gunpowder = 0;
        $explosions = [];
        foreach ($occupied as $stack) {
            if ($stack->identifier === 'minecraft:paper') {
                ++$paper;
            } elseif ($stack->identifier === 'minecraft:gunpowder') {
                ++$gunpowder;
            } elseif ($stack->identifier === 'minecraft:firework_star') {
                $explosion = self::fireworkExplosion($stack);
                if ($explosion !== null) {
                    $explosions[] = $explosion;
                }
            } else {
                return null;
            }
        }
        if ($paper !== 1 || $gunpowder < 1 || $gunpowder > 3 || count($explosions) > 7) {
            return null;
        }
        $consumption = self::consumeAll($occupied, $repetitions);
        if ($consumption === null) {
            return null;
        }
        $nbt = ItemNbt::empty()->withTag('Fireworks', Tag::compound([
            'Explosions' => Tag::list(TagType::COMPOUND, $explosions),
            'Flight' => Tag::byte($gunpowder),
        ]));

        return $this->result(self::FIREWORK_ROCKET, $repetitions, $consumption, [
            $this->output('minecraft:firework_rocket', 3, nbt: $nbt),
        ]);
    }

    private function writtenBookClone(CraftingGrid $grid, int $repetitions): ?CraftingRecipeMatch
    {
        $occupied = $grid->occupiedSlots();
        $source = null;
        $blankCount = 0;
        foreach ($occupied as $stack) {
            if ($stack->identifier === 'minecraft:written_book') {
                if ($source !== null) {
                    return null;
                }
                $source = $stack;
            } elseif ($stack->identifier === 'minecraft:writable_book') {
                ++$blankCount;
            } else {
                return null;
            }
        }
        if ($source === null || $source->nbt === null || $blankCount < 1) {
            return null;
        }
        $generationTag = $source->nbt->tag('generation');
        if ($generationTag === null) {
            $generation = 0;
        } elseif ($generationTag->type() === TagType::INT && is_int($generationTag->value())) {
            $generation = $generationTag->value();
        } else {
            return null;
        }
        if ($generation < 0 || $generation >= 2) {
            return null;
        }
        $consumption = self::consumeAll($occupied, $repetitions);
        if ($consumption === null) {
            return null;
        }
        $copyNbt = $source->nbt->withTag('generation', Tag::int($generation + 1));

        return $this->result(self::WRITTEN_BOOK_CLONE, $repetitions, $consumption, [
            $this->outputFromStack($source, count: $blankCount, nbt: $copyNbt),
            $this->outputFromStack($source),
        ]);
    }

    private function mapClone(CraftingGrid $grid, int $repetitions): ?CraftingRecipeMatch
    {
        $occupied = $grid->occupiedSlots();
        $source = null;
        $blankCount = 0;
        foreach ($occupied as $stack) {
            if ($stack->identifier === 'minecraft:filled_map') {
                if ($source !== null) {
                    return null;
                }
                $source = $stack;
            } elseif ($stack->identifier === 'minecraft:empty_map') {
                ++$blankCount;
            } else {
                return null;
            }
        }
        if ($source === null || $blankCount < 1 || !self::hasMapIdentity($source)) {
            return null;
        }
        $consumption = self::consumeAll($occupied, $repetitions);
        if ($consumption === null) {
            return null;
        }

        return $this->result(self::MAP_CLONE, $repetitions, $consumption, [
            $this->outputFromStack($source, count: $blankCount + 1),
        ]);
    }

    private function decoratedPot(CraftingGrid $grid, int $repetitions): ?CraftingRecipeMatch
    {
        if ($grid->width !== 3 || $grid->height !== 3) {
            return null;
        }
        $slots = [1, 3, 5, 7];
        $sherds = [];
        $consumption = [];
        foreach ($grid->slots as $slot => $stack) {
            if (!in_array($slot, $slots, true)) {
                if ($stack !== null) {
                    return null;
                }
                continue;
            }
            if ($stack === null || ($stack->identifier !== 'minecraft:brick'
                && !isset($this->potterySherds[$stack->identifier]))
                || $stack->count < $repetitions) {
                return null;
            }
            $sherds[] = Tag::string($stack->identifier);
            $consumption[$slot] = $repetitions;
        }
        $nbt = count(array_filter(
            $sherds,
            static fn(Tag $sherd): bool => $sherd->value() !== 'minecraft:brick',
        )) === 0
            ? null
            : ItemNbt::empty()->withTag('sherds', Tag::list(TagType::STRING, $sherds));

        return $this->result(self::DECORATED_POT, $repetitions, $consumption, [
            $this->output('minecraft:decorated_pot', nbt: $nbt),
        ]);
    }

    private function mapExtend(CraftingGrid $grid, int $repetitions): ?CraftingRecipeMatch
    {
        if ($grid->width !== 3 || $grid->height !== 3) {
            return null;
        }
        $map = $grid->slots[4];
        if (!$map instanceof InventoryStack || $map->identifier !== 'minecraft:filled_map'
            || !self::hasMapIdentity($map) || $map->nbt === null) {
            return null;
        }
        $scaleTag = $map->nbt->tag('map_scale');
        if ($scaleTag === null) {
            $scale = 0;
        } elseif ($scaleTag->type() === TagType::INT && is_int($scaleTag->value())) {
            $scale = $scaleTag->value();
        } else {
            return null;
        }
        if ($scale < 0 || $scale >= 4) {
            return null;
        }
        foreach ($grid->slots as $slot => $stack) {
            if ($slot === 4) {
                continue;
            }
            if (!$stack instanceof InventoryStack || $stack->identifier !== 'minecraft:paper') {
                return null;
            }
        }
        $consumption = self::consumeAll($grid->occupiedSlots(), $repetitions);
        if ($consumption === null) {
            return null;
        }
        $nbt = $map->nbt->withTag('map_scale', Tag::int($scale + 1));

        return $this->result(self::MAP_EXTEND, $repetitions, $consumption, [
            $this->outputFromStack($map, nbt: $nbt),
        ]);
    }

    private function repairItem(CraftingGrid $grid, int $repetitions): ?CraftingRecipeMatch
    {
        $occupied = $grid->occupiedSlots();
        if (count($occupied) !== 2) {
            return null;
        }
        $stacks = array_values($occupied);
        if ($stacks[0]->identifier !== $stacks[1]->identifier) {
            return null;
        }
        $type = $this->items->type($stacks[0]->identifier);
        $maximum = $type->durability();
        if ($maximum === null) {
            return null;
        }
        if ($stacks[0]->damage > $maximum || $stacks[1]->damage > $maximum) {
            return null;
        }
        $remaining = ($maximum - $stacks[0]->damage) + ($maximum - $stacks[1]->damage) + intdiv($maximum * 5, 100);
        $damage = max(0, $maximum - $remaining);
        $consumption = self::consumeAll($occupied, $repetitions);
        if ($consumption === null) {
            return null;
        }

        return $this->result(self::REPAIR_ITEM, $repetitions, $consumption, [
            $this->output($stacks[0]->identifier, damage: $damage),
        ]);
    }

    private function bannerToShield(CraftingGrid $grid, int $repetitions): ?CraftingRecipeMatch
    {
        $occupied = $grid->occupiedSlots();
        if (count($occupied) !== 2) {
            return null;
        }
        $banner = null;
        $shield = null;
        foreach ($occupied as $stack) {
            if ($stack->identifier === 'minecraft:banner' && $banner === null) {
                $banner = $stack;
            } elseif ($stack->identifier === 'minecraft:shield' && $shield === null) {
                $shield = $stack;
            } else {
                return null;
            }
        }
        if (!$banner instanceof InventoryStack || !$shield instanceof InventoryStack || $banner->auxValue > 15) {
            return null;
        }
        $shieldPatterns = self::bannerPatterns($shield);
        if ($shieldPatterns === null || $shieldPatterns !== [] || $shield->nbt?->tag('Base') !== null) {
            return null;
        }
        $nbt = ($shield->nbt ?? ItemNbt::empty())->withTag('Base', Tag::int($banner->auxValue));
        $patterns = self::bannerPatterns($banner);
        if ($patterns === null) {
            return null;
        }
        if ($patterns !== []) {
            $nbt = $nbt->withTag('Patterns', Tag::list(TagType::COMPOUND, $patterns));
        }
        $consumption = self::consumeAll($occupied, $repetitions);
        if ($consumption === null) {
            return null;
        }

        return $this->result(self::BANNER_TO_SHIELD, $repetitions, $consumption, [
            $this->outputFromStack($shield, nbt: $nbt),
        ]);
    }

    private function mapUpgrade(CraftingGrid $grid, int $repetitions): ?CraftingRecipeMatch
    {
        $occupied = $grid->occupiedSlots();
        if (count($occupied) !== 2) {
            return null;
        }
        $map = null;
        $compasses = 0;
        foreach ($occupied as $stack) {
            if (in_array($stack->identifier, ['minecraft:filled_map', 'minecraft:empty_map'], true)) {
                if ($map !== null) {
                    return null;
                }
                $map = $stack;
            } elseif ($stack->identifier === 'minecraft:compass') {
                ++$compasses;
            } else {
                return null;
            }
        }
        if (!$map instanceof InventoryStack || $compasses !== 1 || $map->auxValue !== 0) {
            return null;
        }
        if ($map->identifier === 'minecraft:filled_map' && !self::hasMapIdentity($map)) {
            return null;
        }
        $consumption = self::consumeAll($occupied, $repetitions);
        if ($consumption === null) {
            return null;
        }

        return $this->result(self::MAP_UPGRADE, $repetitions, $consumption, [
            $this->outputFromStack($map, auxValue: 2),
        ]);
    }

    /**
     * @param array<int, InventoryStack> $occupied
     * @return null|array<int, int>
     */
    private static function consumeAll(array $occupied, int $repetitions): ?array
    {
        $consumption = [];
        foreach ($occupied as $slot => $stack) {
            if ($stack->count < $repetitions) {
                return null;
            }
            $consumption[$slot] = $repetitions;
        }

        return $consumption;
    }

    /** @return null|list<Tag> */
    private static function bannerPatterns(InventoryStack $stack): ?array
    {
        $tag = $stack->nbt?->tag('Patterns');
        if ($tag === null) {
            return [];
        }
        if ($tag->type() !== TagType::LIST) {
            return null;
        }
        $patterns = $tag->value();
        if (!is_array($patterns) || count($patterns) > 6) {
            return null;
        }
        foreach ($patterns as $pattern) {
            if (!$pattern instanceof Tag || $pattern->type() !== TagType::COMPOUND) {
                return null;
            }
            $compound = $pattern->value();
            $patternId = is_array($compound) ? ($compound['Pattern'] ?? null) : null;
            $color = is_array($compound) ? ($compound['Color'] ?? null) : null;
            if (!$patternId instanceof Tag || $patternId->type() !== TagType::STRING
                || !is_string($patternId->value()) || !self::isBannerPatternId($patternId->value())
                || !$color instanceof Tag || $color->type() !== TagType::INT
                || !is_int($color->value()) || $color->value() < 0 || $color->value() > 15) {
                return null;
            }
        }

        return array_values($patterns);
    }

    private static function gridBannerPattern(CraftingGrid $grid, int $bannerSlot, int $dyeColor): ?string
    {
        if ($grid->width !== 3 || $grid->height !== 3) {
            return null;
        }
        foreach (self::BANNER_GRID_PATTERNS as $name => $shape) {
            $matches = true;
            foreach ($grid->slots as $slot => $stack) {
                $required = $shape[intdiv($slot, 3)][$slot % 3] === '#';
                if ($required !== ($stack !== null && isset(self::DYE_COLORS[$stack->identifier])
                    && self::DYE_COLORS[$stack->identifier] === $dyeColor)) {
                    $matches = false;
                    break;
                }
                if (!$required && $stack !== null && $slot !== $bannerSlot) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                return $name;
            }
        }

        return null;
    }

    private static function fireworkExplosion(InventoryStack $star): ?Tag
    {
        $tag = $star->nbt?->tag('FireworksItem');

        return $tag?->type() === TagType::COMPOUND ? $tag : null;
    }

    private static function isBannerPatternId(string $pattern): bool
    {
        return isset(self::BANNER_GRID_PATTERNS[$pattern])
            || in_array($pattern, self::BANNER_PATTERN_ITEMS, true);
    }

    private static function hasMapIdentity(InventoryStack $map): bool
    {
        $identity = $map->nbt?->tag('map_uuid');

        return $identity?->type() === TagType::LONG && is_int($identity->value());
    }

    private function outputFromStack(
        InventoryStack $stack,
        int $count = 1,
        ?ItemNbt $nbt = null,
        ?int $auxValue = null,
    ): RecipeOutput {
        return $this->output(
            $stack->identifier,
            $count,
            $stack->damage,
            $nbt ?? $stack->nbt,
            $auxValue ?? $stack->auxValue,
        );
    }

    private function output(
        string $identifier,
        int $count = 1,
        int $damage = 0,
        ?ItemNbt $nbt = null,
        int $auxValue = 0,
    ): RecipeOutput {
        $state = $this->items->type($identifier)->placedBlockState;

        return new RecipeOutput(
            $identifier,
            $count,
            $damage,
            $nbt,
            $auxValue,
            $state === null ? null : $this->blocks->internalId($state),
        );
    }

    /**
     * @param array<int, int> $consumption
     * @param list<RecipeOutput> $outputs
     */
    private function result(string $uuid, int $repetitions, array $consumption, array $outputs): CraftingRecipeMatch
    {
        return new CraftingRecipeMatch('minecraft:special_recipe/' . $uuid, $repetitions, $consumption, $outputs);
    }
}
