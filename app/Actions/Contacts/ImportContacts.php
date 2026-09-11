<?php

namespace App\Actions\Contacts;

use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Import;
use App\Models\Suppression;
use App\Models\User;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * Imports a batch of normalized contact rows into a list: validates and
 * de-duplicates by email, then stores each contact locally. Contacts are not
 * sent anywhere at import time — sending a list to a destination is a separate
 * action. Records the outcome as an Import.
 */
class ImportContacts
{
    /**
     * How many addresses to look up against the do-not-contact list at a time.
     */
    private const LOOKUP_CHUNK = 1000;

    /**
     * @param  array<int, array{first_name?: ?string, last_name?: ?string, email?: ?string, phone?: ?string, country?: ?string}>  $rows
     *
     * @throws RuntimeException when the list has been set aside as a draft.
     */
    public function handle(
        ContactList $list,
        array $rows,
        string $source,
        ?string $filename = null,
        ?User $user = null,
    ): Import {
        if ($list->isDraft()) {
            throw new RuntimeException('Contacts cannot be imported into a drafted list.');
        }

        $import = Import::create([
            'team_id' => $list->team_id,
            'contact_list_id' => $list->id,
            'user_id' => $user?->id,
            'source' => $source,
            'filename' => $filename,
            'total_rows' => count($rows),
            'status' => Import::STATUS_PROCESSING,
        ]);

        try {
            return $this->store($import, $list, $rows);
        } catch (Throwable $e) {
            // Never leave the row reading "processing": that state gates whether
            // the list can be drafted, so a failed import that kept it would
            // block the list until the staleness window expired.
            $import->update(['status' => Import::STATUS_FAILED]);

            throw $e;
        }
    }

    /**
     * Validate, de-duplicate, and store the rows, recording the outcome on the
     * import.
     *
     * @param  array<int, array{first_name?: ?string, last_name?: ?string, email?: ?string, phone?: ?string, country?: ?string}>  $rows
     */
    private function store(Import $import, ContactList $list, array $rows): Import
    {
        $existingEmails = $list->contacts()->pluck('email')
            ->map(fn (string $email): string => strtolower($email))
            ->flip();

        $blockedEmails = $this->blockedEmails($list, $rows);

        $imported = 0;
        $skipped = 0;
        $suppressed = 0;
        $failed = 0;
        $now = now();
        $pending = [];

        foreach ($rows as $row) {
            $email = strtolower(trim((string) ($row['email'] ?? '')));

            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $failed++;

                continue;
            }

            // An address on the team's do-not-contact list bounced or asked to
            // be removed. Importing it again would quietly undo that.
            if ($blockedEmails->has($email)) {
                $suppressed++;

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
            'suppressed_count' => $suppressed,
            'failed_count' => $failed,
            'status' => Import::STATUS_COMPLETED,
        ]);

        return $import;
    }

    /**
     * The addresses in this batch that the team has blocked, as a lookup set.
     *
     * Queried by the batch's own addresses rather than by reading the whole
     * do-not-contact list: the number of queries then follows the size of the
     * import, and a team with a long history of bounces does not have to load
     * every one of them to import ten rows.
     *
     * @param  array<int, array{email?: ?string}>  $rows
     * @return Collection<string, bool>
     */
    private function blockedEmails(ContactList $list, array $rows): Collection
    {
        return collect($rows)
            ->map(fn (array $row): string => Suppression::normalize((string) ($row['email'] ?? '')))
            ->filter()
            ->unique()
            ->chunk(self::LOOKUP_CHUNK)
            ->flatMap(fn (Collection $chunk): Collection => Suppression::query()
                ->blocking($list->team_id, $chunk)
                ->pluck('email'))
            ->flip();
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
