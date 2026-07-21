<?php

namespace App\Models;

use App\Enums\ContactStatus;
use Database\Factories\DeliveryContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracks the outcome of pushing one contact to one delivery's destination. This
 * is where sync status lives now that a contact can be sent to several
 * destinations independently.
 */
#[Fillable([
    'delivery_id',
    'contact_id',
    'status',
    'released_at',
    'remote_id',
    'sync_error',
    'synced_at',
])]
class DeliveryContact extends Model
{
    /** @use HasFactory<DeliveryContactFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Delivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Mark this contact as successfully delivered and refresh the parent counts.
     */
    public function markSynced(?string $remoteId = null): void
    {
        $this->forceFill([
            'status' => ContactStatus::Synced,
            'remote_id' => $remoteId,
            'sync_error' => null,
            'synced_at' => now(),
        ])->save();

        $this->delivery->recount();
    }

    /**
     * Mark this contact as failed to deliver and refresh the parent counts.
     */
    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => ContactStatus::Failed,
            'sync_error' => $error,
        ])->save();

        $this->delivery->recount();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ContactStatus::class,
            'released_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }
}
