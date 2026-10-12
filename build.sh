#!/usr/bin/env bash
# Package the plugin as an installable zip: dist/site-buddy-wp-connector-<version>.zip
set -euo pipefail
cd "$(dirname "$0")"
version=$(sed -n 's/^ \* Version: *//p' site-buddy-wp-connector.php | tr -d '[:space:]')
rm -rf dist/stage && mkdir -p dist/stage/site-buddy-wp-connector
cp -r site-buddy-wp-connector.php includes README.md dist/stage/site-buddy-wp-connector/
(cd dist/stage && zip -qr "../site-buddy-wp-connector-${version}.zip" site-buddy-wp-connector)
rm -rf dist/stage
echo "dist/site-buddy-wp-connector-${version}.zip"
