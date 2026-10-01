<?php
/**
 * Plugin Name: Buckmerce test — HTTPS behind a tunnel
 * Description: Disposable Sandbox sites only. The site is served by the PHP built-in server
 *              behind an HTTPS tunnel (ngrok); trust the tunnel's X-Forwarded-Proto so
 *              WordPress generates HTTPS URLs and does not redirect in a loop.
 */

declare(strict_types=1);

if (defined('BUCKMERCE_PLAID_SANDBOX_TEST') && BUCKMERCE_PLAID_SANDBOX_TEST && 'https' === strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))) {
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SERVER_PORT'] = '443';
}
