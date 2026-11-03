<?php

declare(strict_types=1);

// Run ONCE: php server/keygen.php
//
// Creates the RSA keypair used to sign releases.
//  - server/keys/private.pem : signs releases. Stays on the server, gitignored,
//                              and outside server/public so it's never served.
//  - app/public.pem          : verifies releases. Packed into every PHAR and
//                              committed. It can only verify, never sign.

$key = openssl_pkey_new([
    'private_key_bits' => 4096,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
]);

@mkdir(__DIR__ . '/keys', 0700);
openssl_pkey_export_to_file($key, __DIR__ . '/keys/private.pem');
chmod(__DIR__ . '/keys/private.pem', 0600);

file_put_contents(__DIR__ . '/../app/public.pem', openssl_pkey_get_details($key)['key']);

echo "Keys written: server/keys/private.pem, app/public.pem" . PHP_EOL;
