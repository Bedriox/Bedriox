# World-generation qualification

The world-generation qualification report measures generated chunks as player-visible output. It does not rely on
private noise-sampler details, so the same report can compare a heightmap generator with a volumetric-density
generator.

Run a quick deterministic sample:

```shell
php tools/worldgen-qualification.php --seeds=0 --radius=1
```

Run a broader comparison before accepting a generator change:

```shell
php tools/worldgen-qualification.php --seeds=0,1,-1,9123,73511 --radius=2 > worldgen-report.json
```

Target a known biome or cave region by its center chunk without changing the sampled radius:

```shell
php tools/worldgen-qualification.php --seeds=73511 --radius=2 --center-chunk-x=12 --center-chunk-z=-9
```

The radius is measured in chunks from the selected center and is bounded to one through four. Seed count is bounded
to 16.
The center defaults to chunk `0,0`; each center coordinate is bounded from `-1000000` through `1000000`. The report
records the selected center, and the sample radius extends equally in every direction from it.
The JSON report contains:

- height lag correlations, axis bias, and long cardinal contour runs;
- connected and isolated exposed-rock measurements, including the share placed on gentle slopes;
- categorical surface-material runs and normalized X/Z lag agreement for detecting grid-aligned striping;
- coastline straight runs, corner density, and X/Z orientation bias;
- height continuity across biome boundaries;
- cave air volume, connected components, horizontal terrain openings, sinkhole candidates, and fluid-over-air
  diagnostics;
- tree density, nearest-neighbor spacing, and local tree-shape uniqueness;
- bounded foliage-only and unrooted-log components, excluding components clipped by the sampled boundary;
- tree fragments in ocean columns, per-biome tree density, and zero-tree clearing rates in forest-dominant 8x8 cells;
- chunk-border height continuity and reverse-order determinism;
- ordered-region per-chunk generation latency distribution.

The report intentionally does not declare universal pass/fail thresholds. Capture the previous generator and the
candidate with identical seeds and radius, review the map and in-game screenshots, and then ratify thresholds that
reject the observed defects. A lower value is not universally better: cave volume without connectivity, vegetation
density without spacing, or low contour runs produced by random surface noise are all regressions.

Generated JSON is local qualification output and must not be committed unless it is a deliberately reviewed,
stable reference fixture.

The automated visual-regression suite additionally locks the reviewed seed-zero forest hills (`0,-12`), old-growth
taiga coast (`0,0`), jungle lowland (`60,0`), and swamp transition (`-20,-8`) chunk regions. These checks require
grounded biome-appropriate trees, no interior foliage-only or unrooted-log components, no orphan tree cells over
ocean, bounded gentle-slope rock exposure, and an irregular water-and-mud wetland boundary. They protect the exact
failure shapes found during retail-client review while the broader report remains available for exploratory seeds.
