<?php

namespace App\Integrations\Support;

/**
 * A list/campaign/audience that exists on the remote provider and can be
 * selected as the destination for a local contact list.
 */
class RemoteList
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}

    /**
     * @return array{id: string, name: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}
