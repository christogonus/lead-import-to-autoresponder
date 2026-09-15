<?php

namespace App\Actions\Lists;

use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Delivery;
use App\Models\DeliveryContact;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Removes from one list every contact whose email address is also on another
 * list — so a list can be sent without mailing the people another list already
 * reached. The other list is only read, never changed.
 *
 * Emails are stored trimmed and lowercased, so matching is an exact comparison.
 *
 * Deliberately not one transaction: trimming a large list can mean deleting
 * tens of thousands of contacts, and a run that stops partway can simply be run
 * again to finish the job.
 */
class RemoveListOverlap
{
    /**
     * How many contacts to delete at a time.
     */
    private const CHUNK = 500;

    /**
     * Delete the contacts on the target list that also appear on the reference
     * list, returning how many were removed.
     *
     * @throws RuntimeException when the lists are the same, belong to different
     *                          teams, or the target has been set aside as a draft.
     */
    public function handle(ContactList $target, ContactList $reference): int
    {
        $this->guard($target, $reference);

        $removed = 0;
        $deliveryIds = collect();

        $this->overlapping($target, $reference)
            ->select('id')
            ->chunkById(self::CHUNK, function (Collection $contacts) use (&$removed, &$deliveryIds): void {
                $ids = $contacts->pluck('id');

                // Read before the delete: the contacts' delivery rows are
                // cascaded away with them, taking the pointer to the delivery.
                $deliveryIds = $deliveryIds->merge(
                    DeliveryContact::query()->whereIn('contact_id', $ids)->distinct()->pluck('delivery_id'),
                );

                $removed += Contact::query()->whereIn('id', $ids)->delete();
            });

        // A delivery whose last pending contact just vanished would otherwise
        // read "Sending" forever, and its totals would count rows that are gone.
        $deliveryIds->unique()
            ->chunk(self::CHUNK)
            ->each(fn (Collection $chunk) => Delivery::query()->findMany($chunk)->each->recount());

        return $removed;
    }

    /**
     * How many contacts running handle() would remove right now.
     */
    public function count(ContactList $target, ContactList $reference): int
    {
        return $this->overlapping($target, $reference)->count();
    }

    /**
     * The target's contacts whose email is also on the reference list.
     *
     * @return HasMany<Contact, ContactList>
     */
    private function overlapping(ContactList $target, ContactList $reference): HasMany
    {
        return $target->contacts()
            ->whereIn('email', $reference->contacts()->select('email'));
    }

    /**
     * @throws RuntimeException
     */
    private function guard(ContactList $target, ContactList $reference): void
    {
        if ($target->is($reference)) {
            throw new RuntimeException('A list cannot be compared with itself.');
        }

        if ($target->team_id !== $reference->team_id) {
            throw new RuntimeException('Lists from different teams cannot be compared.');
        }

        if ($target->isDraft()) {
            throw new RuntimeException('Contacts cannot be removed from a drafted list.');
        }
    }
}
