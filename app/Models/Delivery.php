<?php

namespace App\Models;

use App\Enums\ContactStatus;
use App\Jobs\PushDeliveryContact;
use Database\Factories\DeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single send of a contact list to a destination (a connected integration +
 * one of its remote lists). A list can be sent to many destinations over time,
 * each recorded as its own delivery with independent per-contact sync status.
 */
#[Fillable([
    'team_id',
    'contact_list_id',
    'contact_list_name',
    'integration_id',
    'remote_id',
    'remote_name',
    'status',
    'contacts_per_hour',
    'pacing_started_at',
    'total_count',
    'synced_count',
    'failed_count',
])]
class Delivery extends Model
{
    /** @use HasFactory<DeliveryFactory> */
    use HasFactory;

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<ContactList, $this>
     */
    public function contactList(): BelongsTo
    {
        return $this->belongsTo(ContactList::class);
    }

    /**
     * The destination integration this delivery pushes to.
     *
     * @return BelongsTo<Integration, $this>
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /**
     * The per-contact rows tracking this delivery's push to each contact.
     *
     * @return HasMany<DeliveryContact, $this>
     */
    public function deliveryContacts(): HasMany
    {
        return $this->hasMany(DeliveryContact::class);
    }

    /**
     * Recalculate the synced/failed counts from the delivery's contacts and mark
     * the delivery completed once none remain pending.
     */
    public function recount(): void
    {
        $counts = $this->deliveryContacts()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $pending = (int) $counts->get(ContactStatus::Pending->value, 0);

        $attributes = [
            // Refreshed alongside the outcome counts: deleting a contact
            // cascades its delivery rows away, and a total frozen at creation
            // would leave the delivery reading "9 of 10 synced" forever.
            'total_count' => (int) $counts->sum(),
            'synced_count' => (int) $counts->get(ContactStatus::Synced->value, 0),
            'failed_count' => (int) $counts->get(ContactStatus::Failed->value, 0),
        ];

        // Read the status straight from the database rather than trusting this
        // instance: a job that started before the cancel landed still holds a
        // stale delivery, and reporting its outcome must not resurrect a
        // cancelled delivery as processing or complete it.
        if ($this->currentStatus() !== self::STATUS_CANCELLED) {
            $attributes['status'] = $pending === 0 ? self::STATUS_COMPLETED : self::STATUS_PROCESSING;
        }

        $this->update($attributes);
    }

    /**
     * Stop this delivery: every contact still waiting is marked cancelled and
     * will never be pushed. Contacts already synced are left untouched at the
     * destination, which no provider API lets us reliably undo in bulk.
     */
    public function cancel(): void
    {
        $this->deliveryContacts()
            ->where('status', ContactStatus::Pending)
            ->update(['status' => ContactStatus::Cancelled]);

        $this->update(['status' => self::STATUS_CANCELLED]);
    }

    /**
     * Restart a cancelled delivery, putting its stood-down contacts back in the
     * queue. Contacts that failed are left alone — those are the retry action's
     * business, not this one's.
     */
    public function resume(): void
    {
        $paced = $this->isPaced();

        // Clear the cancelled status first. PushDeliveryContact refuses to push
        // for a cancelled delivery, so dispatching before this would have every
        // resumed job bail the moment it ran.
        $this->update([
            'status' => self::STATUS_PROCESSING,
            // Restart the clock so the resumed contacts are metered from now,
            // rather than releasing the whole backlog the pause accrued.
            'pacing_started_at' => $paced ? now() : null,
        ]);

        $this->deliveryContacts()
            ->where('status', ContactStatus::Cancelled)
            ->get()
            ->each(function (DeliveryContact $deliveryContact) use ($paced): void {
                $deliveryContact->update([
                    'status' => ContactStatus::Pending,
                    'sync_error' => null,
                    'released_at' => $paced ? null : now(),
                ]);

                if (! $paced) {
                    PushDeliveryContact::dispatch($deliveryContact);
                }
            });

        // Settles the status: with nothing left to stand back up, the delivery
        // is complete rather than stuck reporting that it is sending.
        $this->recount();
    }

    /**
     * Whether this delivery still has work that cancelling would stop.
     */
    public function isCancellable(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Whether this delivery was cancelled and so can be started again.
     */
    public function isResumable(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * This delivery's status as currently stored, bypassing any value held on
     * this instance.
     */
    public function currentStatus(): ?string
    {
        return $this->newQuery()->whereKey($this->getKey())->value('status');
    }

    /**
     * A human-readable label for the delivery's status.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_COMPLETED => __('Completed'),
            self::STATUS_CANCELLED => __('Cancelled'),
            default => __('Sending'),
        };
    }

    /**
     * The Flux badge color for the delivery's status.
     */
    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_COMPLETED => 'green',
            self::STATUS_CANCELLED => 'amber',
            default => 'zinc',
        };
    }

    /**
     * A human-readable label for the destination this delivery targets.
     */
    public function destinationLabel(): string
    {
        return $this->remote_name ?? $this->remote_id;
    }

    /**
     * A human-readable label for the list this delivery was sent from, falling
     * back to the name snapshotted when that list was permanently deleted.
     */
    public function listLabel(): string
    {
        return $this->contactList?->name
            ?? $this->contact_list_name
            ?? __('Deleted list');
    }

    /**
     * Whether the list this delivery was sent from has since been deleted, so
     * there is nowhere to link through to.
     */
    public function listWasDeleted(): bool
    {
        return $this->contact_list_id === null;
    }

    /**
     * Whether this delivery drips its contacts out at a capped hourly rate
     * rather than queueing them all at once.
     */
    public function isPaced(): bool
    {
        return $this->contacts_per_hour !== null && $this->pacing_started_at !== null;
    }

    /**
     * How many contacts the hourly rate permits to have been queued by now,
     * counted from when pacing started.
     *
     * Deliberately cumulative rather than per-tick: a missed scheduler run, a
     * deploy, or a rate below 60/hour would each stall a per-tick quota, but
     * simply widen the gap this catches up on.
     */
    public function pacingAllowance(): int
    {
        if (! $this->isPaced()) {
            return 0;
        }

        $elapsedSeconds = max(0, $this->pacing_started_at->diffInSeconds(now()));

        return (int) floor($elapsedSeconds * $this->contacts_per_hour / 3600);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pacing_started_at' => 'datetime',
        ];
    }
}
