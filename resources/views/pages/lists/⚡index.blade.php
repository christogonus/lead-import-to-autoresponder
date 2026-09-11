<?php

use App\Actions\Lists\DeleteDraftedLists;
use App\Models\ContactList;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Lists')] class extends Component {
    #[Validate('required|string|max:255')]
    public string $name = '';

    /** Which shelf to show: the lists in use, or the ones set aside as drafts. */
    #[Url(except: 'active')]
    public string $filter = 'active';

    public function createList(): void
    {
        $validated = $this->validate();

        $list = $this->currentTeam()->contactLists()->create([
            'name' => $validated['name'],
        ]);

        Flux::toast(variant: 'success', text: __('List created.'));

        $this->redirectRoute('lists.show', ['contactList' => $list->id], navigate: true);
    }

    /**
     * Empty the drafts shelf: every list on it is permanently deleted, with its
     * contacts. Deliveries are kept, as they are when a draft is deleted one at
     * a time.
     */
    public function emptyDrafts(DeleteDraftedLists $deleter): void
    {
        Gate::authorize('deleteLists', $this->currentTeam());

        $deleted = $deleter->handle($this->currentTeam());

        $this->dispatch('close-modal', name: 'empty-drafts');

        // Counts and rows are computed per request, so they are recalculated on
        // the response this call renders — only the cached values need clearing.
        unset($this->lists, $this->draftsCount, $this->draftsContactsCount);

        if ($deleted['lists'] === 0) {
            Flux::toast(variant: 'warning', text: __('There were no drafts left to delete.'));

            return;
        }

        Flux::toast(variant: 'success', text: trans_choice(
            '{1}Deleted :count draft and :contacts.|[2,*]Deleted :count drafts and :contacts.',
            $deleted['lists'],
            [
                'count' => number_format($deleted['lists']),
                'contacts' => trans_choice(
                    '{0}no contacts|{1}:count contact|[2,*]:count contacts',
                    $deleted['contacts'],
                    ['count' => number_format($deleted['contacts'])],
                ),
            ],
        ));
    }

    protected function currentTeam()
    {
        return Auth::user()->currentTeam;
    }

    public function showDrafts(): void
    {
        $this->filter = 'drafts';
    }

    public function showActive(): void
    {
        $this->filter = 'active';
    }

    public function showingDrafts(): bool
    {
        return $this->filter === 'drafts';
    }

    /**
     * @return Collection<int, ContactList>
     */
    #[Computed]
    public function lists(): Collection
    {
        return $this->currentTeam()->contactLists()
            ->when($this->showingDrafts(), fn ($query) => $query->drafted(), fn ($query) => $query->active())
            ->withCount(['contacts', 'deliveries'])
            ->latest()
            ->get();
    }

    #[Computed]
    public function draftsCount(): int
    {
        return $this->currentTeam()->contactLists()->drafted()->count();
    }

    /**
     * How many contacts emptying the drafts would destroy, so the confirmation
     * can say what is actually at stake rather than just how many lists.
     */
    #[Computed]
    public function draftsContactsCount(): int
    {
        return $this->currentTeam()->contacts()
            ->whereIn('contact_list_id', $this->currentTeam()->contactLists()->drafted()->select('id'))
            ->count();
    }

    /**
     * Whether this user may empty the drafts shelf.
     */
    #[Computed]
    public function canEmptyDrafts(): bool
    {
        return Auth::user()->can('deleteLists', $this->currentTeam());
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

    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:button.group>
            <flux:button size="sm" :variant="$this->showingDrafts() ? 'ghost' : 'filled'" wire:click="showActive" data-test="filter-active">
                {{ __('Active') }}
            </flux:button>
            <flux:button size="sm" :variant="$this->showingDrafts() ? 'filled' : 'ghost'" wire:click="showDrafts" data-test="filter-drafts">
                {{ __('Drafts') }}
                @if ($this->draftsCount > 0)
                    <flux:badge size="sm" color="amber" class="ml-2">{{ $this->draftsCount }}</flux:badge>
                @endif
            </flux:button>
        </flux:button.group>

        @if ($this->showingDrafts() && $this->draftsCount > 0 && $this->canEmptyDrafts)
            <flux:modal.trigger name="empty-drafts">
                <flux:button
                    size="sm"
                    variant="danger"
                    icon="trash"
                    x-data=""
                    x-on:click.prevent="$dispatch('open-modal', 'empty-drafts')"
                    data-test="empty-drafts-button"
                >
                    {{ __('Empty drafts') }}
                </flux:button>
            </flux:modal.trigger>
        @endif
    </div>

    <div class="space-y-3">
        @forelse ($this->lists as $list)
            <a href="{{ route('lists.show', $list) }}" wire:navigate class="block rounded-lg border border-zinc-200 bg-white p-4 transition hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-600" data-test="list-row">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="font-medium">{{ $list->name }}</span>
                            @if ($list->isDraft())
                                <flux:badge size="sm" color="amber" data-test="draft-badge">{{ __('Draft') }}</flux:badge>
                            @endif
                        </div>
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
                <flux:text>
                    @if ($this->showingDrafts())
                        {{ __('No drafts. Set a list aside from its page when you are done with it.') }}
                    @else
                        {{ __('No lists yet. Create one to import contacts.') }}
                    @endif
                </flux:text>
            </flux:card>
        @endforelse
    </div>

    {{-- Rendered only alongside its trigger: an always-present modal would put
         the confirmation's wording on the page for someone who cannot use it. --}}
    @if ($this->showingDrafts() && $this->draftsCount > 0 && $this->canEmptyDrafts)
        <flux:modal name="empty-drafts" class="max-w-lg">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Empty drafts') }}</flux:heading>
                    <flux:subheading>
                        {{ trans_choice(
                            '{1}:count drafted list and :contacts will be deleted permanently.|[2,*]:count drafted lists and :contacts will be deleted permanently.',
                            $this->draftsCount,
                            [
                                'count' => number_format($this->draftsCount),
                                'contacts' => trans_choice(
                                    '{0}the contacts on it|{1}:count contact|[2,*]all :count of their contacts',
                                    $this->draftsContactsCount,
                                    ['count' => number_format($this->draftsContactsCount)],
                                ),
                            ],
                        ) }}
                    </flux:subheading>
                </div>

                <flux:callout variant="warning" icon="exclamation-triangle">
                    <flux:callout.text>
                        {{ __('There is no undo. Past deliveries are kept — they keep the name of the list they were sent from — but the lists and their contacts are gone for good.') }}
                    </flux:callout.text>
                </flux:callout>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="danger" wire:click="emptyDrafts" data-test="empty-drafts-submit">
                        {{ __('Delete all drafts') }}
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @endif

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
