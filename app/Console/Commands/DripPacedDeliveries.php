<?php

namespace App\Console\Commands;

use App\Enums\ContactStatus;
use App\Jobs\PushDeliveryContact;
use App\Models\Delivery;
use Illuminate\Console\Command;

/**
 * Releases a slice of each paced delivery's contacts onto the queue, keeping the
 * push rate under the hourly cap the user chose.
 *
 * Runs every minute. Nothing here is provider-specific: pacing happens at the
 * dispatch layer, so every integration is throttled the same way.
 */
class DripPacedDeliveries extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'deliveries:drip';

    /**
     * The console command description.
     */
    protected $description = 'Queue the next slice of contacts for deliveries that have an hourly rate limit';

    /**
     * Fraction by which a tick's release count is randomised, so the push rate
     * averages out to the chosen cap without arriving on a fixed cadence.
     */
    private const JITTER_PERCENT = 30;

    /**
     * Seconds a released batch is scattered across.
     */
    private const WINDOW_SECONDS = 60;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $released = 0;

        Delivery::query()
            ->where('status', Delivery::STATUS_PROCESSING)
            ->whereNotNull('contacts_per_hour')
            ->whereNotNull('pacing_started_at')
            ->each(function (Delivery $delivery) use (&$released): void {
                $released += $this->release($delivery);
            });

        if ($released > 0) {
            $this->info("Queued {$released} contacts.");
        }

        return self::SUCCESS;
    }

    /**
     * Queue however many of this delivery's contacts the hourly rate now allows.
     */
    private function release(Delivery $delivery): int
    {
        // Count only what was released inside the current pacing window. A retry
        // rewinds pacing_started_at, and contacts released before that belong to
        // the previous run's budget, not this one's.
        $alreadyReleased = $delivery->deliveryContacts()
            ->whereNotNull('released_at')
            ->where('released_at', '>=', $delivery->pacing_started_at)
            ->count();

        $quota = $delivery->pacingAllowance() - $alreadyReleased;

        if ($quota < 1) {
            return 0;
        }

        $quota = $this->jitter($quota);

        $contacts = $delivery->deliveryContacts()
            ->whereNull('released_at')
            ->where('status', ContactStatus::Pending)
            ->orderBy('id')
            ->limit($quota)
            ->get();

        foreach ($contacts as $deliveryContact) {
            $deliveryContact->forceFill(['released_at' => now()])->save();

            PushDeliveryContact::dispatch($deliveryContact)
                ->delay(now()->addSeconds(random_int(0, self::WINDOW_SECONDS)));
        }

        return $contacts->count();
    }

    /**
     * Randomise a release count so pushes do not arrive on a metronome. The
     * allowance is cumulative, so whatever a tick under-releases is simply
     * carried into the next one.
     */
    private function jitter(int $quota): int
    {
        $scaled = (int) round($quota * random_int(100 - self::JITTER_PERCENT, 100 + self::JITTER_PERCENT) / 100);

        return max(1, $scaled);
    }
}
