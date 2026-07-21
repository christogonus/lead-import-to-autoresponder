<?php

namespace App\Actions\Contacts;

use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Import;
use App\Models\User;

/**
 * Imports a batch of normalized contact rows into a list: validates and
 * de-duplicates by email, then stores each contact locally. Contacts are not
 * sent anywhere at import time — sending a list to a destination is a separate
 * action. Records the outcome as an Import.
 */
class ImportContacts
{
    /**
     * @param  array<int, array{first_name?: ?string, last_name?: ?string, email?: ?string, phone?: ?string, country?: ?string}>  $rows
     */
    public function handle(
        ContactList $list,
        array $rows,
        string $source,
        ?string $filename = null,
        ?User $user = null,
    ): Import {
        $import = Import::create([
            'team_id' => $list->team_id,
            'contact_list_id' => $list->id,
            'user_id' => $user?->id,
            'source' => $source,
            'filename' => $filename,
            'total_rows' => count($rows),
            'status' => 'processing',
        ]);

        $existingEmails = $list->contacts()->pluck('email')
            ->map(fn (string $email): string => strtolower($email))
            ->flip();

        $imported = 0;
        $skipped = 0;
        $failed = 0;
        $now = now();
        $pending = [];

        foreach ($rows as $row) {
            $email = strtolower(trim((string) ($row['email'] ?? '')));

            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $failed++;

                continue;
            }

            if ($existingEmails->has($email)) {
                $skipped++;

                continue;
            }

            $existingEmails->put($email, true);

            $pending[] = [
                'team_id' => $list->team_id,
                'contact_list_id' => $list->id,
                'import_id' => $import->id,
                'first_name' => $this->clean($row['first_name'] ?? null) ?? Contact::DEFAULT_FIRST_NAME,
                'last_name' => $this->clean($row['last_name'] ?? null),
                'email' => $email,
                'phone' => $this->clean($row['phone'] ?? null),
                'country' => $this->clean($row['country'] ?? null),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $imported++;
        }

        // Bulk insert in chunks so large imports do not fire one query per row.
        //
        // insertOrIgnore, not insert: the duplicate check above reads the list's
        // emails once, up front, so a contact added by a concurrent import lands
        // after that snapshot and would violate the unique index — taking the
        // whole 1,000-row chunk down with it. Ignoring leans on the constraint
        // as the real authority and drops just the offending row.
        $inserted = 0;

        foreach (array_chunk($pending, 1000) as $chunk) {
            $inserted += Contact::insertOrIgnore($chunk);
        }

        $import->update([
            // Report what the database actually accepted. Anything the unique
            // index turned away was a duplicate, so it belongs in the skipped
            // tally rather than silently inflating the imported one.
            'imported_count' => $inserted,
            'skipped_count' => $skipped + ($imported - $inserted),
            'failed_count' => $failed,
            'status' => 'completed',
        ]);

        return $import;
    }

    /**
     * Trim a value, returning null when it is empty.
     */
    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
