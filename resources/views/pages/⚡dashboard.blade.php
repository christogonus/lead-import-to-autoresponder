<?php

use App\Models\Delivery;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component
{
    /**
     * Headline numbers for the current team.
     *
     * @return array{lists: int, contacts: int, delivered: int, integrations: int, blocked: int, sending: int}
     */
    #[Computed]
    public function stats(): array
    {
        $team = $this->team();

        return [
            'lists' => $team->contactLists()->active()->count(),
            'contacts' => $team->contacts()->count(),
            'delivered' => (int) $team->deliveries()->sum('synced_count'),
            'integrations' => $team->integrations()->count(),
            'blocked' => $team->suppressions()->count(),
            'sending' => $team->deliveries()->where('status', Delivery::STATUS_PROCESSING)->count(),
        ];
    }

    /**
     * The three things a new team has to do before Lead2Crm earns its keep.
     *
     * @return array<int, array{done: bool, title: string, body: string, action: string, href: string}>
     */
    #[Computed]
    public function checklist(): array
    {
        $stats = $this->stats;

        return [
            [
                'done' => $stats['integrations'] > 0,
                'title' => __('Connect your email platform'),
                'body' => __('Sign in to it or paste an API key, so Lead2Crm has somewhere to send.'),
                'action' => __('Connect'),
                'href' => route('integrations.index'),
            ],
            [
                'done' => $stats['contacts'] > 0,
                'title' => __('Import your first list'),
                'body' => __('Upload a CSV or paste rows from a spreadsheet. Duplicates are skipped for you.'),
                'action' => __('Create a list'),
                'href' => route('lists.index'),
            ],
            [
                'done' => $this->team()->deliveries()->exists(),
                'title' => __('Send it'),
                'body' => __('Open the list, choose where it should go, and press send.'),
                'action' => __('Go to lists'),
                'href' => route('lists.index'),
            ],
        ];
    }

    /**
     * @return Collection<int, Delivery>
     */
    #[Computed]
    public function recentDeliveries(): Collection
    {
        return $this->team()->deliveries()
            ->with(['integration', 'contactList'])
            ->latest()
            ->limit(6)
            ->get();
    }

    #[Computed]
    public function greeting(): string
    {
        $hour = now()->hour;

        return match (true) {
            $hour < 12 => __('Good morning'),
            $hour < 18 => __('Good afternoon'),
            default => __('Good evening'),
        };
    }

    private function team(): Team
    {
        return Auth::user()->currentTeam;
    }
}; ?>

@php
    $stats = $this->stats;
    $checklist = $this->checklist;
    $completed = collect($checklist)->where('done', true)->count();
    $firstName = str(auth()->user()->name)->before(' ');

    $tiles = [
        ['label' => __('Active lists'), 'value' => $stats['lists'], 'icon' => 'rectangle-stack', 'href' => route('lists.index'), 'class' => 'from-brand-600 to-brand-800'],
        ['label' => __('Contacts stored'), 'value' => $stats['contacts'], 'icon' => 'users', 'href' => route('lists.index'), 'class' => 'from-violet-600 to-violet-800'],
        ['label' => __('Contacts delivered'), 'value' => $stats['delivered'], 'icon' => 'paper-airplane', 'href' => route('deliveries.index'), 'class' => 'from-emerald-700 to-teal-800'],
        ['label' => __('Platforms connected'), 'value' => $stats['integrations'], 'icon' => 'puzzle-piece', 'href' => route('integrations.index'), 'class' => 'from-orange-700 to-rose-700'],
    ];
@endphp

<div class="mx-auto flex w-full max-w-6xl flex-col gap-8">
    {{-- Welcome --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ $this->greeting }}, {{ $firstName }}</flux:heading>
            <flux:text class="mt-1">
                {{ __('Here is what is happening in :team.', ['team' => auth()->user()->currentTeam->name]) }}
                @if ($stats['sending'] > 0)
                    <span class="font-medium text-brand-700 dark:text-brand-300">{{ trans_choice(':count send is going out now.|:count sends are going out now.', $stats['sending'], ['count' => $stats['sending']]) }}</span>
                @endif
            </flux:text>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button :href="route('integrations.index')" icon="puzzle-piece" wire:navigate>{{ __('Connect a platform') }}</flux:button>
            <flux:button :href="route('lists.index')" variant="primary" icon="plus" wire:navigate>{{ __('New list') }}</flux:button>
        </div>
    </div>

    {{-- Getting started --}}
    @if ($completed < count($checklist))
        <section aria-labelledby="getting-started" class="overflow-hidden rounded-2xl border border-brand-200 bg-linear-to-br from-brand-50 via-white to-violet-50 p-6 dark:border-brand-900 dark:from-brand-950 dark:via-zinc-900 dark:to-zinc-900">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 id="getting-started" class="text-lg font-semibold text-ink-900 dark:text-white">{{ __('Get set up') }}</h2>
                    <p class="text-sm text-ink-600 dark:text-ink-300">{{ __(':done of :total done. Most people finish in under ten minutes.', ['done' => $completed, 'total' => count($checklist)]) }}</p>
                </div>
                <div class="w-full sm:w-48">
                    <div class="h-2 overflow-hidden rounded-full bg-brand-100 dark:bg-brand-900" role="progressbar" aria-valuemin="0" aria-valuemax="{{ count($checklist) }}" aria-valuenow="{{ $completed }}" aria-label="{{ __('Setup progress') }}">
                        <div class="h-full rounded-full bg-linear-to-r from-brand-600 to-violet-600" style="width: {{ round($completed / count($checklist) * 100) }}%"></div>
                    </div>
                </div>
            </div>

            <ol class="mt-6 grid gap-4 md:grid-cols-3">
                @foreach ($checklist as $step)
                    <li @class([
                        'flex flex-col rounded-xl border p-4',
                        'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950' => $step['done'],
                        'border-ink-200 bg-white dark:border-zinc-700 dark:bg-zinc-900' => ! $step['done'],
                    ])>
                        <div class="flex items-center gap-3">
                            @if ($step['done'])
                                <flux:icon.check-circle variant="solid" class="size-6 text-emerald-600 dark:text-emerald-400" aria-hidden="true" />
                            @else
                                <span aria-hidden="true" class="flex size-6 items-center justify-center rounded-full bg-brand-600 text-xs font-bold text-white">{{ $loop->iteration }}</span>
                            @endif
                            <h3 class="font-semibold text-ink-900 dark:text-white">
                                {{ $step['title'] }}
                                <span class="sr-only">{{ $step['done'] ? __('(done)') : __('(to do)') }}</span>
                            </h3>
                        </div>
                        <p class="mt-2 flex-1 text-sm text-ink-600 dark:text-ink-300">{{ $step['body'] }}</p>
                        @unless ($step['done'])
                            <flux:button :href="$step['href']" size="sm" variant="primary" class="mt-4 self-start" wire:navigate>{{ $step['action'] }}</flux:button>
                        @endunless
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    {{-- Numbers --}}
    <section aria-labelledby="at-a-glance">
        <h2 id="at-a-glance" class="sr-only">{{ __('At a glance') }}</h2>
        <ul class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($tiles as $tile)
                <li>
                    <a href="{{ $tile['href'] }}" wire:navigate class="group relative block overflow-hidden rounded-2xl bg-linear-to-br p-5 text-white shadow-raised transition hover:-translate-y-0.5 hover:shadow-overlay {{ $tile['class'] }}">
                        <div aria-hidden="true" class="absolute -top-6 -right-6 size-24 rounded-full bg-white/10"></div>
                        <flux:icon :name="$tile['icon']" class="size-7 opacity-90" aria-hidden="true" />
                        <p class="mt-4 text-3xl font-bold tracking-tight tabular">{{ number_format($tile['value']) }}</p>
                        <p class="mt-1 text-sm font-medium text-white/90">{{ $tile['label'] }}</p>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Recent sends --}}
        <section aria-labelledby="recent-sends" class="rounded-2xl border border-zinc-200 bg-white p-6 lg:col-span-2 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between">
                <flux:heading size="lg" level="2" id="recent-sends">{{ __('Recent sends') }}</flux:heading>
                <flux:link :href="route('deliveries.index')" wire:navigate class="text-sm">{{ __('See all') }}</flux:link>
            </div>

            @if ($this->recentDeliveries->isEmpty())
                <div class="mt-6 rounded-xl border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-600">
                    <flux:icon.paper-airplane class="mx-auto size-8 text-brand-500" aria-hidden="true" />
                    <p class="mt-3 font-medium text-ink-900 dark:text-white">{{ __('Nothing sent yet') }}</p>
                    <p class="mt-1 text-sm text-ink-600 dark:text-ink-300">{{ __('When you send a list, its progress shows up here.') }}</p>
                </div>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <caption class="sr-only">{{ __('The six most recent sends') }}</caption>
                        <thead class="text-zinc-600 dark:text-zinc-300">
                            <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                <th scope="col" class="py-2 pe-4 font-medium">{{ __('List') }}</th>
                                <th scope="col" class="py-2 pe-4 font-medium">{{ __('Sent to') }}</th>
                                <th scope="col" class="py-2 pe-4 font-medium">{{ __('Delivered') }}</th>
                                <th scope="col" class="py-2 font-medium">{{ __('Status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($this->recentDeliveries as $delivery)
                                <tr wire:key="recent-{{ $delivery->id }}">
                                    <td class="py-3 pe-4 font-medium text-ink-900 dark:text-white">{{ $delivery->listLabel() }}</td>
                                    <td class="py-3 pe-4 text-ink-700 dark:text-ink-200">
                                        {{ $delivery->destinationLabel() }}
                                        @if ($delivery->integration)
                                            <span class="block text-xs text-ink-600 dark:text-ink-300">{{ $delivery->integration->provider->label() }}</span>
                                        @endif
                                    </td>
                                    <td class="py-3 pe-4 tabular text-ink-700 dark:text-ink-200">
                                        {{ number_format($delivery->synced_count) }} / {{ number_format($delivery->total_count) }}
                                        @if ($delivery->failed_count > 0)
                                            <span class="block text-xs font-medium text-rose-700 dark:text-rose-300">{{ trans_choice(':count failed|:count failed', $delivery->failed_count, ['count' => number_format($delivery->failed_count)]) }}</span>
                                        @endif
                                    </td>
                                    <td class="py-3">
                                        <flux:badge size="sm" :color="$delivery->statusColor()">{{ $delivery->statusLabel() }}</flux:badge>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        {{-- Shortcuts --}}
        <section aria-labelledby="shortcuts" class="flex flex-col gap-4">
            <h2 id="shortcuts" class="sr-only">{{ __('Shortcuts') }}</h2>

            <a href="{{ route('suppressions.index') }}" wire:navigate class="group flex gap-4 rounded-2xl border border-rose-200 bg-rose-50 p-5 hover:border-rose-300 dark:border-rose-900 dark:bg-rose-950">
                <span aria-hidden="true" class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-rose-600 text-white">
                    <flux:icon.no-symbol class="size-6" />
                </span>
                <span>
                    <span class="block font-semibold text-ink-900 dark:text-white">{{ __('Do not contact') }}</span>
                    <span class="block text-sm text-ink-700 dark:text-ink-200">{{ trans_choice(':count address is blocked across every list.|:count addresses are blocked across every list.', $stats['blocked'], ['count' => number_format($stats['blocked'])]) }}</span>
                </span>
            </a>

            <a href="{{ route('teams.edit', auth()->user()->currentTeam) }}" wire:navigate class="group flex gap-4 rounded-2xl border border-cyan-200 bg-cyan-50 p-5 hover:border-cyan-300 dark:border-cyan-900 dark:bg-cyan-950">
                <span aria-hidden="true" class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-cyan-700 text-white">
                    <flux:icon.user-plus class="size-6" />
                </span>
                <span>
                    <span class="block font-semibold text-ink-900 dark:text-white">{{ __('Invite your team') }}</span>
                    <span class="block text-sm text-ink-700 dark:text-ink-200">{{ __('Colleagues and clients join free under your subscription.') }}</span>
                </span>
            </a>

            <a href="mailto:{{ config('legal.support_email') }}" class="group flex gap-4 rounded-2xl border border-amber-200 bg-amber-50 p-5 hover:border-amber-300 dark:border-amber-900 dark:bg-amber-950">
                <span aria-hidden="true" class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-amber-400 text-ink-950">
                    <flux:icon.lifebuoy class="size-6" />
                </span>
                <span>
                    <span class="block font-semibold text-ink-900 dark:text-white">{{ __('Stuck on something?') }}</span>
                    <span class="block text-sm text-ink-700 dark:text-ink-200">{{ __('Email us. A person reads every message.') }}</span>
                </span>
            </a>
        </section>
    </div>
</div>
