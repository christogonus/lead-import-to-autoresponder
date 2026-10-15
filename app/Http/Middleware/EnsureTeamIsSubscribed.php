<?php

namespace App\Http\Middleware;

use App\Models\Team;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the workspace behind a paid plan. Runs after EnsureTeamMembership, so
 * the team is known to exist and the user is known to belong to it.
 */
class EnsureTeamIsSubscribed
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $team = $request->route('current_team');

        if (is_string($team)) {
            $team = Team::where('slug', $team)->first();
        }

        if (! $team instanceof Team || $team->hasActiveSubscription()) {
            return $next($request);
        }

        /** @var User $user */
        $user = $request->user();

        // The owner can fix this themselves; anyone else has to ask them.
        if ($user->ownsTeam($team)) {
            return redirect()->route('billing.show');
        }

        return redirect()->route('teams.select')->with(
            'status',
            __(':team is paused because its owner\'s subscription has ended. Ask them to renew it, or choose another team.', ['team' => $team->name]),
        );
    }
}
