<?php

namespace App\Actions\Deliveries;

use App\Models\Delivery;
use App\Models\Team;

/**
 * Permanently deletes a team's finished deliveries, along with their
 * per-contact rows (cascaded by foreign key).
 *
 * Nothing at the destination changes: contacts already pushed stay there. What
 * is lost is the record of that push, so a later send of the same list to the
 * same destination will push those contacts again.
 *
 * A delivery still sending is refused rather than deleted. The status is
 * checked in the delete itself, so one resumed or retried after it was picked
 * is left alone instead of vanishing mid-push.
 */
class DeleteDeliveries
{
    /**
     * How many deliveries to delete per query.
     */
    private const CHUNK = 500;

    /**
     * Delete the given deliveries, returning how many were deleted. Ids that
     * belong to another team or are still sending are skipped.
     *
     * @param  array<int, int|string>  $deliveryIds
     */
    public function handle(Team $team, array $deliveryIds): int
    {
        return collect($deliveryIds)
            ->map(fn (int|string $id): int => (int) $id)
            ->unique()
            ->chunk(self::CHUNK)
            ->sum(fn ($chunk): int => $team->deliveries()
                ->whereKey($chunk->all())
                ->where('status', '!=', Delivery::STATUS_PROCESSING)
                ->delete());
    }
}
