# Spike tools

The panel-side half of the import, as it was proven by hand on 2026-10-11 and 2026-10-12 (RFE #85). These
are reference scripts, not shipped with the plugin; the product version lives in the Site Buddy panel.

- `client.php`: signs a request the way the panel will. `php spike/client.php <pairing code> <METHOD> <route>`;
  `SBWC_BODY` sets a JSON body (e.g. `{"exclude_data":["wp_gf_entry"]}` for a POST to `export`), `--fallback`
  uses the `/?site-buddy-connector=` route instead of the REST API.
- `import.sh`: runs inside the copy's DDEV web container (`bash -s` with `ARCHIVE_URL`, `ARCHIVE_SHA256`,
  `PREVIEW_URL`). Downloads and checks the archive, mirrors `wp-content` except uploads, loads the database,
  rewrites the live URLs, and makes the copy safe (cron off, firewall/SMTP/cache plugins and cache drop-ins
  removed, the connector switched off with its secret deleted). Prints a JSON summary.

Measured on a shared-hosting test site (shared cPanel, 128 MB PHP memory, 934 MB of Gravity Forms entries left out):
export 21 to 28 s for a 54 MB archive, import about 30 s.
