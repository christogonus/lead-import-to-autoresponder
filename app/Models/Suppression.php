<?php

namespace App\Models;

use App\Enums\SuppressionReason;
use Database\Factories\SuppressionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An address on a team's do-not-contact list. Blocking removes the address from
 * every list the team holds; this row is what stops it coming back on the next
 * import and keeps every delivery from pushing it again.
 */
#[Fillable(['team_id', 'user_id', 'email', 'reason', 'removed_count'])]
class Suppression extends Model
{
    /** @use HasFactory<SuppressionFactory> */
    use HasFactory;

    /**
     * The one spelling of an address the whole app compares against. Contacts
     * are stored lowercased and trimmed on import, so blocks must be too, or a
     * "Bob@Example.com" block would let "bob@example.com" straight through.
     */
    public static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Scope a query to the blocks a team holds against the given addresses,
     * normalizing each address first.
     *
     * @param  Builder<Suppression>  $query
     * @param  iterable<int, string>  $emails
     */
    #[Scope]
    protected function blocking(Builder $query, int $teamId, iterable $emails): void
    {
        $query->where('team_id', $teamId)
            ->whereIn('email', collect($emails)->map(self::normalize(...))->unique()->values());
    }

    /**
     * Get the team the block belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the user who blocked the address, if they are still around.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => SuppressionReason::class,
        ];
    }
}
