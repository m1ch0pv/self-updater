<?php

declare(strict_types=1);

// One-time setup for a fresh checkout:   php setup.php
//
// Starts from version 0 every time:
//   1. removes old keys, releases, latest.yaml and the client folder
//   2. generates a new RSA keypair
//   3. releases version 1
//   4. installs it as client/app.phar
//
// Running it again resets everything, so the client always trusts the key
// that the server signs with.

$root = __DIR__;

// 1. Clean slate.
@unlink("$root/server/latest.yaml");
@unlink("$root/server/keys/private.pem");
@unlink("$root/app/public.pem");
array_map('unlink', glob("$root/server/public/releases/*.phar"));
array_map('unlink', glob("$root/client/*"));

// 2 + 3. Run the same scripts you would run by hand. release.php needs
// phar.readonly=0 to write a PHAR, so it runs as a separate PHP process.
$php = escapeshellarg(PHP_BINARY);
passthru("$php " . escapeshellarg("$root/server/keygen.php"));
passthru("$php -d phar.readonly=0 " . escapeshellarg("$root/server/release.php"));

// 4. Install version 1 as the client.
@mkdir("$root/client");
copy("$root/server/public/releases/app-1.phar", "$root/client/app.phar");

echo PHP_EOL . 'Done. client/app.phar is version 1.' . PHP_EOL;
echo 'Next: php -d phar.readonly=0 server/release.php   (creates version 2)' . PHP_EOL;
echo '      php -S 0.0.0.0:8080 -t server/public server/router.php' . PHP_EOL;
echo '      cd client && php app.phar' . PHP_EOL;
