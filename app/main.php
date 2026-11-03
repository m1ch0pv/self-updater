<?php

declare(strict_types=1);

// Entry point of app.phar (the PHAR stub runs this file).
//
// Load every class up front, before any update happens. After swap() the
// name app.phar points at the new file, so nothing should be loaded from
// "app.phar" lazily after that point.
require_once __DIR__ . '/src/Verifier.php';
require_once __DIR__ . '/src/AppLock.php';
require_once __DIR__ . '/src/Updater.php';

use App\AppLock;
use App\Updater;

// version.txt is added to the PHAR by server/release.php at build time.
$version = (int) file_get_contents(__DIR__ . '/version.txt');

// --health: used by the *old* version to test a freshly downloaded PHAR.
// No lock here: the old process that started us is holding it.
if (in_array('--health', $argv, true)) {
    echo $version . PHP_EOL;
    exit(0);
}

// Inside a PHAR, __DIR__ is a phar:// path. Phar::running(false) gives the
// real file on disk, e.g. /path/to/client/app.phar.
$pharPath = Phar::running(false);

$lock = new AppLock(dirname($pharPath) . '/app.lock');
if (!$lock->acquire()) {
    echo 'Another instance is running' . PHP_EOL;
    exit(2);
}

if (in_array('--updated', $argv, true)) {
    // We were just restarted by the previous version: don't check again.
    echo "Your app was updated to $version" . PHP_EOL;
} else {
    $serverUrl = getenv('UPDATE_SERVER_URL') ?: 'http://localhost:8080';
    $updater = new Updater($pharPath, file_get_contents(__DIR__ . '/public.pem'), $serverUrl);

    $latest = $updater->fetchLatest();

    if (Updater::shouldUpdate($version, $latest['version'])) {
        $newPath = $updater->downloadVerified($latest);       // signature + hash
        $updater->healthCheck($newPath, $latest['version']);  // new PHAR boots and reports its version
        $updater->swap($newPath);                             // atomic rename over app.phar

        if (function_exists('pcntl_exec')) {
            // Release first: the restarted app takes the lock again itself.
            $lock->release();

            // Replace this process with a fresh PHP running the new app.phar.
            // Same PID, new program: nothing after this line runs.
            pcntl_exec(PHP_BINARY, [$pharPath, '--updated']);
        }

        echo "App was updated to {$latest['version']}, please restart" . PHP_EOL;
        exit(0);
    }

    echo "Your app is running version $version" . PHP_EOL;
}

// Stay alive (holding the lock) so a second copy can be started to test locking.
echo 'Press Enter to exit' . PHP_EOL;
fgets(STDIN);

$lock->release();
exit(0);
