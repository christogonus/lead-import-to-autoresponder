<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->unsignedInteger('contacts_per_hour')->nullable()->after('status');
            $table->timestamp('pacing_started_at')->nullable()->after('contacts_per_hour');
        });

        Schema::table('delivery_contacts', function (Blueprint $table) {
            $table->timestamp('released_at')->nullable()->after('status');

            $table->index(['delivery_id', 'released_at']);
        });

        // Rows that predate pacing were dispatched the moment they were created.
        DB::table('delivery_contacts')->update(['released_at' => DB::raw('created_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn(['contacts_per_hour', 'pacing_started_at']);
        });

        Schema::table('delivery_contacts', function (Blueprint $table) {
            $table->dropIndex(['delivery_id', 'released_at']);
            $table->dropColumn('released_at');
        });
    }
};
