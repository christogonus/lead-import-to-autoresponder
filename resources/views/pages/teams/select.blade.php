<x-layouts::auth :title="__('Choose a team')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Choose a team')"
            :description="session('status') ?? __('Select a team to continue.')"
        />

        <div class="flex flex-col gap-3">
            @forelse (auth()->user()->toUserTeams(includeCurrent: true) as $team)
                <a
                    href="{{ route('dashboard', ['current_team' => $team->slug]) }}"
                    wire:navigate
                    class="flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-4 transition hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-600"
                    data-test="select-team-row"
                >
                    <div class="flex items-center gap-2">
                        <span class="font-medium">{{ $team->name }}</span>
                        @if ($team->isPersonal)
                            <flux:badge size="sm" color="zinc">{{ __('Personal') }}</flux:badge>
                        @endif
                    </div>
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ $team->roleLabel }}</flux:text>
                </a>
            @empty
                <flux:text class="text-center text-zinc-500 dark:text-zinc-400">
                    {{ __("You don't belong to any teams yet.") }}
                </flux:text>
            @endforelse
        </div>

        <form method="POST" action="{{ route('logout') }}" class="text-center">
            @csrf
            <flux:button type="submit" variant="ghost" size="sm" data-test="select-team-logout">
                {{ __('Log out') }}
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
