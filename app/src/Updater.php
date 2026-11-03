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
     * @return array{version: int, sha256: string, signature: string}
     */
    public function fetchLatest(): array
    {
        // @ hides PHP's warning: we throw our own, clearer error instead.
        $json = @file_get_contents($this->serverUrl . '/latest');
        if ($json === false) {
            throw new RuntimeException("Could not reach update server {$this->serverUrl}");
        }

        return json_decode($json, true);
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
        //    We build the download URL ourselves from our own server URL and
        //    the *signed* version, instead of trusting a URL from the
        //    response. Otherwise a malicious server could make us fetch any
        //    URL (internal hosts, file://, phar://...).
        //
        //    copy() silently overwrites an existing app.phar.new. That's
        //    intended: a leftover from an earlier failed attempt is simply
        //    replaced, and the new file is re-verified below anyway.
        $newPath = $this->pharPath . '.new';
        if (!@copy("{$this->serverUrl}/releases/app-{$latest['version']}.phar", $newPath)) {
            throw new RuntimeException("Download failed for version {$latest['version']}");
        }

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
        // exec() fills these two by reference. Declaring them first makes that
        // visible, and $output must start empty because exec() *appends* to it.
        $output = [];
        $exitCode = 0;
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
