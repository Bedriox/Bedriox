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

namespace Bedriox\Server\Tests\Simulation;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Persistence\PersistenceEnqueueResult;
use Bedriox\Server\Persistence\PersistenceSubmission;
use Bedriox\Server\Persistence\PersistenceWriteCompletion;
use Bedriox\Server\Persistence\PersistenceWriteRequest;
use Bedriox\Server\Player\Persistence\AsynchronousPlayerDataStore;
use Bedriox\Server\Player\Persistence\PlayerPersistenceManager;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Simulation\MovementMode;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use PHPUnit\Framework\TestCase;

final class PlayerAutosaveCycleTest extends TestCase
{
    public function testAutosaveCycleDoesNotChaseStateThatChangesAfterItsSnapshot(): void
    {
        $store = new AutosaveCycleDataStore();
        $palette = FixedFlatBlockPalette::fromRegistry(new BlockStateRegistry(
            BedrockDataSet::bundled()->blockStateRegistry()->states(),
        ));
        $persistence = new PlayerPersistenceManager(
            $store,
            'world',
            new Position(0.0, 64.0, 0.0),
        );
        $world = new WorldSimulation(blockPalette: $palette, playerPersistence: $persistence);
        $factory = new SimulationCommandFactory();
        self::assertTrue($world->enqueue($factory->join('session-one', 'identity-one', 'One')));
        $world->tick();

        self::assertTrue($world->enqueue($factory->move(
            'session-one',
            1,
            0.1,
            64.0,
            0.0,
            0.0,
            0.0,
            MovementMode::WALKING,
        )));
        $world->tick();
        self::assertSame(1, $world->beginPlayerAutosave());
        self::assertSame(
            ['saved' => 0, 'submitted' => 1, 'remaining' => 1],
            $world->autosavePlayers(1),
        );
        self::assertCount(1, $store->submittedRevisions);
        $firstRevision = $store->submittedRevisions[0];

        self::assertTrue($world->enqueue($factory->move(
            'session-one',
            2,
            0.2,
            64.0,
            0.0,
            0.0,
            0.0,
            MovementMode::WALKING,
        )));
        $world->tick();
        $store->complete($firstRevision);
        self::assertSame(
            ['saved' => 1, 'submitted' => 0, 'remaining' => 0],
            $world->autosavePlayers(1),
        );
        self::assertSame([$firstRevision], $store->submittedRevisions);

        self::assertSame(1, $world->beginPlayerAutosave());
        self::assertSame(
            ['saved' => 0, 'submitted' => 1, 'remaining' => 1],
            $world->autosavePlayers(1),
        );
        self::assertCount(2, $store->submittedRevisions);
        $submittedRevisions = $store->submittedRevisions;
        $secondRevision = array_pop($submittedRevisions);
        self::assertGreaterThan($firstRevision, $secondRevision);
    }
}

final class AutosaveCycleDataStore implements AsynchronousPlayerDataStore
{
    /** @var list<int> */
    public array $submittedRevisions = [];

    /** @var array<int, PersistenceWriteRequest> */
    private array $requests = [];

    /** @var list<PersistenceWriteCompletion> */
    private array $completions = [];

    public function exists(string $uuid): bool
    {
        return false;
    }

    public function load(string $uuid): ?PlayerBootstrap
    {
        return null;
    }

    public function save(PlayerBootstrap $player): void {}

    public function enqueueSave(PlayerBootstrap $player, int $revision): PersistenceEnqueueResult
    {
        if (isset($this->requests[$revision])) {
            return new PersistenceEnqueueResult(PersistenceSubmission::STALE, null);
        }
        $request = new PersistenceWriteRequest(
            count($this->submittedRevisions) + 1,
            count($this->submittedRevisions) + 1,
            'player:' . $player->identity->uuid,
            $revision,
            'player',
        );
        $this->requests[$revision] = $request;
        $this->submittedRevisions[] = $revision;

        return new PersistenceEnqueueResult(PersistenceSubmission::ACCEPTED, $request);
    }

    public function complete(int $revision): void
    {
        $request = $this->requests[$revision];
        unset($this->requests[$revision]);
        $this->completions[] = new PersistenceWriteCompletion(
            $request->id,
            $request->key,
            $request->revision,
            true,
        );
    }

    public function pollSaves(int $maximumCompletions = 256): array
    {
        return array_splice($this->completions, 0, $maximumCompletions);
    }

    public function drainSaves(int $timeoutMilliseconds): array
    {
        return $this->pollSaves();
    }

    public function close(): void {}
}
