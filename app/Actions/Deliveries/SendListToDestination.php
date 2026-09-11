<?php

namespace App\Actions\Deliveries;

use App\Enums\ContactStatus;
use App\Jobs\PushDeliveryContact;
use App\Models\ContactList;
use App\Models\Delivery;
use App\Models\DeliveryContact;
use App\Models\Integration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use RuntimeException;

/**
 * Sends a contact list to a destination (a connected integration + one of its
 * remote lists) by creating a Delivery and queueing a push per contact.
 *
 * Contacts already successfully synced to that same destination are skipped, so
 * re-sending only pushes new contacts and retries previously-failed ones.
 *
 * A delivery may optionally be paced: given an hourly rate, the contacts are
 * recorded but left unreleased, and `deliveries:drip` hands them to the queue a
 * few at a time. Without a rate the whole delivery is queued at once, which is
 * the original behaviour.
 */
class SendListToDestination
{
    /**
     * How many contact rows to build per insert.
     */
    private const INSERT_CHUNK = 1000;

    /**
     * Create and queue a delivery, or return null when there is nothing new to
     * send (every contact is already synced to this destination).
     *
     * @param  int|null  $contactsPerHour  Cap on how many contacts are queued per hour, or null to queue them all immediately.
     *
     * @throws RuntimeException when the list has been set aside as a draft.
     */
    public function handle(
        ContactList $list,
        Integration $integration,
        string $remoteId,
        ?string $remoteName = null,
        ?int $contactsPerHour = null,
    ): ?Delivery {
        if ($list->isDraft()) {
            throw new RuntimeException('A drafted list cannot be sent to a destination.');
        }

        $contacts = $list->contacts()
            ->whereNotIn('id', $this->contactsAlreadySynced($list, $integration, $remoteId))
            // Blocking an address deletes its contacts, so this normally matches
            // nothing. It is the guard for the gap where it doesn't: a contact
            // added between the block and this send, or a block landing while
            // this query runs. A delivery is the last place to catch it.
            ->whereNotExists(fn (Builder $query) => $query->from('suppressions')
                ->where('suppressions.team_id', $list->team_id)
                ->whereColumn('suppressions.email', 'contacts.email'));

        $total = $contacts->clone()->count();

        if ($total === 0) {
            return null;
        }

        $paced = $contactsPerHour !== null && $contactsPerHour > 0;
        $now = now();

        $delivery = $list->deliveries()->create([
            'team_id' => $list->team_id,
            'integration_id' => $integration->id,
            'remote_id' => $remoteId,
            'remote_name' => $remoteName,
            'status' => Delivery::STATUS_PROCESSING,
            'contacts_per_hour' => $paced ? $contactsPerHour : null,
            'pacing_started_at' => $paced ? $now : null,
            'total_count' => $total,
        ]);

        // Bulk insert rather than create-per-contact: a large list would
        // otherwise spend the whole request round-tripping the database.
        $contacts->select('contacts.id')
            ->lazyById(self::INSERT_CHUNK)
            ->chunk(self::INSERT_CHUNK)
            ->each(function (LazyCollection $chunk) use ($delivery, $paced, $now): void {
                DeliveryContact::insert($chunk->map(fn ($contact): array => [
                    'delivery_id' => $delivery->id,
                    'contact_id' => $contact->id,
                    'status' => ContactStatus::Pending->value,
                    'released_at' => $paced ? null : $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });

        if (! $paced) {
            $delivery->deliveryContacts()
                ->lazyById(self::INSERT_CHUNK)
                ->each(fn (DeliveryContact $deliveryContact) => PushDeliveryContact::dispatch($deliveryContact));
        }

        return $delivery;
    }

    /**
     * The ids of contacts on the list that have already been synced to this
     * destination in a previous delivery.
     *
     * @return Collection<int, int>
     */
    private function contactsAlreadySynced(ContactList $list, Integration $integration, string $remoteId): Collection
    {
        return DeliveryContact::query()
            ->where('status', ContactStatus::Synced)
            ->whereHas('delivery', fn ($query) => $query
                ->where('contact_list_id', $list->id)
                ->where('integration_id', $integration->id)
                ->where('remote_id', $remoteId))
            ->pluck('contact_id');
    }
}
