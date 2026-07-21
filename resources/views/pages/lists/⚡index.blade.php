<?php

use App\Models\ContactList;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Lists')] class extends Component {
    #[Validate('required|string|max:255')]
    public string $name = '';

    public function createList(): void
    {
        $validated = $this->validate();

        $list = $this->currentTeam()->contactLists()->create([
            'name' => $validated['name'],
        ]);

        Flux::toast(variant: 'success', text: __('List created.'));

        $this->redirectRoute('lists.show', ['contactList' => $list->id], navigate: true);
    }

    protected function currentTeam()
    {
        return Auth::user()->currentTeam;
    }

    /**
     * @return Collection<int, ContactList>
     */
    #[Computed]
    public function lists(): Collection
    {
        return $this->currentTeam()->contactLists()
            ->withCount(['contacts', 'deliveries'])
            ->latest()
            ->get();
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Lists') }}</flux:heading>
            <flux:subheading>{{ __('Group your contacts, then send a list to any connected destination.') }}</flux:subheading>
        </div>

        <flux:modal.trigger name="create-list">
            <flux:button variant="primary" icon="plus" x-data="" x-on:click.prevent="$dispatch('open-modal', 'create-list')" data-test="create-list-button">
                {{ __('New list') }}
            </flux:button>
        </flux:modal.trigger>
    </div>

    <div class="space-y-3">
        @forelse ($this->lists as $list)
            <a href="{{ route('lists.show', $list) }}" wire:navigate class="block rounded-lg border border-zinc-200 bg-white p-4 transition hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-600" data-test="list-row">
                <div class="flex items-center justify-between">
                    <div>
                        <span class="font-medium">{{ $list->name }}</span>
                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                            {{ trans_choice('{0}Sent to no destinations|{1}Sent to :count destination|[2,*]Sent to :count destinations', $list->deliveries_count, ['count' => $list->deliveries_count]) }}
                        </flux:text>
                    </div>
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ trans_choice('{0}No contacts|{1}:count contact|[2,*]:count contacts', $list->contacts_count, ['count' => $list->contacts_count]) }}
                    </flux:text>
                </div>
            </a>
        @empty
            <flux:card class="text-center">
                <flux:icon.rectangle-stack class="mx-auto mb-2 size-8 text-zinc-400" />
                <flux:text>{{ __('No lists yet. Create one to import contacts.') }}</flux:text>
            </flux:card>
        @endforelse
    </div>

    <flux:modal name="create-list" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
        <form wire:submit="createList" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Create a list') }}</flux:heading>
                <flux:subheading>{{ __('A list is just a group of contacts. You choose where to send it later.') }}</flux:subheading>
            </div>

            <flux:input wire:model="name" :label="__('List name')" :placeholder="__('e.g. First Contact')" data-test="list-name" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="create-list-submit">{{ __('Create list') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
