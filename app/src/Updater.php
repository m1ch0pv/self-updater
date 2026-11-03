<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * The update steps, in the order main.php calls them:
 *
 *   fetchLatest() -> shouldUpdate() -> downloadVerified() -> healthCheck() -> swap()
 *
 * Happy path only: if any security check fails we throw, the process stops,
 * and app.phar is never touched.
 */
final class Updater
{
    public function __construct(
        private string $pharPath,      // the running app.phar on disk
        private string $publicKeyPem,  // shipped inside the PHAR
        private string $serverUrl,     // e.g. http://localhost:8080
    ) {
    }

    public static function shouldUpdate(int $current, int $latest): bool
    {
        return $latest > $current;
    }

    /**
     * Asks the server what the latest release is.
     *
     * @return array{version: int, url: string, sha256: string, signature: string}
     */
    public function fetchLatest(): array
    {
        return json_decode(file_get_contents($this->serverUrl . '/latest'), true);
    }

    /**
     * Verifies the signature, downloads the new PHAR next to the current one,
     * then verifies its hash. Returns the path of the downloaded file.
     */
    public function downloadVerified(array $latest): string
    {
        // 1. Signature first: don't download or trust anything the server
        //    says until we know the release info came from the key holder.
        if (!Verifier::signatureIsValid($latest['version'], $latest['sha256'], $latest['signature'], $this->publicKeyPem)) {
            throw new RuntimeException('Invalid signature');
        }

        // 2. Download into the SAME folder as app.phar. Same filesystem means
        //    the rename() in swap() is atomic: app.phar is always either the
        //    complete old file or the complete new one, never half of each.
        $newPath = $this->pharPath . '.new';
        copy($latest['url'], $newPath);

        // 3. Hash: the file we got must be exactly the file that was signed.
        if (!Verifier::fileMatchesHash($newPath, $latest['sha256'])) {
            throw new RuntimeException('Downloaded file does not match the signed hash');
        }

        return $newPath;
    }

    /**
     * Runs the new PHAR in a separate process with --health, before it
     * replaces anything. It must exit 0 and print the version we expect.
     */
    public function healthCheck(string $newPath, int $expectedVersion): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($newPath) . ' --health', $output, $exitCode);

        if ($exitCode !== 0 || trim($output[0] ?? '') !== (string) $expectedVersion) {
            throw new RuntimeException('Health check failed');
        }
    }

    /**
     * Puts the new PHAR in place of the running one.
     *
     * We never write *into* app.phar: PHP reads code from the running PHAR
     * while it runs, so changing its bytes could break this process.
     * rename() instead points the name "app.phar" at the new file. This
     * process keeps its open handle to the old file's data (the OS frees it
     * when we exit), and the next `php app.phar` loads the new one.
     */
    public function swap(string $newPath): void
    {
        rename($newPath, $this->pharPath);
    }
}
