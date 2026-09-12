#!/usr/bin/env bash
# Builds dist/zerokyc-pay.zip - the exact artifact for manual upload to the
# WordPress.org plugin installer and the initial submission form.
# git archive honors .gitattributes export-ignore, so dev-only paths drop out
# automatically; commit (or stage+`git stash create`) before building.
set -euo pipefail

cd "$(dirname "$0")/.."
mkdir -p dist
rm -f dist/zerokyc-pay.zip

git archive --format=zip --prefix=zerokyc-pay/ -o dist/zerokyc-pay.zip HEAD

echo "Built dist/zerokyc-pay.zip:"
unzip -l dist/zerokyc-pay.zip | tail -3
