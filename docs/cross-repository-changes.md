# Cross-repository changes

Bedriox consumes independently versioned components through one-way dependencies:

```text
Bedriox -> RakNet
       -> Protocol
       -> Data
```

Change behavior in the repository that owns it. RakNet owns UDP and RakNet transport; Protocol owns Bedrock wire codecs; Data owns immutable versioned artifacts; Bedriox owns policy, sessions, simulation, worlds, and composition.

## Coordinated workflow

1. Identify the owner and characterize its current public contract.
2. Update the owner with focused success, failure, boundary, cleanup, and compatibility tests.
3. Update the owner's documentation, changelog, compatibility, and notices.
4. Run the owner's complete gate and commit that repository independently.
5. Update Bedriox to the exact owner commit through Composer and `bedriox.lock.json`.
6. Update consumer integration tests, license approval data, notices, and compatibility documentation together.
7. Run `composer check` in every affected repository and the complete workspace verifier.
8. Inspect all repository diffs and require clean trees before qualification.
9. Confirm pushed CI is green before starting later work.

Never patch the installed `vendor/` copy, copy sibling internals into Bedriox, or temporarily point a lock at an uncommitted checkout. A component pin update is an intentional compatibility change, not housekeeping.

## Pin authorities

`bedriox.lock.json` declares the approved component version and immutable commit. `composer.lock` must resolve the same package and reference. License validation must approve that exact revision. When sibling repositories are present, their Git HEADs must match. CI checkouts must consume the same manifest authority rather than maintain unrelated manual SHAs.

Any mismatch fails closed. Do not bypass the validator or use `-SkipClean` to qualify a component update.

## Breaking and semantic changes

A public interface or supported-version change requires consumer coordination. New cross-repository abstractions, dependency direction changes, worker processes, native extensions, or durable formats require an accepted RFC before implementation.

When external projects such as Cloudburst or PocketMine-MP inform behavior, verify license compatibility and preserve all legally required notices. Do not copy incompatible or unclear code or data.

## Completion

A coordinated change is complete only when the owning component and consumer commits are focused, exact pins agree everywhere, both local and workspace gates pass, CI is green, documentation is synchronized, and the relevant client journey is qualified. Later milestones must not begin while any repository remains dirty or any coordinated commit is missing.
