<x-layouts::marketing
    :title="__('Integrations')"
    :description="__('Send leads from a CSV or spreadsheet to GetResponse, Mailchimp, AWeber, GoToWebinar, Zoho Campaigns, beehiiv and more.')"
>
    @php
        $providers = collect(App\Enums\IntegrationProvider::cases())->sortBy(fn ($provider) => mb_strtolower($provider->label()));
    @endphp

    <section aria-labelledby="integrations-page-heading" class="relative isolate overflow-hidden bg-linear-to-b from-violet-50 via-brand-50 to-white">
        <div aria-hidden="true" class="absolute inset-0 -z-10 bg-[radial-gradient(circle_at_1px_1px,var(--color-brand-200)_1px,transparent_0)] bg-[length:28px_28px] [mask-image:linear-gradient(to_bottom,black,transparent_70%)]"></div>

        <div class="mx-auto max-w-6xl px-5 pt-16 pb-16 sm:px-8 lg:pt-24">
            <div class="max-w-2xl">
                <p class="text-sm font-semibold tracking-wider text-brand-700 uppercase">{{ __('Integrations') }}</p>
                <h1 id="integrations-page-heading" class="mt-3 text-4xl font-extrabold tracking-tight text-balance text-ink-900 sm:text-5xl">
                    {{ __(':count places your leads can go', ['count' => $providers->count()]) }}
                </h1>
                <p class="mt-5 text-lg text-ink-600">
                    {{ __('Connect an account once and every list in that team can be sent to it. Some platforms ask you to sign in and approve access; the others give you an API key to paste. Either way, your password never passes through us.') }}
                </p>
            </div>
        </div>
    </section>

    <section aria-labelledby="all-integrations" class="pb-20">
        <div class="mx-auto max-w-6xl px-5 sm:px-8">
            <h2 id="all-integrations" class="sr-only">{{ __('All integrations') }}</h2>
            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($providers as $provider)
                    <li><x-marketing.provider-tile :provider="$provider" :detailed="true" class="h-full p-5" /></li>
                @endforeach
            </ul>

            <div class="mt-16 grid gap-8 rounded-3xl border border-ink-200 bg-ink-50 p-8 sm:p-10 lg:grid-cols-3">
                @foreach ([
                    ['title' => __('Each list, its own destination'), 'body' => __('Send one list to a Mailchimp audience and the next to a GoToWebinar session. Lead2Crm loads the destinations straight from your account, so you pick from what is really there.')],
                    ['title' => __('Paced to each platform'), 'body' => __('Zoho Campaigns, GoToWebinar, SendPulse and beehiiv lock out callers who go too fast. Lead2Crm keeps under their limits on its own, and you can slow any send further.')],
                    ['title' => __('Need another one?'), 'body' => __('Tell us which platform you use and what you send to it. Requests from paying customers decide what we build next.')],
                ] as $point)
                    <div>
                        <h3 class="text-lg font-semibold text-ink-900">{{ $point['title'] }}</h3>
                        <p class="mt-2 leading-relaxed text-ink-600">{{ $point['body'] }}</p>
                    </div>
                @endforeach
            </div>

            <p class="mt-6 text-sm text-ink-600">
                {{ __('Product names belong to their owners. Lead2Crm is an independent service and is not endorsed by any of the platforms listed.') }}
                <a href="mailto:{{ config('legal.support_email') }}?subject={{ rawurlencode('Integration request') }}" class="font-semibold text-brand-700 underline underline-offset-2">{{ __('Request an integration') }}</a>
            </p>
        </div>
    </section>

    <x-marketing.cta-band />
</x-layouts::marketing>
