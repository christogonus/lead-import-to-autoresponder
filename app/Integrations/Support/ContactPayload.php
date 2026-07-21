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
}
