#!/bin/sh

set -eu

INSTALL_BASE_URL=${BEDRIOX_INSTALL_BASE_URL:-https://bedriox.com/install}
INSTALL_DIRECTORY=${BEDRIOX_INSTALL_DIRECTORY:-bedriox-server}
START_SERVER=yes
TEMPORARY_DIRECTORY=

usage() {
    cat <<'EOF'
Install the latest Bedriox PHAR and packaged PHP runtime.

Usage: install.sh [--dir PATH] [--no-start]
EOF
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --dir)
            [ "$#" -ge 2 ] || { echo "Bedriox installer: --dir requires a path." >&2; exit 64; }
            INSTALL_DIRECTORY=$2
            shift 2
            ;;
        --dir=*)
            INSTALL_DIRECTORY=${1#--dir=}
            shift
            ;;
        --no-start)
            START_SERVER=no
            shift
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            echo "Bedriox installer: unknown option $1" >&2
            usage >&2
            exit 64
            ;;
    esac
done

[ -n "$INSTALL_DIRECTORY" ] || { echo "Bedriox installer: installation directory cannot be empty." >&2; exit 64; }
[ "$INSTALL_DIRECTORY" != "/" ] || { echo "Bedriox installer: refusing to install into /." >&2; exit 64; }
[ ! -e "$INSTALL_DIRECTORY" ] || { echo "Bedriox installer: $INSTALL_DIRECTORY already exists. Choose a new directory." >&2; exit 73; }

command -v curl >/dev/null 2>&1 || { echo "Bedriox installer: curl is required." >&2; exit 69; }
command -v tar >/dev/null 2>&1 || { echo "Bedriox installer: tar is required." >&2; exit 69; }

case "$(uname -s)" in
    Linux) PLATFORM=linux ;;
    Darwin) PLATFORM=macos ;;
    *) echo "Bedriox installer: this installer supports Linux and macOS. Use install.ps1 on Windows." >&2; exit 69 ;;
esac

case "$(uname -m)" in
    x86_64|amd64) ARCHITECTURE=x86-64 ;;
    arm64|aarch64) ARCHITECTURE=arm64 ;;
    *) echo "Bedriox installer: unsupported CPU architecture $(uname -m)." >&2; exit 69 ;;
esac

TARGET="$PLATFORM-$ARCHITECTURE"
TEMPORARY_DIRECTORY=$(mktemp -d "${TMPDIR:-/tmp}/bedriox-install.XXXXXX")
cleanup() {
    if [ -n "$TEMPORARY_DIRECTORY" ] && [ -d "$TEMPORARY_DIRECTORY" ]; then
        rm -rf -- "$TEMPORARY_DIRECTORY"
    fi
}
trap cleanup EXIT HUP INT TERM

MANIFEST="$TEMPORARY_DIRECTORY/manifest.json"
echo "Bedriox installer: checking the latest release for $TARGET..."
curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 \
    "$INSTALL_BASE_URL/manifests/$TARGET.json" -o "$MANIFEST"

json_value() {
    sed -n "s/^[[:space:]]*\"$1\": \"\([^\"]*\)\"[,]*$/\1/p" "$MANIFEST" | head -n 1
}

VERSION=$(json_value bedriox_version)
PHAR_URL=$(json_value phar_url)
PHAR_SHA256=$(json_value phar_sha256)
LAUNCHER_URL=$(json_value launcher_url)
LAUNCHER_SHA256=$(json_value launcher_sha256)
RUNTIME_URL=$(json_value runtime_url)
RUNTIME_SHA256=$(json_value runtime_sha256)

for DIGEST in "$PHAR_SHA256" "$LAUNCHER_SHA256" "$RUNTIME_SHA256"; do
    case "$DIGEST" in
        ''|*[!0-9a-f]*) echo "Bedriox installer: release manifest contains an invalid checksum." >&2; exit 65 ;;
    esac
    [ "${#DIGEST}" -eq 64 ] || { echo "Bedriox installer: release manifest contains an invalid checksum." >&2; exit 65; }
done
case "$PHAR_URL:$LAUNCHER_URL:$RUNTIME_URL" in
    https://bedriox.com/downloads/*:https://bedriox.com/downloads/*:https://bedriox.com/downloads/*) ;;
    *) echo "Bedriox installer: release manifest contains an untrusted download URL." >&2; exit 65 ;;
esac

PAYLOAD="$TEMPORARY_DIRECTORY/payload"
mkdir -p -- "$PAYLOAD"
curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 "$PHAR_URL" -o "$PAYLOAD/Bedriox.phar"
curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 "$LAUNCHER_URL" -o "$PAYLOAD/bedriox"
curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 "$RUNTIME_URL" -o "$TEMPORARY_DIRECTORY/runtime.tar.gz"

checksum() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | awk '{print $1}'
    else
        shasum -a 256 "$1" | awk '{print $1}'
    fi
}

[ "$(checksum "$PAYLOAD/Bedriox.phar")" = "$PHAR_SHA256" ] || { echo "Bedriox installer: Bedriox.phar checksum verification failed." >&2; exit 65; }
[ "$(checksum "$PAYLOAD/bedriox")" = "$LAUNCHER_SHA256" ] || { echo "Bedriox installer: launcher checksum verification failed." >&2; exit 65; }
[ "$(checksum "$TEMPORARY_DIRECTORY/runtime.tar.gz")" = "$RUNTIME_SHA256" ] || { echo "Bedriox installer: Runtime checksum verification failed." >&2; exit 65; }

tar -xzf "$TEMPORARY_DIRECTORY/runtime.tar.gz" -C "$PAYLOAD"
chmod 0755 "$PAYLOAD/bedriox" "$PAYLOAD/bin/php"
"$PAYLOAD/bedriox" --version >/dev/null

mv -- "$PAYLOAD" "$INSTALL_DIRECTORY"
TEMPORARY_DIRECTORY=
echo "Bedriox installer: installed Bedriox $VERSION in $INSTALL_DIRECTORY."

if [ "$START_SERVER" = no ]; then
    echo "Start it with: cd '$INSTALL_DIRECTORY' && ./bedriox serve"
    exit 0
fi

cd -- "$INSTALL_DIRECTORY"
if ( : </dev/tty ) 2>/dev/null; then
    echo "Bedriox installer: starting the server..."
    exec ./bedriox serve </dev/tty
fi

echo "Bedriox installer: no interactive terminal is available, so first-run setup was not started."
echo "Start it with: cd '$INSTALL_DIRECTORY' && ./bedriox serve"
