<?php

use App\Actions\Deliveries\DeleteDeliveries;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Confirms and carries out deleting one or more finished deliveries. Mounted
 * once per page and opened by the "confirm-delete-deliveries" event; announces
 * "deliveries-deleted" so the page can refresh what it shows.
 */
new class extends Component {
    /** @var array<int, int> */
    public array $deliveryIds = [];

    #[On('confirm-delete-deliveries')]
    public function confirm(array $deliveryIds): void
    {
        Gate::authorize('deleteDeliveries', Auth::user()->currentTeam);

        $this->deliveryIds = array_values(array_unique(array_map('intval', $deliveryIds)));

        if ($this->deliveryIds === []) {
            return;
        }

        Flux::modal('delete-deliveries')->show();
    }

    public function deleteDeliveries(DeleteDeliveries $deleter): void
    {
        $team = Auth::user()->currentTeam;

        Gate::authorize('deleteDeliveries', $team);

        $requested = count($this->deliveryIds);
        $deleted = $deleter->handle($team, $this->deliveryIds);

        $this->reset('deliveryIds');

        Flux::modal('delete-deliveries')->close();

        $this->dispatch('deliveries-deleted');

        if ($deleted === 0) {
            Flux::toast(variant: 'warning', text: __('Nothing was deleted. Deliveries still sending must be cancelled first.'));

            return;
        }

        $message = trans_choice('{1}Deleted :count delivery.|[2,*]Deleted :count deliveries.', $deleted, ['count' => number_format($deleted)]);

        if ($deleted < $requested) {
            $message .= ' '.__('Deliveries still sending were skipped.');
        }

        Flux::toast(variant: 'success', text: $message);
    }
}; ?>

<flux:modal name="delete-deliveries" class="max-w-lg">
    <div class="space-y-6">
        <div>
            <flux:heading size="lg">
                {{ trans_choice('{1}Delete delivery|[2,*]Delete :count deliveries', count($deliveryIds), ['count' => number_format(count($deliveryIds))]) }}
            </flux:heading>
            <flux:subheading>
                {{ __('The delivery record and its per-contact history, including failure reasons, are deleted permanently.') }}
            </flux:subheading>
        </div>

        <flux:callout variant="warning" icon="exclamation-triangle">
            <flux:callout.text>
                {{ __('Contacts already sent stay at the destination. Without this record, sending the same list to the same destination again will push those contacts again.') }}
            </flux:callout.text>
        </flux:callout>

        <div class="flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>
            <flux:button variant="danger" wire:click="deleteDeliveries" data-test="delete-deliveries-submit">
                {{ __('Delete') }}
            </flux:button>
        </div>
    </div>
</flux:modal>
