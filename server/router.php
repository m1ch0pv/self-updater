<?php

declare(strict_types=1);

// Router for PHP's built-in web server:
//   php -S 0.0.0.0:8080 -t server/public server/router.php
//
// The document root is server/public, so only files in there can be served
// (the releases). latest.yaml and keys/ live one level up and are unreachable.

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/latest') {
    $state = Yaml::parseFile(__DIR__ . '/latest.yaml');

    // No download URL here: the app builds it from its own server URL and
    // the signed version (/releases/app-<version>.phar).
    header('Content-Type: application/json');
    echo json_encode([
        'version' => $state['version'],
        'sha256' => $state['sha256'],
        'signature' => $state['signature'],
    ]);

    return true;
}

// Anything else: let the built-in server serve the static file from server/public.
return false;
