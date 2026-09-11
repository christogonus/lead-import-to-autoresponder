<?php

namespace App\Actions\Suppressions;

use App\Enums\SuppressionReason;
use App\Models\Contact;
use App\Models\Delivery;
use App\Models\DeliveryContact;
use App\Models\Suppression;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Blocks addresses for a team: records each one on the do-not-contact list and
 * deletes it from every list the team holds, draft or not.
 *
 * The block is what makes the removal stick. Deleting the contacts alone would
 * last until the next import of the same file; the recorded address keeps them
 * out of future imports and out of every delivery from here on.
 *
 * Re-blocking an address that is already on the list is not an error — it
 * sweeps up any contact that slipped back in, so running it twice is safe.
 */
class SuppressEmails
{
    /**
     * How many ids to name in one delete/lookup, so blocking an address that
     * appears on hundreds of lists cannot build a query without an end.
     */
    private const CHUNK = 500;

    /**
     * @param  array<int, string>  $emails  Raw addresses; invalid ones are reported back, not thrown on.
     */
    public function handle(
        Team $team,
        array $emails,
        SuppressionReason $reason = SuppressionReason::Unsubscribed,
        ?User $user = null,
    ): SuppressionResult {
        $invalid = [];
        $valid = collect();

        foreach ($emails as $email) {
            $normalized = Suppression::normalize((string) $email);

            if ($normalized === '') {
                continue;
            }

            if (! filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
                $invalid[] = $normalized;

                continue;
            }

            $valid->push($normalized);
        }

        $valid = $valid->unique()->values();

        if ($valid->isEmpty()) {
            return new SuppressionResult(invalid: $invalid);
        }

        return DB::transaction(function () use ($team, $valid, $reason, $user, $invalid): SuppressionResult {
            $removal = $this->removeContacts($team, $valid);

            $alreadyBlocked = Suppression::query()
                ->blocking($team->id, $valid)
                ->pluck('email');

            $now = now();

            $rows = $valid->diff($alreadyBlocked)
                ->map(fn (string $email): array => [
                    'team_id' => $team->id,
                    'user_id' => $user?->id,
                    'email' => $email,
                    'reason' => $reason->value,
                    // What this block removed, not what the address has cost
                    // over its lifetime: a repeat block reports its own sweep.
                    'removed_count' => $removal['per_email'][$email] ?? 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all();

            // insertOrIgnore, not insert: the read above is a snapshot, and a
            // concurrent block of the same address would otherwise collide with
            // the unique index and take the whole batch down with it.
            $blocked = Suppression::insertOrIgnore($rows);

            return new SuppressionResult(
                blocked: $blocked,
                alreadyBlocked: $valid->count() - $blocked,
                invalid: $invalid,
                contactsRemoved: $removal['contacts'],
                listsAffected: $removal['lists'],
            );
        });
    }

    /**
     * Delete every contact the team holds with one of these addresses and settle
     * the deliveries left behind.
     *
     * @param  Collection<int, string>  $emails
     * @return array{contacts: int, lists: int, per_email: array<string, int>}
     */
    private function removeContacts(Team $team, Collection $emails): array
    {
        $contacts = $team->contacts()
            ->whereIn('email', $emails)
            ->get(['id', 'contact_list_id', 'email']);

        if ($contacts->isEmpty()) {
            return ['contacts' => 0, 'lists' => 0, 'per_email' => []];
        }

        $contactIds = $contacts->pluck('id');

        // Read before the delete: the contact's delivery rows are cascaded away
        // with it, taking the only pointer back to the deliveries with them.
        $deliveryIds = $contactIds->chunk(self::CHUNK)
            ->flatMap(fn (Collection $chunk): Collection => DeliveryContact::query()
                ->whereIn('contact_id', $chunk)
                ->distinct()
                ->pluck('delivery_id'))
            ->unique();

        $contactIds->chunk(self::CHUNK)
            ->each(fn (Collection $chunk) => Contact::query()->whereIn('id', $chunk)->delete());

        // A delivery whose last pending contact just vanished would otherwise
        // read "Sending" forever, and its totals would count rows that are gone.
        $deliveryIds->chunk(self::CHUNK)
            ->each(fn (Collection $chunk) => Delivery::query()->findMany($chunk)->each->recount());

        return [
            'contacts' => $contacts->count(),
            'lists' => $contacts->pluck('contact_list_id')->unique()->count(),
            'per_email' => $contacts->countBy('email')->all(),
        ];
    }
}
