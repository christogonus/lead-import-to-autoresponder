<?php

namespace App\Providers;

use App\Enums\IntegrationProvider;
use App\Integrations\Drivers\BeehiivProvider;
use App\Integrations\Drivers\GoToWebinarProvider;
use App\Integrations\Drivers\SendPulseProvider;
use App\Integrations\Drivers\ZohoCampaignsProvider;
use App\Jobs\PushDeliveryContact;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Paddle\Cashier;

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
        $this->configureBilling();
    }

    /**
     * Keep a customer working while Paddle retries a failed renewal. Paddle
     * only cancels after its dunning schedule gives up, and locking someone
     * out of their lists over an expired card on day one is needlessly harsh.
     */
    protected function configureBilling(): void
    {
        Cashier::keepPastDueSubscriptionsActive();

        // The webhook route is registered in routes/web.php instead, so it can
        // refuse unsigned deliveries on a misconfigured server.
        Cashier::ignoreRoutes();
    }

    /**
     * Cap autoresponder pushes at the queue for providers that punish bursts:
     * Zoho locks an integration out for 30 minutes past its documented call
     * rate, and GoTo's spike arrest 429s anything above its per-second limit.
     * The cap cannot be left to the optional pacing the user picks in the
     * send dialog.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('autoresponder-push', function (PushDeliveryContact $job): Limit {
            $integration = $job->deliveryContact->delivery?->integration;

            return match ($integration?->provider) {
                IntegrationProvider::ZohoCampaigns => Limit::perMinute(ZohoCampaignsProvider::CALLS_PER_MINUTE_LIMIT)
                    ->by('zoho-campaigns:'.$integration->id),
                IntegrationProvider::GoToWebinar => Limit::perSecond(GoToWebinarProvider::CALLS_PER_SECOND_LIMIT)
                    ->by('gotowebinar:'.$integration->id),
                IntegrationProvider::SendPulse => Limit::perSecond(SendPulseProvider::CALLS_PER_SECOND_LIMIT)
                    ->by('sendpulse:'.$integration->id),
                IntegrationProvider::Beehiiv => Limit::perMinute(BeehiivProvider::CALLS_PER_MINUTE_LIMIT)
                    ->by('beehiiv:'.$integration->id),
                default => Limit::none(),
            };
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
