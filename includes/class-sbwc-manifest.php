<?php

defined('ABSPATH') || exit;

/** What Site Buddy needs to know before it copies the site, and what this host lets the exporter use. */
final class SBWC_Manifest
{
    public static function build(): array
    {
        global $wpdb, $wp_version;

        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $active = (array) get_option('active_plugins', []);
        $plugins = [];
        foreach (get_plugins() as $file => $data) {
            $plugins[] = ['file' => $file, 'name' => $data['Name'], 'version' => $data['Version'], 'active' => in_array($file, $active, true)];
        }
        $theme = wp_get_theme();

        return [
            'connector' => SBWC_VERSION,
            'wordpress' => $wp_version,
            'php' => PHP_VERSION,
            'home' => home_url('/'),
            'siteurl' => site_url('/'),
            'table_prefix' => $wpdb->prefix,
            'multisite' => is_multisite(),
            'theme' => ['stylesheet' => $theme->get_stylesheet(), 'template' => $theme->get_template(), 'name' => $theme->get('Name')],
            'plugins' => $plugins,
            'sizes' => [
                'database_bytes' => self::database_bytes(),
                'wp_content_bytes_without_media' => SBWC_Export::content_bytes(),
            ],
            'largest_tables' => self::largest_tables(),
            'host' => [
                'exec' => SBWC_Export::can_exec(),
                'mysqldump' => SBWC_Export::can_exec() && SBWC_Export::which('mysqldump') !== '',
                'tar' => SBWC_Export::can_exec() && SBWC_Export::which('tar') !== '',
                'phardata' => class_exists('PharData'),
                'max_execution_time' => (int) ini_get('max_execution_time'),
                'memory_limit' => (string) ini_get('memory_limit'),
                'disk_free_bytes' => function_exists('disk_free_space') ? (int) @disk_free_space(WP_CONTENT_DIR) : null,
            ],
        ];
    }

    /** The biggest tables, so an unexpectedly large database (log or entry tables) is visible before copying. */
    private static function largest_tables(): array
    {
        global $wpdb;

        return array_map(fn ($row) => ['table' => $row->name, 'bytes' => (int) $row->bytes, 'rows' => (int) $row->row_count], (array) $wpdb->get_results($wpdb->prepare(
            'SELECT table_name AS name, data_length + index_length AS bytes, table_rows AS row_count FROM information_schema.TABLES WHERE table_schema = %s AND table_name LIKE %s ORDER BY bytes DESC LIMIT 8',
            DB_NAME,
            $wpdb->esc_like($wpdb->prefix) . '%'
        )));
    }

    private static function database_bytes(): int
    {
        global $wpdb;
        $like = $wpdb->esc_like($wpdb->prefix) . '%';

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s AND table_name LIKE %s',
            DB_NAME,
            $like
        ));
    }
}
