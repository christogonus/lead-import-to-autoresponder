<?php

namespace App\Integrations\Contracts;

use App\Integrations\Support\ContactPayload;
use App\Integrations\Support\ContactSyncResult;
use App\Integrations\Support\RemoteList;

/**
 * The contract every autoresponder/CRM driver must implement. Adding a new
 * integration is a matter of implementing this interface and registering the
 * driver on the IntegrationProvider enum.
 */
interface AutoresponderProvider
{
    /**
     * Confirm the stored credentials are valid by making a lightweight
     * authenticated request against the provider.
     */
    public function verify(): bool;

    /**
     * Fetch the lists/campaigns/audiences available on the provider that a
     * local contact list can be mapped to.
     *
     * @return array<int, RemoteList>
     */
    public function lists(): array;

    /**
     * Push a single contact to the given remote list.
     */
    public function pushContact(string $remoteListId, ContactPayload $contact): ContactSyncResult;
}
