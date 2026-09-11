<?php

use App\Actions\Suppressions\SuppressEmails;
use App\Actions\Suppressions\SuppressionResult;
use App\Enums\SuppressionReason;
use App\Models\Suppression;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Do not contact')] class extends Component
{
    use WithPagination;

    /** How many addresses one submission may carry. */
    public const MAX_EMAILS = 1000;

    /** Addresses to block, one per line (commas and semicolons work too). */
    public string $emails = '';

    public string $reason = 'unsubscribed';

    #[Url(except: '')]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Block every address in the box: record it and delete it from every list
     * this team holds.
     */
    public function block(SuppressEmails $suppressor): void
    {
        $validated = $this->validate([
            'emails' => ['required', 'string'],
            'reason' => ['required', Rule::enum(SuppressionReason::class)],
        ]);

        $addresses = $this->splitEmails($validated['emails']);

        if (count($addresses) > self::MAX_EMAILS) {
            $this->addError('emails', __('Block at most :count addresses at a time.', ['count' => number_format(self::MAX_EMAILS)]));

            return;
        }

        $result = $suppressor->handle(
            $this->currentTeam(),
            $addresses,
            SuppressionReason::from($validated['reason']),
            Auth::user(),
        );

        if ($result->accepted() === 0) {
            $this->addError('emails', __('No valid email addresses found.'));

            return;
        }

        $this->reset('emails');
        $this->resetPage();
        unset($this->blockedCount);

        Flux::toast(variant: 'success', text: $this->outcome($result));
    }

    /**
     * Take an address off the list. It does not bring back the contacts the
     * block removed — those have to be imported again.
     */
    public function unblock(Suppression $suppression): void
    {
        abort_unless($suppression->team_id === $this->currentTeam()->id, 403);

        $suppression->delete();

        unset($this->blockedCount);

        Flux::toast(variant: 'success', text: __(':email unblocked. It can be imported again.', ['email' => $suppression->email]));
    }

    /**
     * @return LengthAwarePaginator<Suppression>
     */
    #[Computed]
    public function suppressions(): LengthAwarePaginator
    {
        return $this->currentTeam()->suppressions()
            ->with('user')
            ->when($this->trimmedSearch() !== '', fn (Builder $query) => $query
                ->where('email', 'like', '%'.$this->trimmedSearch().'%'))
            ->latest()
            ->paginate(25);
    }

    #[Computed]
    public function blockedCount(): int
    {
        return $this->currentTeam()->suppressions()->count();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function reasons(): array
    {
        return SuppressionReason::options();
    }

    /**
     * A plain-language summary of what a block just did.
     */
    protected function outcome(SuppressionResult $result): string
    {
        $summary = trans_choice(
            '{1}Blocked :count address.|[2,*]Blocked :count addresses.',
            $result->accepted(),
            ['count' => number_format($result->accepted())],
        );

        if ($result->contactsRemoved > 0) {
            $summary .= ' '.__('Removed :contacts from :lists.', [
                'contacts' => trans_choice('{1}:count contact|[2,*]:count contacts', $result->contactsRemoved, ['count' => number_format($result->contactsRemoved)]),
                'lists' => trans_choice('{1}:count list|[2,*]:count lists', $result->listsAffected, ['count' => number_format($result->listsAffected)]),
            ]);
        }

        if ($result->invalid !== []) {
            $summary .= ' '.trans_choice(
                '{1}:count entry was not an email address.|[2,*]:count entries were not email addresses.',
                count($result->invalid),
                ['count' => number_format(count($result->invalid))],
            );
        }

        return $summary;
    }

    /**
     * Split pasted text into addresses on any sensible separator, so a column
     * copied out of a spreadsheet and a comma-separated line both work.
     *
     * @return array<int, string>
     */
    protected function splitEmails(string $input): array
    {
        return collect(preg_split('/[\s,;]+/', $input, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(fn (string $email): string => trim($email, '<>"\''))
            ->filter()
            ->values()
            ->all();
    }

    protected function currentTeam()
    {
        return Auth::user()->currentTeam;
    }

    protected function trimmedSearch(): string
    {
        return trim($this->search);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div>
        <flux:heading size="xl">{{ __('Do not contact') }}</flux:heading>
        <flux:subheading>
            {{ __('Addresses that bounced or asked to be removed. Blocking one deletes it from every list on this team and keeps it out of future imports and sends.') }}
        </flux:subheading>
    </div>

    <flux:card>
        <form wire:submit="block" class="flex flex-col gap-4">
            <flux:textarea
                wire:model="emails"
                :label="__('Email addresses')"
                :placeholder="__('someone@example.com')"
                :description="__('One per line, or separated by commas. Paste as many as you like.')"
                rows="4"
                data-test="suppress-emails"
            />

            <div class="flex flex-wrap items-end justify-between gap-3">
                <flux:select wire:model="reason" :label="__('Reason')" class="max-w-xs" data-test="suppress-reason">
                    @foreach ($this->reasons as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:button type="submit" variant="danger" icon="no-symbol" data-test="block-button">
                    {{ __('Block and remove') }}
                </flux:button>
            </div>

            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Contacts with these addresses are deleted from every list, including drafts. That part cannot be undone — unblocking only lets the address be imported again.') }}
            </flux:text>
        </form>
    </flux:card>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400" data-test="blocked-count">
            {{ trans_choice('{0}No addresses blocked yet|{1}:count blocked address|[2,*]:count blocked addresses', $this->blockedCount, ['count' => number_format($this->blockedCount)]) }}
        </flux:text>

        <flux:input
            wire:model.live.debounce.300ms="search"
            size="sm"
            icon="magnifying-glass"
            :placeholder="__('Search addresses')"
            class="max-w-xs"
            data-test="suppression-search"
        />
    </div>

    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 [&_td]:px-6 [&_td]:py-4 [&_th]:px-6 [&_th]:py-3.5">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Email') }}</flux:table.column>
                <flux:table.column>{{ __('Reason') }}</flux:table.column>
                <flux:table.column>{{ __('Removed') }}</flux:table.column>
                <flux:table.column>{{ __('Blocked') }}</flux:table.column>
                <flux:table.column />
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->suppressions as $suppression)
                    <flux:table.row :key="$suppression->id" data-test="suppression-row">
                        <flux:table.cell>{{ $suppression->email }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$suppression->reason->color()">
                                {{ $suppression->reason->label() }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            {{ trans_choice('{0}—|{1}:count contact|[2,*]:count contacts', $suppression->removed_count, ['count' => number_format($suppression->removed_count)]) }}
                        </flux:table.cell>
                        <flux:table.cell>
                            {{ $suppression->created_at->diffForHumans() }}
                            @if ($suppression->user)
                                <span class="text-zinc-500 dark:text-zinc-400">&middot; {{ $suppression->user->name }}</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:button
                                variant="ghost"
                                size="xs"
                                icon="arrow-uturn-left"
                                wire:click="unblock({{ $suppression->id }})"
                                wire:confirm="{{ __('Unblock this address? Contacts already removed will not come back.') }}"
                                data-test="unblock-button"
                            >
                                {{ __('Unblock') }}
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="text-center text-zinc-500 dark:text-zinc-400">
                            @if (trim($search) !== '')
                                {{ __('No blocked addresses match ":search".', ['search' => trim($search)]) }}
                            @else
                                {{ __('Nothing blocked yet. Paste an address above to remove it everywhere.') }}
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <div>{{ $this->suppressions->links() }}</div>
</div>
