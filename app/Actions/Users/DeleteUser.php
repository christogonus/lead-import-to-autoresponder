<?php

namespace App\Actions\Users;

use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Laravel\Paddle\Exceptions\PaddleException;
use Laravel\Paddle\Subscription;

/**
 * Erases an account: the GDPR right to erasure (Article 17).
 *
 * The subscription is stopped at Paddle first, so nobody is billed for an
 * account that no longer exists; if Paddle cannot be reached, nothing is
 * deleted and the exception tells the caller to try again. Then every team the
 * user owns is erased for good, not soft-deleted, which cascades to its lists,
 * contacts, integrations and sends. Teams they only belong to keep running for
 * their owners.
 *
 * Paddle, as merchant of record, keeps its own copy of invoices for as long as
 * tax law requires; the local mirror of them goes with the account.
 */
class DeleteUser
{
    /**
     * @throws PaddleException|ConnectionException when the subscription could not be cancelled.
     */
    public function handle(User $user): void
    {
        $this->stopBilling($user);

        DB::transaction(function () use ($user): void {
            $user->ownedTeams()
                ->withTrashed()
                ->get()
                ->each(fn (Team $team) => $team->forceDelete());

            $user->subscriptions()->each(function (Subscription $subscription): void {
                $subscription->items()->delete();
                $subscription->delete();
            });

            $user->transactions()->delete();
            $user->customer()->delete();

            $user->delete();
        });
    }

    /**
     * Cancel, with immediate effect, any subscription still due to renew.
     * Safe to call twice: a cancelled subscription is skipped.
     *
     * @throws PaddleException|ConnectionException when Paddle refuses or cannot be reached.
     */
    public function stopBilling(User $user): void
    {
        $user->subscriptions()
            ->get()
            ->filter(fn (Subscription $subscription): bool => $subscription->valid() && ! $subscription->canceled())
            ->each(fn (Subscription $subscription) => $subscription->cancelNow());
    }
}
