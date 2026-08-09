<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\ContactList;
use App\Models\User;

class ContactListPolicy
{
    /**
     * Determine whether the user can view the list.
     *
     * Scoped to the team the user is currently in, not merely to a team they
     * belong to: every list route sits under a team prefix, and a list from one
     * team must not render beneath another team's URL.
     */
    public function view(User $user, ContactList $list): bool
    {
        return $user->isCurrentTeam($list->team);
    }

    /**
     * Determine whether the user can set the list aside or bring it back.
     *
     * Drafting and restoring are the same reversible toggle, so they are open to
     * any member of the team — nothing is destroyed and the list can be restored
     * in one click.
     */
    public function draft(User $user, ContactList $list): bool
    {
        return $this->view($user, $list);
    }

    /**
     * Determine whether the user can permanently delete the list.
     *
     * Deleting destroys the list's contacts with no undo, so it is held to the
     * same bar as the team's other irreversible actions rather than being left
     * open to every member.
     */
    public function delete(User $user, ContactList $list): bool
    {
        return $this->view($user, $list)
            && $user->hasTeamPermission($list->team, TeamPermission::DeleteList);
    }
}
