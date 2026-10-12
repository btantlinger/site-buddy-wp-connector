<?php

defined('ABSPATH') || exit;

/** Tools, Site Buddy: the pairing code to paste into Site Buddy, and what the connector is doing. */
final class SBWC_Admin
{
    public static function boot(): void
    {
        add_action('admin_menu', function (): void {
            add_management_page('Site Buddy', 'Site Buddy', 'manage_options', 'site-buddy-connector', [self::class, 'render']);
        });
        add_action('admin_post_sbwc_rotate', [self::class, 'rotate']);
    }

    public static function rotate(): void
    {
        if (! current_user_can('manage_options') || ! check_admin_referer('sbwc_rotate')) {
            wp_die('Not allowed.');
        }
        SBWC_Auth::rotate();
        SBWC_Export::cleanup();
        wp_safe_redirect(admin_url('tools.php?page=site-buddy-connector&rotated=1'));
        exit;
    }

    public static function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $code = SBWC_Auth::pairing_code();
        $status = SBWC_Export::status();
        ?>
        <div class="wrap">
            <h1>Site Buddy</h1>
            <?php if (isset($_GET['rotated'])) : ?>
                <div class="notice notice-success"><p>A new pairing code was made. The old one no longer works.</p></div>
            <?php endif; ?>
            <p>Copy this pairing code and paste it into Site Buddy when it asks for one. It lets Site Buddy make a
                private development copy of this site (database, themes and plugins; images are loaded from this site).</p>
            <p><textarea readonly rows="3" style="width:100%;font-family:monospace" onclick="this.select()"><?php echo esc_textarea($code); ?></textarea></p>
            <p>Treat it like a password: anyone with it can copy this site. Make a new one if it was shared by mistake.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="sbwc_rotate">
                <?php wp_nonce_field('sbwc_rotate'); ?>
                <?php submit_button('Make a new pairing code', 'secondary', 'submit', false); ?>
            </form>
            <h2>Status</h2>
            <p><?php echo esc_html(self::describe($status)); ?></p>
            <p>When Site Buddy has copied your site you can deactivate and delete this plugin under Plugins.</p>
        </div>
        <?php
    }

    private static function describe(array $status): string
    {
        switch ($status['state'] ?? 'idle') {
            case 'running':
                return 'Preparing a copy for Site Buddy (started ' . human_time_diff((int) $status['started']) . ' ago).';
            case 'ready':
                return 'A copy is ready for Site Buddy to collect. It is removed automatically within a few hours.';
            case 'failed':
                return 'The last copy failed: ' . ($status['error'] ?? 'unknown error');
            default:
                return 'Nothing in progress.';
        }
    }
}
