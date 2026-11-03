<?php

declare(strict_types=1);

namespace Tests;

use App\Verifier;
use PHPUnit\Framework\TestCase;

final class VerifierTest extends TestCase
{
    private string $publicKey;
    private string $sha;
    private string $signature;

    protected function setUp(): void
    {
        [$privateKey, $this->publicKey] = self::keyPair();
        $this->sha = hash('sha256', 'phar contents');

        // Sign exactly like the release script does.
        openssl_sign(Verifier::payload(2, $this->sha), $raw, $privateKey, OPENSSL_ALGO_SHA256);
        $this->signature = base64_encode($raw);
    }

    public function test_valid_signature_passes(): void
    {
        $this->assertTrue(Verifier::signatureIsValid(2, $this->sha, $this->signature, $this->publicKey));
    }

    public function test_changed_version_fails(): void
    {
        $this->assertFalse(Verifier::signatureIsValid(3, $this->sha, $this->signature, $this->publicKey));
    }

    public function test_changed_sha_fails(): void
    {
        $this->assertFalse(Verifier::signatureIsValid(2, str_repeat('0', 64), $this->signature, $this->publicKey));
    }

    public function test_signature_from_other_key_fails(): void
    {
        [$otherPrivate] = self::keyPair();
        openssl_sign(Verifier::payload(2, $this->sha), $raw, $otherPrivate, OPENSSL_ALGO_SHA256);

        $this->assertFalse(Verifier::signatureIsValid(2, $this->sha, base64_encode($raw), $this->publicKey));
    }

    public function test_file_hash_matches(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'phar');
        file_put_contents($file, 'hello');

        $this->assertTrue(Verifier::fileMatchesHash($file, hash('sha256', 'hello')));
    }

    public function test_file_hash_one_byte_changed_fails(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'phar');
        file_put_contents($file, 'hellp');

        $this->assertFalse(Verifier::fileMatchesHash($file, hash('sha256', 'hello')));
    }

    /** @return array{0: \OpenSSLAsymmetricKey, 1: string} private key and public key PEM */
    private static function keyPair(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        return [$key, openssl_pkey_get_details($key)['key']];
    }
}
