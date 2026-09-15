<?php

namespace App\Actions\Lists;

use App\Models\ContactList;
use App\Models\Team;
use InvalidArgumentException;

/**
 * Permanently deletes every list on a team whose name matches a pattern, such
 * as "ElechyComplete 15 *" to clear away the lists a split produced.
 *
 * Matching lists on either shelf are swept up. Active ones are moved to drafts
 * first, in one query, so each list is still deleted through DeleteContactList
 * on exactly the terms a single delete uses. A list with a send or import still
 * running is left alone and reported as skipped.
 *
 * Like emptying the drafts, this is deliberately not one transaction: a sweep
 * that stops partway leaves the lists it did not reach in drafts, where running
 * it again — or emptying the drafts — finishes the job.
 */
class DeleteListsByName
{
    /**
     * How many lists to load at a time.
     */
    private const CHUNK = 100;

    public function __construct(private DeleteContactList $deleter) {}

    /**
     * Whether a pattern names something more specific than "every list". A
     * pattern made only of wildcards and spaces would match the whole team.
     */
    public static function isUsablePattern(string $pattern): bool
    {
        return trim(str_replace('*', '', $pattern)) !== '';
    }

    /**
     * Delete the team's lists matching the pattern, returning what was destroyed
     * and how many matches were skipped because work is still running on them.
     *
     * @return array{lists: int, contacts: int, skipped: int}
     *
     * @throws InvalidArgumentException when the pattern would match every list.
     */
    public function handle(Team $team, string $pattern): array
    {
        if (! self::isUsablePattern($pattern)) {
            throw new InvalidArgumentException('The pattern must name something besides wildcards.');
        }

        $lists = 0;
        $contacts = 0;

        $team->contactLists()
            ->active()
            ->nameMatches($pattern)
            ->idle()
            ->update(['drafted_at' => now()]);

        $team->contactLists()
            ->drafted()
            ->nameMatches($pattern)
            ->withCount('contacts')
            ->lazyById(self::CHUNK)
            ->each(function (ContactList $list) use (&$lists, &$contacts): void {
                $this->deleter->handle($list);

                $lists++;
                $contacts += $list->contacts_count;
            });

        $skipped = $team->contactLists()->nameMatches($pattern)->count();

        return ['lists' => $lists, 'contacts' => $contacts, 'skipped' => $skipped];
    }
}
