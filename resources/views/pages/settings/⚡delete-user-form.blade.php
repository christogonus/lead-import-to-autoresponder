<?php

use Livewire\Component;

new class extends Component {}; ?>

<div>
    <section class="mt-10 space-y-4" aria-labelledby="your-data-heading">
        <div>
            <flux:heading level="2" id="your-data-heading">{{ __('Your data') }}</flux:heading>
            <flux:subheading>
                {{ __('Download a copy of everything we hold about you, including the lists in teams you own, as a JSON file.') }}
            </flux:subheading>
        </div>

        <flux:button :href="route('privacy.export')" icon="arrow-down-tray" data-test="export-data-button">
            {{ __('Download my data') }}
        </flux:button>
    </section>

    <section class="mt-10 space-y-6">
        <div class="relative mb-5">
            <flux:heading level="2">{{ __('Delete account') }}</flux:heading>
            <flux:subheading>{{ __('Cancel your subscription and erase your account and the teams you own') }}</flux:subheading>
        </div>

        <flux:modal.trigger name="confirm-user-deletion">
            <flux:button variant="danger" data-test="delete-user-button">
                {{ __('Delete account') }}
            </flux:button>
        </flux:modal.trigger>

        <livewire:pages::settings.delete-user-modal />
    </section>
</div>
