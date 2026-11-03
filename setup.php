<?php

declare(strict_types=1);

// Everything needed to try the app, in one command:   php setup.php
//
// Starts from version 0 every time:
//   1. installs Composer dependencies (if missing)
//   2. removes old keys, releases, latest.yaml and the client folder
//   3. generates a new RSA keypair
//   4. releases version 1 and installs it as client/app.phar
//   5. starts the update server on http://localhost:8080 (Ctrl+C to stop)
//
// Running it again resets everything, so the client always trusts the key
// that the server signs with.

$root = __DIR__;
$php = escapeshellarg(PHP_BINARY);

// 1. Dependencies (symfony/yaml for the server scripts).
if (!is_dir("$root/vendor")) {
    passthru('composer install --no-interaction --working-dir=' . escapeshellarg($root));
}

// 2. Clean slate.
@unlink("$root/server/latest.yaml");
@unlink("$root/server/keys/private.pem");
@unlink("$root/app/public.pem");
array_map('unlink', glob("$root/server/public/releases/*.phar"));
array_map('unlink', glob("$root/client/*"));

// 3 + 4. Run the same scripts you would run by hand. release.php needs
// phar.readonly=0 to write a PHAR, so it runs as a separate PHP process.
passthru("$php " . escapeshellarg("$root/server/keygen.php"));
passthru("$php -d phar.readonly=0 " . escapeshellarg("$root/server/release.php"));

@mkdir("$root/client");
copy("$root/server/public/releases/app-1.phar", "$root/client/app.phar");

echo <<<TXT

client/app.phar is version 1. Starting the update server on http://localhost:8080 (Ctrl+C to stop).

In another terminal:
  php client/app.phar                          -> Your app is running version 1
  php -d phar.readonly=0 server/release.php    -> Released version 2
  php client/app.phar                          -> Your app was updated to 2


TXT;

// 5. The update server, in the foreground.
passthru("$php -S 0.0.0.0:8080 -t " . escapeshellarg("$root/server/public") . ' ' . escapeshellarg("$root/server/router.php"));
