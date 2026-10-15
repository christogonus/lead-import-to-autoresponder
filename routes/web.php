<?php

use App\Http\Controllers\DownloadInvoiceController;
use App\Http\Controllers\ExportContactListController;
use App\Http\Controllers\ExportDeliveryFailuresController;
use App\Http\Controllers\ExportPersonalDataController;
use App\Http\Controllers\OAuthController;
use App\Http\Middleware\EnsureTeamIsSubscribed;
use App\Http\Middleware\EnsureTeamMembership;
use App\Http\Middleware\RequirePaddleWebhookSecret;
use Illuminate\Support\Facades\Route;
use Laravel\Paddle\Http\Controllers\WebhookController;

Route::view('/', 'marketing.home')->name('home');
Route::view('pricing', 'marketing.pricing')->name('pricing');
Route::view('integrations', 'marketing.integrations')->name('marketing.integrations');

Route::prefix('legal')->name('legal.')->group(function () {
    Route::view('terms', 'legal.terms')->name('terms');
    Route::view('privacy', 'legal.privacy')->name('privacy');
    Route::view('refunds', 'legal.refunds')->name('refunds');
    Route::view('cookies', 'legal.cookies')->name('cookies');
    Route::view('data-processing', 'legal.data-processing')->name('dpa');
    Route::view('accessibility', 'legal.accessibility')->name('accessibility');
});

Route::post('paddle/webhook', WebhookController::class)
    ->middleware(RequirePaddleWebhookSecret::class)
    ->name('cashier.webhook');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('billing', 'pages::billing.show')->name('billing.show');
    Route::get('billing/invoices/{transaction}', DownloadInvoiceController::class)->name('billing.invoice');
    Route::get('settings/privacy/export', ExportPersonalDataController::class)->name('privacy.export');
});

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class, EnsureTeamIsSubscribed::class])
    ->group(function () {
        Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

        Route::livewire('integrations', 'pages::integrations.index')->name('integrations.index');
        Route::get('integrations/oauth/{provider}/redirect', [OAuthController::class, 'redirect'])->name('integrations.oauth.redirect');

        Route::livewire('lists', 'pages::lists.index')->name('lists.index');
        Route::livewire('lists/{contactList}', 'pages::lists.show')->name('lists.show');
        Route::get('lists/{contactList}/export', ExportContactListController::class)->name('lists.export');

        Route::livewire('deliveries', 'pages::deliveries.index')->name('deliveries.index');
        Route::get('deliveries/{delivery}/failures/export', ExportDeliveryFailuresController::class)->name('deliveries.failures.export');

        Route::livewire('do-not-contact', 'pages::suppressions.index')->name('suppressions.index');
    });

Route::middleware(['auth'])->group(function () {
    // Team-independent so a single redirect URI can be registered with the provider.
    Route::get('integrations/oauth/callback', [OAuthController::class, 'callback'])->name('integrations.oauth.callback');

    // Graceful landing when a team route is invalid or inaccessible.
    Route::view('choose-team', 'pages.teams.select')->name('teams.select');

    Route::livewire('invitations/{invitation}/accept', 'pages::teams.accept-invitation')->name('invitations.accept');
});

require __DIR__.'/settings.php';
