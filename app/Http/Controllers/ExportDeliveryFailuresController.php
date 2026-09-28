<?php

namespace App\Http\Controllers;

use App\Actions\Deliveries\ExportDeliveryFailures;
use App\Models\Delivery;
use App\Models\Team;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads the contacts a delivery failed to push as a CSV file, with the
 * destination's reason for each, so they can be investigated or cleaned up.
 */
class ExportDeliveryFailuresController extends Controller
{
    public function __invoke(Team $current_team, Delivery $delivery, ExportDeliveryFailures $exporter): StreamedResponse
    {
        Gate::authorize('view', $delivery);

        return response()->streamDownload(
            function () use ($exporter, $delivery): void {
                $stream = fopen('php://output', 'w');

                $exporter->handle($delivery, $stream);

                fclose($stream);
            },
            $exporter->fileName($delivery),
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
