<?php

namespace App\Integrations\Exceptions;

use RuntimeException;

/**
 * Thrown when a provider driver cannot complete a request (bad credentials,
 * network failure, unexpected API response, etc.).
 */
class IntegrationException extends RuntimeException
{
    public static function invalidCredentials(string $provider): self
    {
        return new self("The credentials for [{$provider}] were rejected by the provider.");
    }

    public static function requestFailed(string $provider, string $reason): self
    {
        return new self("The request to [{$provider}] failed: {$reason}");
    }
}
