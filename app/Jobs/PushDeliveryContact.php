<?php

namespace App\Jobs;

use App\Enums\ContactStatus;
use App\Integrations\IntegrationManager;
use App\Integrations\Support\ContactPayload;
use App\Models\Delivery;
use App\Models\DeliveryContact;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

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

        Log::error('Autoresponder contact push failed.', [
            ...$this->logContext($deliveryContact),
            'error' => $error,
            'response' => $result->context,
        ]);

        $deliveryContact->markFailed($error);
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
