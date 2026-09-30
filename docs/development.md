# Development

Install 64-bit PHP `^8.4` (8.4 through 8.x, excluding PHP 9) and Composer 2, then run:

```shell
composer install
composer check
```

Production code belongs in `src/`, tests in `tests/`, executable entry points in `bin/`, and repository-specific documentation here. Changes must keep dependency direction one-way and include tests and documentation appropriate to the behavior.

Do not commit Minecraft client assets, credentials, access tokens, packet captures containing personal data, or implementation code without verified redistribution rights.

## Safe change workflow

Before editing, write down the intended subsystem and files, the observable outcome, and the already-working behavior that must remain unchanged. When a compatibility surface is involved, first add or identify a characterization test. Keep implementation commits focused; an unexpected need to change another subsystem requires an explicit scope expansion rather than an incidental edit.

Run focused tests while developing. Before handoff or commit, run `composer check`, inspect the complete diff, and run the workspace verifier for component or integration changes. Do not proceed to a later milestone while the current milestone has a failing local gate, failing pushed CI, unexplained diff, or unfinished retail qualification.

The complete procedure and protected compatibility surfaces are defined in [`change-safety.md`](change-safety.md). Cross-component work follows [`cross-repository-changes.md`](cross-repository-changes.md). The cumulative user-visible acceptance baseline is [`client-journey-contract.md`](client-journey-contract.md).

## Release archive

Build the standalone production PHAR with:

```shell
composer build:phar
```

The build installs only locked production dependencies in an isolated staging
directory and writes `Bedriox.phar`, its SHA-256 checksum, and platform
launchers to `build/`. The archive embeds application code and production
vendor packages, but not tests, development tools, worlds, configuration, or
the PHP runtime. A release deployment places the matching Runtime `bin/`
directory beside these files.

## Private component resolution

During private alpha development, Composer resolves the Protocol, RakNet, and Data packages from explicit sibling checkouts. Their package versions and immutable commit IDs are recorded in `bedriox.lock.json` and checked against `composer.lock`; when sibling Git repositories are present, the manifest validator also verifies their checked-out commits. CI derives every private checkout ref directly from that manifest through `tools/export-component-pins.php`; never duplicate component commit hashes in the workflow.

The private-dependency CI job runs only for pushes to `main` and explicit trusted manual dispatches of that branch. It does not expose the private component token to pull-request code, and it must never be converted to `pull_request_target`. `BEDRIOX_COMPONENTS_TOKEN` must remain an Actions secret with repository-read access to Protocol, RakNet, and Data. Untrusted pull requests require a secret-free review path until these packages are public or served by a suitably scoped private Composer registry.

Composer Dependabot updates are disabled while sibling path repositories are required because Dependabot cannot safely resolve that workspace layout. GitHub Actions update checks remain enabled. Re-enable Composer updates only after an authenticated registry or public package source replaces the private paths.
