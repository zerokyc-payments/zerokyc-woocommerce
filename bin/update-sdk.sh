#!/usr/bin/env bash
# Re-vendor the ZeroKYC PHP SDK from the local checkout.
# Usage: bin/update-sdk.sh [path-to-zkp-sdk-php] [commit-hash]
set -euo pipefail

SDK_DIR="${1:-../zkp-sdk-php}"
COMMIT="${2:-$(git -C "$SDK_DIR" rev-parse --short HEAD)}"
DEST="$(cd "$(dirname "$0")/.." && pwd)/vendor/zerokyc/zkp-sdk-php"

rm -rf "$DEST"
mkdir -p "$DEST"
( cd "$SDK_DIR" && tar cf - \
    --exclude='./.git' --exclude='./tests' --exclude='./.github' \
    --exclude='./phpunit.xml' --exclude='./composer.lock' . ) \
  | ( cd "$DEST" && tar xf - )
echo "$COMMIT" > "$DEST/COMMIT"
echo "Vendored SDK from $SDK_DIR @ $COMMIT"
