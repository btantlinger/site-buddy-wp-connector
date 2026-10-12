<?php

defined('ABSPATH') || exit;

/**
 * Pairing and request signing.
 *
 * The plugin holds one secret. The pairing code shown to the site's admin carries the site's address and that
 * secret; the person pastes it into Site Buddy, which then signs every request with it. A request is accepted only
 * when its signature matches and its timestamp is within five minutes, so a captured request cannot be replayed
 * later. Nothing here logs or echoes the secret except the pairing code on the admin page.
 */
final class SBWC_Auth
{
    const OPTION = 'sbwc_secret';
    const MAX_SKEW_SECONDS = 300;
    const CODE_PREFIX = 'sbwc1.';

    public static function ensure_secret(): string
    {
        $secret = get_option(self::OPTION);
        if (! is_string($secret) || strlen($secret) < 40) {
            $secret = self::new_secret();
            update_option(self::OPTION, $secret, false);
        }

        return $secret;
    }

    public static function rotate(): void
    {
        update_option(self::OPTION, self::new_secret(), false);
    }

    /** What the person pastes into Site Buddy: the site's address and the secret, in one line. */
    public static function pairing_code(): string
    {
        $payload = wp_json_encode(['u' => home_url('/'), 's' => self::ensure_secret(), 'v' => SBWC_VERSION]);

        return self::CODE_PREFIX . rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    /**
     * Whether a request was signed by the paired panel.
     * Signed string: "<unix time>\n<METHOD>\n<route>\n<sha256 of the body>", HMAC-SHA256 with the secret, hex.
     */
    public static function verify(string $method, string $route, string $body, string $time, string $signature): bool
    {
        if (! ctype_digit($time) || abs(time() - (int) $time) > self::MAX_SKEW_SECONDS || $signature === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $time . "\n" . strtoupper($method) . "\n" . $route . "\n" . hash('sha256', $body), self::ensure_secret());

        return hash_equals($expected, strtolower($signature));
    }

    private static function new_secret(): string
    {
        return bin2hex(random_bytes(32));
    }
}
