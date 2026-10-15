<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cashier only checks a webhook's signature when a secret is configured. A
 * live server missing PADDLE_WEBHOOK_SECRET would therefore accept forged
 * "subscription created" calls and hand out free accounts, so outside local
 * development and tests an unconfigured secret refuses every webhook instead.
 * Paddle retries refused deliveries, so nothing is lost once it is set.
 */
class RequirePaddleWebhookSecret
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (blank(config('cashier.webhook_secret')) && ! app()->environment(['local', 'testing'])) {
            report(new RuntimeException('Refused a Paddle webhook: PADDLE_WEBHOOK_SECRET is not set.'));

            abort(503, 'Webhook signing is not configured.');
        }

        return $next($request);
    }
}
