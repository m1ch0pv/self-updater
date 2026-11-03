<?php

declare(strict_types=1);

// Prepares everything needed to try the app:   php setup.php
//
// Starts from version 0 every time:
//   1. installs Composer dependencies (if missing)
//   2. removes old releases, latest.yaml and the client folder
//   3. generates the RSA keypair, only the first time (keys are kept after that)
//   4. releases version 1 and installs it as client/app.phar
//
// Running it again resets the version and the client, but keeps the keys.
// The update server is started separately (see the README).

$root = __DIR__;
$php = escapeshellarg(PHP_BINARY);

// 1. Dependencies (symfony/yaml for the server scripts).
if (!is_dir("$root/vendor")) {
    passthru('composer install --no-interaction --working-dir=' . escapeshellarg($root));
}

// 2. Back to version 0: no releases, no installed client. Keys stay.
@unlink("$root/server/latest.yaml");
array_map('unlink', glob("$root/server/public/releases/*.phar"));
array_map('unlink', glob("$root/client/*"));

// 3. Keys only if we don't have both halves yet. A pair is generated
// together, so if either file is missing both are recreated.
if (!file_exists("$root/server/keys/private.pem") || !file_exists("$root/app/public.pem")) {
    passthru("$php " . escapeshellarg("$root/server/keygen.php"));
} else {
    echo 'Keeping existing keys (server/keys/private.pem, app/public.pem)' . PHP_EOL;
}

// 4. Run the same script you would run by hand. release.php needs
// phar.readonly=0 to write a PHAR, so it runs as a separate PHP process.
passthru("$php -d phar.readonly=0 " . escapeshellarg("$root/server/release.php"));

@mkdir("$root/client");
copy("$root/server/public/releases/app-1.phar", "$root/client/app.phar");

echo <<<TXT

Done. client/app.phar is version 1.

Next:
  php -S 0.0.0.0:8080 -t server/public server/router.php   (update server, keep it running)
  php client/app.phar                                       -> Your app is running version 1

TXT;
