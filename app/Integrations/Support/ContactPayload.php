<?php

namespace App\Integrations\Support;

use App\Models\Contact;
use Illuminate\Support\Str;

/**
 * A provider-agnostic representation of a contact to be pushed to a remote
 * provider. Drivers map these fields onto their own API shape.
 */
class ContactPayload
{
    public function __construct(
        public string $email,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $phone = null,
        public ?string $country = null,
    ) {}

    /**
     * Build a payload from a stored Contact model.
     */
    public static function fromContact(Contact $contact): self
    {
        return new self(
            email: $contact->email,
            firstName: $contact->first_name,
            lastName: $contact->last_name,
            phone: $contact->phone,
            country: $contact->country,
        );
    }

    /**
     * Get the combined full name, or null when no name parts are present.
     */
    public function fullName(): ?string
    {
        $name = trim(implode(' ', array_filter([$this->firstName, $this->lastName])));

        return Str::of($name)->trim()->value() ?: null;
    }

    /**
     * Whether the contact carries a real first name, as opposed to the greeting
     * placeholder the importer stores for nameless rows.
     */
    public function hasRealFirstName(): bool
    {
        return filled($this->firstName) && $this->firstName !== Contact::DEFAULT_FIRST_NAME;
    }

    /**
     * Names for providers that require a genuine first and last name on every
     * record. When the contact has none, they are guessed from the email's local
     * part ("grace.hopper@example.com" becomes "Grace Hopper"), which reads far
     * better on a registrant list than the "there" placeholder would.
     *
     * Only used by drivers that need it — providers happy to greet a contact as
     * "Hi there" should keep reading firstName directly.
     *
     * @return array{first: ?string, last: ?string}
     */
    public function resolvedNames(): array
    {
        if ($this->hasRealFirstName()) {
            return ['first' => $this->firstName, 'last' => $this->lastName];
        }

        return $this->namesFromEmail();
    }

    /**
     * @return array{first: ?string, last: ?string}
     */
    private function namesFromEmail(): array
    {
        $parts = collect(preg_split('/[._+-]+/', Str::before($this->email, '@'), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            // Strip digits so "grace.hopper88" doesn't register as "Hopper88".
            ->map(fn (string $part): string => (string) preg_replace('/\d+/', '', $part))
            ->filter(fn (string $part): bool => $part !== '')
            ->map(fn (string $part): string => Str::ucfirst(Str::lower($part)))
            ->values();

        return ['first' => $parts->first(), 'last' => $parts->get(1)];
    }
}
