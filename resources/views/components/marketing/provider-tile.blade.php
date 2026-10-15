@props([
    'provider',
    'detailed' => false,
])

@php
    /** @var \App\Enums\IntegrationProvider $provider */

    // Spelled out in full so Tailwind finds every class. Each pair keeps the
    // monogram at 4.5:1 or better.
    $palette = [
        'bg-brand-600 text-white',
        'bg-violet-600 text-white',
        'bg-emerald-700 text-white',
        'bg-rose-600 text-white',
        'bg-amber-400 text-ink-950',
        'bg-cyan-700 text-white',
        'bg-fuchsia-600 text-white',
        'bg-orange-700 text-white',
        'bg-indigo-600 text-white',
        'bg-teal-700 text-white',
        'bg-sky-700 text-white',
    ];

    $index = array_search($provider, \App\Enums\IntegrationProvider::cases(), true);
    $colour = $palette[$index % count($palette)];

    $words = preg_split('/[\s.]+/', $provider->label(), -1, PREG_SPLIT_NO_EMPTY);
    $monogram = count($words) > 1
        ? mb_strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1))
        : mb_substr($provider->label(), 0, 2);
@endphp

<div {{ $attributes->class(['flex items-center gap-3 rounded-2xl border border-ink-200 bg-white p-4 shadow-card']) }}>
    <span aria-hidden="true" class="flex size-11 shrink-0 items-center justify-center rounded-xl text-sm font-extrabold tracking-tight {{ $colour }}">{{ $monogram }}</span>
    <div class="min-w-0">
        <p class="font-semibold text-ink-900">{{ $provider->label() }}</p>
        @if ($detailed)
            <p class="text-sm text-ink-600">
                {{ __('Sends to a :noun', ['noun' => $provider->listNoun()]) }}
                &middot;
                {{ $provider->usesOAuth() ? __('Sign in to connect') : __('Connect with an API key') }}
            </p>
        @endif
    </div>
</div>
