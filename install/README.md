# Bedriox installer

These scripts install the released `Bedriox.phar`, its release launcher, and
the qualified PHP Runtime selected for the current platform. They do not clone
source repositories or install Composer.

The website publishes target-specific manifests at
`https://bedriox.com/install/manifests/<target>.json`. Every manifest is built
from reviewed GitHub release metadata and the SHA-256 values in the current
Bedriox release. Both the PHAR and Runtime archive are verified before the
staged installation is activated.

The Unix installer supports Linux and macOS on x86-64 and ARM64. The PowerShell
installer currently supports Windows x86-64. Both refuse to overwrite an
existing destination and accept a no-start option for unattended provisioning.
When the Unix installer is piped to `sh`, automatic startup reads the first-run
wizard from `/dev/tty` instead of the exhausted script pipe. A host without a
controlling terminal completes installation and prints the manual start command
instead of invoking an inevitably non-interactive first run.
