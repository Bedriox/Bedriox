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

namespace Bedriox\Server\Gameplay\Portal;

use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\World;
use InvalidArgumentException;

/** Authoritative, bounded Nether portal frame and block-state operations for one dimension runtime. */
final class NetherPortalSystem
{
    private const int MAXIMUM_INVALIDATION_BLOCKS = 1_024;
    private const int BUILD_CLEARANCE_HORIZONTAL_MINIMUM = -2;
    private const int BUILD_CLEARANCE_HORIZONTAL_MAXIMUM = 3;
    private const int BUILD_CLEARANCE_DEPTH = 4;
    private const int BUILD_CLEARANCE_HEIGHT = 4;
    private const int FALLBACK_EXIT_SEARCH_RADIUS = 16;
    private const int NATURAL_LANDING_DEPTH = 3;
    private const int FALLBACK_EXIT_VERTICAL_RADIUS = 11;

    private readonly InternalBlockStateId $air;
    private readonly InternalBlockStateId $obsidian;
    private readonly InternalBlockStateId $portalX;
    private readonly InternalBlockStateId $portalZ;

    public function __construct(
        private readonly World $world,
        private readonly BlockStateRegistry $states,
    ) {
        $this->air = $this->resolve('minecraft:air');
        $this->obsidian = $this->resolve('minecraft:obsidian');
        $this->portalX = $this->resolve('minecraft:portal', ['portal_axis' => 'x']);
        $this->portalZ = $this->resolve('minecraft:portal', ['portal_axis' => 'z']);
    }

    public function detect(BlockPosition $position): ?NetherPortalFrame
    {
        return $this->detectAxis($position, PortalAxis::X)
            ?? $this->detectAxis($position, PortalAxis::Z);
    }

    /** @return list<PortalBlockMutation> */
    public function ignite(BlockPosition $position): array
    {
        $frame = $this->detect($position);
        if ($frame === null) {
            return [];
        }
        $portal = $frame->axis === PortalAxis::X ? $this->portalX : $this->portalZ;
        $mutations = [];
        foreach ($frame->interior() as $interior) {
            $previous = $this->world->setBlockState($interior->x, $interior->y, $interior->z, $portal);
            if ($previous->value !== $portal->value) {
                $mutations[] = new PortalBlockMutation($interior, $previous, $portal);
            }
        }

        return $mutations;
    }

    /** Builds a standard 4x5 frame with a 2x3 interior at the supplied bottom-left interior origin. */
    /** @return list<PortalBlockMutation> */
    public function build(BlockPosition $origin, PortalAxis $axis): array
    {
        $mutations = [];
        $dx = $axis->stepX();
        $dz = $axis->stepZ();
        for ($horizontal = -1; $horizontal <= 2; ++$horizontal) {
            for ($vertical = -1; $vertical <= 3; ++$vertical) {
                $frame = $horizontal === -1 || $horizontal === 2 || $vertical === -1 || $vertical === 3;
                $position = new BlockPosition(
                    $origin->x + ($dx * $horizontal),
                    $origin->y + $vertical,
                    $origin->z + ($dz * $horizontal),
                );
                $state = $frame
                    ? $this->obsidian
                    : ($axis === PortalAxis::X ? $this->portalX : $this->portalZ);
                $previous = $this->world->setBlockState($position->x, $position->y, $position->z, $state);
                if ($previous->value !== $state->value) {
                    $mutations[] = new PortalBlockMutation($position, $previous, $state);
                }
            }
        }

        return $mutations;
    }

    /**
     * Carves a bounded three-block-deep arrival area and installs a solid floor before an emergency portal build.
     *
     * @return list<PortalBlockMutation>
     */
    public function prepareFallbackBuildSite(BlockPosition $origin, PortalAxis $axis): array
    {
        $mutations = [];
        $dx = $axis->stepX();
        $dz = $axis->stepZ();
        $perpendicularX = $dz;
        $perpendicularZ = $dx;
        for ($horizontal = self::BUILD_CLEARANCE_HORIZONTAL_MINIMUM;
            $horizontal <= self::BUILD_CLEARANCE_HORIZONTAL_MAXIMUM;
            ++$horizontal) {
            for ($depth = -self::BUILD_CLEARANCE_DEPTH; $depth <= self::BUILD_CLEARANCE_DEPTH; ++$depth) {
                $x = $origin->x + ($dx * $horizontal) + ($perpendicularX * $depth);
                $z = $origin->z + ($dz * $horizontal) + ($perpendicularZ * $depth);
                $floor = new BlockPosition($x, $origin->y - 1, $z);
                $previous = $this->world->setBlockState($floor->x, $floor->y, $floor->z, $this->obsidian);
                if ($previous->value !== $this->obsidian->value) {
                    $mutations[] = new PortalBlockMutation($floor, $previous, $this->obsidian);
                }
                for ($vertical = 0; $vertical <= self::BUILD_CLEARANCE_HEIGHT; ++$vertical) {
                    $position = new BlockPosition($x, $origin->y + $vertical, $z);
                    $previous = $this->world->setBlockState($position->x, $position->y, $position->z, $this->air);
                    if ($previous->value !== $this->air->value) {
                        $mutations[] = new PortalBlockMutation($position, $previous, $this->air);
                    }
                }
            }
        }

        $exit = $this->nearestOpenColumn($origin, $axis);
        if ($exit !== null) {
            $positiveStart = new BlockPosition(
                $origin->x + ($perpendicularX * (self::BUILD_CLEARANCE_DEPTH + 1)),
                $origin->y,
                $origin->z + ($perpendicularZ * (self::BUILD_CLEARANCE_DEPTH + 1)),
            );
            $negativeStart = new BlockPosition(
                $origin->x - ($perpendicularX * (self::BUILD_CLEARANCE_DEPTH + 1)),
                $origin->y,
                $origin->z - ($perpendicularZ * (self::BUILD_CLEARANCE_DEPTH + 1)),
            );
            $start = $this->horizontalDistance($positiveStart, $exit)
                <= $this->horizontalDistance($negativeStart, $exit)
                ? $positiveStart
                : $negativeStart;
            $cursorX = $start->x;
            $cursorY = $start->y;
            $cursorZ = $start->z;
            $this->carveFallbackColumn($mutations, $cursorX, $cursorY, $cursorZ);
            while ($cursorX !== $exit->x) {
                $cursorX += $cursorX < $exit->x ? 1 : -1;
                $cursorY = $this->stepToward($cursorY, $exit->y);
                $this->carveFallbackColumn($mutations, $cursorX, $cursorY, $cursorZ);
            }
            while ($cursorZ !== $exit->z) {
                $cursorZ += $cursorZ < $exit->z ? 1 : -1;
                $cursorY = $this->stepToward($cursorY, $exit->y);
                $this->carveFallbackColumn($mutations, $cursorX, $cursorY, $cursorZ);
            }
        }

        return $mutations;
    }

    public function isSuitableBuildOrigin(BlockPosition $origin, PortalAxis $axis): bool
    {
        $dx = $axis->stepX();
        $dz = $axis->stepZ();
        $perpendicularX = $dz;
        $perpendicularZ = $dx;
        if (!$this->isReplaceable($this->identifierAt($origin->x, $origin->y, $origin->z))) {
            return false;
        }
        $originFloor = $this->identifierAt($origin->x, $origin->y - 1, $origin->z);
        if ($originFloor === 'minecraft:air' || $this->isFluid($originFloor)) {
            return false;
        }

        for ($horizontal = 0; $horizontal <= 1; ++$horizontal) {
            $x = $origin->x + ($dx * $horizontal);
            $z = $origin->z + ($dz * $horizontal);
            $floor = $this->identifierAt($x, $origin->y - 1, $z);
            if ($floor === 'minecraft:air' || $this->isFluid($floor)) {
                return false;
            }
            for ($vertical = 0; $vertical <= 2; ++$vertical) {
                if (!$this->isReplaceable($this->identifierAt($x, $origin->y + $vertical, $z))) {
                    return false;
                }
            }
        }

        return $this->hasWalkableExit($origin, $axis);
    }

    private function hasWalkableExit(BlockPosition $origin, PortalAxis $axis): bool
    {
        $dx = $axis->stepX();
        $dz = $axis->stepZ();
        $perpendicularX = $dz;
        $perpendicularZ = $dx;
        foreach ([-1, 1] as $direction) {
            $walkable = true;
            for ($depth = 1; $depth <= self::NATURAL_LANDING_DEPTH; ++$depth) {
                for ($horizontal = 0; $horizontal <= 1; ++$horizontal) {
                    $x = $origin->x + ($dx * $horizontal) + ($perpendicularX * $depth * $direction);
                    $z = $origin->z + ($dz * $horizontal) + ($perpendicularZ * $depth * $direction);
                    if (!$this->isWalkableOpenColumn($x, $origin->y, $z)) {
                        $walkable = false;
                        break 2;
                    }
                }
            }

            if ($walkable) {
                return true;
            }
        }

        return false;
    }

    private function nearestOpenColumn(BlockPosition $origin, PortalAxis $axis): ?BlockPosition
    {
        $dx = $axis->stepX();
        $dz = $axis->stepZ();
        $perpendicularX = $dz;
        $perpendicularZ = $dx;
        for ($radius = self::BUILD_CLEARANCE_DEPTH + 1; $radius <= self::FALLBACK_EXIT_SEARCH_RADIUS; ++$radius) {
            for ($offset = -$radius; $offset <= $radius; ++$offset) {
                foreach ([
                    [$origin->x + $offset, $origin->z - $radius],
                    [$origin->x + $offset, $origin->z + $radius],
                    [$origin->x - $radius, $origin->z + $offset],
                    [$origin->x + $radius, $origin->z + $offset],
                ] as [$x, $z]) {
                    $positiveStart = new BlockPosition(
                        $origin->x + ($perpendicularX * (self::BUILD_CLEARANCE_DEPTH + 1)),
                        $origin->y,
                        $origin->z + ($perpendicularZ * (self::BUILD_CLEARANCE_DEPTH + 1)),
                    );
                    $negativeStart = new BlockPosition(
                        $origin->x - ($perpendicularX * (self::BUILD_CLEARANCE_DEPTH + 1)),
                        $origin->y,
                        $origin->z - ($perpendicularZ * (self::BUILD_CLEARANCE_DEPTH + 1)),
                    );
                    $horizontalDistance = min(
                        abs($x - $positiveStart->x) + abs($z - $positiveStart->z),
                        abs($x - $negativeStart->x) + abs($z - $negativeStart->z),
                    );
                    $maximumVerticalOffset = min(self::FALLBACK_EXIT_VERTICAL_RADIUS, $horizontalDistance);
                    for ($verticalOffset = 0; $verticalOffset <= $maximumVerticalOffset; ++$verticalOffset) {
                        foreach ($verticalOffset === 0 ? [$origin->y] : [$origin->y + $verticalOffset, $origin->y - $verticalOffset] as $y) {
                            if ($y < Chunk::MIN_Y + 2 || $y > Chunk::MAX_Y - 3) {
                                continue;
                            }
                            if ($this->isWalkableOpenColumn($x, $y, $z)) {
                                return new BlockPosition($x, $y, $z);
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

    private function isWalkableOpenColumn(int $x, int $y, int $z): bool
    {
        $floor = $this->identifierAt($x, $y - 1, $z);
        if ($floor === 'minecraft:air' || $this->isFluid($floor)) {
            return false;
        }
        for ($vertical = 0; $vertical <= 2; ++$vertical) {
            if (!$this->isReplaceable($this->identifierAt($x, $y + $vertical, $z))) {
                return false;
            }
        }

        return true;
    }

    /** @param list<PortalBlockMutation> $mutations */
    private function carveFallbackColumn(array &$mutations, int $x, int $y, int $z): void
    {
        $floor = new BlockPosition($x, $y - 1, $z);
        $floorIdentifier = $this->identifierAt($floor->x, $floor->y, $floor->z);
        if ($floorIdentifier === 'minecraft:air' || $this->isFluid($floorIdentifier)) {
            $previous = $this->world->setBlockState($floor->x, $floor->y, $floor->z, $this->obsidian);
            if ($previous->value !== $this->obsidian->value) {
                $mutations[] = new PortalBlockMutation($floor, $previous, $this->obsidian);
            }
        }
        for ($vertical = 0; $vertical <= 2; ++$vertical) {
            $position = new BlockPosition($x, $y + $vertical, $z);
            $previous = $this->world->setBlockState($position->x, $position->y, $position->z, $this->air);
            if ($previous->value !== $this->air->value) {
                $mutations[] = new PortalBlockMutation($position, $previous, $this->air);
            }
        }
    }

    private function horizontalDistance(BlockPosition $from, BlockPosition $to): int
    {
        return abs($from->x - $to->x) + abs($from->z - $to->z);
    }

    private function stepToward(int $value, int $target): int
    {
        return $value === $target ? $value : $value + ($value < $target ? 1 : -1);
    }

    /** Chooses a landing side that is open in the current terrain. */
    public function preferredArrivalDirection(NetherPortalFrame $frame): int
    {
        $perpendicularX = $frame->axis->stepZ();
        $perpendicularZ = $frame->axis->stepX();
        foreach ([1, -1] as $direction) {
            $x = $frame->bottomLeft->x
                + $frame->axis->stepX()
                + ($perpendicularX * 2 * $direction);
            $z = $frame->bottomLeft->z
                + $frame->axis->stepZ()
                + ($perpendicularZ * 2 * $direction);
            if ($this->isWalkableOpenColumn($x, $frame->bottomLeft->y, $z)) {
                return $direction;
            }
        }

        return 1;
    }

    /** Identifies the landing-platform signature used by Bedriox-created fallback portals. */
    public function hasGeneratedLandingPlatform(NetherPortalFrame $frame): bool
    {
        $dx = $frame->axis->stepX();
        $dz = $frame->axis->stepZ();
        $perpendicularX = $dz;
        $perpendicularZ = $dx;
        for ($horizontal = 0; $horizontal < $frame->width; ++$horizontal) {
            for ($depth = -1; $depth <= 1; ++$depth) {
                if ($this->identifierAt(
                    $frame->bottomLeft->x + ($dx * $horizontal) + ($perpendicularX * $depth),
                    $frame->bottomLeft->y - 1,
                    $frame->bottomLeft->z + ($dz * $horizontal) + ($perpendicularZ * $depth),
                ) !== 'minecraft:obsidian') {
                    return false;
                }
            }
        }

        return true;
    }

    /** Removes portal components whose enclosing obsidian frame was invalidated by a nearby block change. */
    /** @return list<PortalBlockMutation> */
    public function invalidateNear(BlockPosition $changed, bool $portalCellRemoved = false): array
    {
        $seeds = [];
        foreach ([[0, 0, 0], [1, 0, 0], [-1, 0, 0], [0, 1, 0], [0, -1, 0], [0, 0, 1], [0, 0, -1]] as [$x, $y, $z]) {
            $position = $this->offset($changed, $x, $y, $z);
            if ($position !== null && $this->portalAxisAt($position) !== null) {
                $seeds[$this->key($position)] = $position;
            }
        }
        $mutations = [];
        $visited = [];
        foreach ($seeds as $seed) {
            if (isset($visited[$this->key($seed)]) || (!$portalCellRemoved && $this->detect($seed) !== null)) {
                continue;
            }
            $axis = $this->portalAxisAt($seed);
            if ($axis === null) {
                continue;
            }
            $queue = [$seed];
            $component = [];
            for ($cursor = 0; isset($queue[$cursor]) && count($component) < self::MAXIMUM_INVALIDATION_BLOCKS; ++$cursor) {
                $position = $queue[$cursor];
                $key = $this->key($position);
                if (isset($visited[$key]) || $this->portalAxisAt($position) !== $axis) {
                    continue;
                }
                $visited[$key] = true;
                $component[] = $position;
                foreach ([[0, 1, 0], [0, -1, 0], [$axis->stepX(), 0, $axis->stepZ()], [-$axis->stepX(), 0, -$axis->stepZ()]] as [$x, $y, $z]) {
                    $neighbor = $this->offset($position, $x, $y, $z);
                    if ($neighbor !== null && !isset($visited[$this->key($neighbor)])) {
                        $queue[] = $neighbor;
                    }
                }
            }
            foreach ($component as $position) {
                $previous = $this->world->setBlockState($position->x, $position->y, $position->z, $this->air);
                if ($previous->value !== $this->air->value) {
                    $mutations[] = new PortalBlockMutation($position, $previous, $this->air);
                }
            }
        }

        return $mutations;
    }

    public function portalAxisAt(BlockPosition $position): ?PortalAxis
    {
        $state = $this->world->loadedBlockStateAt($position->x, $position->y, $position->z);
        if ($state === null) {
            return null;
        }
        $canonical = $this->states->state($state);
        if ($canonical->identifier() !== 'minecraft:portal') {
            return null;
        }

        return match ($canonical->properties()['portal_axis'] ?? null) {
            'x' => PortalAxis::X,
            'z' => PortalAxis::Z,
            default => null,
        };
    }

    /** @return array<int, PortalAxis> process-local state ID to axis */
    public function portalStateAxes(): array
    {
        return [
            $this->portalX->value => PortalAxis::X,
            $this->portalZ->value => PortalAxis::Z,
        ];
    }

    private function detectAxis(BlockPosition $position, PortalAxis $axis): ?NetherPortalFrame
    {
        $x = $position->x;
        $y = $position->y;
        $z = $position->z;
        while ($y > Chunk::MIN_Y) {
            if ($this->identifierAt($x, $y - 1, $z) === 'minecraft:obsidian') {
                break;
            }
            if (!$this->isInterior($this->identifierAt($x, $y - 1, $z))) {
                return null;
            }
            --$y;
        }
        $bottomY = $y;
        $leftX = $x;
        $leftZ = $z;
        for ($distance = 0; $distance <= NetherPortalFrame::MAXIMUM_WIDTH; ++$distance) {
            $nextX = $leftX - $axis->stepX();
            $nextZ = $leftZ - $axis->stepZ();
            $identifier = $this->identifierAt($nextX, $bottomY, $nextZ);
            if ($identifier === 'minecraft:obsidian') {
                break;
            }
            if (!$this->isInterior($identifier) || $distance === NetherPortalFrame::MAXIMUM_WIDTH) {
                return null;
            }
            $leftX = $nextX;
            $leftZ = $nextZ;
        }
        $width = 0;
        for ($distance = 0; $distance <= NetherPortalFrame::MAXIMUM_WIDTH; ++$distance) {
            $identifier = $this->identifierAt(
                $leftX + ($axis->stepX() * $distance),
                $bottomY,
                $leftZ + ($axis->stepZ() * $distance),
            );
            if ($identifier === 'minecraft:obsidian') {
                break;
            }
            if (!$this->isInterior($identifier) || $distance === NetherPortalFrame::MAXIMUM_WIDTH) {
                return null;
            }
            ++$width;
        }
        if ($width < NetherPortalFrame::MINIMUM_WIDTH || $width > NetherPortalFrame::MAXIMUM_WIDTH) {
            return null;
        }
        $height = 0;
        for ($vertical = 0; $vertical <= NetherPortalFrame::MAXIMUM_HEIGHT; ++$vertical) {
            $identifier = $this->identifierAt($leftX, $bottomY + $vertical, $leftZ);
            if ($identifier === 'minecraft:obsidian') {
                break;
            }
            if (!$this->isInterior($identifier) || $vertical === NetherPortalFrame::MAXIMUM_HEIGHT) {
                return null;
            }
            ++$height;
        }
        if ($height < NetherPortalFrame::MINIMUM_HEIGHT || $height > NetherPortalFrame::MAXIMUM_HEIGHT) {
            return null;
        }
        if (!$this->validate($leftX, $bottomY, $leftZ, $width, $height, $axis)) {
            return null;
        }

        return new NetherPortalFrame(new BlockPosition($leftX, $bottomY, $leftZ), $width, $height, $axis);
    }

    private function validate(int $leftX, int $bottomY, int $leftZ, int $width, int $height, PortalAxis $axis): bool
    {
        $dx = $axis->stepX();
        $dz = $axis->stepZ();
        for ($horizontal = 0; $horizontal < $width; ++$horizontal) {
            if ($this->identifierAt($leftX + ($dx * $horizontal), $bottomY - 1, $leftZ + ($dz * $horizontal)) !== 'minecraft:obsidian'
                || $this->identifierAt($leftX + ($dx * $horizontal), $bottomY + $height, $leftZ + ($dz * $horizontal)) !== 'minecraft:obsidian') {
                return false;
            }
        }
        for ($vertical = 0; $vertical < $height; ++$vertical) {
            if ($this->identifierAt($leftX - $dx, $bottomY + $vertical, $leftZ - $dz) !== 'minecraft:obsidian'
                || $this->identifierAt($leftX + ($dx * $width), $bottomY + $vertical, $leftZ + ($dz * $width)) !== 'minecraft:obsidian') {
                return false;
            }
            for ($horizontal = 0; $horizontal < $width; ++$horizontal) {
                if (!$this->isInterior($this->identifierAt(
                    $leftX + ($dx * $horizontal),
                    $bottomY + $vertical,
                    $leftZ + ($dz * $horizontal),
                ))) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<string, int|string> $properties */
    private function resolve(string $identifier, array $properties = []): InternalBlockStateId
    {
        try {
            return $this->states->internalId(CanonicalBlockState::from($identifier, $properties));
        } catch (InvalidArgumentException $error) {
            throw new InvalidArgumentException("Required Nether portal block state {$identifier} is unavailable.", previous: $error);
        }
    }

    private function identifierAt(int $x, int $y, int $z): string
    {
        if ($y < Chunk::MIN_Y || $y > Chunk::MAX_Y) {
            return 'minecraft:void_air';
        }

        return $this->states->state($this->world->blockStateAt($x, $y, $z))->identifier();
    }

    private function isInterior(string $identifier): bool
    {
        return in_array($identifier, ['minecraft:air', 'minecraft:fire', 'minecraft:soul_fire', 'minecraft:portal'], true);
    }

    private function isReplaceable(string $identifier): bool
    {
        return $this->isInterior($identifier) || $identifier === 'minecraft:void_air';
    }

    private function isFluid(string $identifier): bool
    {
        return $identifier === 'minecraft:water' || $identifier === 'minecraft:flowing_water'
            || $identifier === 'minecraft:lava' || $identifier === 'minecraft:flowing_lava';
    }

    private function offset(BlockPosition $position, int $x, int $y, int $z): ?BlockPosition
    {
        try {
            return new BlockPosition($position->x + $x, $position->y + $y, $position->z + $z);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function key(BlockPosition $position): string
    {
        return $position->x . ':' . $position->y . ':' . $position->z;
    }
}
