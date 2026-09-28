<?php

use App\Enums\ContactStatus;
use App\Models\Delivery;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lists the contacts one delivery failed to push, with the reason the
 * destination gave for each. Mounted once per page and pointed at a delivery
 * by the "show-delivery-failures" event.
 */
new class extends Component {
    use WithPagination;

    public ?int $deliveryId = null;

    #[On('show-delivery-failures')]
    public function show(int $deliveryId): void
    {
        $this->deliveryId = $deliveryId;

        unset($this->delivery, $this->failures, $this->reasons);

        // Authorize up front so a forged id never opens the modal at all.
        Gate::authorize('view', $this->delivery);

        $this->resetPage('failuresPage');

        Flux::modal('delivery-failures')->show();
    }

    #[Computed]
    public function delivery(): ?Delivery
    {
        if ($this->deliveryId === null) {
            return null;
        }

        $delivery = Delivery::with('integration', 'contactList')->findOrFail($this->deliveryId);

        Gate::authorize('view', $delivery);

        return $delivery;
    }

    /**
     * @return LengthAwarePaginator<\App\Models\DeliveryContact>|null
     */
    #[Computed]
    public function failures(): ?LengthAwarePaginator
    {
        return $this->failedContacts()
            ?->with('contact')
            ->latest('updated_at')
            ->latest('id')
            ->paginate(15, pageName: 'failuresPage');
    }

    /**
     * How many contacts failed for each distinct reason, most common first, so
     * a single systemic cause stands out from a scatter of bad addresses.
     *
     * @return Collection<string, int>
     */
    #[Computed]
    public function reasons(): Collection
    {
        return collect($this->failedContacts()
            ?->selectRaw('sync_error, count(*) as aggregate')
            ->groupBy('sync_error')
            ->orderByDesc('aggregate')
            ->limit(5)
            ->pluck('aggregate', 'sync_error'));
    }

    /**
     * @return HasMany<\App\Models\DeliveryContact, Delivery>|null
     */
    protected function failedContacts(): ?HasMany
    {
        return $this->delivery?->deliveryContacts()->where('status', ContactStatus::Failed);
    }
}; ?>

<flux:modal name="delivery-failures" class="w-full max-w-4xl">
    @if ($this->delivery)
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Failed contacts') }}</flux:heading>
                <flux:subheading>
                    {{ $this->delivery->listLabel() }} &rarr; {{ $this->delivery->integration?->name }} &middot; {{ $this->delivery->destinationLabel() }}
                </flux:subheading>
            </div>

            @if ($this->failures->total() === 0)
                <flux:text data-test="no-failures">{{ __('No contacts failed on this delivery.') }}</flux:text>
            @else
                <div class="space-y-2" data-test="failure-reasons">
                    <flux:text class="text-sm font-medium">{{ __('Most common reasons') }}</flux:text>

                    @foreach ($this->reasons as $reason => $count)
                        <div wire:key="reason-{{ md5((string) $reason) }}" class="flex items-start justify-between gap-4 text-sm">
                            <flux:text class="text-sm">{{ $reason ?: __('No reason given') }}</flux:text>
                            <flux:badge size="sm" color="red">{{ number_format($count) }}</flux:badge>
                        </div>
                    @endforeach
                </div>

                <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 [&_td]:px-4 [&_td]:py-3 [&_th]:px-4 [&_th]:py-3">
                    <flux:table :paginate="$this->failures">
                        <flux:table.columns>
                            <flux:table.column>{{ __('Email') }}</flux:table.column>
                            <flux:table.column>{{ __('Name') }}</flux:table.column>
                            <flux:table.column>{{ __('Reason') }}</flux:table.column>
                            <flux:table.column>{{ __('Failed') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($this->failures as $failure)
                                <flux:table.row :key="$failure->id" data-test="failure-row">
                                    <flux:table.cell class="font-medium">{{ $failure->contact?->email }}</flux:table.cell>
                                    <flux:table.cell>{{ trim($failure->contact?->first_name.' '.$failure->contact?->last_name) }}</flux:table.cell>
                                    <flux:table.cell class="whitespace-normal! break-words text-red-600 dark:text-red-400">{{ $failure->sync_error ?: __('No reason given') }}</flux:table.cell>
                                    <flux:table.cell class="whitespace-nowrap">{{ $failure->updated_at?->diffForHumans() }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            @endif

            <div class="flex justify-end gap-2">
                @if ($this->failures->total() > 0)
                    <flux:button
                        icon="arrow-down-tray"
                        :href="route('deliveries.failures.export', $this->delivery)"
                        data-test="export-failures"
                    >
                        {{ __('Download CSV') }}
                    </flux:button>
                @endif

                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Close') }}</flux:button>
                </flux:modal.close>
            </div>
        </div>
    @endif
</flux:modal>
