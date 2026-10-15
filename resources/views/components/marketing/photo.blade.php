@if ($src())
    <div {{ $attributes->class(['relative overflow-hidden bg-ink-100', $ratio]) }}>
        <img
            src="{{ $src() }}"
            @if ($srcset()) srcset="{{ $srcset() }}" sizes="{{ $sizes }}" @endif
            alt="{{ $alt() }}"
            loading="{{ $eager ? 'eager' : 'lazy' }}"
            fetchpriority="{{ $eager ? 'high' : 'auto' }}"
            decoding="async"
            class="size-full object-cover"
        >
    </div>
@endif
