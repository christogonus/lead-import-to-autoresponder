@props([
    // 'light' sets the letters in white for dark grounds (footer, brand panels).
    'tone' => 'dark',
    'size' => 'md',
])

{{--
    The interim Lead2Crm logo, set in type until a drawn mark exists. The "2"
    sits in a brand tile so the name reads as two halves joined by the product.
    The tile is aria-hidden and the full name is spoken once, so a screen
    reader says "Lead2Crm" rather than spelling out the pieces.
--}}
@php
    $text = match ($size) {
        'sm' => 'text-base',
        'lg' => 'text-2xl',
        default => 'text-lg',
    };

    $tile = match ($size) {
        'sm' => 'size-5 text-xs rounded-[5px]',
        'lg' => 'size-8 text-lg rounded-lg',
        default => 'size-6 text-sm rounded-md',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center font-extrabold tracking-tight', $text]) }}>
    <span class="sr-only">{{ config('app.name') }}</span>
    <span aria-hidden="true" @class(['inline-flex items-center gap-[0.18em]', 'text-ink-900 dark:text-white' => $tone === 'dark', 'text-white' => $tone === 'light'])>
        <span>Lead</span>
        <span @class(['inline-flex items-center justify-center bg-brand-600 leading-none font-extrabold text-white shadow-sm', $tile])>2</span>
        <span>Crm</span>
    </span>
</span>
