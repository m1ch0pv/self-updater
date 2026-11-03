<?php

declare(strict_types=1);

namespace App;

/**
 * The two security checks of an update.
 *
 *  - Signature (authenticity): "did the holder of the private key publish this release?"
 *  - Hash (integrity):         "is the file I downloaded exactly the file that was signed?"
 */
final class Verifier
{
    /**
     * The exact string that gets signed for a release.
     *
     * The version is part of it on purpose: it ties the version number to one
     * specific file, so nobody can take an old, validly signed PHAR and
     * advertise it as a newer version.
     */
    public static function payload(int $version, string $sha256): string
    {
        return $version . ':' . $sha256;
    }

    /**
     * Checks the RSA signature of "<version>:<sha256>" with the public key
     * shipped inside the PHAR. The public key can only verify, never sign,
     * so having it inside the app gives an attacker nothing.
     */
    public static function signatureIsValid(int $version, string $sha256, string $signatureBase64, string $publicKeyPem): bool
    {
        $signature = base64_decode($signatureBase64, true);
        if ($signature === false) {
            return false;
        }

        // openssl_verify returns 1 for valid, 0 for invalid, -1/false on error.
        return openssl_verify(self::payload($version, $sha256), $signature, $publicKeyPem, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * Compares the SHA-256 of a file with the expected (signed) value.
     * hash_equals() compares in constant time.
     */
    public static function fileMatchesHash(string $path, string $sha256): bool
    {
        return hash_equals($sha256, hash_file('sha256', $path));
    }
}
