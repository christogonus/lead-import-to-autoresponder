<?php

namespace App\Models;

use Database\Factories\ContactListFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['team_id', 'name'])]
class ContactList extends Model
{
    /** @use HasFactory<ContactListFactory> */
    use HasFactory;

    /**
     * Get the team the list belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the contacts on this list.
     *
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * Get the deliveries (sends to a destination) made from this list.
     *
     * @return HasMany<Delivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    /**
     * Get the imports run against this list.
     *
     * @return HasMany<Import, $this>
     */
    public function imports(): HasMany
    {
        return $this->hasMany(Import::class);
    }

    /**
     * Scope a query to the lists still in use.
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('drafted_at');
    }

    /**
     * Scope a query to the lists that have been set aside as drafts.
     */
    #[Scope]
    protected function drafted(Builder $query): void
    {
        $query->whereNotNull('drafted_at');
    }

    /**
     * Whether this list has been set aside as a draft.
     */
    public function isDraft(): bool
    {
        return $this->drafted_at !== null;
    }

    /**
     * Whether this list can be set aside right now.
     */
    public function isDraftable(): bool
    {
        return ! $this->isDraft() && ! $this->hasWorkInProgress();
    }

    /**
     * Whether this list can be permanently deleted. Drafting first is the only
     * route to deletion, so an in-use list can never be destroyed in one step.
     */
    public function isDeletable(): bool
    {
        return $this->isDraft();
    }

    /**
     * Whether a delivery or import is still running against this list. Drafting
     * mid-send would leave queued jobs pushing contacts from a list the team
     * believes it has already set aside.
     */
    public function hasWorkInProgress(): bool
    {
        return $this->deliveries()->where('status', Delivery::STATUS_PROCESSING)->exists()
            || $this->imports()->running()->exists();
    }

    /**
     * Set this list aside. Its contacts are kept — drafting is reversible, and
     * only the permanent delete that a draft unlocks destroys anything.
     */
    public function draft(): void
    {
        // Assigned rather than mass-assigned: 'drafted_at' is deliberately
        // absent from the fillable attributes so no user input can set it.
        $this->drafted_at = now();
        $this->save();
    }

    /**
     * Bring this list back into use.
     */
    public function restoreFromDraft(): void
    {
        $this->drafted_at = null;
        $this->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'drafted_at' => 'datetime',
        ];
    }
}
