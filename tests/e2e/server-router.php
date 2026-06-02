<?php

/**
 * EPIPE-safe router for the E2E built-in PHP web server.
 *
 * Mirrors Laravel's framework router (Illuminate/Foundation/resources/server.php)
 * but deliberately OMITS its per-request `file_put_contents('php://stdout', ...)`
 * access-log line. That write is what crashes `php artisan serve` workers with
 * "Write of N bytes failed with errno=32 Broken pipe" once the Symfony Process
 * pipe backs up under load — returning a raw PHP Notice instead of HTML and
 * cascading failures across specs.
 *
 * Run directly (no artisan serve, no capturing pipe):
 *   php -S 127.0.0.1:PORT -t public tests/e2e/server-router.php
 *
 * `__DIR__`-relative public path keeps it correct regardless of the working
 * directory the server is launched from.
 */

$publicPath = realpath(__DIR__ . '/../../public');

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

// Emulate mod_rewrite: serve existing files (built assets, images, etc.)
// straight from disk; route everything else through the framework.
if ($uri !== '/' && file_exists($publicPath . $uri)) {
    return false;
}

require_once $publicPath . '/index.php';
