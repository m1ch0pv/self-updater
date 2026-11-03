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
            'url' => 'http://localhost:1/releases/app-2.phar',
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
}
