<?php

namespace App\Actions\Lists;

use App\Models\Contact;
use App\Models\ContactList;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * Copies a list's contacts into new, smaller lists of at most the given size,
 * named "{list name} {size} {n}" in order. The source list is left untouched:
 * this exists for providers that cap subscribers, where only a slice of a large
 * list can be imported at a time.
 */
class SplitContactList
{
    /**
     * How many contact rows to build per insert.
     */
    private const INSERT_CHUNK = 1000;

    /**
     * Split the list, returning the newly created lists in order.
     *
     * @return Collection<int, ContactList>
     */
    public function handle(ContactList $list, int $chunkSize): Collection
    {
        $splits = collect();
        $now = now();

        $list->contacts()
            ->lazyById(self::INSERT_CHUNK)
            ->chunk($chunkSize)
            ->each(function (LazyCollection $contacts) use ($list, $chunkSize, $now, $splits): void {
                $split = ContactList::create([
                    'team_id' => $list->team_id,
                    'name' => "{$list->name} {$chunkSize} ".($splits->count() + 1),
                ]);

                $contacts
                    ->chunk(self::INSERT_CHUNK)
                    ->each(fn (LazyCollection $rows) => Contact::insert(
                        $rows->map(fn (Contact $contact): array => [
                            'team_id' => $contact->team_id,
                            'contact_list_id' => $split->id,
                            'first_name' => $contact->first_name,
                            'last_name' => $contact->last_name,
                            'email' => $contact->email,
                            'phone' => $contact->phone,
                            'country' => $contact->country,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ])->values()->all(),
                    ));

                $splits->push($split);
            });

        return $splits;
    }
}
