<?php

use App\Enums\BillingPlan;
use App\Models\User;
use Flux\Flux;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Paddle\Exceptions\PaddleException;
use Laravel\Paddle\Subscription;
use Laravel\Paddle\Transaction;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Billing')] class extends Component
{
    /**
     * Set on the URL Paddle sends the customer back to after paying. The
     * subscription arrives by webhook a few seconds later, so until it does the
     * page says so instead of offering to take their money a second time.
     */
    #[Url(as: 'welcome', except: false)]
    public bool $returningFromCheckout = false;

    /**
     * Open Paddle's checkout overlay for the chosen billing cycle.
     */
    public function checkout(string $plan): void
    {
        $billingPlan = BillingPlan::tryFrom($plan);

        if (! $billingPlan || ! BillingPlan::checkoutIsConfigured()) {
            Flux::toast(variant: 'danger', text: __('Checkout is not available right now. Please contact support.'));

            return;
        }

        if ($this->user()->hasActiveSubscription()) {
            Flux::toast(text: __('You are already subscribed. Switch billing cycle below instead.'));

            return;
        }

        try {
            $checkout = $this->user()
                ->subscribe($billingPlan->priceId())
                ->returnTo(route('billing.show', ['welcome' => 1]));
        } catch (PaddleException|ConnectionException $exception) {
            Log::warning('Paddle checkout could not be prepared.', ['message' => $exception->getMessage()]);
            Flux::toast(variant: 'danger', text: __('We could not reach Paddle to start checkout. Please try again in a moment.'));

            return;
        }

        $options = $checkout->options();
        $options['settings'] = array_filter([
            'displayMode' => 'overlay',
            'successUrl' => $options['settings']['successUrl'] ?? null,
            'allowLogout' => false,
            'locale' => app()->getLocale(),
        ]);

        $this->dispatch('open-paddle-checkout', options: $options);
    }

    /**
     * Polled while waiting for Paddle's webhook after checkout.
     */
    public function checkForSubscription(): void
    {
        // The webhook writes the subscription in another request, so the
        // relation already loaded on this user is stale by definition.
        $this->user()->load('subscriptions');

        if ($this->user()->hasActiveSubscription()) {
            $this->redirectRoute('dashboard', ['current_team' => $this->user()->currentTeam ?? $this->user()->personalTeam()]);
        }
    }

    /**
     * Move to the other billing cycle. Paddle prorates the difference straight
     * away, so nobody pays twice for the same days.
     */
    public function switchTo(string $plan): void
    {
        $billingPlan = BillingPlan::tryFrom($plan);
        $subscription = $this->subscription;

        if (! $billingPlan || ! $billingPlan->priceId() || ! $subscription?->valid() || $this->user()->currentPlan() === $billingPlan) {
            return;
        }

        $this->callPaddle(
            fn () => $subscription->swap($billingPlan->priceId()),
            __('You are now on :plan billing.', ['plan' => strtolower($billingPlan->label())]),
        );

        Flux::modal('switch-to-'.$billingPlan->value)->close();
    }

    /**
     * Cancel at the end of the period already paid for.
     */
    public function cancel(): void
    {
        $subscription = $this->subscription;

        if (! $subscription?->recurring()) {
            return;
        }

        $this->callPaddle(
            fn () => $subscription->cancel(),
            __('Your subscription is cancelled. Everything keeps working until the end of the period you have paid for.'),
        );

        Flux::modal('cancel-subscription')->close();
    }

    /**
     * Undo a pending cancellation before it takes effect.
     */
    public function resume(): void
    {
        $subscription = $this->subscription;

        if (! $subscription?->onGracePeriod()) {
            return;
        }

        $this->callPaddle(
            fn () => $subscription->stopCancelation(),
            __('Welcome back. Your subscription will renew as normal.'),
        );
    }

    /**
     * Send the customer to Paddle's secure page for changing their card.
     */
    public function updatePaymentMethod(): void
    {
        $subscription = $this->subscription;

        if (! $subscription) {
            return;
        }

        try {
            $this->redirect($subscription->paymentMethodUpdateUrl());
        } catch (PaddleException|ConnectionException $exception) {
            Log::warning('Paddle payment method link failed.', ['message' => $exception->getMessage()]);
            Flux::toast(variant: 'danger', text: __('We could not reach Paddle. Please try again in a moment.'));
        }
    }

    #[Computed]
    public function subscription(): ?Subscription
    {
        return $this->user()->subscription();
    }

    #[Computed]
    public function currentPlan(): ?BillingPlan
    {
        return $this->user()->currentPlan();
    }

    /**
     * @return Collection<int, Transaction>
     */
    #[Computed]
    public function payments(): Collection
    {
        return $this->user()->transactions()
            ->whereIn('status', ['billed', 'paid', 'completed'])
            ->latest('billed_at')
            ->limit(24)
            ->get();
    }

    #[Computed]
    public function nextPaymentDate(): ?string
    {
        $subscription = $this->subscription;

        if (! $subscription?->recurring()) {
            return null;
        }

        // Paddle is the only source for this date, so it is cached rather than
        // fetched on every visit, and forgotten whenever the plan changes.
        return Cache::remember($this->nextPaymentCacheKey($subscription), now()->addHour(), function () use ($subscription): ?string {
            // Only a convenience on the page: any failure here is reported but
            // must never stop a customer reaching their billing controls.
            try {
                return $subscription->nextPayment()?->date()->format('j F Y');
            } catch (Throwable $exception) {
                report($exception);

                return null;
            }
        });
    }

    /**
     * Run a Paddle call, toast the outcome and refresh what the page shows.
     *
     * @param  callable(): mixed  $call
     */
    private function callPaddle(callable $call, string $success): void
    {
        try {
            $call();
        } catch (PaddleException|ConnectionException $exception) {
            Log::warning('Paddle subscription change failed.', ['message' => $exception->getMessage()]);
            Flux::toast(variant: 'danger', text: __('Paddle could not make that change just now. Nothing was charged. Please try again.'));

            return;
        }

        if ($this->subscription) {
            Cache::forget($this->nextPaymentCacheKey($this->subscription));
        }

        unset($this->subscription, $this->currentPlan, $this->nextPaymentDate);

        Flux::toast(variant: 'success', text: $success);
    }

    private function nextPaymentCacheKey(Subscription $subscription): string
    {
        return 'billing:next-payment:'.$subscription->paddle_id;
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }
}; ?>

<div class="mx-auto w-full max-w-5xl">
    @assets
        @paddleJS
    @endassets

    <div x-data x-on:open-paddle-checkout.window="window.Paddle && window.Paddle.Checkout.open($event.detail.options)"></div>

    <flux:heading size="xl" level="1">{{ __('Billing') }}</flux:heading>
    <flux:text class="mt-2 max-w-2xl">
        {{ __('One plan with everything in it. Payments are handled by Paddle, our reseller, which is why your bank statement will say PADDLE.NET.') }}
    </flux:text>

    @if ($this->subscription?->valid())
        {{-- Subscribed --}}
        @php($subscription = $this->subscription)

        @if ($subscription->pastDue())
            <flux:callout variant="warning" icon="exclamation-triangle" class="mt-8">
                <flux:callout.heading>{{ __('Your last payment did not go through') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('Paddle will try your card again over the next few days and everything keeps working meanwhile. Updating your card now stops the retries.') }}
                </flux:callout.text>
                <x-slot name="actions">
                    <flux:button size="sm" wire:click="updatePaymentMethod">{{ __('Update card') }}</flux:button>
                </x-slot>
            </flux:callout>
        @endif

        <div class="mt-8 grid gap-6 lg:grid-cols-3">
            <section aria-labelledby="plan-heading" class="relative overflow-hidden rounded-2xl bg-linear-to-br from-brand-600 via-brand-700 to-brand-900 p-6 text-white shadow-raised lg:col-span-2">
                <div aria-hidden="true" class="absolute inset-0 bg-[radial-gradient(circle_at_1px_1px,rgb(255_255_255/0.12)_1px,transparent_0)] bg-[length:22px_22px]"></div>
                <div class="relative">
                    <p class="text-sm font-semibold tracking-wider text-brand-100 uppercase">{{ __('Your plan') }}</p>
                    <h2 id="plan-heading" class="mt-2 text-3xl font-bold tracking-tight">
                        Lead2Crm {{ $this->currentPlan?->label() ?? __('Subscription') }}
                    </h2>

                    @if ($this->currentPlan)
                        <p class="mt-1 text-brand-100">
                            ${{ $this->currentPlan->monthlyRate() }}/{{ __('month') }} &middot; {{ $this->currentPlan->billingSummary() }}
                        </p>
                    @endif

                    <dl class="mt-6 grid gap-4 sm:grid-cols-2">
                        <div class="rounded-xl bg-white/10 p-4 ring-1 ring-white/15">
                            <dt class="text-sm text-brand-100">{{ __('Status') }}</dt>
                            <dd class="mt-1 flex items-center gap-2 text-lg font-semibold">
                                @if ($subscription->onGracePeriod())
                                    <span aria-hidden="true" class="size-2.5 rounded-full bg-amber-300"></span>
                                    {{ __('Cancelled, active until :date', ['date' => $subscription->ends_at->format('j F Y')]) }}
                                @elseif ($subscription->pastDue())
                                    <span aria-hidden="true" class="size-2.5 rounded-full bg-amber-300"></span>
                                    {{ __('Payment overdue') }}
                                @else
                                    <span aria-hidden="true" class="size-2.5 rounded-full bg-emerald-300"></span>
                                    {{ __('Active') }}
                                @endif
                            </dd>
                        </div>
                        <div class="rounded-xl bg-white/10 p-4 ring-1 ring-white/15">
                            <dt class="text-sm text-brand-100">{{ __('Next payment') }}</dt>
                            <dd class="mt-1 text-lg font-semibold">
                                {{ $this->nextPaymentDate ?? ($subscription->onGracePeriod() ? __('None, it will not renew') : __('Shown on your Paddle receipt')) }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </section>

            <section aria-labelledby="manage-heading" class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="lg" level="2" id="manage-heading">{{ __('Manage') }}</flux:heading>

                <div class="mt-4 flex flex-col gap-3">
                    @if ($this->currentPlan === App\Enums\BillingPlan::Monthly && $subscription->recurring())
                        <flux:modal.trigger name="switch-to-yearly">
                            <flux:button variant="primary" icon="sparkles" class="w-full">
                                {{ __('Switch to yearly, save :percent%', ['percent' => App\Enums\BillingPlan::Yearly->savingsPercent()]) }}
                            </flux:button>
                        </flux:modal.trigger>
                    @elseif ($this->currentPlan === App\Enums\BillingPlan::Yearly && $subscription->recurring())
                        <flux:modal.trigger name="switch-to-monthly">
                            <flux:button class="w-full">{{ __('Switch to monthly') }}</flux:button>
                        </flux:modal.trigger>
                    @endif

                    <flux:button wire:click="updatePaymentMethod" icon="credit-card" class="w-full">
                        {{ __('Update payment method') }}
                    </flux:button>

                    @if ($subscription->onGracePeriod())
                        <flux:button wire:click="resume" variant="primary" icon="arrow-path" class="w-full">
                            {{ __('Keep my subscription') }}
                        </flux:button>
                    @elseif ($subscription->recurring())
                        <flux:modal.trigger name="cancel-subscription">
                            <flux:button variant="ghost" class="w-full">{{ __('Cancel subscription') }}</flux:button>
                        </flux:modal.trigger>
                    @endif
                </div>
            </section>
        </div>

        <section aria-labelledby="payments-heading" class="mt-10">
            <flux:heading size="lg" level="2" id="payments-heading">{{ __('Payments and invoices') }}</flux:heading>

            @if ($this->payments->isEmpty())
                <flux:text class="mt-3">{{ __('Your first receipt will appear here once Paddle confirms the payment.') }}</flux:text>
            @else
                <div class="mt-4 overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                    <table class="w-full text-left text-sm">
                        <caption class="sr-only">{{ __('Your payments, newest first') }}</caption>
                        <thead class="bg-zinc-50 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                            <tr>
                                <th scope="col" class="px-4 py-3 font-medium">{{ __('Date') }}</th>
                                <th scope="col" class="px-4 py-3 font-medium">{{ __('Amount') }}</th>
                                <th scope="col" class="px-4 py-3 font-medium">{{ __('Invoice') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($this->payments as $payment)
                                <tr wire:key="payment-{{ $payment->id }}">
                                    <td class="px-4 py-3">{{ $payment->billed_at->format('j M Y') }}</td>
                                    <td class="px-4 py-3 tabular">{{ $payment->total() }}</td>
                                    <td class="px-4 py-3">
                                        @if ($payment->invoice_number)
                                            <flux:link :href="route('billing.invoice', $payment->paddle_id)">
                                                {{ __('Download PDF') }}<span class="sr-only">, {{ $payment->billed_at->format('j F Y') }}</span>
                                            </flux:link>
                                        @else
                                            <span class="text-zinc-500 dark:text-zinc-400">{{ __('Not issued yet') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <flux:modal name="switch-to-yearly" class="max-w-md">
            <div class="space-y-5">
                <flux:heading size="lg">{{ __('Switch to yearly billing?') }}</flux:heading>
                <flux:text>
                    {{ __('You will pay $:amount today for a full year, less a credit for the unused part of this month. After that it is $:rate a month instead of $:monthly.', ['amount' => App\Enums\BillingPlan::Yearly->amountPerCycle(), 'rate' => App\Enums\BillingPlan::Yearly->monthlyRate(), 'monthly' => App\Enums\BillingPlan::Monthly->monthlyRate()]) }}
                </flux:text>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Not now') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" wire:click="switchTo('yearly')">{{ __('Switch to yearly') }}</flux:button>
                </div>
            </div>
        </flux:modal>

        <flux:modal name="switch-to-monthly" class="max-w-md">
            <div class="space-y-5">
                <flux:heading size="lg">{{ __('Switch to monthly billing?') }}</flux:heading>
                <flux:text>
                    {{ __('Paddle credits the unused part of your year against the monthly price, then bills $:monthly a month.', ['monthly' => App\Enums\BillingPlan::Monthly->monthlyRate()]) }}
                </flux:text>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Keep yearly') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" wire:click="switchTo('monthly')">{{ __('Switch to monthly') }}</flux:button>
                </div>
            </div>
        </flux:modal>

        <flux:modal name="cancel-subscription" class="max-w-md">
            <div class="space-y-5">
                <flux:heading size="lg">{{ __('Cancel your subscription?') }}</flux:heading>
                <flux:text>
                    {{ __('Nothing stops today. Your lists, integrations and sends keep working until the end of the period you have paid for, and you will not be charged again. Payments already made are not refunded.') }}
                </flux:text>
                <flux:text>
                    {{ __('Your data stays in your account after it lapses, so you can pick up where you left off. If you would rather it was erased, delete your account from Settings.') }}
                </flux:text>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Keep subscription') }}</flux:button></flux:modal.close>
                    <flux:button variant="danger" wire:click="cancel" data-test="confirm-cancel-subscription">{{ __('Cancel subscription') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @elseif ($returningFromCheckout)
        {{-- Paid, waiting for Paddle's webhook --}}
        <div wire:poll.2s="checkForSubscription" class="mt-8 rounded-2xl border border-brand-200 bg-brand-50 p-8 text-center dark:border-brand-800 dark:bg-brand-950" role="status">
            <flux:icon.arrow-path class="mx-auto size-8 animate-spin text-brand-600 dark:text-brand-300" aria-hidden="true" />
            <h2 class="mt-4 text-xl font-semibold text-ink-900 dark:text-white">{{ __('Thanks! Setting up your account…') }}</h2>
            <p class="mx-auto mt-2 max-w-md text-ink-600 dark:text-ink-300">
                {{ __('Paddle is confirming your payment. This usually takes a few seconds, and this page will move on by itself.') }}
            </p>
            <p class="mx-auto mt-4 max-w-md text-sm text-ink-600 dark:text-ink-300">
                {{ __('Still here after a minute? Refresh the page, or email us and we will sort it out.') }}
            </p>
        </div>
    @else
        {{-- Choose a plan --}}
        <div x-data="{ cycle: 'yearly' }" class="mt-8">
            <fieldset>
                <legend class="sr-only">{{ __('Billing cycle') }}</legend>
                <div class="inline-flex rounded-full bg-zinc-100 p-1 dark:bg-zinc-800">
                    @foreach (App\Enums\BillingPlan::cases() as $plan)
                        <label class="relative cursor-pointer">
                            <input type="radio" name="cycle" value="{{ $plan->value }}" x-model="cycle" class="peer sr-only">
                            <span class="block rounded-full px-5 py-2 text-sm font-semibold text-zinc-600 peer-checked:bg-white peer-checked:text-brand-700 peer-checked:shadow-sm peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-brand-600 dark:text-zinc-300 dark:peer-checked:bg-zinc-950 dark:peer-checked:text-brand-300">
                                {{ $plan->label() }}
                                @if ($plan->savingsPercent() > 0)
                                    <span class="ms-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200">{{ __('Save :percent%', ['percent' => $plan->savingsPercent()]) }}</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div class="mt-6 grid gap-6 lg:grid-cols-5">
                <section aria-labelledby="offer-heading" class="rounded-2xl border-2 border-brand-600 bg-white p-8 shadow-raised lg:col-span-3 dark:bg-zinc-900">
                    <h2 id="offer-heading" class="text-sm font-semibold tracking-wider text-brand-700 uppercase dark:text-brand-300">Lead2Crm</h2>

                    @foreach (App\Enums\BillingPlan::cases() as $plan)
                        <div x-show="cycle === '{{ $plan->value }}'" @if ($plan !== App\Enums\BillingPlan::Yearly) style="display: none" @endif>
                            <p class="mt-3 flex items-baseline gap-2">
                                <span class="text-5xl font-extrabold tracking-tight text-ink-900 dark:text-white">${{ $plan->monthlyRate() }}</span>
                                <span class="text-lg text-ink-600 dark:text-ink-300">/ {{ __('month') }}</span>
                            </p>
                            <p class="mt-1 text-ink-600 dark:text-ink-300">{{ $plan->billingSummary() }}</p>

                            <flux:button
                                variant="primary"
                                class="mt-6 w-full"
                                wire:click="checkout('{{ $plan->value }}')"
                                :disabled="! App\Enums\BillingPlan::checkoutIsConfigured()"
                                data-test="checkout-{{ $plan->value }}"
                            >
                                {{ __('Continue to secure checkout') }}
                            </flux:button>
                        </div>
                    @endforeach

                    @unless (App\Enums\BillingPlan::checkoutIsConfigured())
                        <flux:callout variant="warning" icon="wrench" class="mt-4">
                            <flux:callout.text>{{ __('Checkout is being set up. Please check back shortly or email us to get started.') }}</flux:callout.text>
                        </flux:callout>
                    @endunless

                    <p class="mt-4 text-sm text-ink-600 dark:text-ink-300">
                        {{ __('Cancel whenever you like and keep access until the period you paid for ends. As the service costs us money to run from the first day, payments are not refunded.') }}
                        <flux:link :href="route('legal.refunds')">{{ __('Refund policy') }}</flux:link>
                    </p>
                </section>

                <section aria-labelledby="included-heading" class="rounded-2xl bg-ink-50 p-8 lg:col-span-2 dark:bg-zinc-800">
                    <h2 id="included-heading" class="font-semibold text-ink-900 dark:text-white">{{ __('Everything is included') }}</h2>
                    <ul class="mt-4 space-y-3 text-ink-700 dark:text-ink-200">
                        @foreach ([
                            __('Unlimited leads and unlimited sends'),
                            __('All :count integrations', ['count' => count(App\Enums\IntegrationProvider::cases())]),
                            __('Duplicate removal on every import'),
                            __('Do-not-contact list across all your lists'),
                            __('Split, merge and overlap tools'),
                            __('Paced sending and failure reports'),
                            __('Teams with invited members'),
                        ] as $feature)
                            <li class="flex gap-3">
                                <flux:icon.check-circle variant="solid" class="size-5 shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden="true" />
                                <span>{{ $feature }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            </div>
        </div>
    @endif
</div>
