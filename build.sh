#!/usr/bin/env bash
# Package the plugin as an installable zip: dist/site-buddy-wp-connector-<version>.zip, plus an unversioned copy
# (dist/site-buddy-wp-connector.zip) for the GitHub release, so .../releases/latest/download/<that name> is a
# stable link the Site Buddy wizard can offer. WordPress installs either the same way.
set -euo pipefail
cd "$(dirname "$0")"
version=$(sed -n 's/^ \* Version: *//p' site-buddy-wp-connector.php | tr -d '[:space:]')
rm -rf dist/stage && mkdir -p dist/stage/site-buddy-wp-connector
cp -r site-buddy-wp-connector.php includes README.md dist/stage/site-buddy-wp-connector/
(cd dist/stage && zip -qr "../site-buddy-wp-connector-${version}.zip" site-buddy-wp-connector)
rm -rf dist/stage
cp "dist/site-buddy-wp-connector-${version}.zip" dist/site-buddy-wp-connector.zip
echo "dist/site-buddy-wp-connector-${version}.zip"
