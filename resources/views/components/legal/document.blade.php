@props([
    'title',
    'summary' => null,
    // Each entry is ['id' => 'anchor', 'title' => 'Heading', 'body' => '<p>…</p>'].
    // Bodies are written in the page templates, never user input, so they are
    // rendered unescaped.
    'sections' => [],
])

@php
    /**
     * Every policy links to its siblings; a new legal page is added here once.
     *
     * @var array<string, string>
     */
    $documents = [
        'legal.terms' => __('Terms of Service'),
        'legal.privacy' => __('Privacy Policy'),
        'legal.dpa' => __('Data Processing Agreement'),
        'legal.cookies' => __('Cookie Policy'),
        'legal.refunds' => __('Refund Policy'),
        'legal.accessibility' => __('Accessibility Statement'),
    ];
@endphp

<x-layouts::marketing :title="$title" :description="$summary">
    <section aria-labelledby="document-title" class="relative isolate overflow-hidden border-b border-ink-100 bg-linear-to-b from-brand-50 to-white">
        <div class="mx-auto max-w-6xl px-5 pt-16 pb-12 sm:px-8 sm:pt-20">
            <div class="max-w-3xl">
                <p class="text-sm font-semibold tracking-wider text-brand-700 uppercase">{{ __('Legal') }}</p>
                <h1 id="document-title" class="mt-3 text-4xl font-extrabold tracking-tight text-balance text-ink-900 sm:text-5xl">{{ $title }}</h1>

                @if ($summary)
                    <p class="mt-5 text-lg leading-relaxed text-pretty text-ink-600">{{ $summary }}</p>
                @endif

                <dl class="mt-8 flex flex-wrap gap-x-10 gap-y-4 border-t border-ink-200 pt-6">
                    <div>
                        <dt class="text-sm text-ink-600">{{ __('Published by') }}</dt>
                        <dd class="mt-0.5 font-semibold text-ink-900">{{ config('legal.entity') }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-ink-600">{{ __('Last updated') }}</dt>
                        <dd class="mt-0.5 font-semibold text-ink-900">
                            <time datetime="{{ config('legal.updated') }}">{{ \Illuminate\Support\Carbon::parse(config('legal.updated'))->format('j F Y') }}</time>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-ink-600">{{ __('Questions') }}</dt>
                        <dd class="mt-0.5 font-semibold">
                            <a href="mailto:{{ config('legal.support_email') }}" class="text-brand-700 underline underline-offset-2">{{ config('legal.support_email') }}</a>
                        </dd>
                    </div>
                </dl>
            </div>
        </div>
    </section>

    <div class="py-14 sm:py-20">
        <div class="mx-auto grid max-w-6xl gap-12 px-5 sm:px-8 lg:grid-cols-12 lg:gap-16">
            <nav class="lg:col-span-4" aria-labelledby="toc-heading">
                <div class="lg:sticky lg:top-24">
                    <h2 id="toc-heading" class="text-sm font-semibold tracking-wider text-ink-600 uppercase">{{ __('On this page') }}</h2>
                    <ol class="mt-4 space-y-2.5 border-l-2 border-ink-100 pl-4">
                        @foreach ($sections as $section)
                            <li>
                                <a href="#{{ $section['id'] }}" class="leading-snug text-ink-700 hover:text-brand-700 hover:underline">
                                    {{ $section['title'] }}
                                </a>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </nav>

            <article class="space-y-12 lg:col-span-8" aria-labelledby="document-title">
                @foreach ($sections as $section)
                    <section id="{{ $section['id'] }}" class="scroll-mt-24" aria-labelledby="{{ $section['id'] }}-heading">
                        <h2 id="{{ $section['id'] }}-heading" class="text-2xl font-bold tracking-tight text-ink-900">
                            <span aria-hidden="true" class="mr-2 text-brand-600 tabular">{{ $loop->iteration }}.</span>{{ $section['title'] }}
                        </h2>
                        <div class="legal-prose mt-4">{!! $section['body'] !!}</div>
                    </section>
                @endforeach

                @if (config('legal.address'))
                    <address class="rounded-2xl border border-ink-200 bg-ink-50 p-6 not-italic">
                        <span class="block font-semibold text-ink-900">{{ config('legal.entity') }}</span>
                        <span class="mt-2 block whitespace-pre-line text-ink-700">{{ config('legal.address') }}</span>
                    </address>
                @endif
            </article>
        </div>
    </div>

    <nav aria-labelledby="other-documents" class="border-t border-ink-100 bg-ink-50 py-14">
        <div class="mx-auto max-w-6xl px-5 sm:px-8">
            <h2 id="other-documents" class="text-xl font-bold text-ink-900">{{ __('Other legal documents') }}</h2>
            <ul class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($documents as $route => $label)
                    <li>
                        @if (request()->routeIs($route))
                            <span aria-current="page" class="block rounded-2xl border border-brand-200 bg-brand-50 p-4 font-semibold text-brand-800">{{ $label }}</span>
                        @else
                            <a href="{{ route($route) }}" class="block rounded-2xl border border-ink-200 bg-white p-4 font-semibold text-ink-900 shadow-card hover:border-brand-300 hover:text-brand-700">{{ $label }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </nav>
</x-layouts::marketing>
