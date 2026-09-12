<?php

namespace App\Actions\Lists;

use App\Models\Contact;
use App\Models\ContactList;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use RuntimeException;

/**
 * Copies the contacts from several lists into one new list, keeping a single
 * contact per email address. The source lists are left exactly as they were:
 * this exists for teams who build lists separately — one per import, per
 * webinar, per campaign — and then need one combined list to send from without
 * mailing anyone twice.
 *
 * Where the same address appears on more than one source, the copy taken is the
 * one from the earliest source list; the later ones are counted as duplicates
 * and dropped.
 */
class MergeContactLists
{
    /**
     * How many contact rows to read and build per insert.
     */
    private const INSERT_CHUNK = 1000;

    /**
     * The fewest sources a merge is worth running on.
     */
    public const MINIMUM_SOURCES = 2;

    /**
     * Merge the given lists into a newly created list of the given name.
     *
     * @param  Collection<int, ContactList>  $sources
     * @return array{list: ContactList, merged: int, duplicates: int}
     *
     * @throws RuntimeException when fewer than two lists are given, they do not
     *                          all belong to one team, or any of them has been
     *                          set aside as a draft.
     */
    public function handle(Collection $sources, string $name): array
    {
        // Sorted, not merely validated: the order decides which copy of a
        // repeated address survives, so it must not depend on the order the
        // caller happened to collect the lists in.
        $sources = $this->guard($sources)->sortBy('id')->values();

        $list = ContactList::create([
            'team_id' => $sources->first()->team_id,
            'name' => $name,
        ]);

        $now = now();
        $scanned = 0;
        $merged = 0;

        foreach ($sources as $source) {
            $source->contacts()
                ->lazyById(self::INSERT_CHUNK)
                ->chunk(self::INSERT_CHUNK)
                ->each(function (LazyCollection $contacts) use ($list, $now, &$scanned, &$merged): void {
                    $rows = $contacts->map(fn (Contact $contact): array => [
                        'team_id' => $contact->team_id,
                        'contact_list_id' => $list->id,
                        'first_name' => $contact->first_name,
                        'last_name' => $contact->last_name,
                        'email' => $contact->email,
                        'phone' => $contact->phone,
                        'country' => $contact->country,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->values()->all();

                    $scanned += count($rows);

                    // insertOrIgnore, leaning on the (contact_list_id, email)
                    // unique index to do the de-duplication: an address already
                    // copied from an earlier source is turned away by the
                    // database rather than by a set of every email seen so far,
                    // which on a merge of several large lists would have to be
                    // held in memory in its entirety.
                    $merged += Contact::insertOrIgnore($rows);
                });
        }

        return [
            'list' => $list,
            'merged' => $merged,
            'duplicates' => $scanned - $merged,
        ];
    }

    /**
     * Check the lists can be merged, returning them.
     *
     * @param  Collection<int, ContactList>  $sources
     * @return Collection<int, ContactList>
     *
     * @throws RuntimeException
     */
    private function guard(Collection $sources): Collection
    {
        if ($sources->count() < self::MINIMUM_SOURCES) {
            throw new RuntimeException('At least two lists are needed to merge.');
        }

        if ($sources->pluck('team_id')->unique()->count() > 1) {
            throw new RuntimeException('Lists from different teams cannot be merged.');
        }

        if ($sources->contains(fn (ContactList $list): bool => $list->isDraft())) {
            throw new RuntimeException('A drafted list cannot be merged.');
        }

        return $sources;
    }
}
