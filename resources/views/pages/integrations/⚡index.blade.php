<?php

use App\Enums\IntegrationProvider;
use App\Integrations\IntegrationManager;
use App\Models\Integration;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Integrations')] class extends Component {
    public string $provider = 'getresponse';

    #[Validate('required|string|max:255')]
    public string $name = '';

    /** @var array<string, string> */
    public array $credentials = [];

    public function mount(): void
    {
        match (request()->query('oauth')) {
            'connected' => Flux::toast(variant: 'success', text: __('Integration connected.')),
            'error' => Flux::toast(variant: 'danger', text: __('Authorization was cancelled or failed. Nothing was saved.')),
            default => null,
        };
    }

    public function connect(IntegrationManager $manager): void
    {
        $provider = IntegrationProvider::from($this->provider);

        // OAuth providers leave the app to authorize, so redirect into that flow
        // instead of verifying manually-entered credentials.
        if ($provider->usesOAuth()) {
            $this->validate(['name' => ['required', 'string', 'max:255']]);

            $this->redirect(route('integrations.oauth.redirect', [
                'current_team' => $this->currentTeam()->slug,
                'provider' => $provider->value,
                'name' => $this->name,
            ]));

            return;
        }

        $this->validate($this->connectRules($provider));

        $driver = $manager->make($provider, $this->credentials);

        if (! $driver->verify()) {
            $this->addError('credentials', __('Those credentials were rejected by :provider.', ['provider' => $provider->label()]));

            return;
        }

        $this->currentTeam()->integrations()->create([
            'provider' => $provider,
            'name' => $this->name,
            'credentials' => $this->credentials,
            'status' => 'connected',
            'last_verified_at' => now(),
        ]);

        Flux::modal('connect-integration')->close();
        $this->reset('name', 'credentials');

        Flux::toast(variant: 'success', text: __('Integration connected.'));
    }

    public function verifyIntegration(IntegrationManager $manager, Integration $integration): void
    {
        $this->authorizeIntegration($integration);

        $connected = $manager->driver($integration)->verify();

        $integration->update([
            'status' => $connected ? 'connected' : 'invalid',
            'last_verified_at' => $connected ? now() : $integration->last_verified_at,
        ]);

        Flux::toast(
            variant: $connected ? 'success' : 'danger',
            text: $connected ? __('Connection is healthy.') : __('Connection failed. Check the credentials.'),
        );
    }

    public function disconnect(Integration $integration): void
    {
        $this->authorizeIntegration($integration);

        $integration->delete();

        Flux::toast(variant: 'success', text: __('Integration disconnected.'));
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function connectRules(IntegrationProvider $provider): array
    {
        $rules = ['name' => ['required', 'string', 'max:255']];

        foreach ($provider->credentialFields() as $field) {
            $rules['credentials.'.$field['key']] = ['required', 'string'];
        }

        return $rules;
    }

    protected function authorizeIntegration(Integration $integration): void
    {
        abort_unless($integration->team_id === $this->currentTeam()->id, 403);
    }

    protected function currentTeam()
    {
        return Auth::user()->currentTeam;
    }

    #[Computed]
    public function selectedProvider(): IntegrationProvider
    {
        return IntegrationProvider::from($this->provider);
    }

    /**
     * @return array<int, IntegrationProvider>
     */
    #[Computed]
    public function providers(): array
    {
        return IntegrationProvider::cases();
    }

    /**
     * @return Collection<int, Integration>
     */
    #[Computed]
    public function integrations(): Collection
    {
        return $this->currentTeam()->integrations()->latest()->get();
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Integrations') }}</flux:heading>
            <flux:subheading>{{ __('Connect the autoresponders and CRMs you import contacts into.') }}</flux:subheading>
        </div>

        <flux:modal.trigger name="connect-integration">
            <flux:button variant="primary" icon="plus" data-test="connect-integration-button">
                {{ __('Connect') }}
            </flux:button>
        </flux:modal.trigger>
    </div>

    <div class="space-y-3">
        @forelse ($this->integrations as $integration)
            <div class="flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="integration-row">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="font-medium">{{ $integration->name }}</span>
                        <flux:badge size="sm" :color="$integration->status === 'connected' ? 'green' : 'red'">
                            {{ $integration->status === 'connected' ? __('Connected') : __('Invalid') }}
                        </flux:badge>
                    </div>
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ $integration->provider->label() }}
                        @if ($integration->last_verified_at)
                            &middot; {{ __('verified :time', ['time' => $integration->last_verified_at->diffForHumans()]) }}
                        @endif
                    </flux:text>
                </div>

                <div class="flex items-center gap-1">
                    <flux:tooltip :content="__('Test connection')">
                        <flux:button variant="ghost" size="sm" icon="arrow-path" wire:click="verifyIntegration({{ $integration->id }})" data-test="verify-integration-button" />
                    </flux:tooltip>
                    <flux:tooltip :content="__('Disconnect')">
                        <flux:button variant="ghost" size="sm" icon="trash" wire:click="disconnect({{ $integration->id }})" wire:confirm="{{ __('Disconnect this integration? Lists using it will stop syncing.') }}" data-test="disconnect-integration-button" />
                    </flux:tooltip>
                </div>
            </div>
        @empty
            <flux:card class="text-center">
                <flux:icon.puzzle-piece class="mx-auto mb-2 size-8 text-zinc-400" />
                <flux:text>{{ __('No integrations yet. Connect one to start importing contacts.') }}</flux:text>
            </flux:card>
        @endforelse
    </div>

    <flux:modal name="connect-integration" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
        <form wire:submit="connect" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Connect an integration') }}</flux:heading>
                <flux:subheading>{{ __('Enter your provider credentials. We verify them before saving.') }}</flux:subheading>
            </div>

            <flux:select wire:model.live="provider" :label="__('Provider')" data-test="connect-provider">
                @foreach ($this->providers as $option)
                    <flux:select.option value="{{ $option->value }}">{{ $option->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="name" :label="__('Name')" :placeholder="__('e.g. My GetResponse account')" data-test="connect-name" />

            @if ($this->selectedProvider->usesOAuth())
                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400" data-test="connect-oauth-notice">
                    {{ __('You will be securely redirected to :provider to authorize access. No password is stored.', ['provider' => $this->selectedProvider->label()]) }}
                </flux:text>
            @else
                @foreach ($this->selectedProvider->credentialFields() as $field)
                    <flux:input
                        wire:model="credentials.{{ $field['key'] }}"
                        :type="$field['type']"
                        :label="$field['label']"
                        :description="$field['hint'] ?? null"
                        data-test="connect-credential-{{ $field['key'] }}"
                    />
                @endforeach
            @endif

            @error('credentials')
                <flux:text class="text-sm text-red-500">{{ $message }}</flux:text>
            @enderror

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="connect-submit">
                    {{ $this->selectedProvider->usesOAuth() ? __('Continue to :provider', ['provider' => $this->selectedProvider->label()]) : __('Verify & connect') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
