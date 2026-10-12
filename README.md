# Site Buddy Connector (WordPress)

Lets Site Buddy copy a WordPress site (database, themes and plugins; media stays on the live site) into a private
development copy. Install it, open **Tools, Site Buddy**, and paste the pairing code into Site Buddy.

Design: btantlinger/site-buddy issue #85. Spike version.

## API (signed)

Every request carries `X-Site-Buddy-Time` (unix seconds) and `X-Site-Buddy-Signature`:
HMAC-SHA256, hex, of `"<time>\n<METHOD>\n<route>\n<sha256 of body>"` with the pairing secret. Requests older or
newer than five minutes are refused.

| Route | Method | What it does |
| --- | --- | --- |
| `manifest` | GET | WordPress/PHP versions, table prefix, theme, plugins, sizes, what the host allows |
| `export` | POST | Start building the archive (answers 202 at once, keeps working) |
| `export` | GET | Status: running, ready (with `url`, `bytes`, `sha256`) or failed |
| `export` | DELETE | Remove the archive |

Reachable at `/wp-json/site-buddy/v1/<route>` and, where REST is switched off, `/?site-buddy-connector=<route>`.

## Build a zip

    ./build.sh   # dist/site-buddy-wp-connector-<version>.zip
