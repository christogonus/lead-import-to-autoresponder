<?php

namespace App\Actions\Lists;

use App\Models\ContactList;
use App\Models\Team;

/**
 * Empties a team's drafts: permanently deletes every list set aside there,
 * along with its contacts. The bin equivalent of deleting one draft at a time.
 *
 * Each list still goes through DeleteContactList, so every draft is deleted on
 * exactly the terms a single delete uses — deliveries kept, list name
 * snapshotted onto them — and a list that is somehow no longer a draft when its
 * turn comes is refused rather than swept up.
 *
 * Deliberately not one transaction around the whole sweep: a team emptying a
 * long-standing bin can be deleting hundreds of thousands of contacts, and one
 * lock held across all of it would be worse than a sweep that stops partway
 * with the drafts it did not reach still sitting there.
 */
class DeleteDraftedLists
{
    /**
     * How many drafts to load at a time.
     */
    private const CHUNK = 100;

    public function __construct(private DeleteContactList $deleter) {}

    /**
     * Delete every drafted list on the team, returning what was destroyed.
     *
     * @return array{lists: int, contacts: int}
     */
    public function handle(Team $team): array
    {
        $lists = 0;
        $contacts = 0;

        $team->contactLists()
            ->drafted()
            ->withCount('contacts')
            ->lazyById(self::CHUNK)
            ->each(function (ContactList $list) use (&$lists, &$contacts): void {
                $this->deleter->handle($list);

                $lists++;
                $contacts += $list->contacts_count;
            });

        return ['lists' => $lists, 'contacts' => $contacts];
    }
}
