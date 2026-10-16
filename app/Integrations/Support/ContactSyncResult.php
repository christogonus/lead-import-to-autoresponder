<?php

namespace App\Integrations\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * The outcome of pushing a single contact to a remote provider.
 */
class ContactSyncResult
{
    /**
     * How much of a failed response body to retain. Enough to hold a validation
     * error list, short enough that a bad push cannot flood the log.
     */
    private const MAX_BODY_LENGTH = 2000;

    /**
     * @param  array<string, mixed>  $context  Diagnostic detail about a failure, for the log.
     * @param  bool  $blocksDestination  Whether the destination refused the push outright, so every other contact would fail the same way.
     */
    public function __construct(
        public bool $successful,
        public ?string $remoteId = null,
        public ?string $error = null,
        public array $context = [],
        public bool $blocksDestination = false,
    ) {}

    public static function success(?string $remoteId = null): self
    {
        return new self(successful: true, remoteId: $remoteId);
    }

    /**
     * Build a failure, capturing the raw response where there was one. Providers
     * collapse a response to a single message for display, which routinely loses
     * the detail needed to tell why a push was rejected — so the status and body
     * are kept alongside it for the log.
     */
    public static function failure(string $error, ?Response $response = null): self
    {
        return new self(
            successful: false,
            error: $error,
            context: $response === null ? [] : [
                'status' => $response->status(),
                'body' => Str::limit($response->body(), self::MAX_BODY_LENGTH),
            ],
        );
    }

    /**
     * Build a failure that is not about this contact at all: the destination is
     * refusing every push (an account or permission block), so the rest of the
     * delivery should be stopped rather than rejected one contact at a time.
     */
    public static function destinationBlocked(string $error, ?Response $response = null): self
    {
        $result = self::failure($error, $response);
        $result->blocksDestination = true;

        return $result;
    }
}
