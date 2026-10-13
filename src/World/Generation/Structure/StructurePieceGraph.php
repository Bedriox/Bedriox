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

namespace Bedriox\Server\World\Generation\Structure;

use InvalidArgumentException;

final readonly class StructurePieceGraph
{
    /** @var array<string, StructurePiece> */
    private array $piecesById;

    /** @var array<string, list<string>> */
    private array $childrenById;

    /**
     * @param list<StructurePiece>        $pieces
     * @param array<string, list<string>> $childrenById
     */
    public function __construct(
        public string $rootId,
        array $pieces,
        array $childrenById = [],
    ) {
        if (count($pieces) < 1 || count($pieces) > 4_096) {
            throw new InvalidArgumentException('Structure piece graph must contain between 1 and 4096 pieces.');
        }
        $byId = [];
        foreach ($pieces as $piece) {
            if (isset($byId[$piece->id])) {
                throw new InvalidArgumentException("Structure piece graph contains duplicate ID $piece->id.");
            }
            $byId[$piece->id] = $piece;
        }
        if (!isset($byId[$rootId])) {
            throw new InvalidArgumentException('Structure piece graph root does not exist.');
        }
        $parents = [];
        foreach ($childrenById as $parent => $children) {
            if (!isset($byId[$parent])) {
                throw new InvalidArgumentException("Structure piece graph references missing parent $parent.");
            }
            foreach ($children as $child) {
                if (!isset($byId[$child])) {
                    throw new InvalidArgumentException("Structure piece graph references missing child $child.");
                }
                if (isset($parents[$child])) {
                    throw new InvalidArgumentException("Structure piece graph child $child has multiple parents.");
                }
                $parents[$child] = $parent;
            }
        }
        foreach ($byId as $id => $_piece) {
            if ($id !== $rootId && !isset($parents[$id])) {
                throw new InvalidArgumentException("Structure piece graph contains unreachable piece $id.");
            }
        }
        $visited = [];
        $visiting = [];
        $visit = function (string $id) use (&$visit, &$visited, &$visiting, $childrenById): void {
            if (isset($visiting[$id])) {
                throw new InvalidArgumentException('Structure piece graph contains a cycle.');
            }
            if (isset($visited[$id])) {
                return;
            }
            $visiting[$id] = true;
            foreach ($childrenById[$id] ?? [] as $child) {
                $visit($child);
            }
            unset($visiting[$id]);
            $visited[$id] = true;
        };
        $visit($rootId);
        $this->piecesById = $byId;
        $this->childrenById = $childrenById;
    }

    /** @return list<StructurePiece> */
    public function pieces(): array
    {
        $ordered = [];
        $visit = function (string $id) use (&$visit, &$ordered): void {
            $ordered[] = $this->piecesById[$id];
            foreach ($this->childrenById[$id] ?? [] as $child) {
                $visit($child);
            }
        };
        $visit($this->rootId);

        return $ordered;
    }
}
