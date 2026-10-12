<?php
/**
 * Plugin Name:       Site Buddy Connector
 * Description:       Lets Site Buddy copy this site (database, themes and plugins) into a private development copy. Pair it from Tools, Site Buddy.
 * Version:           0.1.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Site Buddy
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       site-buddy-wp-connector
 */

defined('ABSPATH') || exit;

define('SBWC_VERSION', '0.1.0');
define('SBWC_DIR', __DIR__);

require_once SBWC_DIR . '/includes/class-sbwc-auth.php';
require_once SBWC_DIR . '/includes/class-sbwc-manifest.php';
require_once SBWC_DIR . '/includes/class-sbwc-export.php';
require_once SBWC_DIR . '/includes/class-sbwc-api.php';
require_once SBWC_DIR . '/includes/class-sbwc-admin.php';

register_activation_hook(__FILE__, ['SBWC_Auth', 'ensure_secret']);
register_deactivation_hook(__FILE__, ['SBWC_Export', 'cleanup']);

SBWC_Api::boot();
SBWC_Admin::boot();
SBWC_Export::boot();
