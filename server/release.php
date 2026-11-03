<?php

declare(strict_types=1);

// Builds and signs a new release:
//   php -d phar.readonly=0 server/release.php
//
// (PHP refuses to write PHARs unless phar.readonly is off; we only turn it off
// for this build command.)

require __DIR__ . '/../vendor/autoload.php';

use App\Verifier;
use Symfony\Component\Yaml\Yaml;

$yamlPath = __DIR__ . '/latest.yaml';
$appDir = __DIR__ . '/../app';

// 1. Read the current state. No YAML yet means nothing released: version 0.
$state = file_exists($yamlPath)
    ? Yaml::parseFile($yamlPath)
    : ['version' => 0, 'private_key' => 'keys/private.pem'];

$version = $state['version'] + 1;

// 2. Build the PHAR: the app code, the public key, and the version.
$pharPath = __DIR__ . "/public/releases/app-$version.phar";
@unlink($pharPath);

$phar = new Phar($pharPath);
$phar->addFile("$appDir/main.php", 'main.php');
foreach (glob("$appDir/src/*.php") as $file) {
    $phar->addFile($file, 'src/' . basename($file));
}
$phar->addFile("$appDir/public.pem", 'public.pem');
$phar->addFromString('version.txt', (string) $version);

// The stub is the small piece of PHP at the start of the file that runs when
// you do `php app.phar`. mapPhar() registers this file under the alias
// "app.phar" whatever its file name is (so app.phar.new works too).
$phar->setStub("#!/usr/bin/env php\n<?php Phar::mapPhar('app.phar'); require 'phar://app.phar/main.php'; __HALT_COMPILER();");
unset($phar); // write the file to disk

// 3. Fingerprint and sign "<version>:<sha256>" with the private key.
$sha256 = hash_file('sha256', $pharPath);
$privateKey = openssl_pkey_get_private(file_get_contents(__DIR__ . '/' . $state['private_key']));
openssl_sign(Verifier::payload($version, $sha256), $signature, $privateKey, OPENSSL_ALGO_SHA256);

// 4. Record the new latest release. router.php serves this as /latest.
$state['version'] = $version;
$state['sha256'] = $sha256;
$state['signature'] = base64_encode($signature);
file_put_contents($yamlPath, Yaml::dump($state));

echo "Released version $version" . PHP_EOL;
