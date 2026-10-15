<?php

namespace App\Actions\Privacy;

use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Delivery;
use App\Models\Integration;
use App\Models\Suppression;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;
use Laravel\Paddle\Subscription;
use Laravel\Paddle\Transaction;

/**
 * Writes out everything Lead2Crm holds about a user as one JSON document: the
 * GDPR right of access (Article 15) and data portability (Article 20).
 *
 * Teams the user owns are exported in full, contacts included, because those
 * lists are theirs. Teams they were only invited to are listed by name and
 * role; that data belongs to the owner. Integration credentials are never
 * written out: they are secrets, not personal data, and a download is far
 * easier to leak than an encrypted column.
 *
 * The document is streamed, so a workspace with a million contacts never sits
 * in memory whole.
 */
class ExportPersonalData
{
    private const CHUNK = 1000;

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * Write the export to the given stream.
     *
     * @param  resource  $stream
     */
    public function handle(User $user, $stream): void
    {
        fwrite($stream, '{');

        $this->field($stream, 'exported_at', now()->toIso8601String(), first: true);
        $this->field($stream, 'account', [
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $this->date($user->email_verified_at),
            'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
            'created_at' => $this->date($user->created_at),
            'updated_at' => $this->date($user->updated_at),
        ]);

        $this->field($stream, 'billing', [
            'subscriptions' => $user->subscriptions()->with('items')->get()->map(fn (Subscription $subscription): array => [
                'status' => $subscription->status,
                'prices' => $subscription->items->pluck('price_id')->all(),
                'ends_at' => $this->date($subscription->ends_at),
                'created_at' => $this->date($subscription->created_at),
            ])->all(),
            'payments' => $user->transactions()->get()->map(fn (Transaction $transaction): array => [
                'invoice_number' => $transaction->invoice_number,
                'status' => $transaction->status,
                'total' => $transaction->total,
                'tax' => $transaction->tax,
                'currency' => $transaction->currency,
                'billed_at' => $this->date($transaction->billed_at),
            ])->all(),
        ]);

        fwrite($stream, ',"teams":[');

        $user->teams()->orderBy('teams.id')->get()->each(function (Team $team, int $index) use ($stream, $user): void {
            if ($index > 0) {
                fwrite($stream, ',');
            }

            $this->team($stream, $team, $user);
        });

        fwrite($stream, ']}');
    }

    /**
     * The download's file name.
     */
    public function fileName(): string
    {
        return 'lead2crm-data-export-'.now()->format('Y-m-d').'.json';
    }

    /**
     * @param  resource  $stream
     */
    private function team($stream, Team $team, User $user): void
    {
        $owns = $user->ownsTeam($team);

        fwrite($stream, '{');
        $this->field($stream, 'name', $team->name, first: true);
        $this->field($stream, 'role', $user->teamRole($team)?->value);

        if (! $owns) {
            $this->field($stream, 'note', 'You were invited to this team. Its lists belong to the team owner and are not part of your export.');
            fwrite($stream, '}');

            return;
        }

        $this->field($stream, 'integrations', $team->integrations()->get()->map(fn (Integration $integration): array => [
            'provider' => $integration->provider->label(),
            'name' => $integration->name,
            'status' => $integration->status,
            'connected_at' => $this->date($integration->created_at),
        ])->all());

        $this->field($stream, 'deliveries', $team->deliveries()->get()->map(fn (Delivery $delivery): array => [
            'list' => $delivery->contact_list_name,
            'destination' => $delivery->remote_name,
            'status' => $delivery->status,
            'total' => $delivery->total_count,
            'synced' => $delivery->synced_count,
            'failed' => $delivery->failed_count,
            'sent_at' => $this->date($delivery->created_at),
        ])->all());

        $this->field($stream, 'do_not_contact', $team->suppressions()->get()->map(fn (Suppression $suppression): array => [
            'email' => $suppression->email,
            'reason' => $suppression->reason->value,
            'added_at' => $this->date($suppression->created_at),
        ])->all());

        fwrite($stream, ',"lists":[');

        $team->contactLists()->orderBy('id')->get()->each(function (ContactList $list, int $index) use ($stream): void {
            if ($index > 0) {
                fwrite($stream, ',');
            }

            fwrite($stream, '{');
            $this->field($stream, 'name', $list->name, first: true);
            $this->field($stream, 'created_at', $this->date($list->created_at));
            fwrite($stream, ',"contacts":[');

            $first = true;

            $list->contacts()->lazyById(self::CHUNK)->each(function (Contact $contact) use ($stream, &$first): void {
                fwrite($stream, ($first ? '' : ',').json_encode([
                    'first_name' => $contact->first_name === Contact::DEFAULT_FIRST_NAME ? null : $contact->first_name,
                    'last_name' => $contact->last_name,
                    'email' => $contact->email,
                    'phone' => $contact->phone,
                    'country' => $contact->country,
                ], self::JSON_FLAGS));

                $first = false;
            });

            fwrite($stream, ']}');
        });

        fwrite($stream, ']}');
    }

    /**
     * @param  resource  $stream
     */
    private function field($stream, string $key, mixed $value, bool $first = false): void
    {
        fwrite($stream, ($first ? '' : ',').json_encode($key, self::JSON_FLAGS).':'.json_encode($value, self::JSON_FLAGS));
    }

    private function date(?CarbonInterface $date): ?string
    {
        return $date?->toIso8601String();
    }
}
