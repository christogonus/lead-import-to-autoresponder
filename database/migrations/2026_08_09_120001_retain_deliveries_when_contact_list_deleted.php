<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a delivery outlive the list it was sent from, so permanently deleting a
 * drafted list clears its contacts without erasing the record of what was
 * already pushed to a destination.
 *
 * The create migration now builds this shape directly, so this is a no-op on
 * fresh installs — it exists to bring already-migrated databases forward.
 * SQLite cannot drop a foreign key, which is why the change is expressed as an
 * edit to the create migration plus this guarded catch-up rather than a plain
 * alter that every environment runs.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('deliveries', 'contact_list_name')) {
            return;
        }

        Schema::table('deliveries', function (Blueprint $table) {
            $table->string('contact_list_name')->nullable()->after('contact_list_id');
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropForeign(['contact_list_id']);
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->unsignedBigInteger('contact_list_id')->nullable()->change();
            $table->foreign('contact_list_id')->references('id')->on('contact_lists')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
