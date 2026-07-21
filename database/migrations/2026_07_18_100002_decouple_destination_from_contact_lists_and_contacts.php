<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Destinations are now chosen per delivery (see the deliveries table) rather
 * than baked into a list, and sync status is tracked per delivery contact.
 *
 * The create_contact_lists / create_contacts migrations now build the final
 * schema directly, so this migration is effectively a no-op for fresh installs.
 * It is kept (and made idempotent) so any environment that already ran the
 * older schema is still cleaned up, and so it can never fail by trying to drop
 * something that no longer exists.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('contact_lists', 'integration_id')) {
            Schema::table('contact_lists', function (Blueprint $table) {
                $table->dropConstrainedForeignId('integration_id');
            });
        }

        $listColumns = array_values(array_filter(
            ['remote_id', 'remote_name'],
            fn (string $column): bool => Schema::hasColumn('contact_lists', $column),
        ));

        if ($listColumns !== []) {
            Schema::table('contact_lists', function (Blueprint $table) use ($listColumns) {
                $table->dropColumn($listColumns);
            });
        }

        $contactColumns = array_values(array_filter(
            ['status', 'sync_error', 'remote_id', 'synced_at'],
            fn (string $column): bool => Schema::hasColumn('contacts', $column),
        ));

        if ($contactColumns !== []) {
            Schema::table('contacts', function (Blueprint $table) use ($contactColumns) {
                $table->dropColumn($contactColumns);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op: the create migrations already produce the final schema.
    }
};
