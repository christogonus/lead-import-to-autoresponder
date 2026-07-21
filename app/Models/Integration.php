<?php

namespace App\Models;

use App\Enums\IntegrationProvider;
use Database\Factories\IntegrationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['team_id', 'provider', 'name', 'credentials', 'status', 'last_verified_at'])]
class Integration extends Model
{
    /** @use HasFactory<IntegrationFactory> */
    use HasFactory;

    /**
     * Get the team the integration belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the contact lists that push to this integration.
     *
     * @return HasMany<ContactList, $this>
     */
    public function contactLists(): HasMany
    {
        return $this->hasMany(ContactList::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => IntegrationProvider::class,
            'credentials' => 'encrypted:array',
            'last_verified_at' => 'datetime',
        ];
    }
}
