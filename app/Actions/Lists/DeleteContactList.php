<?php

namespace App\Actions\Lists;

use App\Models\ContactList;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Permanently deletes a drafted list and the contacts on it. There is no undo,
 * which is why only a draft can be passed here: setting a list aside is the
 * reversible step, and deleting is the deliberate second one.
 *
 * Deliveries are kept. A push to a destination already happened and cannot be
 * taken back, so the record of it survives the list with the list's name
 * snapshotted onto it. Imports go with the list — they only describe how the
 * contacts being deleted got there.
 */
class DeleteContactList
{
    /**
     * @throws RuntimeException when the list has not been drafted first.
     */
    public function handle(ContactList $list): void
    {
        if (! $list->isDeletable()) {
            throw new RuntimeException('Only a drafted list can be deleted.');
        }

        DB::transaction(function () use ($list): void {
            $list->deliveries()->update(['contact_list_name' => $list->name]);

            // Contacts, imports, and each delivery's per-contact rows are
            // cascaded away by their foreign keys; the deliveries themselves
            // have their list reference nulled rather than cascaded.
            $list->delete();
        });
    }
}
