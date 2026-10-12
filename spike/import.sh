#!/usr/bin/env bash
# Spike: import a Site Buddy Connector archive into this DDEV WordPress site (run inside the web container).
# Env: ARCHIVE_URL, ARCHIVE_SHA256, PREVIEW_URL. Prints a JSON summary of what it did.
set -euo pipefail
cd /var/www/html/project
work=$(mktemp -d /tmp/sbwc-import.XXXXXX)
trap 'rm -rf "$work"' EXIT
log() { echo "[import] $*" >&2; }

log "downloading"
curl -fsSL --retry 3 -C - -o "$work/site.tgz" "$ARCHIVE_URL"
echo "$ARCHIVE_SHA256  $work/site.tgz" | sha256sum -c - >/dev/null
mkdir "$work/x" && tar xzf "$work/site.tgz" -C "$work/x"

prefix=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["table_prefix"])' "$work/x/manifest.json")
live=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["home"].rstrip("/"))' "$work/x/manifest.json")
host=${live#*://}

log "copying wp-content (no uploads)"
# wp-content mirrors the live site's: anything here the archive doesn't carry (the fresh install's themes,
# leftovers from an earlier import) goes, except uploads, which the copy keeps and the archive never carries.
for entry in wp-content/*; do
    name=$(basename "$entry")
    [ "$name" = uploads ] && continue
    [ -e "$work/x/wp-content/$name" ] || rm -rf "$entry"
done
for entry in "$work"/x/wp-content/*; do
    name=$(basename "$entry")
    [ "$name" = uploads ] && continue
    if [ -d "$entry" ]; then
        rsync -a --delete "$entry/" "wp-content/$name/"
    else
        cp -a "$entry" "wp-content/$name"
    fi
done

log "loading the database (prefix $prefix)"
wp db reset --yes >/dev/null
wp db import "$work/x/database.sql" >/dev/null
wp config set table_prefix "$prefix" >/dev/null

log "replacing $live with $PREVIEW_URL"
replaced=0
for from in "https://$host" "http://$host" "https://www.$host" "http://www.$host"; do
    n=$(wp search-replace "$from" "$PREVIEW_URL" --all-tables-with-prefix --skip-columns=guid --precise --format=count 2>/dev/null || echo 0)
    replaced=$((replaced + n))
done
wp option update home "$PREVIEW_URL" >/dev/null
wp option update siteurl "$PREVIEW_URL" >/dev/null

log "making the copy safe"
changes=()
wp config set DISABLE_WP_CRON true --raw >/dev/null && changes+=("WordPress cron off (DISABLE_WP_CRON)")
for plugin in wordfence ninjafirewall all-in-one-wp-security-and-firewall sucuri-scanner \
              wp-mail-smtp post-smtp easy-wp-smtp fluent-smtp smtp-mailer gmail-smtp wp-ses \
              wp-rocket litespeed-cache w3-total-cache wp-super-cache; do
    if wp plugin is-active "$plugin" 2>/dev/null; then
        wp plugin deactivate "$plugin" >/dev/null 2>&1 && changes+=("deactivated $plugin")
    fi
done
# Cache drop-ins belong to the live host's caching layer (LiteSpeed, Redis, a host's own); in the copy they
# error or serve stale pages. WordPress loads them only when present, so removing them is enough.
for dropin in object-cache.php advanced-cache.php; do
    if [ -f "wp-content/$dropin" ]; then
        rm -f "wp-content/$dropin" && changes+=("removed the live host's cache drop-in wp-content/$dropin")
    fi
done
# The connector came along in the copy; it must not answer for the copy, and its secret is the live site's.
if wp plugin is-installed site-buddy-wp-connector 2>/dev/null; then
    wp plugin deactivate site-buddy-wp-connector >/dev/null 2>&1 || true
    wp option delete sbwc_secret sbwc_export >/dev/null 2>&1 || true
    wp cron event delete sbwc_cleanup >/dev/null 2>&1 || true
    changes+=("switched off the Site Buddy connector in the copy")
fi
if [ -f .user.ini ] && grep -q auto_prepend_file .user.ini; then
    sed -i '/auto_prepend_file/d' .user.ini && changes+=("removed the firewall's auto_prepend_file from .user.ini")
fi
if wp plugin is-active woocommerce 2>/dev/null; then
    changes+=("WooCommerce is active: check payment gateways are in test mode before taking orders")
fi
wp cache flush >/dev/null 2>&1 || true
wp rewrite flush >/dev/null 2>&1 || true

python3 - "$prefix" "$live" "$PREVIEW_URL" "$replaced" "${changes[@]}" <<'EOF'
import json, sys
prefix, live, preview, replaced, *changes = sys.argv[1:]
print(json.dumps({"table_prefix": prefix, "live": live, "preview": preview, "urls_replaced": int(replaced), "safety_changes": changes}, indent=2))
EOF
