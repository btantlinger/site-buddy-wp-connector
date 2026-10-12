<?php

defined('ABSPATH') || exit;

/**
 * The signed API Site Buddy calls. Every route is reachable two ways: the REST API (/wp-json/site-buddy/v1/<route>)
 * and, for sites where a security plugin has switched REST off, a query parameter on the home page
 * (/?site-buddy-connector=<route>). Both run the same handler and the same signature check.
 *
 * Routes: GET manifest, POST export (start a build), GET export (its status), DELETE export (remove the archive).
 */
final class SBWC_Api
{
    const NS = 'site-buddy/v1';
    const QUERY_VAR = 'site-buddy-connector';

    public static function boot(): void
    {
        add_action('rest_api_init', [self::class, 'register_rest']);
        add_action('init', [self::class, 'maybe_handle_query'], 0);
    }

    public static function register_rest(): void
    {
        foreach (['manifest', 'export'] as $route) {
            register_rest_route(self::NS, '/' . $route, [
                'methods' => ['GET', 'POST', 'DELETE'],
                'permission_callback' => '__return_true',
                'callback' => function (WP_REST_Request $request) use ($route) {
                    self::handle($request->get_method(), $route, (string) $request->get_body(), (string) $request->get_header('x-site-buddy-time'), (string) $request->get_header('x-site-buddy-signature'));
                },
            ]);
        }
    }

    public static function maybe_handle_query(): void
    {
        if (! isset($_GET[self::QUERY_VAR])) {
            return;
        }
        $route = sanitize_key(wp_unslash($_GET[self::QUERY_VAR]));
        self::handle(
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $route,
            (string) file_get_contents('php://input'),
            (string) ($_SERVER['HTTP_X_SITE_BUDDY_TIME'] ?? ''),
            (string) ($_SERVER['HTTP_X_SITE_BUDDY_SIGNATURE'] ?? '')
        );
    }

    /** Answer and stop: the response is written here rather than through REST, so an export can go on afterwards. */
    private static function handle(string $method, string $route, string $body, string $time, string $signature): void
    {
        $method = strtoupper($method);
        if (! SBWC_Auth::verify($method, $route, $body, $time, $signature)) {
            self::send(401, ['error' => 'not signed by the paired Site Buddy']);
        }
        if (is_multisite()) {
            self::send(409, ['error' => 'multisite installs are not supported yet']);
        }

        if ($route === 'manifest' && $method === 'GET') {
            self::send(200, SBWC_Manifest::build());
        }
        if ($route === 'export' && $method === 'GET') {
            self::send(200, SBWC_Export::status());
        }
        if ($route === 'export' && $method === 'DELETE') {
            SBWC_Export::cleanup();
            self::send(200, ['state' => 'idle']);
        }
        if ($route === 'export' && $method === 'POST') {
            try {
                $options = json_decode($body, true);
                $job = SBWC_Export::begin(is_array($options) ? (array) ($options['exclude_data'] ?? []) : []);
            } catch (Throwable $e) {
                self::send(500, ['error' => $e->getMessage()]);
            }
            self::send(202, ['state' => 'running', 'job' => $job], true);
            SBWC_Export::run();
            exit;
        }
        self::send(404, ['error' => 'unknown route']);
    }

    /** Send JSON. With $continue the connection is closed but PHP keeps running (the export runs after it). */
    private static function send(int $status, array $payload, bool $continue = false): void
    {
        $json = (string) wp_json_encode($payload);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        status_header($status);
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        if (! $continue) {
            echo $json;
            exit;
        }
        @ignore_user_abort(true);
        @set_time_limit(0);
        header('Connection: close');
        header('Content-Length: ' . strlen($json));
        echo $json;
        flush();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
    }
}
