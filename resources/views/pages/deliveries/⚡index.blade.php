<?php

use App\Models\Delivery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Deliveries')] class extends Component {
    use WithPagination;

    /** Sentinel for the status filter meaning "don't filter at all". */
    public const STATUS_ALL = 'all';

    #[Url(except: self::STATUS_ALL)]
    public string $status = self::STATUS_ALL;

    #[Url(except: '')]
    public string $search = '';

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function filterByStatus(string $status): void
    {
        $this->status = $status;
        $this->resetPage();
    }

    protected function currentTeam()
    {
        return Auth::user()->currentTeam;
    }

    protected function trimmedSearch(): string
    {
        return trim($this->search);
    }

    /**
     * Every send this team has made, newest first.
     *
     * Deliberately queried from the team rather than from any list: a delivery
     * outlives the list it was sent from, and those orphaned rows are the whole
     * reason this page exists — they have nowhere else to show up.
     *
     * @return LengthAwarePaginator<Delivery>
     */
    #[Computed]
    public function deliveries(): LengthAwarePaginator
    {
        return $this->currentTeam()->deliveries()
            ->with(['integration', 'contactList'])
            ->when($this->status !== self::STATUS_ALL, fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->trimmedSearch() !== '', function (Builder $query): void {
                $term = '%'.$this->trimmedSearch().'%';

                $query->where(function (Builder $query) use ($term): void {
                    $query->where('remote_name', 'like', $term)
                        // Matches both a surviving list and the name snapshotted
                        // onto the delivery when its list was deleted.
                        ->orWhere('contact_list_name', 'like', $term)
                        ->orWhereHas('contactList', fn (Builder $list) => $list->where('name', 'like', $term));
                });
            })
            ->latest()
            ->paginate(25);
    }

    /**
     * How many deliveries sit under each status, so the filters can say what
     * they lead to. Ignores the search box — these count the whole history.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function statusCounts(): array
    {
        $counts = $this->currentTeam()->deliveries()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            self::STATUS_ALL => (int) $counts->sum(),
            Delivery::STATUS_PROCESSING => (int) $counts->get(Delivery::STATUS_PROCESSING, 0),
            Delivery::STATUS_COMPLETED => (int) $counts->get(Delivery::STATUS_COMPLETED, 0),
            Delivery::STATUS_CANCELLED => (int) $counts->get(Delivery::STATUS_CANCELLED, 0),
        ];
    }

    /**
     * Whether the view is showing a narrowed slice rather than the full history,
     * so an empty result can say which of the two it is.
     */
    #[Computed]
    public function isFiltered(): bool
    {
        return $this->status !== self::STATUS_ALL || $this->trimmedSearch() !== '';
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function filters(): array
    {
        return [
            self::STATUS_ALL => __('All'),
            Delivery::STATUS_PROCESSING => __('Sending'),
            Delivery::STATUS_COMPLETED => __('Completed'),
            Delivery::STATUS_CANCELLED => __('Cancelled'),
        ];
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div>
        <flux:heading size="xl">{{ __('Deliveries') }}</flux:heading>
        <flux:subheading>{{ __('Every send this team has made, including sends from lists that have since been deleted.') }}</flux:subheading>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:button.group>
            @foreach ($this->filters as $value => $label)
                <flux:button
                    size="sm"
                    :variant="$status === $value ? 'filled' : 'ghost'"
                    wire:click="filterByStatus('{{ $value }}')"
                    data-test="filter-{{ $value }}"
                >
                    {{ $label }}
                    <flux:badge size="sm" class="ml-2">{{ $this->statusCounts[$value] }}</flux:badge>
                </flux:button>
            @endforeach
        </flux:button.group>

        <flux:input
            wire:model.live.debounce.300ms="search"
            size="sm"
            icon="magnifying-glass"
            :placeholder="__('Search list or destination')"
            class="max-w-xs"
            data-test="delivery-search"
        />
    </div>

    <div class="space-y-3">
        @forelse ($this->deliveries as $delivery)
            <div class="flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="delivery-row">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium">{{ $delivery->listLabel() }}</span>

                        @if ($delivery->listWasDeleted())
                            <flux:badge size="sm" color="zinc" data-test="deleted-list-badge">{{ __('List deleted') }}</flux:badge>
                        @endif

                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                            &rarr; {{ $delivery->integration?->name }} &middot; {{ $delivery->destinationLabel() }}
                        </flux:text>

                        <flux:badge size="sm" :color="$delivery->statusColor()">
                            {{ $delivery->statusLabel() }}
                        </flux:badge>
                    </div>

                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ __(':synced of :total synced', ['synced' => $delivery->synced_count, 'total' => $delivery->total_count]) }}
                        @if ($delivery->failed_count > 0)
                            &middot; <span class="text-red-500">{{ __(':count failed', ['count' => $delivery->failed_count]) }}</span>
                        @endif
                        &middot; {{ $delivery->created_at->diffForHumans() }}
                    </flux:text>
                </div>

                @if (! $delivery->listWasDeleted())
                    <flux:button
                        variant="subtle"
                        size="sm"
                        icon="arrow-up-right"
                        :href="route('lists.show', $delivery->contact_list_id)"
                        wire:navigate
                        data-test="view-list-button"
                    >
                        {{ __('View list') }}
                    </flux:button>
                @endif
            </div>
        @empty
            <flux:card class="text-center">
                <flux:icon.paper-airplane class="mx-auto mb-2 size-8 text-zinc-400" />
                <flux:text>
                    @if ($this->isFiltered)
                        {{ __('No deliveries match these filters.') }}
                    @else
                        {{ __('Nothing sent yet. Send a list to a destination to see it here.') }}
                    @endif
                </flux:text>
            </flux:card>
        @endforelse
    </div>

    <div>{{ $this->deliveries->links() }}</div>
</div>
