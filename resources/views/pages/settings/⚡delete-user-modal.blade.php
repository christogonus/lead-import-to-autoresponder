<?php

use App\Actions\Users\DeleteUser;
use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Paddle\Exceptions\PaddleException;
use Livewire\Component;

new class extends Component {
    use PasswordValidationRules;

    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout, DeleteUser $deleter): void
    {
        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        $user = Auth::user();

        // Billing is stopped while the user is still signed in, so a Paddle
        // failure leaves them where they were with nothing lost.
        try {
            $deleter->stopBilling($user);
        } catch (PaddleException|ConnectionException $exception) {
            Log::warning('Account deletion stopped: Paddle subscription could not be cancelled.', ['message' => $exception->getMessage()]);
            $this->addError('password', __('We could not cancel your subscription with Paddle, so nothing has been deleted yet. Please try again in a moment.'));

            return;
        }

        // Signing out writes the remember token back to the user row, so it
        // has to happen before the row is gone.
        $logout();

        $deleter->handle($user);

        $this->redirect('/', navigate: true);
    }
}; ?>

<flux:modal name="confirm-user-deletion" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
    <form method="POST" wire:submit="deleteUser" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Are you sure you want to delete your account?') }}</flux:heading>

            <flux:subheading>
                {{ __('This cancels your subscription straight away and permanently erases your account, every team you own, and all of their lists, contacts and integrations. Members you invited lose access to those teams. It cannot be undone.') }}
            </flux:subheading>

            <flux:subheading class="mt-2">
                {{ __('Want a copy first? Download your data from the Privacy section before you continue. Enter your password to confirm.') }}
            </flux:subheading>
        </div>

        <flux:input wire:model="password" :label="__('Password')" type="password" viewable />

        <div class="flex justify-end space-x-2 rtl:space-x-reverse">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>

            <flux:button variant="danger" type="submit" data-test="confirm-delete-user-button">
                {{ __('Delete account') }}
            </flux:button>
        </div>
    </form>
</flux:modal>
