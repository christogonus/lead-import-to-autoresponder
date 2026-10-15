<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-ink-50 antialiased dark:bg-ink-950">
        <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-3 focus:left-3 focus:z-50 focus:rounded-lg focus:bg-brand-600 focus:px-4 focus:py-2 focus:text-white">
            {{ __('Skip to content') }}
        </a>

        <div class="relative isolate flex min-h-svh flex-col items-center justify-center gap-6 overflow-hidden p-6 md:p-10">
            <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 -top-48 -z-10 h-[32rem] bg-[radial-gradient(55%_60%_at_50%_40%,var(--color-brand-100),transparent)] dark:bg-[radial-gradient(55%_60%_at_50%_40%,color-mix(in_oklab,var(--color-brand-600)_30%,transparent),transparent)]"></div>

            <header class="flex flex-col items-center">
                <a href="{{ route('home') }}" class="rounded-md" wire:navigate>
                    <x-wordmark size="lg" />
                </a>
            </header>

            <main id="main" class="w-full max-w-sm">
                <div class="rounded-2xl border border-ink-200 bg-white p-8 shadow-raised dark:border-ink-700 dark:bg-ink-900">
                    <div class="flex flex-col gap-6">
                        {{ $slot }}
                    </div>
                </div>
            </main>

            <footer class="text-center text-sm text-ink-500 dark:text-ink-400">
                <nav aria-label="{{ __('Legal') }}" class="flex flex-wrap justify-center gap-x-4 gap-y-1">
                    <a href="{{ route('legal.terms') }}" class="underline-offset-2 hover:text-ink-900 hover:underline dark:hover:text-white">{{ __('Terms') }}</a>
                    <a href="{{ route('legal.privacy') }}" class="underline-offset-2 hover:text-ink-900 hover:underline dark:hover:text-white">{{ __('Privacy') }}</a>
                    <a href="{{ route('legal.accessibility') }}" class="underline-offset-2 hover:text-ink-900 hover:underline dark:hover:text-white">{{ __('Accessibility') }}</a>
                </nav>
            </footer>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
