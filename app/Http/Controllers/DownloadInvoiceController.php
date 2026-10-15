<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Paddle\Transaction;

/**
 * Sends the customer to the PDF invoice Paddle issued for one of their
 * payments. Paddle is the merchant of record, so the invoice is theirs to
 * render; the link it returns is short-lived, which is why it is fetched on
 * click rather than printed into the billing page.
 */
class DownloadInvoiceController extends Controller
{
    public function __invoke(Request $request, string $transaction): RedirectResponse
    {
        /** @var Transaction $payment */
        $payment = $request->user()
            ->transactions()
            ->where('paddle_id', $transaction)
            ->whereNotNull('invoice_number')
            ->firstOrFail();

        return $payment->redirectToInvoicePdf();
    }
}
