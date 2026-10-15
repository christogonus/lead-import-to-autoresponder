<x-layouts::marketing
    :title="__('Pricing')"
    :description="__('One Lead2Crm plan with everything in it: $24 a month, or $17 a month paid yearly. Unlimited leads and sends.')"
>
    @php
        $monthly = App\Enums\BillingPlan::Monthly;
        $yearly = App\Enums\BillingPlan::Yearly;
        $included = [
            __('Unlimited leads and contacts'),
            __('Unlimited sends to your platforms'),
            __('All :count integrations, as many accounts as you like', ['count' => count(App\Enums\IntegrationProvider::cases())]),
            __('Duplicate and invalid-address checks on every import'),
            __('A do-not-contact list across all your lists'),
            __('Split, merge, remove overlap and bulk delete'),
            __('Paced sending with automatic retries'),
            __('Failure reports and CSV exports'),
            __('Teams, with admins and members'),
            __('Two-factor sign-in and passkeys'),
        ];
    @endphp

    <section aria-labelledby="pricing-heading" class="relative isolate overflow-hidden bg-linear-to-b from-brand-50 to-white">
        <div aria-hidden="true" class="absolute inset-0 -z-10 bg-[radial-gradient(circle_at_1px_1px,var(--color-brand-200)_1px,transparent_0)] bg-[length:28px_28px] [mask-image:linear-gradient(to_bottom,black,transparent_70%)]"></div>

        <div class="mx-auto max-w-6xl px-5 pt-16 pb-20 sm:px-8 lg:pt-24">
            <div class="mx-auto max-w-2xl text-center">
                <p class="text-sm font-semibold tracking-wider text-brand-700 uppercase">{{ __('Pricing') }}</p>
                <h1 id="pricing-heading" class="mt-3 text-4xl font-extrabold tracking-tight text-balance text-ink-900 sm:text-5xl">{{ __('One plan. Everything in it.') }}</h1>
                <p class="mt-5 text-lg text-ink-600">
                    {{ __('No tiers to outgrow, no contact counts, no add-ons. Choose how often you would like to pay; that is the only decision.') }}
                </p>
            </div>

            <div class="mx-auto mt-14 grid max-w-4xl gap-6 md:grid-cols-2">
                <section aria-labelledby="plan-monthly" class="flex flex-col rounded-3xl border border-ink-200 bg-white p-8 shadow-card">
                    <h2 id="plan-monthly" class="text-lg font-semibold text-ink-900">{{ __('Monthly') }}</h2>
                    <p class="mt-1 text-ink-600">{{ __('Pay as you go, stop any month.') }}</p>
                    <p class="mt-6 flex items-baseline gap-2">
                        <span class="text-5xl font-extrabold tracking-tight text-ink-900">${{ $monthly->monthlyRate() }}</span>
                        <span class="text-ink-600">/ {{ __('month') }}</span>
                    </p>
                    <p class="mt-1 text-sm text-ink-600">{{ $monthly->billingSummary() }}</p>
                    <a href="{{ route('register') }}" class="mt-8 inline-flex items-center justify-center rounded-xl border border-brand-600 px-5 py-3 font-semibold text-brand-700 hover:bg-brand-50">
                        {{ __('Start monthly') }}
                    </a>
                </section>

                <section aria-labelledby="plan-yearly" class="relative flex flex-col rounded-3xl border-2 border-brand-600 bg-white p-8 shadow-overlay">
                    <p class="absolute -top-3.5 left-8 rounded-full bg-linear-to-r from-brand-600 to-violet-600 px-3 py-1 text-xs font-bold tracking-wide text-white uppercase">
                        {{ __('Save :percent%', ['percent' => $yearly->savingsPercent()]) }}
                    </p>
                    <h2 id="plan-yearly" class="text-lg font-semibold text-ink-900">{{ __('Yearly') }}</h2>
                    <p class="mt-1 text-ink-600">{{ __('Pay once a year and keep $:saving.', ['saving' => ($monthly->monthlyRate() - $yearly->monthlyRate()) * 12]) }}</p>
                    <p class="mt-6 flex items-baseline gap-2">
                        <span class="text-5xl font-extrabold tracking-tight text-ink-900">${{ $yearly->monthlyRate() }}</span>
                        <span class="text-ink-600">/ {{ __('month') }}</span>
                    </p>
                    <p class="mt-1 text-sm text-ink-600">{{ $yearly->billingSummary() }}</p>
                    <a href="{{ route('register') }}" class="mt-8 inline-flex items-center justify-center rounded-xl bg-brand-600 px-5 py-3 font-semibold text-white shadow-raised hover:bg-brand-700">
                        {{ __('Start yearly') }}
                    </a>
                </section>
            </div>

            <section aria-labelledby="included-heading" class="mx-auto mt-10 max-w-4xl rounded-3xl bg-ink-950 p-8 sm:p-10">
                <h2 id="included-heading" class="text-xl font-bold text-white">{{ __('Included on both') }}</h2>
                <ul class="mt-6 grid gap-x-8 gap-y-3 text-ink-200 sm:grid-cols-2">
                    @foreach ($included as $item)
                        <li class="flex gap-3">
                            <svg aria-hidden="true" class="mt-0.5 size-5 shrink-0 text-emerald-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" /></svg>
                            <span>{{ $item }}</span>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-8 border-t border-white/10 pt-6 text-sm leading-relaxed text-ink-300">
                    {{ __('Prices are in US dollars. Paddle, our reseller, adds VAT or sales tax where your country requires it and shows the total before you pay. There is no free plan or trial, and payments are not refunded: the service costs us money to run from the day you join. You can cancel at any time and keep everything until the period you paid for ends.') }}
                    <a href="{{ route('legal.refunds') }}" class="font-medium text-white underline underline-offset-2">{{ __('Refund policy') }}</a>
                </p>
            </section>
        </div>
    </section>

    {{-- FAQ --}}
    <section id="faq" aria-labelledby="faq-heading" class="py-20 sm:py-28">
        <div class="mx-auto grid max-w-6xl gap-12 px-5 sm:px-8 lg:grid-cols-12">
            <div class="lg:col-span-4">
                <h2 id="faq-heading" class="text-3xl font-bold tracking-tight text-ink-900">{{ __('Questions people ask before they join') }}</h2>
                <p class="mt-4 text-ink-600">
                    {{ __('Something not covered here?') }}
                    <a href="mailto:{{ config('legal.support_email') }}" class="font-semibold text-brand-700 underline underline-offset-2">{{ __('Email us') }}</a>
                    {{ __('and a person will reply.') }}
                </p>
                <x-marketing.photo name="founder" ratio="aspect-[4/5]" class="mt-8 hidden rounded-3xl lg:block" sizes="(min-width: 1024px) 30vw, 0px" />
            </div>

            <div class="divide-y divide-ink-200 border-y border-ink-200 lg:col-span-8">
                @foreach ([
                    [__('Is there a free plan or a trial?'), __('No. Lead2Crm stores your lists and runs every send on servers we pay for from your first upload, so there is one paid plan and nothing in front of it. If you are unsure, start monthly: it is the smallest commitment we offer.')],
                    [__('Can I get a refund?'), __('No. Payments are not refunded, in full or in part, because the cost of running your account starts on day one. Cancel any time and you will not be charged again; your account stays fully working until the end of the period you paid for. Where the law of your country gives you a right to a refund that cannot be waived, that right still applies.')],
                    [__('What does "no limits" actually mean?'), __('We do not count your leads, lists or sends, and we will not move you to a bigger plan because you grew. The limits that do exist belong to your email platform, such as how many contacts it accepts per minute, and Lead2Crm paces around them rather than letting a send fail.')],
                    [__('Which platforms can I send to?'), __('GetResponse, Systeme.io, Mailchimp, BirdSend, AWeber, GoToWebinar, Zoho Campaigns, Sender.net, SendX, SendPulse and beehiiv. Connect as many accounts of each as you need.')],
                    [__('What happens when a platform rejects a contact?'), __('That contact is marked as failed with the reason the platform gave, the rest of the list carries on, and temporary errors are retried automatically. You can download the failures as a CSV, correct them and send them again.')],
                    [__('Do you keep my contacts, and do you use them?'), __('They stay in your account until you delete them, so you can send the same list to several places. We never sell them, share them, or contact them ourselves. Delete a list permanently and it is gone; delete your account and every team you own goes with it.')],
                    [__('Is Lead2Crm GDPR compliant?'), __('For the leads you upload, you are the controller and we are your processor. Our Data Processing Agreement is part of the terms you accept on sign-up, and you can export or erase everything yourself at any time. You are still responsible for having a lawful basis to email the people on your lists.')],
                    [__('Who handles payment?'), __('Paddle, which acts as our reseller and merchant of record. It takes the card details, handles tax and sends your receipts, so we never see your card number. Your statement will show PADDLE.NET.')],
                    [__('Can my team or my clients use my account?'), __('Yes. Create a team for each client or project and invite people as admins or members. One subscription covers every team you own, and members do not need a subscription of their own.')],
                    [__('Do you use tracking cookies?'), __('No. We set one cookie to keep you signed in and one to protect forms from forgery. No analytics, no advertising pixels, no cookie banner, because there is nothing to consent to.')],
                ] as [$question, $answer])
                    <details class="group py-5">
                        <summary class="flex cursor-pointer list-none items-start justify-between gap-6 rounded-lg text-lg font-semibold text-ink-900 [&::-webkit-details-marker]:hidden">
                            <span>{{ $question }}</span>
                            <span aria-hidden="true" class="mt-1 flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-700 transition-transform group-open:rotate-45">
                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" d="M12 5v14M5 12h14" /></svg>
                            </span>
                        </summary>
                        <p class="mt-3 max-w-2xl leading-relaxed text-ink-600">{{ $answer }}</p>
                    </details>
                @endforeach
            </div>
        </div>
    </section>

    <x-marketing.cta-band />
</x-layouts::marketing>
