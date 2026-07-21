<?php

namespace App\Integrations\OAuth;

/**
 * Generates Proof Key for Code Exchange (PKCE, RFC-7636) values. AWeber only
 * accepts the S256 challenge method.
 */
class Pkce
{
    /**
     * Generate a high-entropy code verifier (base64url, unpadded, 43-128 chars).
     */
    public static function verifier(): string
    {
        return self::base64Url(random_bytes(64));
    }

    /**
     * Derive the S256 code challenge for a verifier.
     */
    public static function challenge(string $verifier): string
    {
        return self::base64Url(hash('sha256', $verifier, true));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
