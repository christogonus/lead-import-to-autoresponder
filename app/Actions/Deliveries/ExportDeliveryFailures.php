<?php

namespace App\Actions\Deliveries;

use App\Actions\Lists\ExportContactList;
use App\Enums\ContactStatus;
use App\Models\Delivery;
use App\Models\DeliveryContact;

/**
 * Writes the contacts a delivery failed to push out as CSV, each with the
 * reason the destination gave. The contact columns match the list export, so
 * the file can be cleaned up and imported straight back into a list.
 */
class ExportDeliveryFailures
{
    /**
     * How many failed contacts to hold in memory at a time.
     */
    private const CHUNK = 1000;

    public function __construct(private ExportContactList $contactExporter) {}

    /**
     * Write the delivery's failed contacts to the given stream.
     *
     * @param  resource  $stream
     */
    public function handle(Delivery $delivery, $stream): void
    {
        fputcsv($stream, [...array_values(ExportContactList::COLUMNS), 'Error', 'Failed At'], escape: '');

        $delivery->deliveryContacts()
            ->where('status', ContactStatus::Failed)
            ->with('contact')
            ->lazyById(self::CHUNK)
            ->each(function (DeliveryContact $deliveryContact) use ($stream): void {
                if ($deliveryContact->contact === null) {
                    return;
                }

                fputcsv($stream, [
                    ...$this->contactExporter->row($deliveryContact->contact),
                    $this->contactExporter->neutraliseFormula('error', (string) $deliveryContact->sync_error),
                    $deliveryContact->updated_at?->toDateTimeString() ?? '',
                ], escape: '');
            });
    }

    /**
     * The download's file name, taken from the list and destination names.
     */
    public function fileName(Delivery $delivery): string
    {
        $name = str($delivery->listLabel().' '.$delivery->destinationLabel())->slug()->value();

        return ($name !== '' ? $name : 'delivery-'.$delivery->id).'-failed.csv';
    }
}
