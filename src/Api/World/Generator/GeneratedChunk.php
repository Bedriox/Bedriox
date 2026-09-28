<?php

declare(strict_types=1);

namespace Bedriox\Api\World\Generator;

use InvalidArgumentException;

final readonly class GeneratedChunk
{
    /** @var list<GeneratedSection> */
    public array $sections;

    /** @param list<mixed> $sections */
    public function __construct(
        array $sections,
        public string $biome = 'minecraft:plains',
    ) {
        if (strlen($biome) > 128 || preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $biome) !== 1) {
            throw new InvalidArgumentException('Generated chunk biome must be bounded and namespaced.');
        }
        $indexed = [];
        foreach ($sections as $section) {
            if (!$section instanceof GeneratedSection || isset($indexed[$section->sectionY])) {
                throw new InvalidArgumentException('Generated chunk contains an invalid or duplicate section.');
            }
            $indexed[$section->sectionY] = $section;
        }
        ksort($indexed, SORT_NUMERIC);
        $this->sections = array_values($indexed);
    }
}
