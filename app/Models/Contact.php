<?php

namespace App\Models;

use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'team_id',
    'contact_list_id',
    'import_id',
    'first_name',
    'last_name',
    'email',
    'phone',
    'country',
])]
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    /**
     * Graceful fallback used when a contact is imported without a first name,
     * so greetings read naturally (e.g. "Hi there,"). Kept here (not only as a
     * DB column default) because the importer bulk inserts explicit values, and
     * a column default is skipped whenever a value — even null — is supplied.
     * The last name is left blank when not provided.
     */
    public const DEFAULT_FIRST_NAME = 'there';

    /**
     * The model's default attribute values.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'first_name' => self::DEFAULT_FIRST_NAME,
    ];

    /**
     * Get the team the contact belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the list the contact belongs to.
     *
     * @return BelongsTo<ContactList, $this>
     */
    public function contactList(): BelongsTo
    {
        return $this->belongsTo(ContactList::class);
    }

    /**
     * Get the import the contact was created by, if any.
     *
     * @return BelongsTo<Import, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    /**
     * The per-delivery rows tracking where this contact has been sent.
     *
     * @return HasMany<DeliveryContact, $this>
     */
    public function deliveryContacts(): HasMany
    {
        return $this->hasMany(DeliveryContact::class);
    }

    /**
     * Get the full name of the contact.
     */
    public function fullName(): string
    {
        return trim(implode(' ', array_filter([$this->first_name, $this->last_name])));
    }

    /**
     * The contact's Gravatar image URL, hashed per the Gravatar spec (SHA256 of
     * the trimmed, lowercased email). Requests d=404 so an address without a
     * Gravatar returns nothing rather than a generic silhouette, letting the UI
     * fall back to initials.
     *
     * @param  int  $size  Pixel dimension requested; images are square.
     */
    public function gravatarUrl(int $size = 64): string
    {
        $hash = hash('sha256', strtolower(trim((string) $this->email)));

        return 'https://gravatar.com/avatar/'.$hash.'?'.http_build_query([
            's' => $size,
            'd' => '404',
            'r' => 'pg',
        ]);
    }

    /**
     * Initials for the avatar fallback. Falls back to the email when the contact
     * carries only the default placeholder first name, so imported rows without
     * a real name don't all render an identical "T".
     */
    public function initials(): string
    {
        $named = $this->first_name !== self::DEFAULT_FIRST_NAME || $this->last_name;

        $source = $named ? $this->fullName() : (string) $this->email;

        $initials = collect(preg_split('/[\s@._-]+/', $source, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');

        return $initials !== '' ? $initials : '?';
    }
}
