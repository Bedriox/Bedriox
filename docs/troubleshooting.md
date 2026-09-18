# Troubleshooting

## Composer refuses to install

Confirm that `php -v` reports a 64-bit PHP version of at least 8.4 and that Composer 2 is using the same PHP executable.

## Packaged startup reports runtime validation failed

Do not work around this by invoking a PHP executable from `PATH`. Reinstall the
exact target archive named in `bedriox.lock.json` with
`php tools/install-runtime.php <local-archive>`. A wrong archive digest,
modified runtime file, missing extension, wrong CPU/OS target, additional PHP
configuration, or launch through a different PHP binary intentionally fails
before Composer autoload.

## The executable says pre-alpha

That is expected during M0. There is no playable server until the transport, login, and flat-world milestones pass their acceptance gates.

## The server appears online but the client never connects

Query the UDP discovery response and confirm that the advertised protocol, version, player counts, game-mode fields, and ports match the qualified baseline. A valid RakNet pong is not enough: a retail client can reject semantically invalid Bedrock advertisement fields before sending the first connection request. Do not change RakNet negotiation until the last received packet proves negotiation was reached.

## The client disconnects after joining

Enable bounded protocol diagnostics temporarily, reproduce one action, and identify the last decoded packet ID, session phase, and failure category. Do not log encrypted packet bodies, JWTs, keys, GUIDs, account identifiers, or raw credentials. Distinguish a session-local disconnect from a server process crash. Add a regression test before changing the decoder or session policy.

The packet ownership path is documented in [`packet-lifecycle.md`](packet-lifecycle.md), and the investigation procedure is in [`change-safety.md`](change-safety.md).

## Terrain stops, is empty, or shows the wrong blocks

Check the stages independently: authoritative chunk generation, internal canonical states, network translation, serialized section and biome framing, per-session view scheduling, and transport delivery. Never repair a visual symptom by changing registry IDs or RakNet framing without proving that boundary is wrong. See [`world-chunk-pipeline.md`](world-chunk-pipeline.md).

## A component works locally but CI fails

Compare every component checkout with `bedriox.lock.json` and `composer.lock`. The main repository validates exact sibling commits; a correct working tree at a different revision is intentionally rejected. Follow [`cross-repository-changes.md`](cross-repository-changes.md) and do not bypass the manifest validator.
