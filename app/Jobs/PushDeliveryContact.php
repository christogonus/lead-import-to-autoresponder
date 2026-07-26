<?php

namespace App\Jobs;

use App\Enums\ContactStatus;
use App\Integrations\IntegrationManager;
use App\Integrations\Support\ContactPayload;
use App\Integrations\Support\ContactSyncResult;
use App\Models\Delivery;
use App\Models\DeliveryContact;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes a single contact to a single delivery's destination and records the
 * outcome on the delivery contact row.
 */
class PushDeliveryContact implements ShouldQueue
{
    use Queueable;

    /**
     * The number of exception-throwing attempts before the job fails. Attempts
     * themselves are unbounded (see retryUntil) because a rate-limited release
     * also consumes an attempt, and waiting in line must not burn the retries
     * reserved for real errors.
     */
    public int $maxExceptions = 3;

    /**
     * HTTP statuses meaning the destination temporarily could not take the
     * push — provider-side throttling or a gateway blip — rather than that it
     * rejected the contact. These are retried, not recorded as failures.
     *
     * @var array<int, int>
     */
    private const TRANSIENT_STATUSES = [429, 502, 503, 504];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public DeliveryContact $deliveryContact,
    ) {}

    /**
     * The backoff (seconds) between retries.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * How long the job may keep being attempted, bounding the rate-limited
     * releases that maxExceptions deliberately does not count.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addDay();
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RateLimited('autoresponder-push')];
    }

    /**
     * Execute the job.
     */
    public function handle(IntegrationManager $manager): void
    {
        $deliveryContact = $this->deliveryContact->fresh(['delivery.integration', 'contact']);

        if ($deliveryContact === null) {
            return;
        }

        $delivery = $deliveryContact->delivery;
        $contact = $deliveryContact->contact;
        $integration = $delivery?->integration;

        if ($delivery === null || $contact === null || $integration === null) {
            Log::error('Autoresponder contact push skipped: destination no longer available.', [
                ...$this->logContext($deliveryContact),
                'missing' => [
                    'delivery' => $delivery === null,
                    'contact' => $contact === null,
                    'integration' => $integration === null,
                ],
            ]);

            $deliveryContact->markFailed('The destination is no longer available.');

            return;
        }

        // Cancelling cannot pull jobs back off the queue, so the last chance to
        // stop a push is here, immediately before it goes out. Not a failure:
        // the contact was deliberately stood down, not rejected.
        if ($delivery->status === Delivery::STATUS_CANCELLED) {
            return;
        }

        $result = $manager->driver($integration)->pushContact(
            $delivery->remote_id,
            ContactPayload::fromContact($contact),
        );

        if ($result->successful) {
            $deliveryContact->markSynced($result->remoteId);

            return;
        }

        $error = $result->error ?? 'Unknown error.';

        // A throttled or momentarily-unavailable destination has not rejected
        // the contact; put the job back in line and let it try again.
        if ($this->isTransientFailure($result)) {
            Log::warning('Autoresponder contact push throttled; released for retry.', [
                ...$this->logContext($deliveryContact),
                'error' => $error,
                'response' => $result->context,
                'attempts' => $this->attempts(),
            ]);

            $this->release(random_int(10, 30));

            return;
        }

        Log::error('Autoresponder contact push failed.', [
            ...$this->logContext($deliveryContact),
            'error' => $error,
            'response' => $result->context,
        ]);

        $deliveryContact->markFailed($error);
    }

    /**
     * Whether the failed push can be expected to succeed on a later attempt.
     */
    private function isTransientFailure(ContactSyncResult $result): bool
    {
        return in_array($result->context['status'] ?? null, self::TRANSIENT_STATUSES, true);
    }

    /**
     * Handle a job failure after all retries are exhausted.
     */
    public function failed(Throwable $exception): void
    {
        $deliveryContact = $this->deliveryContact->fresh();

        // A contact stood down mid-flight is cancelled, not failed; letting the
        // exception overwrite that would put it back in the retry pool.
        if ($deliveryContact === null || $deliveryContact->status === ContactStatus::Cancelled) {
            return;
        }

        // Distinct from a rejected push: the request never produced a usable
        // response, so the stack trace is the only diagnostic there is.
        Log::error('Autoresponder contact push threw an exception.', [
            ...$this->logContext($deliveryContact),
            'error' => $exception->getMessage(),
            'exception' => $exception,
        ]);

        $deliveryContact->markFailed($exception->getMessage());
    }

    /**
     * The identifiers needed to trace a failed push back to its delivery,
     * destination and contact.
     *
     * @return array<string, mixed>
     */
    private function logContext(DeliveryContact $deliveryContact): array
    {
        $delivery = $deliveryContact->delivery;

        return [
            'provider' => $delivery?->integration?->provider->value,
            'integration_id' => $delivery?->integration_id,
            'delivery_id' => $deliveryContact->delivery_id,
            'delivery_contact_id' => $deliveryContact->id,
            'contact_id' => $deliveryContact->contact_id,
            'email' => $deliveryContact->contact?->email,
            'remote_list_id' => $delivery?->remote_id,
        ];
    }
}
