<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-lg focus:bg-brand-600 focus:px-4 focus:py-2 focus:text-white">
            {{ __('Skip to main content') }}
        </a>

        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <nav aria-label="{{ __('Home') }}">
                    <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                </nav>
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <livewire:team-switcher />

            <flux:sidebar.nav aria-label="{{ __('Workspace') }}">
                <flux:sidebar.group :heading="__('Workspace')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="rectangle-stack" :href="route('lists.index')" :current="request()->routeIs('lists.*')" wire:navigate>
                        {{ __('Lists') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="paper-airplane" :href="route('deliveries.index')" :current="request()->routeIs('deliveries.*')" wire:navigate>
                        {{ __('Deliveries') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="no-symbol" :href="route('suppressions.index')" :current="request()->routeIs('suppressions.*')" wire:navigate>
                        {{ __('Do not contact') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="puzzle-piece" :href="route('integrations.index')" :current="request()->routeIs('integrations.*')" wire:navigate>
                        {{ __('Integrations') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav aria-label="{{ __('Account') }}">
                <flux:sidebar.item icon="credit-card" :href="route('billing.show')" :current="request()->routeIs('billing.*')">
                    {{ __('Billing') }}
                </flux:sidebar.item>

                <flux:sidebar.item icon="lifebuoy" :href="'mailto:'.config('legal.support_email')">
                    {{ __('Get help') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        <livewire:create-team-modal />

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
