<?php

use App\Http\Controllers\OAuthController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard');

        Route::livewire('integrations', 'pages::integrations.index')->name('integrations.index');
        Route::get('integrations/oauth/{provider}/redirect', [OAuthController::class, 'redirect'])->name('integrations.oauth.redirect');

        Route::livewire('lists', 'pages::lists.index')->name('lists.index');
        Route::livewire('lists/{contactList}', 'pages::lists.show')->name('lists.show');
    });

Route::middleware(['auth'])->group(function () {
    // Team-independent so a single redirect URI can be registered with the provider.
    Route::get('integrations/oauth/callback', [OAuthController::class, 'callback'])->name('integrations.oauth.callback');

    // Graceful landing when a team route is invalid or inaccessible.
    Route::view('choose-team', 'pages.teams.select')->name('teams.select');

    Route::livewire('invitations/{invitation}/accept', 'pages::teams.accept-invitation')->name('invitations.accept');
});

require __DIR__.'/settings.php';
