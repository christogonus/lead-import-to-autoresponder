<x-layouts::marketing>
    @php
        $providers = App\Enums\IntegrationProvider::cases();
        $yearly = App\Enums\BillingPlan::Yearly;
        $monthly = App\Enums\BillingPlan::Monthly;
    @endphp

    {{-- Hero --}}
    <section aria-labelledby="hero-heading" class="relative isolate overflow-hidden bg-linear-to-b from-brand-50 via-white to-white">
        <div aria-hidden="true" class="absolute inset-0 -z-10 bg-[radial-gradient(circle_at_1px_1px,var(--color-brand-200)_1px,transparent_0)] bg-[length:28px_28px] [mask-image:linear-gradient(to_bottom,black,transparent_75%)]"></div>
        <div aria-hidden="true" class="absolute -top-40 -right-32 -z-10 size-[36rem] rounded-full bg-brand-200/50 blur-3xl"></div>
        <div aria-hidden="true" class="absolute top-64 -left-40 -z-10 size-[28rem] rounded-full bg-violet-200/40 blur-3xl"></div>

        <div class="mx-auto grid max-w-6xl items-center gap-14 px-5 pt-16 pb-20 sm:px-8 lg:grid-cols-12 lg:pt-24 lg:pb-28">
            <div class="lg:col-span-6">
                <p class="inline-flex items-center gap-2 rounded-full border border-brand-200 bg-white px-3 py-1 text-sm font-medium text-brand-800 shadow-card">
                    <span aria-hidden="true" class="size-2 rounded-full bg-emerald-500"></span>
                    {{ __('Works with :count email and webinar platforms', ['count' => count($providers)]) }}
                </p>

                <h1 id="hero-heading" class="mt-6 text-4xl font-extrabold tracking-tight text-balance text-ink-900 sm:text-5xl lg:text-[3.5rem] lg:leading-[1.05]">
                    {{ __('Get your leads out of spreadsheets and') }}
                    <span class="bg-linear-to-r from-brand-600 to-violet-600 bg-clip-text text-transparent">{{ __('into your email list.') }}</span>
                </h1>

                <p class="mt-6 max-w-xl text-lg leading-relaxed text-pretty text-ink-600">
                    {{ __('Upload a CSV or paste rows straight from Google Sheets. Lead2Crm sets aside bad addresses, skips duplicates and anyone you were asked not to contact, then delivers every lead to GetResponse, Mailchimp, AWeber and the rest, at a pace your provider will accept.') }}
                </p>

                <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                    <a href="{{ route('register') }}" class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-600 px-6 py-3.5 text-base font-semibold text-white shadow-raised hover:bg-brand-700">
                        {{ __('Start for $:rate a month', ['rate' => $yearly->monthlyRate()]) }}
                        <svg aria-hidden="true" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2.25" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12l-7.5 7.5M21 12H3" /></svg>
                    </a>
                    <a href="#how-it-works" class="inline-flex items-center justify-center rounded-xl border border-ink-200 bg-white px-6 py-3.5 text-base font-semibold text-ink-800 hover:border-ink-300 hover:bg-ink-50">
                        {{ __('See how it works') }}
                    </a>
                </div>

                <ul class="mt-8 flex flex-wrap gap-x-6 gap-y-2 text-sm font-medium text-ink-700">
                    @foreach ([__('Unlimited leads'), __('Unlimited sends'), __('Cancel whenever you like')] as $promise)
                        <li class="flex items-center gap-2">
                            <svg aria-hidden="true" class="size-5 text-emerald-600" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z" clip-rule="evenodd" /></svg>
                            {{ $promise }}
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- An illustration of one import, drawn in HTML rather than a fake screenshot. --}}
            <figure class="lg:col-span-6">
                <div class="relative rounded-3xl border border-ink-200 bg-white/80 p-5 shadow-overlay backdrop-blur sm:p-7">
                    <div class="flex items-center gap-3 rounded-2xl border border-ink-200 bg-ink-50 p-4">
                        <span aria-hidden="true" class="flex size-11 items-center justify-center rounded-xl bg-emerald-100 text-emerald-800">
                            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 0 1-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0 1 12 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125M3.375 8.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125m17.25-3.75h-7.5c-.621 0-1.125.504-1.125 1.125m8.625-1.125c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125M12 10.875v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 10.875c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125M13.125 12h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125M20.625 12c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5M12 14.625v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 14.625c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125m0 1.5v-1.5m0 0c0-.621.504-1.125 1.125-1.125m0 0h7.5" /></svg>
                        </span>
                        <div>
                            <p class="font-semibold text-ink-900">webinar-signups-may.csv</p>
                            <p class="text-sm text-ink-600">{{ __('2,418 rows · columns matched') }}</p>
                        </div>
                    </div>

                    <div aria-hidden="true" class="mx-auto h-8 w-px bg-linear-to-b from-ink-200 to-brand-400"></div>

                    <div class="rounded-2xl bg-linear-to-br from-brand-600 to-brand-800 p-5 text-white shadow-raised">
                        <div class="flex items-center justify-between">
                            <x-wordmark tone="light" size="sm" />
                            <span class="rounded-full bg-white/15 px-2.5 py-1 text-xs font-semibold">{{ __('Checked') }}</span>
                        </div>
                        <dl class="mt-4 grid grid-cols-3 gap-3 text-center">
                            <div class="rounded-xl bg-white/10 px-2 py-3">
                                <dt class="text-xs text-brand-100">{{ __('Ready') }}</dt>
                                <dd class="text-xl font-bold tabular">2,301</dd>
                            </div>
                            <div class="rounded-xl bg-white/10 px-2 py-3">
                                <dt class="text-xs text-brand-100">{{ __('Duplicates') }}</dt>
                                <dd class="text-xl font-bold tabular">96</dd>
                            </div>
                            <div class="rounded-xl bg-white/10 px-2 py-3">
                                <dt class="text-xs text-brand-100">{{ __('Do not contact') }}</dt>
                                <dd class="text-xl font-bold tabular">21</dd>
                            </div>
                        </dl>
                    </div>

                    <div aria-hidden="true" class="mx-auto h-8 w-px bg-linear-to-b from-brand-400 to-ink-200"></div>

                    <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @foreach (array_slice($providers, 0, 6) as $provider)
                            <li><x-marketing.provider-tile :provider="$provider" class="p-3! [&>span]:size-9 [&_p]:text-sm" /></li>
                        @endforeach
                    </ul>
                </div>
                <figcaption class="mt-3 text-center text-sm text-ink-600">{{ __('An example import: one file in, a clean list out, sent where you choose.') }}</figcaption>
            </figure>
        </div>
    </section>

    {{-- Integrations strip --}}
    <section aria-labelledby="integrations-heading" class="border-y border-ink-100 bg-ink-50 py-14">
        <div class="mx-auto max-w-6xl px-5 sm:px-8">
            <div class="flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-end">
                <div>
                    <h2 id="integrations-heading" class="text-2xl font-bold tracking-tight text-ink-900">{{ __('Delivers to the tools you already pay for') }}</h2>
                    <p class="mt-2 text-ink-600">{{ __('Connect as many accounts as you like. Each list can go to a different one.') }}</p>
                </div>
                <a href="{{ route('marketing.integrations') }}" class="font-semibold text-brand-700 underline-offset-4 hover:underline">
                    {{ __('All integrations and how they connect') }} <span aria-hidden="true">&rarr;</span>
                </a>
            </div>
            <ul class="mt-8 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($providers as $provider)
                    <li><x-marketing.provider-tile :provider="$provider" /></li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- How it works --}}
    <section id="how-it-works" aria-labelledby="how-heading" class="py-20 sm:py-28">
        <div class="mx-auto max-w-6xl px-5 sm:px-8">
            <div class="max-w-2xl">
                <p class="text-sm font-semibold tracking-wider text-brand-700 uppercase">{{ __('How it works') }}</p>
                <h2 id="how-heading" class="mt-3 text-3xl font-bold tracking-tight text-balance text-ink-900 sm:text-4xl">{{ __('Three steps, and the boring part is ours') }}</h2>
                <p class="mt-4 text-lg text-ink-600">{{ __('You know the routine: export, open in a spreadsheet, delete the blanks, hunt for repeats, import, find out half failed. This replaces it.') }}</p>
            </div>

            <ol class="mt-14 grid gap-6 lg:grid-cols-3">
                @foreach ([
                    ['colour' => 'bg-brand-600', 'tint' => 'bg-brand-50', 'title' => __('Bring your list'), 'body' => __('Upload a CSV of up to 10 MB or paste rows from any spreadsheet. Commas, semicolons or tabs: Lead2Crm works out which. Match your columns to first name, last name, email, phone and country, and you are done.')],
                    ['colour' => 'bg-violet-600', 'tint' => 'bg-violet-50', 'title' => __('Let it tidy up'), 'body' => __('Invalid addresses are set aside, an address already on the list is skipped, and anyone on your do-not-contact list is left out before they can be emailed again. You see exactly how many of each.')],
                    ['colour' => 'bg-emerald-700', 'tint' => 'bg-emerald-50', 'title' => __('Send it where it belongs'), 'body' => __('Choose the account and the campaign, audience, tag or webinar the list should land in. Spread a large list over several hours if you want to, then watch each contact go through.')],
                ] as $step)
                    <li class="relative rounded-3xl border border-ink-200 bg-white p-7 shadow-card">
                        <span aria-hidden="true" class="flex size-12 items-center justify-center rounded-2xl text-xl font-extrabold text-white {{ $step['colour'] }}">{{ $loop->iteration }}</span>
                        <h3 class="mt-6 text-xl font-bold text-ink-900"><span class="sr-only">{{ __('Step :n:', ['n' => $loop->iteration]) }} </span>{{ $step['title'] }}</h3>
                        <p class="mt-3 leading-relaxed text-ink-600">{{ $step['body'] }}</p>
                        <div aria-hidden="true" class="absolute inset-x-7 bottom-0 h-1 rounded-t-full {{ $step['colour'] }}"></div>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- Features --}}
    <section aria-labelledby="features-heading" class="bg-ink-950 py-20 text-ink-300 sm:py-28">
        <div class="mx-auto max-w-6xl px-5 sm:px-8">
            <div class="max-w-2xl">
                <p class="text-sm font-semibold tracking-wider text-brand-300 uppercase">{{ __('What it handles') }}</p>
                <h2 id="features-heading" class="mt-3 text-3xl font-bold tracking-tight text-balance text-white sm:text-4xl">{{ __('All the list jobs you used to do by hand') }}</h2>
            </div>

            <ul class="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['icon' => 'M18.364 18.364A9 9 0 0 0 5.636 5.636m12.728 12.728A9 9 0 0 1 5.636 5.636m12.728 12.728L5.636 5.636', 'colour' => 'bg-rose-500/15 text-rose-300', 'title' => __('A do-not-contact list that sticks'), 'body' => __('Block a bounced or unsubscribed address once. It is removed from every list you hold and kept out of every future import.')],
                    ['icon' => 'M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5', 'colour' => 'bg-amber-400/15 text-amber-300', 'title' => __('Split big exports'), 'body' => __('Break a 40,000-row export into batches of whatever size your provider, or your warm-up plan, will take.')],
                    ['icon' => 'M6 6.878V6a2.25 2.25 0 0 1 2.25-2.25h7.5A2.25 2.25 0 0 1 18 6v.878m-12 0c.235-.083.487-.128.75-.128h10.5c.263 0 .515.045.75.128m-12 0A2.25 2.25 0 0 0 4.5 9v.878m13.5-3A2.25 2.25 0 0 1 19.5 9v.878m0 0a2.246 2.246 0 0 0-.75-.128H5.25c-.263 0-.515.045-.75.128m15 0A2.25 2.25 0 0 1 21 12v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6c0-.98.626-1.813 1.5-2.122', 'colour' => 'bg-brand-400/15 text-brand-300', 'title' => __('Merge without repeats'), 'body' => __('Fold several lists into one where every address appears exactly once.')],
                    ['icon' => 'M15.75 15.75 9 9m0 0v5.25M9 9h5.25M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z', 'colour' => 'bg-violet-400/15 text-violet-300', 'title' => __('Remove the overlap'), 'body' => __('Drop everyone from one list who is already on another, so nobody gets your launch sequence twice.')],
                    ['icon' => 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z', 'colour' => 'bg-emerald-400/15 text-emerald-300', 'title' => __('Paced sending'), 'body' => __('Set a contacts-per-hour cap and the list goes out steadily. Providers that punish bursts are throttled for you, whatever you choose.')],
                    ['icon' => 'M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z', 'colour' => 'bg-orange-400/15 text-orange-300', 'title' => __('Failures you can act on'), 'body' => __('When a provider turns a contact away, you see which one and the reason it gave. Download the failures as a CSV, fix them, send again.')],
                    ['icon' => 'M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z', 'colour' => 'bg-cyan-400/15 text-cyan-300', 'title' => __('Bring your team'), 'body' => __('Invite colleagues or clients as admins or members. One subscription covers every team you own.')],
                    ['icon' => 'M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3', 'colour' => 'bg-fuchsia-400/15 text-fuchsia-300', 'title' => __('Nothing locked in'), 'body' => __('Download any list as a CSV whose headers Lead2Crm reads straight back in. Your data leaves with you whenever you like.')],
                ] as $feature)
                    <li class="rounded-3xl border border-white/10 bg-white/[0.03] p-6">
                        <span aria-hidden="true" class="flex size-11 items-center justify-center rounded-xl {{ $feature['colour'] }}">
                            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $feature['icon'] }}" /></svg>
                        </span>
                        <h3 class="mt-5 text-lg font-semibold text-white">{{ $feature['title'] }}</h3>
                        <p class="mt-2 leading-relaxed">{{ $feature['body'] }}</p>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- Who it's for --}}
    <section aria-labelledby="audience-heading" class="py-20 sm:py-28">
        <div class="mx-auto max-w-6xl px-5 sm:px-8">
            <div class="max-w-2xl">
                <p class="text-sm font-semibold tracking-wider text-brand-700 uppercase">{{ __('Who it is for') }}</p>
                <h2 id="audience-heading" class="mt-3 text-3xl font-bold tracking-tight text-balance text-ink-900 sm:text-4xl">{{ __('Anyone whose leads arrive as a file') }}</h2>
            </div>

            <div class="mt-14 grid gap-8 lg:grid-cols-3">
                @foreach ([
                    ['photo' => 'webinars', 'accent' => 'text-brand-700', 'label' => __('Webinar hosts'), 'title' => __('Registrants into GoToWebinar, follow-ups into your list'), 'body' => __('Add a list of sign-ups straight to a GoToWebinar session, then send the same people to your email platform for the replay sequence.')],
                    ['photo' => 'agencies', 'accent' => 'text-violet-700', 'label' => __('Agencies'), 'title' => __('One workspace per client'), 'body' => __('Keep each client in their own team with their own integrations, and invite the client in when they want to see what went out.')],
                    ['photo' => 'owners', 'accent' => 'text-emerald-700', 'label' => __('Shop owners and creators'), 'title' => __('From event sign-up sheets to a list you can mail'), 'body' => __('Typed up a sign-up sheet from a market stall or a workshop? Paste it in and those people are on your list the same afternoon.')],
                ] as $audience)
                    <article class="group overflow-hidden rounded-3xl border border-ink-200 bg-white shadow-card">
                        <x-marketing.photo :name="$audience['photo']" ratio="aspect-[3/2]" sizes="(min-width: 1024px) 33vw, 100vw" />
                        <div class="p-6">
                            <p class="text-sm font-semibold tracking-wider uppercase {{ $audience['accent'] }}">{{ $audience['label'] }}</p>
                            <h3 class="mt-2 text-xl font-bold text-ink-900">{{ $audience['title'] }}</h3>
                            <p class="mt-3 leading-relaxed text-ink-600">{{ $audience['body'] }}</p>
                        </div>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Before and after --}}
    <section aria-labelledby="compare-heading" class="bg-ink-50 py-20 sm:py-28">
        <div class="mx-auto grid max-w-6xl items-center gap-12 px-5 sm:px-8 lg:grid-cols-2">
            <x-marketing.photo name="hero" ratio="aspect-[4/3]" class="rounded-3xl shadow-overlay" />

            <div>
                <h2 id="compare-heading" class="text-3xl font-bold tracking-tight text-balance text-ink-900 sm:text-4xl">{{ __('The spreadsheet way, and the Lead2Crm way') }}</h2>

                <div class="mt-8 overflow-hidden rounded-2xl border border-ink-200 bg-white">
                    <table class="w-full text-left">
                        <caption class="sr-only">{{ __('How common list jobs compare when done by hand and with Lead2Crm') }}</caption>
                        <thead class="bg-ink-100 text-sm text-ink-700">
                            <tr>
                                <th scope="col" class="px-4 py-3 font-semibold">{{ __('Job') }}</th>
                                <th scope="col" class="px-4 py-3 font-semibold">{{ __('By hand') }}</th>
                                <th scope="col" class="px-4 py-3 font-semibold text-brand-800">Lead2Crm</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-ink-100">
                            @foreach ([
                                [__('Duplicates'), __('Sort, scroll, delete, hope'), __('Skipped on every import')],
                                [__('Unsubscribes'), __('A separate sheet you forget to check'), __('Blocked everywhere, for good')],
                                [__('Provider limits'), __('Upload, get blocked, wait, retry'), __('Paced to fit')],
                                [__('Rejected contacts'), __('Silent, or an error you cannot read'), __('Named, with the reason, as a CSV')],
                            ] as [$job, $byHand, $withUs])
                                <tr>
                                    <th scope="row" class="px-4 py-3 font-semibold text-ink-900">{{ $job }}</th>
                                    <td class="px-4 py-3 text-ink-600">{{ $byHand }}</td>
                                    <td class="px-4 py-3 font-medium text-emerald-800">{{ $withUs }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    {{-- Pricing teaser --}}
    <section aria-labelledby="price-heading" class="py-20 sm:py-28">
        <div class="mx-auto max-w-4xl px-5 text-center sm:px-8">
            <p class="text-sm font-semibold tracking-wider text-brand-700 uppercase">{{ __('Pricing') }}</p>
            <h2 id="price-heading" class="mt-3 text-3xl font-bold tracking-tight text-balance text-ink-900 sm:text-4xl">{{ __('One plan. No tiers. No counting contacts.') }}</h2>
            <p class="mx-auto mt-4 max-w-2xl text-lg text-ink-600">
                {{ __('Everything Lead2Crm does, for as many leads and sends as you have. $:yearly a month paid yearly, or $:monthly month to month.', ['yearly' => $yearly->monthlyRate(), 'monthly' => $monthly->monthlyRate()]) }}
            </p>
            <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                <a href="{{ route('register') }}" class="inline-flex items-center justify-center rounded-xl bg-brand-600 px-6 py-3.5 font-semibold text-white shadow-raised hover:bg-brand-700">{{ __('Create your account') }}</a>
                <a href="{{ route('pricing') }}" class="inline-flex items-center justify-center rounded-xl border border-ink-200 px-6 py-3.5 font-semibold text-ink-800 hover:bg-ink-50">{{ __('Compare monthly and yearly') }}</a>
            </div>
        </div>
    </section>

    <x-marketing.cta-band />
</x-layouts::marketing>
