<?php

namespace App\Providers;

use App\Enums\IntegrationProvider;
use App\Integrations\Drivers\ZohoCampaignsProvider;
use App\Jobs\PushDeliveryContact;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    /**
     * Cap autoresponder pushes at the queue for providers that punish bursts.
     * Zoho locks an integration out for 30 minutes when it crosses its
     * documented call rate, so the cap cannot be left to the optional pacing
     * the user picks in the send dialog.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('autoresponder-push', function (PushDeliveryContact $job): Limit {
            $integration = $job->deliveryContact->delivery?->integration;

            if ($integration?->provider !== IntegrationProvider::ZohoCampaigns) {
                return Limit::none();
            }

            return Limit::perMinute(ZohoCampaignsProvider::CALLS_PER_MINUTE_LIMIT)
                ->by('zoho-campaigns:'.$integration->id);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
