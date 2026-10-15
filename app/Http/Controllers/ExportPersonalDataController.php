<?php

namespace App\Http\Controllers;

use App\Actions\Privacy\ExportPersonalData;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads everything Lead2Crm holds about the signed-in user as JSON.
 */
class ExportPersonalDataController extends Controller
{
    public function __invoke(Request $request, ExportPersonalData $exporter): StreamedResponse
    {
        $user = $request->user();

        return response()->streamDownload(
            function () use ($exporter, $user): void {
                $stream = fopen('php://output', 'w');

                $exporter->handle($user, $stream);

                fclose($stream);
            },
            $exporter->fileName(),
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }
}
