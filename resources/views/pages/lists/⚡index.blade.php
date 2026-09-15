<?php

use App\Actions\Lists\DeleteDraftedLists;
use App\Actions\Lists\DeleteListsByName;
use App\Actions\Lists\MergeContactLists;
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

    /** Ids of the lists chosen for a merge. */
    public array $mergeSelection = [];

    /** Name for the list a merge would create. */
    public string $mergeName = '';

    /** Name pattern picking the lists to delete in bulk, with "*" as a wildcard. */
    public string $deletePattern = '';

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
     * Combine the chosen lists into one new list holding a single contact per
     * email address. The chosen lists themselves are left alone.
     */
    public function mergeLists(MergeContactLists $merger): void
    {
        $validated = $this->validate([
            'mergeName' => ['required', 'string', 'max:255'],
            'mergeSelection' => ['array', 'min:'.MergeContactLists::MINIMUM_SOURCES],
            'mergeSelection.*' => ['integer'],
        ], [
            'mergeSelection.min' => __('Choose at least :count lists to merge.', ['count' => MergeContactLists::MINIMUM_SOURCES]),
        ], [
            'mergeName' => __('list name'),
        ]);

        // Re-read the chosen lists through the team's own active lists, so a
        // tampered-with checkbox value cannot pull in another team's list or one
        // that has since been set aside.
        $sources = $this->currentTeam()->contactLists()
            ->active()
            ->whereIn('id', $validated['mergeSelection'])
            ->get();

        if ($sources->count() < MergeContactLists::MINIMUM_SOURCES) {
            $this->addError('mergeSelection', __('Some of those lists are no longer available to merge.'));

            return;
        }

        $merged = $merger->handle($sources, $validated['mergeName']);

        Flux::toast(variant: 'success', text: trans_choice(
            '{0}Merged :lists — no contacts to copy.|{1}Merged :lists into :count contact.|[2,*]Merged :lists into :count unique contacts.',
            $merged['merged'],
            [
                'count' => number_format($merged['merged']),
                'lists' => trans_choice('{1}:count list|[2,*]:count lists', $sources->count(), ['count' => $sources->count()]),
            ],
        ).($merged['duplicates'] > 0 ? ' '.trans_choice(
            '{1}:count duplicate was left out.|[2,*]:count duplicates were left out.',
            $merged['duplicates'],
            ['count' => number_format($merged['duplicates'])],
        ) : ''));

        $this->reset('mergeSelection', 'mergeName');

        $this->redirectRoute('lists.show', ['contactList' => $merged['list']->id], navigate: true);
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

        Flux::modal('empty-drafts')->close();

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

    /**
     * Permanently delete every list, active or drafted, whose name matches the
     * pattern — the way out after a split produced far more lists than meant.
     */
    public function deleteListsByName(DeleteListsByName $deleter): void
    {
        Gate::authorize('deleteLists', $this->currentTeam());

        $validated = $this->validate([
            'deletePattern' => ['required', 'string', 'max:255'],
        ], [], [
            'deletePattern' => __('name pattern'),
        ]);

        if (! DeleteListsByName::isUsablePattern($validated['deletePattern'])) {
            $this->addError('deletePattern', __('Add some of the name to the pattern — a wildcard on its own would match every list.'));

            return;
        }

        $deleted = $deleter->handle($this->currentTeam(), $validated['deletePattern']);

        Flux::modal('delete-by-name')->close();
        $this->reset('deletePattern');

        unset($this->lists, $this->draftsCount, $this->draftsContactsCount, $this->patternMatches);

        if ($deleted['lists'] === 0 && $deleted['skipped'] === 0) {
            Flux::toast(variant: 'warning', text: __('No lists matched that name.'));

            return;
        }

        Flux::toast(variant: $deleted['skipped'] > 0 ? 'warning' : 'success', text: trans_choice(
            '{0}Deleted no lists.|{1}Deleted :count list and :contacts.|[2,*]Deleted :count lists and :contacts.',
            $deleted['lists'],
            [
                'count' => number_format($deleted['lists']),
                'contacts' => trans_choice(
                    '{0}no contacts|{1}:count contact|[2,*]:count contacts',
                    $deleted['contacts'],
                    ['count' => number_format($deleted['contacts'])],
                ),
            ],
        ).($deleted['skipped'] > 0 ? ' '.trans_choice(
            '{1}:count list was skipped because a send or import is still running on it.|[2,*]:count lists were skipped because a send or import is still running on them.',
            $deleted['skipped'],
            ['count' => number_format($deleted['skipped'])],
        ) : ''));
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

    /**
     * What merging the current selection would produce, so the outcome — above
     * all how many duplicates get dropped — is visible before committing.
     *
     * @return array{unique: int, duplicates: int}|null
     */
    #[Computed]
    public function mergeEstimate(): ?array
    {
        $ids = array_map('intval', $this->mergeSelection);

        if (count($ids) < MergeContactLists::MINIMUM_SOURCES) {
            return null;
        }

        $contacts = fn () => $this->currentTeam()->contacts()->whereIn('contact_list_id', $ids);

        $total = $contacts()->count();
        $unique = $contacts()->distinct()->count('email');

        return ['unique' => $unique, 'duplicates' => $total - $unique];
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
     * What deleting by the current pattern would destroy, so a pattern that is
     * broader than intended shows up before anything is gone.
     *
     * @return array{lists: int, contacts: int, busy: int, names: array<int, string>}|null
     */
    #[Computed]
    public function patternMatches(): ?array
    {
        if (! DeleteListsByName::isUsablePattern($this->deletePattern)) {
            return null;
        }

        $matching = fn () => $this->currentTeam()->contactLists()->nameMatches($this->deletePattern);

        return [
            'lists' => $matching()->count(),
            'contacts' => $this->currentTeam()->contacts()->whereIn('contact_list_id', $matching()->select('id'))->count(),
            'busy' => $matching()->active()->count() - $matching()->active()->idle()->count(),
            'names' => $matching()->orderBy('name')->limit(5)->pluck('name')->all(),
        ];
    }

    /**
     * Whether this user may permanently delete lists — emptying the drafts
     * shelf or deleting by name.
     */
    #[Computed]
    public function canDeleteLists(): bool
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
            <flux:button variant="primary" icon="plus" data-test="create-list-button">
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

        @if (! $this->showingDrafts() && $this->lists->count() >= 2)
            <flux:modal.trigger name="merge-lists">
                <flux:button
                    size="sm"
                    variant="subtle"
                    icon="arrows-pointing-in"
                    data-test="merge-button"
                >
                    {{ __('Merge lists') }}
                </flux:button>
            </flux:modal.trigger>
        @endif

        <div class="flex flex-wrap items-center gap-2">
            @if ($this->canDeleteLists)
                <flux:modal.trigger name="delete-by-name">
                    <flux:button
                        size="sm"
                        variant="subtle"
                        icon="trash"
                        data-test="delete-by-name-button"
                    >
                        {{ __('Delete by name') }}
                    </flux:button>
                </flux:modal.trigger>
            @endif

            @if ($this->showingDrafts() && $this->draftsCount > 0 && $this->canDeleteLists)
                <flux:modal.trigger name="empty-drafts">
                    <flux:button
                        size="sm"
                        variant="danger"
                        icon="trash"
                        data-test="empty-drafts-button"
                    >
                        {{ __('Empty drafts') }}
                    </flux:button>
                </flux:modal.trigger>
            @endif
        </div>
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
    @if ($this->canDeleteLists)
        <flux:modal name="delete-by-name" :show="$errors->has('deletePattern')" focusable class="max-w-lg">
            <form wire:submit="deleteListsByName" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Delete lists by name') }}</flux:heading>
                    <flux:subheading>
                        {{ __('Every list whose name matches is deleted permanently with its contacts, whether it is active or a draft. Use * to stand for any text.') }}
                    </flux:subheading>
                </div>

                <flux:input
                    wire:model.live.debounce.400ms="deletePattern"
                    :label="__('Name pattern')"
                    :placeholder="__('e.g. ElechyComplete 15 *')"
                    data-test="delete-pattern"
                />

                @if ($this->patternMatches)
                    <div class="space-y-2" data-test="delete-pattern-preview">
                        <flux:text class="text-sm">
                            {{ trans_choice(
                                '{0}No lists match this pattern.|{1}Matches :count list holding :contacts.|[2,*]Matches :count lists holding :contacts.',
                                $this->patternMatches['lists'],
                                [
                                    'count' => number_format($this->patternMatches['lists']),
                                    'contacts' => trans_choice(
                                        '{0}no contacts|{1}:count contact|[2,*]:count contacts',
                                        $this->patternMatches['contacts'],
                                        ['count' => number_format($this->patternMatches['contacts'])],
                                    ),
                                ],
                            ) }}
                        </flux:text>

                        @if ($this->patternMatches['lists'] > 0)
                            <ul class="list-inside list-disc text-sm text-zinc-500 dark:text-zinc-400">
                                @foreach ($this->patternMatches['names'] as $index => $matchedName)
                                    <li wire:key="pattern-match-{{ $index }}">{{ $matchedName }}</li>
                                @endforeach
                                @if ($this->patternMatches['lists'] > count($this->patternMatches['names']))
                                    <li class="list-none">
                                        {{ __('…and :count more', ['count' => number_format($this->patternMatches['lists'] - count($this->patternMatches['names']))]) }}
                                    </li>
                                @endif
                            </ul>
                        @endif

                        @if ($this->patternMatches['busy'] > 0)
                            <flux:text class="text-sm text-amber-600 dark:text-amber-400">
                                {{ trans_choice(
                                    '{1}:count of them has a send or import still running and will be skipped.|[2,*]:count of them have a send or import still running and will be skipped.',
                                    $this->patternMatches['busy'],
                                    ['count' => number_format($this->patternMatches['busy'])],
                                ) }}
                            </flux:text>
                        @endif
                    </div>
                @endif

                <flux:callout variant="warning" icon="exclamation-triangle">
                    <flux:callout.text>
                        {{ __('There is no undo. Past deliveries are kept, but the matching lists and their contacts are gone for good.') }}
                    </flux:callout.text>
                </flux:callout>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button
                        variant="danger"
                        type="submit"
                        :disabled="! $this->patternMatches || $this->patternMatches['lists'] === 0"
                        data-test="delete-by-name-submit"
                    >
                        {{ $this->patternMatches && $this->patternMatches['lists'] > 0
                            ? trans_choice('{1}Delete :count list|[2,*]Delete :count lists', $this->patternMatches['lists'], ['count' => number_format($this->patternMatches['lists'])])
                            : __('Delete lists') }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    @if ($this->showingDrafts() && $this->draftsCount > 0 && $this->canDeleteLists)
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

    {{-- Merge lists modal --}}
    @if (! $this->showingDrafts() && $this->lists->count() >= 2)
        <flux:modal name="merge-lists" :show="$errors->hasAny(['mergeName', 'mergeSelection'])" focusable class="max-w-lg">
            <form wire:submit="mergeLists" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Merge lists') }}</flux:heading>
                    <flux:subheading>
                        {{ __('Pick the lists to combine. The new list keeps one contact per email address; the lists you pick are left exactly as they are.') }}
                    </flux:subheading>
                </div>

                <flux:input wire:model="mergeName" :label="__('New list name')" :placeholder="__('e.g. All Webinar Leads')" data-test="merge-name" />

                <flux:checkbox.group wire:model.live="mergeSelection" :label="__('Lists to merge')" class="max-h-64 overflow-y-auto">
                    @foreach ($this->lists as $list)
                        <flux:checkbox
                            :value="(string) $list->id"
                            :label="$list->name"
                            :description="trans_choice('{0}No contacts|{1}:count contact|[2,*]:count contacts', $list->contacts_count, ['count' => number_format($list->contacts_count)])"
                            data-test="merge-option"
                        />
                    @endforeach
                </flux:checkbox.group>

                <flux:error name="mergeSelection" />

                @if ($this->mergeEstimate)
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400" data-test="merge-estimate">
                        {{ trans_choice(
                            '{0}Nothing to copy — the chosen lists are empty.|{1}The new list would hold :count contact.|[2,*]The new list would hold :count unique contacts.',
                            $this->mergeEstimate['unique'],
                            ['count' => number_format($this->mergeEstimate['unique'])],
                        ) }}
                        @if ($this->mergeEstimate['duplicates'] > 0)
                            {{ trans_choice(
                                '{1}:count address appears on more than one list and is copied once.|[2,*]:count addresses appear on more than one list and are copied once each.',
                                $this->mergeEstimate['duplicates'],
                                ['count' => number_format($this->mergeEstimate['duplicates'])],
                            ) }}
                        @endif
                    </flux:text>
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit" data-test="merge-submit">{{ __('Merge lists') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    {{-- Opened by its own field's errors only: the page now has a second form,
         and a failed merge must not pop the create dialog open as well. --}}
    <flux:modal name="create-list" :show="$errors->has('name')" focusable class="max-w-lg">
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
