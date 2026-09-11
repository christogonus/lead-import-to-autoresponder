<?php

namespace App\Actions\Suppressions;

/**
 * What blocking a batch of addresses actually did, so the UI can report it
 * without going back to the database for rows that no longer exist.
 */
class SuppressionResult
{
    /**
     * @param  int  $blocked  Addresses added to the do-not-contact list.
     * @param  int  $alreadyBlocked  Addresses that were already on it.
     * @param  array<int, string>  $invalid  Input that was not an email address.
     * @param  int  $contactsRemoved  Contact rows deleted across every list.
     * @param  int  $listsAffected  Lists those contacts were removed from.
     */
    public function __construct(
        public int $blocked = 0,
        public int $alreadyBlocked = 0,
        public array $invalid = [],
        public int $contactsRemoved = 0,
        public int $listsAffected = 0,
    ) {}

    /**
     * How many addresses were accepted, whether or not they were new.
     */
    public function accepted(): int
    {
        return $this->blocked + $this->alreadyBlocked;
    }
}
