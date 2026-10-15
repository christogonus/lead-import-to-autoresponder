@props([
    'title' => null,
    'description' => 'Lead2Crm moves your leads from spreadsheets and CSV exports into your email platform, cleaned, de-duplicated and paced. One plan, no limits.',
])

@php
    /**
     * Kept as data so the desktop bar and the mobile menu cannot drift apart.
     *
     * @var array<int, array{label: string, href: string, active: bool}>
     */
    $navigation = [
        ['label' => __('How it works'), 'href' => route('home').'#how-it-works', 'active' => false],
        ['label' => __('Integrations'), 'href' => route('marketing.integrations'), 'active' => request()->routeIs('marketing.integrations')],
        ['label' => __('Pricing'), 'href' => route('pricing'), 'active' => request()->routeIs('pricing')],
        ['label' => __('FAQ'), 'href' => route('pricing').'#faq', 'active' => false],
    ];

    $pageTitle = filled($title) ? $title.' · '.config('app.name') : config('app.name').' · '.__('Leads to your email platform, without the busywork');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-pt-24 scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $pageTitle }}</title>
        <meta name="description" content="{{ $description }}">
        <meta property="og:title" content="{{ $pageTitle }}">
        <meta property="og:description" content="{{ $description }}">
        <meta property="og:type" content="website">
        <meta name="theme-color" content="#065CFE">

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @vite(['resources/css/app.css'])
    </head>
    <body class="flex min-h-screen flex-col bg-white text-ink-800 antialiased">
        <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-lg focus:bg-brand-600 focus:px-4 focus:py-2 focus:font-semibold focus:text-white">
            {{ __('Skip to main content') }}
        </a>

        <header class="sticky top-0 z-40 border-b border-ink-100 bg-white/90 backdrop-blur-md">
            <div class="mx-auto flex h-16 max-w-6xl items-center gap-8 px-5 sm:px-8">
                <a href="{{ route('home') }}" class="shrink-0 rounded-md">
                    <x-wordmark />
                </a>

                <nav aria-label="{{ __('Main') }}" class="hidden items-center gap-1 lg:flex">
                    @foreach ($navigation as $link)
                        <a
                            href="{{ $link['href'] }}"
                            @if ($link['active']) aria-current="page" @endif
                            @class([
                                'rounded-lg px-3 py-2 text-[0.9375rem] font-medium transition-colors',
                                'text-brand-700' => $link['active'],
                                'text-ink-600 hover:bg-ink-100 hover:text-ink-900' => ! $link['active'],
                            ])
                        >{{ $link['label'] }}</a>
                    @endforeach
                </nav>

                <div class="ml-auto flex items-center gap-2">
                    @auth
                        <a href="{{ route('dashboard') }}" class="rounded-lg bg-brand-600 px-4 py-2 text-[0.9375rem] font-semibold text-white shadow-sm hover:bg-brand-700">
                            {{ __('Open your workspace') }}
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="hidden rounded-lg px-3 py-2 text-[0.9375rem] font-medium text-ink-700 hover:bg-ink-100 sm:block">
                            {{ __('Sign in') }}
                        </a>
                        <a href="{{ route('register') }}" class="rounded-lg bg-brand-600 px-4 py-2 text-[0.9375rem] font-semibold text-white shadow-sm hover:bg-brand-700">
                            {{ __('Get started') }}
                        </a>
                    @endauth

                    {{-- A native disclosure: works without JavaScript and announces its
                         open state to screen readers by itself. --}}
                    <details class="group relative lg:hidden">
                        <summary class="flex size-10 cursor-pointer list-none items-center justify-center rounded-lg text-ink-700 hover:bg-ink-100 [&::-webkit-details-marker]:hidden">
                            <span class="sr-only">{{ __('Menu') }}</span>
                            <svg aria-hidden="true" class="size-6 group-open:hidden" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16" /></svg>
                            <svg aria-hidden="true" class="hidden size-6 group-open:block" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" /></svg>
                        </summary>
                        <nav aria-label="{{ __('Main') }}" class="absolute right-0 mt-2 w-64 rounded-2xl border border-ink-100 bg-white p-2 shadow-overlay">
                            @foreach ($navigation as $link)
                                <a href="{{ $link['href'] }}" class="block rounded-lg px-3 py-2.5 font-medium text-ink-700 hover:bg-ink-100">{{ $link['label'] }}</a>
                            @endforeach
                            @guest
                                <a href="{{ route('login') }}" class="block rounded-lg px-3 py-2.5 font-medium text-ink-700 hover:bg-ink-100">{{ __('Sign in') }}</a>
                            @endguest
                        </nav>
                    </details>
                </div>
            </div>
        </header>

        <main id="main" tabindex="-1" class="flex-1 focus:outline-none">
            {{ $slot }}
        </main>

        <footer class="bg-ink-950 text-ink-300">
            <div class="mx-auto max-w-6xl px-5 py-16 sm:px-8">
                <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <x-wordmark tone="light" />
                        <p class="mt-4 max-w-xs leading-relaxed">
                            {{ __('Leads from your spreadsheets, delivered to your email platform. Built for people who would rather be selling than copy-pasting.') }}
                        </p>
                    </div>

                    <nav aria-labelledby="footer-product">
                        <h2 id="footer-product" class="text-sm font-semibold tracking-wider text-white uppercase">{{ __('Product') }}</h2>
                        <ul class="mt-4 space-y-3">
                            <li><a href="{{ route('home') }}#how-it-works" class="hover:text-white hover:underline">{{ __('How it works') }}</a></li>
                            <li><a href="{{ route('marketing.integrations') }}" class="hover:text-white hover:underline">{{ __('Integrations') }}</a></li>
                            <li><a href="{{ route('pricing') }}" class="hover:text-white hover:underline">{{ __('Pricing') }}</a></li>
                            <li><a href="{{ route('login') }}" class="hover:text-white hover:underline">{{ __('Sign in') }}</a></li>
                        </ul>
                    </nav>

                    <nav aria-labelledby="footer-help">
                        <h2 id="footer-help" class="text-sm font-semibold tracking-wider text-white uppercase">{{ __('Help') }}</h2>
                        <ul class="mt-4 space-y-3">
                            <li><a href="{{ route('pricing') }}#faq" class="hover:text-white hover:underline">{{ __('Questions and answers') }}</a></li>
                            <li><a href="mailto:{{ config('legal.support_email') }}" class="hover:text-white hover:underline">{{ __('Email us') }}</a></li>
                            <li><a href="{{ route('legal.accessibility') }}" class="hover:text-white hover:underline">{{ __('Accessibility') }}</a></li>
                        </ul>
                    </nav>

                    <nav aria-labelledby="footer-legal">
                        <h2 id="footer-legal" class="text-sm font-semibold tracking-wider text-white uppercase">{{ __('Legal') }}</h2>
                        <ul class="mt-4 space-y-3">
                            <li><a href="{{ route('legal.terms') }}" class="hover:text-white hover:underline">{{ __('Terms of Service') }}</a></li>
                            <li><a href="{{ route('legal.privacy') }}" class="hover:text-white hover:underline">{{ __('Privacy Policy') }}</a></li>
                            <li><a href="{{ route('legal.dpa') }}" class="hover:text-white hover:underline">{{ __('Data Processing') }}</a></li>
                            <li><a href="{{ route('legal.cookies') }}" class="hover:text-white hover:underline">{{ __('Cookies') }}</a></li>
                            <li><a href="{{ route('legal.refunds') }}" class="hover:text-white hover:underline">{{ __('Refund Policy') }}</a></li>
                        </ul>
                    </nav>
                </div>

                <div class="mt-14 flex flex-col gap-3 border-t border-white/10 pt-8 text-sm text-ink-400 sm:flex-row sm:items-center sm:justify-between">
                    <p>&copy; {{ now()->year }} {{ config('legal.entity') }}. {{ __('Payments are processed by Paddle, our merchant of record.') }}</p>
                    <p>
                        {{ __('Photos from') }}
                        <a href="https://www.pexels.com" rel="noopener" class="underline hover:text-white">Pexels</a>.
                        {{ __('No tracking cookies, ever.') }}
                    </p>
                </div>
            </div>
        </footer>
    </body>
</html>
