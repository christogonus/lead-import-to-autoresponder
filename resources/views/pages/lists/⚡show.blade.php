<?php

use App\Actions\Contacts\ImportContacts;
use App\Actions\Contacts\ParseDelimitedContacts;
use App\Actions\Deliveries\SendListToDestination;
use App\Actions\Lists\SplitContactList;
use App\Enums\ContactStatus;
use App\Integrations\IntegrationManager;
use App\Jobs\PushDeliveryContact;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Delivery;
use App\Models\DeliveryContact;
use App\Models\Integration;
use Carbon\CarbonInterval;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Title('List')] class extends Component
{
    use WithFileUploads, WithPagination;

    public ContactList $contactList;

    #[Url(except: '')]
    public string $search = '';

    public ?int $editingContactId = null;

    public string $editingEmail = '';

    /** @var array<string, string> */
    public array $manual = [
        'first_name' => '',
        'last_name' => '',
        'email' => '',
        'phone' => '',
        'country' => '',
    ];

    public string $importMode = 'upload';

    public $file;

    public string $pasted = '';

    public string $importStep = 'source';

    /** @var array<int, string> */
    public array $header = [];

    /** Path to the uploaded/pasted file on the local disk while mapping. */
    public string $importPath = '';

    /** Number of data rows detected in the uploaded file. */
    public int $importRowCount = 0;

    /** @var array<string, string> */
    public array $mapping = [
        'first_name' => '',
        'last_name' => '',
        'email' => '',
        'phone' => '',
        'country' => '',
    ];

    public ?string $importFilename = null;

    // Send-to-destination state.
    public ?int $sendIntegrationId = null;

    public string $sendRemoteId = '';

    /** @var array<int, array{id: string, name: string}> */
    public array $sendRemoteLists = [];

    public ?string $sendRemoteListsError = null;

    /** Contacts queued per hour. Empty means send everything at once. */
    public ?int $sendContactsPerHour = null;

    /** Contacts per list when splitting this list into smaller ones. */
    public ?int $splitSize = null;

    public function mount(ContactList $contactList): void
    {
        abort_unless($contactList->team_id === Auth::user()->currentTeam->id, 403);

        $this->contactList = $contactList;
    }

    public function addContact(ImportContacts $import): void
    {
        $this->validate([
            'manual.email' => ['required', 'email'],
            'manual.first_name' => ['nullable', 'string', 'max:255'],
            'manual.last_name' => ['nullable', 'string', 'max:255'],
            'manual.phone' => ['nullable', 'string', 'max:255'],
            'manual.country' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $import->handle($this->contactList, [$this->manual], 'manual', null, Auth::user());

        if ($result->skipped_count > 0) {
            $this->addError('manual.email', __('That email is already on this list.'));

            return;
        }

        $this->reset('manual');
        $this->dispatch('close-modal', name: 'add-contact');
        Flux::toast(variant: 'success', text: __('Contact added.'));
    }

    public function parseSource(ParseDelimitedContacts $parser): void
    {
        if ($this->importMode === 'upload') {
            $this->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:10240']]);
            $path = $this->file->storeAs('imports', Str::uuid()->toString().'.csv', 'local');
            $this->importFilename = $this->file->getClientOriginalName();
        } else {
            $this->validate(['pasted' => ['required', 'string']]);
            $path = 'imports/'.Str::uuid()->toString().'.csv';
            Storage::disk('local')->put($path, $this->pasted);
            $this->importFilename = null;
        }

        $parsed = $parser->handle(Storage::disk('local')->get($path));

        if ($parsed['header'] === [] || $parsed['rows'] === []) {
            Storage::disk('local')->delete($path);
            $this->addError('file', __('No rows found. Include a header row followed by contacts.'));
            $this->addError('pasted', __('No rows found. Include a header row followed by contacts.'));

            return;
        }

        // Keep the rows on disk and only hold the header + count in component
        // state, so large files don't bloat the Livewire snapshot that is
        // round-tripped on every request.
        $this->importPath = $path;
        $this->importRowCount = count($parsed['rows']);
        $this->header = $parsed['header'];
        $this->mapping = $this->guessMapping($parsed['header']);
        $this->reset('pasted');
        $this->importStep = 'map';
    }

    public function runImport(ImportContacts $import, ParseDelimitedContacts $parser): void
    {
        $this->validate(
            ['mapping.email' => ['required', 'string']],
            ['mapping.email.required' => __('Choose which column contains the email address.')],
        );

        abort_unless(
            str_starts_with($this->importPath, 'imports/') && Storage::disk('local')->exists($this->importPath),
            422,
        );

        $parsed = $parser->handle(Storage::disk('local')->get($this->importPath));

        $rows = array_map(function (array $row): array {
            $mapped = [];

            foreach ($this->mapping as $field => $column) {
                $mapped[$field] = ($column === '') ? null : ($row[(int) $column] ?? null);
            }

            return $mapped;
        }, $parsed['rows']);

        $result = $import->handle(
            $this->contactList,
            $rows,
            $this->importMode === 'upload' ? 'csv' : 'paste',
            $this->importFilename,
            Auth::user(),
        );

        $this->resetImport();
        $this->dispatch('close-modal', name: 'import-contacts');

        Flux::toast(variant: 'success', text: __(':imported imported, :skipped skipped, :failed failed.', [
            'imported' => $result->imported_count,
            'skipped' => $result->skipped_count,
            'failed' => $result->failed_count,
        ]));
    }

    public function updatedSendIntegrationId(IntegrationManager $manager): void
    {
        $this->reset('sendRemoteId', 'sendRemoteLists', 'sendRemoteListsError');

        $integration = $this->currentTeam()->integrations()->find($this->sendIntegrationId);

        if ($integration === null) {
            return;
        }

        try {
            $this->sendRemoteLists = collect($manager->driver($integration)->lists())
                ->map(fn ($list): array => $list->toArray())
                ->all();
        } catch (Throwable $e) {
            $this->sendRemoteListsError = $e->getMessage();
        }
    }

    public function sendToDestination(SendListToDestination $sender): void
    {
        $validated = $this->validate([
            'sendIntegrationId' => ['required', 'integer'],
            'sendRemoteId' => ['required', 'string'],
            'sendContactsPerHour' => ['nullable', 'integer', 'min:1'],
        ]);

        $integration = $this->currentTeam()->integrations()->findOrFail($validated['sendIntegrationId']);

        $remoteName = collect($this->sendRemoteLists)->firstWhere('id', $validated['sendRemoteId'])['name'] ?? null;

        $delivery = $sender->handle(
            $this->contactList,
            $integration,
            $validated['sendRemoteId'],
            $remoteName,
            $validated['sendContactsPerHour'] ?? null,
        );

        $this->reset('sendIntegrationId', 'sendRemoteId', 'sendRemoteLists', 'sendRemoteListsError', 'sendContactsPerHour');
        $this->dispatch('close-modal', name: 'send-list');

        if ($delivery === null) {
            Flux::toast(variant: 'warning', text: __('Every contact is already synced to that destination.'));

            return;
        }

        if ($delivery->isPaced()) {
            Flux::toast(variant: 'success', text: __('Sending :count contacts to :destination at :rate per hour.', [
                'count' => $delivery->total_count,
                'destination' => $delivery->destinationLabel(),
                'rate' => number_format($delivery->contacts_per_hour),
            ]));

            return;
        }

        Flux::toast(variant: 'success', text: __('Sending :count contacts to :destination.', [
            'count' => $delivery->total_count,
            'destination' => $delivery->destinationLabel(),
        ]));
    }

    public function splitList(SplitContactList $splitter): void
    {
        $count = $this->contactsCount;

        $this->validate(
            ['splitSize' => ['required', 'integer', 'min:1', 'lt:'.max($count, 1)]],
            ['splitSize.lt' => __('Choose a size smaller than the whole list — :count or more would just copy it.', ['count' => $count])],
        );

        $splits = $splitter->handle($this->contactList, (int) $this->splitSize);

        $this->reset('splitSize');
        $this->dispatch('close-modal', name: 'split-list');

        Flux::toast(variant: 'success', text: trans_choice('{1}Created :count list.|[2,*]Created :count lists.', $splits->count(), ['count' => $splits->count()]));

        $this->redirectRoute('lists.index', navigate: true);
    }

    public function cancelDelivery(Delivery $delivery): void
    {
        abort_unless($delivery->contact_list_id === $this->contactList->id, 403);

        if (! $delivery->isCancellable()) {
            Flux::toast(variant: 'warning', text: __('That delivery has already finished.'));

            return;
        }

        $delivery->cancel();

        unset($this->deliveries);

        Flux::toast(variant: 'success', text: __('Delivery cancelled. Contacts already sent were left at the destination.'));
    }

    public function resumeDelivery(Delivery $delivery): void
    {
        abort_unless($delivery->contact_list_id === $this->contactList->id, 403);

        if (! $delivery->isResumable()) {
            Flux::toast(variant: 'warning', text: __('That delivery is not paused.'));

            return;
        }

        $delivery->resume();

        unset($this->deliveries);

        Flux::toast(variant: 'success', text: __('Delivery resumed.'));
    }

    public function retryDelivery(Delivery $delivery): void
    {
        abort_unless($delivery->contact_list_id === $this->contactList->id, 403);

        // A cancelled delivery is terminal: retrying would sweep its stood-down
        // contacts back onto the queue alongside the failed ones.
        abort_if($delivery->status === Delivery::STATUS_CANCELLED, 403);

        $paced = $delivery->isPaced();

        // Only contacts that actually failed: a retry can land while the
        // delivery is still processing, and sweeping pending contacts along
        // would queue a second push for ones already in flight.
        $delivery->deliveryContacts()
            ->where('status', ContactStatus::Failed)
            ->get()
            ->each(function (DeliveryContact $deliveryContact) use ($paced): void {
                $deliveryContact->update([
                    'status' => ContactStatus::Pending,
                    'sync_error' => null,
                    'released_at' => $paced ? null : now(),
                ]);

                if (! $paced) {
                    PushDeliveryContact::dispatch($deliveryContact);
                }
            });

        $delivery->update([
            'status' => Delivery::STATUS_PROCESSING,
            // Restart the clock so the retry is metered from now, not from the
            // original send, which would release the whole backlog at once.
            'pacing_started_at' => $paced ? now() : null,
        ]);

        // Settle the counts so the failed badge clears now rather than when
        // the first retried push reports back.
        $delivery->recount();

        unset($this->deliveries);

        Flux::toast(variant: 'success', text: __('Retrying failed contacts.'));
    }

    public function deleteContact(Contact $contact): void
    {
        abort_unless($contact->contact_list_id === $this->contactList->id, 403);

        $deliveryIds = $contact->deliveryContacts()->pluck('delivery_id');

        $contact->delete();

        // The delete cascades the contact's delivery rows away without any push
        // reporting back, so recount here — otherwise a delivery whose last
        // pending contact just vanished would read "Sending" forever.
        Delivery::query()->findMany($deliveryIds)->each->recount();

        unset($this->deliveries);

        Flux::toast(variant: 'success', text: __('Contact removed.'));
    }

    public function updatedSearch(): void
    {
        $this->cancelEditingEmail();
        $this->resetPage();
    }

    public function editEmail(Contact $contact): void
    {
        abort_unless($contact->contact_list_id === $this->contactList->id, 403);

        $this->resetErrorBag('editingEmail');
        $this->editingContactId = $contact->id;
        $this->editingEmail = $contact->email;
    }

    public function cancelEditingEmail(): void
    {
        $this->resetErrorBag('editingEmail');
        $this->reset('editingContactId', 'editingEmail');
    }

    public function saveEmail(): void
    {
        $contact = $this->contactList->contacts()->findOrFail($this->editingContactId);

        // Emails are stored lowercased and trimmed on import, so normalize here too
        // or case variants would slip past the (contact_list_id, email) unique index.
        $this->editingEmail = strtolower(trim($this->editingEmail));

        $this->validate([
            'editingEmail' => [
                'required',
                'email',
                'max:255',
                Rule::unique('contacts', 'email')
                    ->where('contact_list_id', $this->contactList->id)
                    ->ignore($contact->id),
            ],
        ], [
            'editingEmail.unique' => __('That email is already on this list.'),
        ]);

        $contact->update(['email' => $this->editingEmail]);

        $this->cancelEditingEmail();

        Flux::toast(variant: 'success', text: __('Email updated.'));
    }

    public function resetImport(): void
    {
        if ($this->importPath !== '' && Storage::disk('local')->exists($this->importPath)) {
            Storage::disk('local')->delete($this->importPath);
        }

        $this->reset('file', 'pasted', 'header', 'importPath', 'importRowCount', 'importFilename');
        $this->importStep = 'source';
        $this->mapping = array_fill_keys(array_keys($this->mapping), '');
    }

    protected function currentTeam()
    {
        return Auth::user()->currentTeam;
    }

    /**
     * @param  array<int, string>  $header
     * @return array<string, string>
     */
    protected function guessMapping(array $header): array
    {
        $synonyms = [
            'email' => ['email', 'e-mail', 'mail', 'email address'],
            'first_name' => ['first name', 'firstname', 'first', 'given name', 'fname'],
            'last_name' => ['last name', 'lastname', 'last', 'surname', 'lname'],
            'phone' => ['phone', 'mobile', 'phone number', 'tel', 'telephone', 'cell'],
            'country' => ['country', 'nation', 'country code'],
        ];

        $mapping = array_fill_keys(array_keys($synonyms), '');

        foreach ($header as $index => $column) {
            $normalized = strtolower(trim($column));

            foreach ($synonyms as $field => $options) {
                if ($mapping[$field] === '' && in_array($normalized, $options, true)) {
                    $mapping[$field] = (string) $index;
                }
            }
        }

        return $mapping;
    }

    #[Computed]
    public function contactsCount(): int
    {
        return $this->contactList->contacts()->count();
    }

    /**
     * How long the chosen sending speed would take for this list, so the rate is
     * picked with its cost in elapsed time visible.
     */
    #[Computed]
    public function pacingEstimate(): ?string
    {
        if (! $this->sendContactsPerHour || $this->sendContactsPerHour < 1) {
            return null;
        }

        $count = $this->contactsCount;

        if ($count === 0) {
            return null;
        }

        $minutes = (int) ceil($count / $this->sendContactsPerHour * 60);

        return __('About :duration to send :count contacts at this speed.', [
            'duration' => CarbonInterval::minutes($minutes)->cascade()->forHumans(parts: 2),
            'count' => number_format($count),
        ]);
    }

    /**
     * What the chosen split size would produce, so the outcome is visible
     * before the lists are actually created.
     */
    #[Computed]
    public function splitEstimate(): ?string
    {
        $count = $this->contactsCount;

        if (! $this->splitSize || $this->splitSize < 1 || $this->splitSize >= $count) {
            return null;
        }

        $chunks = (int) ceil($count / $this->splitSize);

        return __('Will create :chunks lists, ":first" through ":last". This list is left untouched.', [
            'chunks' => $chunks,
            'first' => "{$this->contactList->name} {$this->splitSize} 1",
            'last' => "{$this->contactList->name} {$this->splitSize} {$chunks}",
        ]);
    }

    /**
     * @return Collection<int, Integration>
     */
    #[Computed]
    public function integrations(): Collection
    {
        return $this->currentTeam()->integrations()->where('status', 'connected')->latest()->get();
    }

    /**
     * @return Collection<int, Delivery>
     */
    #[Computed]
    public function deliveries(): Collection
    {
        return $this->contactList->deliveries()->with('integration')->latest()->get();
    }

    /**
     * @return LengthAwarePaginator<Contact>
     */
    #[Computed]
    public function contacts(): LengthAwarePaginator
    {
        return $this->contactList->contacts()
            ->when($this->trimmedSearch() !== '', function ($query): void {
                $query->where('email', 'like', '%'.$this->trimmedSearch().'%');
            })
            ->latest()
            ->paginate(25);
    }

    protected function trimmedSearch(): string
    {
        return trim($this->search);
    }

    /**
     * The fields that can be mapped from imported columns.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function mappableFields(): array
    {
        return [
            'email' => __('Email'),
            'first_name' => __('First name'),
            'last_name' => __('Last name'),
            'phone' => __('Phone'),
            'country' => __('Country'),
        ];
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div>
        <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('lists.index')" wire:navigate>{{ __('Lists') }}</flux:button>
    </div>

    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ $contactList->name }}</flux:heading>
            <flux:subheading>{{ trans_choice('{0}No contacts yet|{1}:count contact|[2,*]:count contacts', $this->contactsCount, ['count' => $this->contactsCount]) }}</flux:subheading>
        </div>

        <div class="flex items-center gap-2">
            <flux:modal.trigger name="add-contact">
                <flux:button variant="subtle" icon="user-plus" x-data="" x-on:click.prevent="$dispatch('open-modal', 'add-contact')" data-test="add-contact-button">
                    {{ __('Add contact') }}
                </flux:button>
            </flux:modal.trigger>

            <flux:modal.trigger name="import-contacts">
                <flux:button variant="subtle" icon="arrow-up-tray" x-data="" x-on:click.prevent="$dispatch('open-modal', 'import-contacts')" data-test="import-button">
                    {{ __('Import') }}
                </flux:button>
            </flux:modal.trigger>

            <flux:modal.trigger name="split-list">
                <flux:button variant="subtle" icon="square-2-stack" x-data="" x-on:click.prevent="$dispatch('open-modal', 'split-list')" data-test="split-button" :disabled="$this->contactsCount < 2">
                    {{ __('Split') }}
                </flux:button>
            </flux:modal.trigger>

            <flux:modal.trigger name="send-list">
                <flux:button variant="primary" icon="paper-airplane" x-data="" x-on:click.prevent="$dispatch('open-modal', 'send-list')" data-test="send-button" :disabled="$this->contactsCount === 0">
                    {{ __('Send to destination') }}
                </flux:button>
            </flux:modal.trigger>
        </div>
    </div>

    {{-- Deliveries --}}
    @if ($this->deliveries->isNotEmpty())
        <div class="space-y-3">
            <flux:heading size="lg">{{ __('Deliveries') }}</flux:heading>

            @foreach ($this->deliveries as $delivery)
                <div class="flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="delivery-row">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="font-medium">{{ $delivery->integration?->name }}</span>
                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">&middot; {{ $delivery->destinationLabel() }}</flux:text>
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

                    <div class="flex items-center gap-2">
                        @if ($delivery->isCancellable())
                            <flux:button variant="subtle" size="sm" icon="x-circle" wire:click="cancelDelivery({{ $delivery->id }})" wire:confirm="{{ __('Stop sending the remaining contacts? Contacts already sent stay at the destination.') }}" data-test="cancel-delivery-button">
                                {{ __('Cancel') }}
                            </flux:button>
                        @endif

                        @if ($delivery->isResumable())
                            <flux:button variant="subtle" size="sm" icon="play" wire:click="resumeDelivery({{ $delivery->id }})" data-test="resume-delivery-button">
                                {{ __('Resume') }}
                            </flux:button>
                        @endif

                        @if ($delivery->failed_count > 0 && $delivery->status !== App\Models\Delivery::STATUS_CANCELLED)
                            <flux:button variant="subtle" size="sm" icon="arrow-path" wire:click="retryDelivery({{ $delivery->id }})" data-test="retry-delivery-button">
                                {{ __('Retry :count failed', ['count' => $delivery->failed_count]) }}
                            </flux:button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Contacts --}}
    <div class="flex items-center justify-between gap-4">
        <flux:input
            wire:model.live.debounce.300ms="search"
            icon="magnifying-glass"
            class="max-w-sm"
            :placeholder="__('Search emails, e.g. @gmail.com')"
            data-test="contact-search"
        />

        @if ($this->contacts->total() !== $this->contactsCount)
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400" data-test="contact-search-count">
                {{ trans_choice('{0}No matching contacts|{1}:count matching contact|[2,*]:count matching contacts', $this->contacts->total(), ['count' => number_format($this->contacts->total())]) }}
            </flux:text>
        @endif
    </div>

    {{-- Padding is set on the wrapper rather than each cell: the descendant selector
         outranks Flux's own utility classes, so one rule covers the whole table. --}}
    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 [&_td]:px-6 [&_td]:py-4 [&_th]:px-6 [&_th]:py-3.5">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Email') }}</flux:table.column>
                <flux:table.column>{{ __('Phone') }}</flux:table.column>
                <flux:table.column>{{ __('Country') }}</flux:table.column>
                <flux:table.column />
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->contacts as $contact)
                    <flux:table.row :key="$contact->id" data-test="contact-row">
                        <flux:table.cell>
                            <div class="flex items-center gap-2.5">
                                {{-- Gravatar returns a 404 when the address has no avatar, so the
                                     broken load is the signal to swap in initials. --}}
                                <div class="shrink-0" x-data="{ hasGravatar: true }">
                                    <img
                                        src="{{ $contact->gravatarUrl(64) }}"
                                        alt=""
                                        loading="lazy"
                                        class="size-8 rounded-full object-cover"
                                        x-show="hasGravatar"
                                        x-on:error="hasGravatar = false"
                                    />
                                    <span
                                        class="flex size-8 items-center justify-center rounded-full bg-zinc-100 text-xs font-medium text-zinc-500 dark:bg-zinc-700 dark:text-zinc-300"
                                        style="display: none"
                                        x-show="! hasGravatar"
                                    >{{ $contact->initials() }}</span>
                                </div>

                                <span>{{ $contact->fullName() ?: '—' }}</span>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($this->editingContactId === $contact->id)
                                <div class="flex items-start gap-2">
                                    <div>
                                        <flux:input
                                            wire:model="editingEmail"
                                            wire:keydown.enter.prevent="saveEmail"
                                            wire:keydown.escape="cancelEditingEmail"
                                            type="email"
                                            size="sm"
                                            autofocus
                                            data-test="edit-email-input"
                                        />
                                        <flux:error name="editingEmail" />
                                    </div>
                                    <flux:button variant="primary" size="xs" icon="check" wire:click="saveEmail" data-test="save-email-button" />
                                    <flux:button variant="ghost" size="xs" icon="x-mark" wire:click="cancelEditingEmail" data-test="cancel-email-button" />
                                </div>
                            @else
                                <button
                                    type="button"
                                    wire:click="editEmail({{ $contact->id }})"
                                    class="group flex items-center gap-1.5 text-left hover:text-zinc-950 dark:hover:text-white"
                                    data-test="edit-email-button"
                                >
                                    {{ $contact->email }}
                                    <flux:icon.pencil-square class="size-3.5 opacity-0 transition group-hover:opacity-60" />
                                </button>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $contact->phone ?: '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $contact->country ?: '—' }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:button variant="ghost" size="xs" icon="trash" wire:click="deleteContact({{ $contact->id }})" wire:confirm="{{ __('Remove this contact?') }}" />
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="text-center text-zinc-500 dark:text-zinc-400">
                            @if (trim($search) !== '')
                                {{ __('No contacts match ":search".', ['search' => trim($search)]) }}
                            @else
                                {{ __('No contacts yet. Import or add one to get started.') }}
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <div>{{ $this->contacts->links() }}</div>

    {{-- Send to destination modal --}}
    <flux:modal name="send-list" :show="$errors->has('sendIntegrationId') || $errors->has('sendRemoteId')" focusable class="max-w-lg">
        <form wire:submit="sendToDestination" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Send to a destination') }}</flux:heading>
                <flux:subheading>{{ __('Push these contacts to a connected provider. Contacts already synced to the chosen destination are skipped.') }}</flux:subheading>
            </div>

            @if ($this->integrations->isEmpty())
                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('Connect an integration first.') }}
                    <flux:link :href="route('integrations.index')" wire:navigate>{{ __('Go to integrations') }}</flux:link>
                </flux:text>
            @else
                <flux:select wire:model.live="sendIntegrationId" :label="__('Integration')" :placeholder="__('Select an integration')" data-test="send-integration">
                    <flux:select.option value="">Select Option</flux:select.option>
                    @foreach ($this->integrations as $integration)
                        <flux:select.option value="{{ $integration->id }}">{{ $integration->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($this->sendRemoteListsError)
                    <flux:text class="text-sm text-red-500">{{ __('Could not load lists: :error', ['error' => $this->sendRemoteListsError]) }}</flux:text>
                @elseif ($sendIntegrationId)
                    <flux:select wire:model="sendRemoteId" :label="__('Destination list')" :placeholder="__('Select a list')" data-test="send-remote">
                        @foreach ($this->sendRemoteLists as $remote)
                            <flux:select.option value="{{ $remote['id'] }}">{{ $remote['name'] }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                <flux:input
                    wire:model.live.debounce.500ms="sendContactsPerHour"
                    type="number"
                    min="1"
                    :label="__('Sending speed (optional)')"
                    :placeholder="__('Leave empty to send all at once')"
                    :description="__('Contacts queued per hour. Use this to spread a large list out instead of pushing it in one burst.')"
                    data-test="send-rate"
                />

                @if ($this->pacingEstimate)
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400" data-test="send-rate-estimate">
                        {{ $this->pacingEstimate }}
                    </flux:text>
                @endif
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="send-submit" :disabled="$this->integrations->isEmpty()">{{ __('Send') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Split list modal --}}
    <flux:modal name="split-list" :show="$errors->has('splitSize')" focusable class="max-w-lg">
        <form wire:submit="splitList" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Split into smaller lists') }}</flux:heading>
                <flux:subheading>{{ __('Copy these contacts into smaller numbered lists — handy when a provider caps how many subscribers you can import. This list stays as it is.') }}</flux:subheading>
            </div>

            <flux:input
                wire:model.live.debounce.500ms="splitSize"
                type="number"
                min="1"
                :label="__('Contacts per list')"
                :placeholder="__('e.g. 700')"
                data-test="split-size"
            />

            @if ($this->splitEstimate)
                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400" data-test="split-estimate">
                    {{ $this->splitEstimate }}
                </flux:text>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="split-submit">{{ __('Split list') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Add contact modal --}}
    <flux:modal name="add-contact" focusable class="max-w-lg">
        <form wire:submit="addContact" class="space-y-6">
            <flux:heading size="lg">{{ __('Add a contact') }}</flux:heading>

            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="manual.first_name" :label="__('First name')" data-test="manual-first-name" />
                <flux:input wire:model="manual.last_name" :label="__('Last name')" data-test="manual-last-name" />
            </div>
            <flux:input wire:model="manual.email" type="email" :label="__('Email')" required data-test="manual-email" />
            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="manual.phone" :label="__('Phone')" data-test="manual-phone" />
                <flux:input wire:model="manual.country" :label="__('Country')" data-test="manual-country" />
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="manual-submit">{{ __('Add contact') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Import modal --}}
    <flux:modal name="import-contacts" focusable class="max-w-2xl" x-on:close="$wire.resetImport()">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Import contacts') }}</flux:heading>
                <flux:subheading>{{ __('Upload a CSV or paste rows. The first row is treated as column headers.') }}</flux:subheading>
            </div>

            @if ($importStep === 'source')
                <flux:radio.group wire:model.live="importMode" variant="segmented">
                    <flux:radio value="upload" :label="__('Upload file')" />
                    <flux:radio value="paste" :label="__('Paste rows')" />
                </flux:radio.group>

                @if ($importMode === 'upload')
                    <flux:input type="file" wire:model="file" accept=".csv,.txt" :label="__('CSV file')" data-test="import-file" />
                @else
                    <flux:textarea wire:model="pasted" rows="8" :label="__('Rows')" :placeholder="__('first_name,last_name,email,phone,country')" data-test="import-paste" />
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" wire:click="parseSource" data-test="import-continue">{{ __('Continue') }}</flux:button>
                </div>
            @else
                <flux:text>{{ __(':count rows detected. Map your columns to contact fields.', ['count' => number_format($importRowCount)]) }}</flux:text>

                <div class="space-y-3">
                    @foreach ($this->mappableFields as $field => $label)
                        <flux:select wire:model="mapping.{{ $field }}" :label="$label" :placeholder="__('— Not imported —')" data-test="map-{{ $field }}">
                            <flux:select.option value="">{{ __('— Not imported —') }}</flux:select.option>
                            @foreach ($header as $index => $column)
                                <flux:select.option value="{{ $index }}">{{ $column }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endforeach
                </div>

                @error('mapping.email')
                    <flux:text class="text-sm text-red-500">{{ $message }}</flux:text>
                @enderror

                <div class="flex justify-between">
                    <flux:button variant="ghost" wire:click="$set('importStep', 'source')">{{ __('Back') }}</flux:button>
                    <flux:button variant="primary" wire:click="runImport" data-test="import-run">{{ __('Import contacts') }}</flux:button>
                </div>
            @endif
        </div>
    </flux:modal>
</div>
