<?php

namespace App\Models;

use Database\Factories\ImportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'team_id',
    'contact_list_id',
    'user_id',
    'source',
    'filename',
    'total_rows',
    'imported_count',
    'skipped_count',
    'failed_count',
    'status',
])]
class Import extends Model
{
    /** @use HasFactory<ImportFactory> */
    use HasFactory;

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * How long an import may sit in "processing" before it is treated as dead.
     *
     * Imports run synchronously inside the request, so one still processing an
     * hour later did not finish: the process died without unwinding — a request
     * timeout or a memory limit — taking with it the chance to mark the row
     * failed. Since a running import blocks its list from being drafted, a
     * stranded row would otherwise block that list forever, with nothing in the
     * UI able to clear it.
     */
    public const STALE_AFTER_MINUTES = 60;

    /**
     * Scope a query to imports that are genuinely still running, ignoring rows
     * stranded in "processing" by a crash.
     */
    #[Scope]
    protected function running(Builder $query): void
    {
        $query->where('status', self::STATUS_PROCESSING)
            ->where('created_at', '>=', now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    /**
     * Get the team the import belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the list the import targeted.
     *
     * @return BelongsTo<ContactList, $this>
     */
    public function contactList(): BelongsTo
    {
        return $this->belongsTo(ContactList::class);
    }

    /**
     * Get the user who ran the import.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the contacts created by this import.
     *
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }
}
