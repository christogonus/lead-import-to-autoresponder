<?php

namespace App\Http\Controllers;

use App\Actions\Lists\ExportContactList;
use App\Models\ContactList;
use App\Models\Team;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads a list's contacts as a CSV file. Streamed rather than built up
 * front, so a large list never sits in memory whole.
 */
class ExportContactListController extends Controller
{
    public function __invoke(Team $current_team, ContactList $contactList, ExportContactList $exporter): StreamedResponse
    {
        Gate::authorize('view', $contactList);

        return response()->streamDownload(
            function () use ($exporter, $contactList): void {
                $stream = fopen('php://output', 'w');

                $exporter->handle($contactList, $stream);

                fclose($stream);
            },
            $exporter->fileName($contactList),
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
