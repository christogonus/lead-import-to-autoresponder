<?php

namespace App\Policies;

use App\Models\Delivery;
use App\Models\User;

class DeliveryPolicy
{
    /**
     * Determine whether the user can view the delivery and its per-contact
     * outcomes.
     *
     * Scoped to the team the user is currently in, for the same reason lists
     * are: delivery routes sit under a team prefix, and one team's send must
     * not render beneath another team's URL.
     */
    public function view(User $user, Delivery $delivery): bool
    {
        return $user->isCurrentTeam($delivery->team);
    }
}
