@props([
    'sidebar' => false,
])

<a {{ $attributes->class(['flex items-center rounded-md px-1 py-1.5', 'in-data-flux-sidebar-collapsed-desktop:px-0' => $sidebar]) }}>
    <x-wordmark />
</a>
