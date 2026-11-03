<?php

declare(strict_types=1);

namespace Tests;

use App\Updater;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UpdaterTest extends TestCase
{
    public function test_should_update_when_latest_is_newer(): void
    {
        $this->assertTrue(Updater::shouldUpdate(1, 2));
    }

    public function test_no_update_when_equal(): void
    {
        $this->assertFalse(Updater::shouldUpdate(2, 2));
    }

    public function test_no_update_when_older(): void
    {
        $this->assertFalse(Updater::shouldUpdate(3, 2));
    }

    public function test_download_rejects_bad_signature_before_downloading(): void
    {
        $pharPath = sys_get_temp_dir() . '/updater-test-' . uniqid() . '.phar';
        $key = openssl_pkey_new(['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $updater = new Updater($pharPath, openssl_pkey_get_details($key)['key'], 'http://localhost:1');

        $latest = [
            'version' => 2,
            'sha256' => str_repeat('a', 64),
            'signature' => base64_encode('not a real signature'),
        ];

        try {
            $updater->downloadVerified($latest);
            $this->fail('Expected a RuntimeException for a bad signature');
        } catch (RuntimeException) {
            // Nothing may be written to disk when the signature is wrong.
            $this->assertFileDoesNotExist($pharPath . '.new');
        }
    }

    public function test_fetch_latest_throws_when_server_unreachable(): void
    {
        $updater = new Updater('/tmp/app.phar', 'unused', 'file:///nonexistent-' . uniqid());

        $this->expectException(RuntimeException::class);
        $updater->fetchLatest();
    }

    public function test_download_throws_when_release_file_is_missing(): void
    {
        // Correctly signed release info, but the server has no such file.
        $key = openssl_pkey_new(['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $sha = str_repeat('b', 64);
        openssl_sign("2:$sha", $signature, $key, OPENSSL_ALGO_SHA256);

        $pharPath = sys_get_temp_dir() . '/updater-test-' . uniqid() . '.phar';
        $updater = new Updater($pharPath, openssl_pkey_get_details($key)['key'], 'file:///nonexistent-' . uniqid());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Download failed');
        $updater->downloadVerified(['version' => 2, 'sha256' => $sha, 'signature' => base64_encode($signature)]);
    }

    public function test_download_url_comes_from_server_url_and_signed_version_not_from_response(): void
    {
        // A fake "server" folder holding the real, signed release.
        $serverDir = sys_get_temp_dir() . '/updater-server-' . uniqid();
        mkdir("$serverDir/releases", 0777, true);
        file_put_contents("$serverDir/releases/app-2.phar", 'real release');

        $key = openssl_pkey_new(['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $sha = hash('sha256', 'real release');
        openssl_sign("2:$sha", $signature, $key, OPENSSL_ALGO_SHA256);

        $pharPath = sys_get_temp_dir() . '/updater-test-' . uniqid() . '.phar';
        $updater = new Updater($pharPath, openssl_pkey_get_details($key)['key'], "file://$serverDir");

        // A malicious server points `url` somewhere else. It must be ignored.
        $newPath = $updater->downloadVerified([
            'version' => 2,
            'url' => 'file:///etc/hosts',
            'sha256' => $sha,
            'signature' => base64_encode($signature),
        ]);

        $this->assertSame('real release', file_get_contents($newPath));
    }
}
